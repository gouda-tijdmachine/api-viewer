<?php

declare(strict_types=1);

require_once 'geoPHP.php';
require_once 'SparqlService.php';
require_once 'Formatters.php';
require_once 'Lijsten.php';

class DataService
{
    private SparqlService $sparqlService;
    private CacheService $cache;

    private $formatters;
    private $geoPHP;
    public $lijsten;

    public function __construct()
    {
        $this->sparqlService = new SparqlService();
        $this->formatters = new Formatters();
        $this->geoPHP = new GeoPHP();
        $this->lijsten = new Lijsten();
        $this->cache = new CacheService();
    }

    # "geen beroep"-varianten; een écht beroep uit een andere vermelding
    # verdient de voorkeur in lijstweergaven
    private const GEEN_BEROEP = ['geen', 'zonder', 'geen beroep', 'zonder beroep',
        'zonder beroep of bezigheid', 'geen beroep of bezigheid'];

    private function isGeenBeroep(?string $beroep): bool
    {
        return $beroep === null
            || in_array(strtolower(trim($beroep, " .")), self::GEEN_BEROEP, true);
    }

    # deterministische keuze bij meerdere beroepen per reconstructie: een écht
    # beroep wint van een "geen beroep"-variant, en het langste (meest
    # specifieke, "koopman in manufacturen" > "koopman") wint daarbinnen
    private function beterBeroep(?string $huidig, ?string $nieuw): ?string
    {
        if (empty($nieuw)) {
            return $huidig;
        }
        if ($this->isGeenBeroep($huidig)) {
            return $this->isGeenBeroep($nieuw) ? ($huidig ?? $nieuw) : $nieuw;
        }
        if (!$this->isGeenBeroep($nieuw) && strlen($nieuw) > strlen($huidig)) {
            return $nieuw;
        }

        return $huidig;
    }

    public function getStraten(): array
    {
        $straten = $this->sparqlService->get_straten();
        $results = [];
        foreach ($straten as $straat) {
            $results[] = [
                'identifier' => $straat['identifier']['value'] ?? '',
                'naam' => $straat['naam']['value'] ?? '',
                'naam_alt' => $straat['naam_alt']['value'] ?? null
            ];
        }

        return $results;
    }

    public function getTijdvakken(): array
    {
        $tijdvakken = $this->sparqlService->get_tijdvakken();
        $results = [];
        foreach ($tijdvakken as $tijdvak) {
            $results[] = [
                'identifier' => $tijdvak['identifier']['value'],
                'naam' => $tijdvak['naam']['value'],
                'naam_alt' => $tijdvak['naam_alt']['value'] ?? null,
                'omschrijving' => $tijdvak['omschrijving']['value'] ?? null,
                'jaar_start' => $tijdvak['startjaar']['value'] ?? null,
                'jaar_einde' => $tijdvak['eindjaar']['value'] ?? null
            ];
        }

        return $results;
    }

    public function getJaarPanden($jaar)
    {
        $cache_key = "panden/$jaar";
        $geoJson = $this->cache->get($cache_key);
        if (!$geoJson) {
            $geoJson = $this->_getJaarpanden($jaar);
            if ($geoJson === null) {
                return null;
            }
            $geoJson = json_encode($geoJson);
            $this->cache->put($cache_key, $geoJson);
        }

        return json_decode($geoJson);
    }

    private function _getJaarpanden($jaar): array
    {
        $panden = $this->sparqlService->get_panden_jaar($jaar);

        $geojson = [
            "type" => "FeatureCollection",
            "features" => []
        ];

        foreach ($panden as $pand) {
            $identifier = !empty($pand['locatiepunt']['value']) ? $pand['locatiepunt']['value'] : ($pand['identifier']['value'] ?? '');

            $naam = !empty($pand['adressen']['value']) ? $this->formatters->recenteAdressenFormatter($pand['adressen']['value']) : ($pand['naam']['value'] ?? '');

            $feature = [
                "type" => "Feature",
                "properties" => [
                    "identifier" => $identifier,
                    "naam" => $naam
                ]
            ];

            if (!empty($pand['geometry']['value'])) {
                $multipoint = $this->geoPHP->load($pand['geometry']['value'], 'wkt');
                $feature["geometry"] = json_decode($multipoint->out('json'));
            } else {
                $feature["geometry"] = null;
            }

            $geojson["features"][] = $feature;
        }

        return $geojson;
    }

    public function getPanden($q = null, $straatidentifier = null, $tijdvakidentifier = null, $status = 'alle', $limit = 10, $offset = 0): array
    {
        $panden = $this->sparqlService->get_panden_index($q, $straatidentifier, $status, $this->lijsten->get_tijdvak($tijdvakidentifier));

        $filtered = [];

        foreach ($panden as $pand) {
            if (empty($pand['locatiepunt']['value'])) {
                continue;
            }

            $id = $pand['locatiepunt']['value'];
            if (!isset($filtered[$id])) {
                $filtered[$id] = [
                    'identifier' => $id,
                    'naam' => $this->formatters->locatiepuntFormatter($pand['naam']['value']) ?? '',
                    'straten' => []
                ];
            }

            if (!empty($pand['straat']['value']) && !empty($pand['straatnaam']['value'])) {
                if (!isset($filtered[$id]['straten'][$pand['straat']['value']])) {
                    $filtered[$id]['straten'][$pand['straat']['value']] = [
                        'identifier' => $pand['straat']['value'],
                        'naam' => $pand['straatnaam']['value']
                    ];
                }
            }
        }

        foreach ($filtered as &$item) {
            $item['straten'] = array_values($item['straten']);
        }

        $results = array_values($filtered);
        $sliced_results = array_slice($results, $offset, $limit);

        return [
            'panden' => $sliced_results,
            'aantal' => count($results)
        ];
    }

    public function getPersonen($q = null, $straatidentifier = null, $tijdvakidentifier = null, $limit = 10, $offset = 0): array
    {
        $personen = $this->sparqlService->get_personen_index($q, $straatidentifier, $this->lijsten->get_tijdvak($tijdvakidentifier));

        $filtered = [];

        foreach ($personen as $persoon) {
            if (empty($persoon['identifier']['value'])) {
                continue;
            }

            $id = $persoon['identifier']['value'];
            if (!isset($filtered[$id])) {
                $filtered[$id] = [
                    'identifier' => $id,
                    'naam' => $persoon['naam']['value'] ?? '',
                    'beroep' => !empty($persoon['beroep']['value']) ? $this->formatters->beroepFormatter($persoon['beroep']['value']) : null,
                    'datering' => null,
                    'pandidentifiers' => [],
                    '_jaren' => []
                ];

                # datering = geboorte–overlijdensjaar van de reconstructie;
                # is geen van beide bekend, dan als fallback het bereik van de
                # vermeldingsjaren (verzameld over alle rijen, zie onder)
                $geb = substr($persoon['geboortedatum']['value'] ?? '', 0, 4);
                $ovl = substr($persoon['overlijdensdatum']['value'] ?? '', 0, 4);
                if ($geb !== '' || $ovl !== '') {
                    $filtered[$id]['datering'] = $geb . '–' . $ovl;
                }
            }

            $beter = $this->beterBeroep($filtered[$id]['beroep'], $persoon['beroep']['value'] ?? null);
            if ($beter !== $filtered[$id]['beroep']) {
                $filtered[$id]['beroep'] = $this->formatters->beroepFormatter($beter);
            }

            if ($filtered[$id]['datering'] === null && !empty($persoon['datering']['value'])) {
                $filtered[$id]['_jaren'][] = (int) substr($persoon['datering']['value'], 0, 4);
            }

            # een persoonsreconstructie kan via meerdere vermeldingen aan
            # meerdere panden gekoppeld zijn: verzamel ze allemaal (uniek)
            if (!empty($persoon['locatiepunt']['value'])
                    && !in_array($persoon['locatiepunt']['value'], $filtered[$id]['pandidentifiers'], true)) {
                $filtered[$id]['pandidentifiers'][] = $persoon['locatiepunt']['value'];
            }
        }

        foreach ($filtered as &$item) {
            if ($item['datering'] === null && !empty($item['_jaren'])) {
                $min = min($item['_jaren']);
                $max = max($item['_jaren']);
                $item['datering'] = $min === $max ? (string) $min : $min . '–' . $max;
            }
            unset($item['_jaren']);
        }
        unset($item);

        $results = array_values($filtered);
        $sliced_results = array_slice($results, $offset, $limit);

        return [
            'personen' => $sliced_results,
            'aantal' => count($results)
        ];
    }

    public function getFotos($q = null, $straatidentifier = null, $tijdvakidentifier = null, $limit = 10, $offset = 0): array
    {
        $fotos = $this->sparqlService->get_foto_index($q, $straatidentifier, $this->lijsten->get_tijdvak($tijdvakidentifier));

        $filtered = [];

        foreach ($fotos as $foto) {
            if (empty($foto['identifier']['value'])) {
                continue;
            }

            $id = $foto['identifier']['value'];
            if (!isset($filtered[$id])) {
                $filtered[$id] = [
                    'identifier' => $id,
                    'titel' => $foto['titel']['value'] ?? '',
                    'thumbnail' => $foto['thumbnail']['value'] ?? '',
                    'vervaardiger' => $foto['vervaardiger']['value'] ?? null,
                    'datering' => $foto['datering']['value'] ?? null,
                    'bronorganisatie' => (!empty($foto['url']['value']) && strstr($foto['url']['value'], 'samh.nl') !== false) ? 'SAMH' : 'RCE',
                    'straten' => [],
                    'pandidentifiers' => []
                ];
            }

            if (!empty($foto['straat']['value']) && !empty($foto['straatnaam']['value'])) {
                if (!isset($filtered[$id]['straten'][$foto['straat']['value']])) {
                    $filtered[$id]['straten'][$foto['straat']['value']] = [
                        'identifier' => $foto['straat']['value'],
                        'naam' => $foto['straatnaam']['value']
                    ];
                }
            }

            if (!empty($foto['locatiepunt']['value'])) {
                $filtered[$id]['pandidentifiers'][$foto['locatiepunt']['value']] = true;
            }
        }

        foreach ($filtered as &$item) {
            $item['straten'] = array_values($item['straten']);
            $item['pandidentifiers'] = array_keys($item['pandidentifiers']);
        }

        $results = array_values($filtered);
        $aantal = count($results);
        $results = array_slice($results, $offset, $limit);

        return [
            'aantal' => $aantal,
            'fotos' => $results
        ];
    }

    public function getPersoon($identifier): ?array
    {
        # Sinds 2026-07-11 een persoonsRECONSTRUCTIE: één rij per onderliggende
        # vermelding(-combinatie); canonieke velden uit de eerste rij, panden
        # en bronnen geaggregeerd over alle rijen.
        $rows = $this->sparqlService->get_persoon($identifier);

        if (empty($rows)) {
            return null;
        }
        $eerste = $rows[0];

        # per pand alle (unieke) bronnen verzamelen: een persoon kan meerdere
        # vermeldingen op hetzelfde pand hebben (bv. adresboek 1871 én
        # bevolkingsregister 1880) en die bronnen horen allemaal getoond
        $perPand = [];
        $leeftijd = null;
        foreach ($rows as $persoon) {
            $leeftijd = $leeftijd ?? ($persoon['hasAge']['value'] ?? null);

            $locatiepunt = $persoon['locatiepunt']['value'] ?? null;
            if (empty($locatiepunt)) {
                continue;
            }

            $datering = '';
            if (!empty($persoon['beginDate']['value'])) {
                $datering = $persoon['beginDate']['value'];
                if (!empty($persoon['endDate']['value'])) {
                    if ($datering != $persoon['endDate']['value']) {
                        $datering .= " – " . $persoon['endDate']['value'];
                    }
                } else {
                    $datering .= " – nu";
                }
            } else {
                $datering = "????";
                if (!empty($persoon['bronNaam']['value']) && preg_match('/\d{4}/', $persoon['bronNaam']['value'], $matches)) {
                    $datering = $matches[0];
                    $persoon['beginDate']['value'] = $datering;
                    $persoon['endDate']['value'] = $datering;
                }
            }

            $bron = [
                'naam' => $persoon['bronNaam']['value'] ?? null,
                'naam_kort' => $persoon['bronInventaris']['value'] ?? '',
                'datering' => $datering,
                'url' => $persoon['bronUrl']['value'] ?? null
            ];

            if (!isset($perPand[$locatiepunt])) {
                $perPand[$locatiepunt] = ['bronnen' => [], 'begin' => null, 'eind' => null];
            }

            # één bronregel per onderliggende vermelding: de SPARQL-rijen
            # bevatten per vermelding een cartesisch product van bronnaam-
            # varianten (o:label én schema:name) en bron-URL's (scan-ARK én
            # akte-URL) — kies deterministisch de beschrijvendste naam en
            # bij voorkeur de akte-URL
            $bronKey = $persoon['pv']['value'] ?? (($bron['naam'] ?? '') . '|' . ($bron['url'] ?? ''));
            $bestaand = $perPand[$locatiepunt]['bronnen'][$bronKey] ?? null;
            if ($bestaand === null) {
                $perPand[$locatiepunt]['bronnen'][$bronKey] = $bron;
            } else {
                if (strlen((string) $bron['naam']) > strlen((string) $bestaand['naam'])) {
                    $perPand[$locatiepunt]['bronnen'][$bronKey]['naam'] = $bron['naam'];
                }
                if (!str_contains((string) $bestaand['url'], '/genealogie/deeds/')
                        && str_contains((string) $bron['url'], '/genealogie/deeds/')) {
                    $perPand[$locatiepunt]['bronnen'][$bronKey]['url'] = $bron['url'];
                }
            }

            $begin = $persoon['beginDate']['value'] ?? null;
            $eind = $persoon['endDate']['value'] ?? $begin;
            if ($begin !== null) {
                $perPand[$locatiepunt]['begin'] = min($perPand[$locatiepunt]['begin'] ?? $begin, $begin);
                $perPand[$locatiepunt]['eind'] = max($perPand[$locatiepunt]['eind'] ?? $eind, $eind);
            }
        }

        $panden = [];
        foreach ($perPand as $locatiepunt => $info) {
            # tweede dedup: verschillende vermeldingen kunnen dezelfde bron
            # opleveren (zelfde akte/register) — identieke regels samenvoegen
            $uniek = [];
            foreach ($info['bronnen'] as $bron) {
                $uniek[($bron['naam'] ?? '') . '|' . ($bron['url'] ?? '') . '|' . $bron['datering']] = $bron;
            }
            $bronnen = array_values($uniek);
            usort($bronnen, fn ($a, $b) => strcmp($a['datering'], $b['datering']));

            // adres = "pandnaam", over de volledige vermeldingsperiode
            list($pandnaam, $straaturi) = $this->sparqlService->get_adres_jaar(
                $locatiepunt,
                $info['begin'],
                $info['eind']
            );

            # geen adres in die periode (bv. verponding 1772, adressen
            # beginnen later): val terug op de meest recente adresnaam
            if (empty($pandnaam)) {
                $recent = $this->sparqlService->get_adressen_locatiepunt($locatiepunt, 1);
                if (!empty($recent[0]['naam']['value'])) {
                    $adresNaam = preg_replace("/, wijk.*/", "", $recent[0]['naam']['value']);
                    $adresNaam = preg_replace("/ \([0-9]{4}.*$/", "", $adresNaam);
                    $pandnaam = "meest recent bekend als " . $adresNaam;
                }
            }

            $panden[$locatiepunt] = [
                'identifier' => $locatiepunt,
                'naam' => $pandnaam ?? null,
                'bron' => $bronnen
            ];
        }

        $persoonData = [
            'identifier' => $eerste['identifier']['value'] ?? $identifier,
            'naam' => $eerste['name']['value'] ?? '',
            'geboortedatum' => !empty($eerste['birthDate']['value']) ? $this->formatters->datumFormatter($eerste['birthDate']['value']) : null,
            'geboorteplaats' => $this->plaatsLabel($eerste['birthPlace']['value'] ?? null),
            'overlijdensdatum' => !empty($eerste['deathDate']['value']) ? $this->formatters->datumFormatter($eerste['deathDate']['value']) : null,
            'overlijdensplaats' => $this->plaatsLabel($eerste['deathPlace']['value'] ?? null),
            'leeftijd' => $leeftijd,
            'beroep' => !empty($eerste['hasOccupation']['value']) ? $this->formatters->beroepFormatter($eerste['hasOccupation']['value']) : null,
            'panden' => array_values($panden)
        ];

        return $persoonData;
    }

    # Plaats kan een label zijn óf een URI zonder label in de triplestore
    # (bv. https://gemeentegeschiedenis.nl/gemeentenaam/Schiedam): in dat
    # geval het laatste padsegment als leesbare naam gebruiken.
    private function plaatsLabel(?string $plaats): ?string
    {
        if (empty($plaats)) {
            return null;
        }
        if (str_starts_with($plaats, 'http://') || str_starts_with($plaats, 'https://')) {
            $segment = rawurldecode(basename((string) parse_url($plaats, PHP_URL_PATH)));

            return $segment !== '' ? $segment : $plaats;
        }

        return $plaats;
    }

    public function getPand($locatiepuntidentifier): ?array
    {
        $pand = $this->sparqlService->get_pand($locatiepuntidentifier);

        if (empty($pand)) {
            return null;
        }

        $pand = $pand[0];
        $fotos = [];

        foreach ($this->sparqlService->get_fotos_locatiepunt($locatiepuntidentifier) as $foto) {
            $fotos[] = [
                'identifier' => $foto['identifier']['value'] ?? '',
                'titel' => $foto['titel']['value'] ?? '',
                'thumbnail' => $foto['thumbnail']['value'] ?? '',
                'datering' => $foto['datering']['value'] ?? '',
                'type' => $foto['type']['value'] ?? 'foto',
            ];
        }

        # per persoonsreconstructie één regel; dateringen van de onderliggende
        # vermeldingen samenvoegen ("1897, 1898, 1900")
        $personen = [];
        foreach ($this->sparqlService->get_personen_locatiepunt($locatiepuntidentifier) as $persoon) {
            $id = $persoon['identifier']['value'] ?? '';
            if ($id === '') {
                continue;
            }
            $datering = $persoon['datering']['value'] ?? null;
            if (!isset($personen[$id])) {
                $personen[$id] = [
                    'identifier' => $id,
                    'naam' => $persoon['naam']['value'] ?? '',
                    'beroep' => !empty($persoon['beroep']['value']) ? $this->formatters->beroepFormatter($persoon['beroep']['value']) : null,
                    'datering' => $datering
                ];
                continue;
            }
            $beter = $this->beterBeroep($personen[$id]['beroep'], $persoon['beroep']['value'] ?? null);
            if ($beter !== $personen[$id]['beroep']) {
                $personen[$id]['beroep'] = $this->formatters->beroepFormatter($beter);
            }
            if ($datering !== null) {
                $bestaand = $personen[$id]['datering'];
                if ($bestaand === null) {
                    $personen[$id]['datering'] = $datering;
                } elseif (!in_array($datering, explode(', ', $bestaand), true)) {
                    $personen[$id]['datering'] = $bestaand . ', ' . $datering;
                }
            }
        }
        $personen = array_values($personen);

        # sorteer op het vroegste vermeldingsjaar (de SPARQL-orde is over de
        # UNION-takken heen onbetrouwbaar door gemengde datering-datatypes);
        # personen zonder datering achteraan, gelijke jaren op naam
        $vroegsteJaar = function (?string $datering): int {
            $jaren = array_filter(array_map(
                fn ($deel) => (int) substr(trim($deel), 0, 4),
                explode(',', (string) $datering)
            ));

            return $jaren ? min($jaren) : PHP_INT_MAX;
        };
        usort($personen, fn ($a, $b) =>
            [$vroegsteJaar($a['datering']), $a['naam']]
            <=> [$vroegsteJaar($b['datering']), $b['naam']]);

        $adressen = [];
        $sadressen = $this->sparqlService->get_adressen_locatiepunt($locatiepuntidentifier);

        usort($sadressen, fn ($a, $b) => ($a['startDate'] ?? 0) <=> ($b['startDate'] ?? 0));

        foreach ($sadressen as $adres) {
            $naam = $adres['naam']['value'] ?? '';
            $naam = preg_replace("/, wijk.*? \(/", "(", $naam);
            $naam = preg_replace("/\s*\([0-9]{4}-[0-9]{0,4}\)/", "", $naam);
            # datering zelf opbouwen, niet uit naam halen
            $datering = $adres['startDate']['value'] . ' – ' . $adres['endDate']['value'];
            # schoon de wijknaam op, geen Gouda, geen periode en begin met hoofdletter
            $wijknaam = str_replace("Gouda, ", "", $adres['wijknaam']['value'] ?? '');
            $wijknaam = preg_replace("/ \([0-9]{4}.*/", "", ucfirst($wijknaam));
            $adressen[] = [
                'type' => !empty($adres['type']['value']) ? $this->formatters->adrestypeFormatter($adres['type']['value']) : '',
                'naam' => $naam,
                'datering' => $datering,
                'wijk' => $wijknaam
            ];
        }

        $filtered = [
            'identifier' => $locatiepuntidentifier,
            'naam' => "Locatiepunt " . ($pand['naam']['value'] ?? ''),
            'datering' => "(nog niet geïmplementeerd)",
            'adressen' => $adressen,
            'personen' => $personen,
            'fotos' => $fotos
        ];

        # GeoJSON-punt van het locatiepunt; alleen opnemen als er echt een
        # POINT bekend is (afwijkende WKT-typen wegfilteren)
        if (!empty($pand['wkt']['value'])) {
            $geom = $this->geoPHP->load($pand['wkt']['value'], 'wkt');
            $decoded = $geom ? json_decode($geom->out('json')) : null;
            if (!empty($decoded->type) && $decoded->type === 'Point') {
                $filtered['geometrie'] = $decoded;
            }
        }

        return $filtered;
    }

    public function getFoto($identifier): ?array
    {
        $fotos = $this->sparqlService->get_foto($identifier);

        # Geen foto? Dan kan het een krantenknipsel (oa:Annotation) zijn.
        if (empty($fotos)) {
            return $this->getKrantenknipsel($identifier);
        }

        $fotos_dichtbij = [];
        foreach ($this->sparqlService->get_fotos_dichtbij($identifier) as $foto_dichtbij) {
            $fotos_dichtbij[] = [
                'identifier' => $foto_dichtbij['identifier']['value'] ?? '',
                'titel' => $foto_dichtbij['titel']['value'] ?? '',
                'thumbnail' => $foto_dichtbij['thumbnail']['value'] ?? '',
                'iiif_info_json' => $foto_dichtbij['iiif_info_json']['value'] ?? '',
            ];
        }

        $foto = $fotos[0];

        [$straten, $panden] = $this->extractStratenPanden($fotos);

        return [
            'identifier' => $foto['identifier']['value'] ?? '',
            'titel' => $foto['titel']['value'] ?? '',
            'thumbnail' => $foto['thumbnail']['value'] ?? '',
            'image' => !empty($foto['iiif_info_json']['value']) ? str_replace("info.json", "full/500,/0/default.jpg", $foto['iiif_info_json']['value']) : '',
            'iiif_info_json' => $foto['iiif_info_json']['value'] ?? '',
            'vervaardiger' => $foto['vervaardiger']['value'] ?? null,
            'informatie_auteursrechten' => !empty($foto['informatieAuteursRechten']['value']) ? str_replace("https://samh.nl/auteursrechten#", "", $foto['informatieAuteursRechten']['value']) : null,
            'url' => $foto['url']['value'] ?? null,
            'datering' => $foto['datering']['value'] ?? null,
            'bronbronorganisatie' => (!empty($foto['url']['value']) && strstr($foto['url']['value'], 'samh.nl') !== false) ? 'Streekarchief Midden-Holland' : 'Rijkdienst voor het Cultureel Erfgoed',
            'type' => 'foto',
            'straten' => $straten,
            'panden' => $panden,
            'fotos_dichtbij' => $fotos_dichtbij
        ];
    }

    private function getKrantenknipsel($identifier): ?array
    {
        $knipsels = $this->sparqlService->get_krantenknipsel($identifier);
        if (empty($knipsels)) {
            return null;
        }

        $knipsel = $knipsels[0];

        [$straten, $panden] = $this->extractStratenPanden($knipsels);

        return [
            'identifier' => $knipsel['identifier']['value'] ?? '',
            'titel' => $knipsel['titel']['value'] ?? '',
            'thumbnail' => $knipsel['thumbnail']['value'] ?? '',
            'image' => $knipsel['image']['value'] ?? '',
            'iiif_info_json' => $knipsel['iiif_info_json']['value'] ?? '',
            'vervaardiger' => null,
            'informatie_auteursrechten' => null,
            'url' => $knipsel['url']['value'] ?? null,
            'datering' => $knipsel['datering']['value'] ?? null,
            'bronbronorganisatie' => (!empty($knipsel['url']['value']) && strstr($knipsel['url']['value'], 'samh.nl') !== false) ? 'Streekarchief Midden-Holland' : 'Rijkdienst voor het Cultureel Erfgoed',
            'type' => 'krantenknipsel',
            'krant' => $knipsel['krant']['value'] ?? null,
            'pagina' => $knipsel['pagina']['value'] ?? null,
            'tekst' => $knipsel['tekst']['value'] ?? null,
            'region' => $knipsel['xywh']['value'] ?? null,
            'straten' => $straten,
            'panden' => $panden,
            'fotos_dichtbij' => []
        ];
    }

    # Bouw de unieke straten- en pandenlijst op uit de SPARQL-rijen (foto of knipsel).
    private function extractStratenPanden(array $rows): array
    {
        $straatMap = [];
        $pandMap = [];

        foreach ($rows as $row) {
            if (!empty($row['straat']['value']) && !empty($row['straatnaam']['value'])) {
                if (!isset($straatMap[$row['straat']['value']])) {
                    $straatMap[$row['straat']['value']] = [
                        'identifier' => $row['straat']['value'],
                        'naam' => $row['straatnaam']['value']
                    ];
                }
            }

            if (!empty($row['locatiepunt']['value']) && !isset($pandMap[$row['locatiepunt']['value']])) {
                $pandnaam = 'Pand';
                $arrAdressen = $this->sparqlService->get_adressen_locatiepunt($row['locatiepunt']['value'], 1);

                if (!empty($arrAdressen)) {
                    $locatienaam = $arrAdressen[0]["locatienaam"]["value"] ?? '';
                    $adresNaam = $arrAdressen[0]["naam"]["value"] ?? '';
                    if ($locatienaam && $adresNaam) {
                        $adresNaam = preg_replace("/, wijk.*/", "", $adresNaam);
                        $adresNaam = preg_replace("/ \([0-9]{4}.*$/", "", $adresNaam);
                        #$pandnaam = "Locatiepunt {$locatienaam}, recent bekend als {$adresNaam}";
                        $pandnaam = "Pand meest recent bekend als {$adresNaam}";
                    }
                }

                $pandMap[$row['locatiepunt']['value']] = [
                    'identifier' => $row['locatiepunt']['value'],
                    'naam' => $pandnaam,
                    'straten' => []   # TODO nog niet geïmplementeerd, echt nodig?
                ];
            }
        }

        return [array_values($straatMap), array_values($pandMap)];
    }
}
