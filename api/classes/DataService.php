<?php

require_once 'geoPHP.php';
require_once 'SparqlService.php';
require_once 'Formatters.php';
require_once 'Lijsten.php';

class DataService {

    private SparqlService $sparqlService;
    private CacheService $cache;
    
    private $formatters;
    private $geoPHP;
    public $lijsten;

    public function __construct() {
        $this->sparqlService = new SparqlService();
        $this->formatters = new Formatters();
        $this->geoPHP = new GeoPHP();
        $this->lijsten = new Lijsten();
        $this->cache = new CacheService();
    }

    public function getStraten(): array {
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

    public function getTijdvakken(): array {
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

    public function getJaarPanden($jaar) {
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

    private function _getJaarpanden($jaar): array {
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

    public function getPanden($q = null, $straatidentifier = null, $tijdvakidentifier = null, $status = 'alle', $limit = 10, $offset = 0): array {
        #error_log("getPanden called with q=$q, straatidentifier=$straatidentifier, tijdvakidentifier=$tijdvakidentifier, status=$status, limit=$limit, offset=$offset");
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

    public function getPersonen($q = null, $straatidentifier = null, $tijdvakidentifier = null, $limit = 10, $offset = 0): array {
        $personen = $this->sparqlService->get_personen_index($q, $straatidentifier, $this->lijsten->get_tijdvak($tijdvakidentifier));

        $filtered = [];

        foreach ($personen as $persoon) {
            if (empty($persoon['identifier']['value'])) {
                continue;
            }

            $id = $persoon['identifier']['value'];
            if (!isset($filtered[$id])) {
                $pandidentifiers = [];
                if (!empty($persoon['locatiepunt']['value'])) {
                    $pandidentifiers[] = $persoon['locatiepunt']['value'];
                }

                $filtered[$id] = [
                    'identifier' => $id,
                    'naam' => $persoon['naam']['value'] ?? '',
                    'beroep' => !empty($persoon['beroep']['value']) ? $this->formatters->beroepFormatter($persoon['beroep']['value']) : null,
                    'datering' => $persoon['datering']['value'] ?? null,
                    'pandidentifiers' => $pandidentifiers
                ];
            }
        }

        $results = array_values($filtered);
        $sliced_results = array_slice($results, $offset, $limit);
        return [
            'personen' => $sliced_results,
            'aantal' => count($results)
        ];
    }

    public function getFotos($q = null, $straatidentifier = null, $tijdvakidentifier = null, $limit = 10, $offset = 0): array {
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

    public function getPersoon($identifier): ?array {
        $persoon = $this->sparqlService->get_persoon($identifier);

        if (empty($persoon)) {
            return null;
        }
        $persoon = $persoon[0];

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
            $datering="????";
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

        // adres = "pandnaam"
        list($pandnaam, $straaturi) = $this->sparqlService->get_adres_jaar(
            $persoon['locatiepunt']['value'] ?? null,
            $persoon['beginDate']['value'] ?? null,
            $persoon['endDate']['value'] ?? null
        );

        $pand = [
            'identifier' => $persoon['locatiepunt']['value'] ?? null,
            'naam' => $pandnaam ?? null,
            'bron' => $bron
        ];

        $persoonData = [
            'identifier' => $identifier,
            'naam' => $persoon['name']['value'] ?? '',
            'geboortedatum' => !empty($persoon['birthDate']['value']) ? $this->formatters->datumFormatter($persoon['birthDate']['value']) : null,
            'geboorteplaats' => $persoon['birthPlace']['value'] ?? null,
            'overlijdensdatum' => !empty($persoon['deathDate']['value']) ? $this->formatters->datumFormatter($persoon['deathDate']['value']) : null,
            'overlijdensplaats' => $persoon['deathPlace']['value'] ?? null,
            'leeftijd' => $persoon['hasAge']['value'] ?? null,
            'beroep' => !empty($persoon['hasOccupation']['value']) ? $this->formatters->beroepFormatter($persoon['hasOccupation']['value']) : null,
            'panden' => [$pand]
        ];

        return $persoonData;
    }

    public function getPand($locatiepuntidentifier): ?array {
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
            ];
        }

        $personen = [];
        foreach ($this->sparqlService->get_personen_locatiepunt($locatiepuntidentifier) as $persoon) {
            $personen[] = [
                'identifier' => $persoon['identifier']['value'] ?? '',
                'naam' => $persoon['naam']['value'] ?? '',
                'beroep' => !empty($persoon['beroep']['value']) ? $this->formatters->beroepFormatter($persoon['beroep']['value']) : null,
                'datering' => $persoon['datering']['value'] ?? null
            ];
        }

        $adressen = [];

        $sadressen = $this->sparqlService->get_adressen_locatiepunt($locatiepuntidentifier);
        #error_log(print_r($sadressen,1));
        usort($sadressen, fn($a, $b) => ($a['startDate'] ?? 0) <=> ($b['startDate'] ?? 0));

        foreach ($sadressen as $adres) {
            $naam = $adres['naam']['value'] ?? '';
            $naam = preg_replace("/, wijk.*? \(/", "(", $naam);
            $naam = preg_replace("/\s*\([0-9]{4}-[0-9]{0,4}\)/", "", $naam);
            # datering zelf opbouwen, niet uit naam halen
            $datering = $adres['startDate']['value'] . ' – ' . $adres['endDate']['value'];
            # schoon de wijknaam op, geen Gouda, geen periode en begin met hoofdletter
            $wijknaam = str_replace("Gouda, ", "", $adres['wijknaam']['value'] ?? '');
            $wijknaam = preg_replace("/ \([0-9]{4}.*/","",ucfirst($wijknaam));
            $adressen[] = [
                'type' => !empty($adres['type']['value']) ? $this->formatters->adrestypeFormatter($adres['type']['value']) : '',
                'naam' => $naam,
                'datering' => $datering,
                'wijk' => $wijknaam
            ];
        }

        $filtered = [
            'identifier' => $locatiepuntidentifier,
            'naam' => "Locatiepjunt " . ($pand['naam']['value'] ?? ''),
            'datering' => "(nog niet geïmplementeerd)",
            'adressen' => $adressen,
            'personen' => $personen,
            'fotos' => $fotos
        ];

        return $filtered;
    }

    public function getFoto($identifier): ?array {
        $fotos_dichtbij = [];

        foreach ($this->sparqlService->get_fotos_dichtbij($identifier) as $foto_dichtbij) {
            $fotos_dichtbij[] = [
                'identifier' => $foto_dichtbij['identifier']['value'] ?? '',
                'titel' => $foto_dichtbij['titel']['value'] ?? '',
                'thumbnail' => $foto_dichtbij['thumbnail']['value'] ?? '',
                'iiif_info_json' => $foto_dichtbij['iiif_info_json']['value'] ?? '',
            ];
        }

        $fotos = $this->sparqlService->get_foto($identifier);
        if (empty($fotos)) {
            return null;
        }

        $foto = $fotos[0];

        $filtered = [
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
            'straten' => [],
            'panden' => [],
            'fotos_dichtbij' => $fotos_dichtbij
        ];

        $straatMap = [];
        $pandMap = [];

        foreach ($fotos as $foto) {
            if (!empty($foto['straat']['value']) && !empty($foto['straatnaam']['value'])) {
                if (!isset($straatMap[$foto['straat']['value']])) {
                    $straatMap[$foto['straat']['value']] = [
                        'identifier' => $foto['straat']['value'],
                        'naam' => $foto['straatnaam']['value']
                    ];
                }
            }

            if (!empty($foto['locatiepunt']['value']) && !isset($pandMap[$foto['locatiepunt']['value']])) {
                $pandnaam = 'Pand';
                $arrAdressen = $this->sparqlService->get_adressen_locatiepunt($foto['locatiepunt']['value'], 1);

                if (!empty($arrAdressen)) {
                    $locatienaam = $arrAdressen[0]["locatienaam"]["value"] ?? '';
                    $adresNaam = $arrAdressen[0]["naam"]["value"] ?? '';
                    if ($locatienaam && $adresNaam) {
                        $adresNaam = preg_replace("/, wijk.*/", "", $adresNaam);
                        $adresNaam = preg_replace("/ \([0-9]{4}.*$/","", $adresNaam);
                        #$pandnaam = "Locatiepunt {$locatienaam}, recent bekend als {$adresNaam}";
                        $pandnaam = "Pand meest recent bekend als {$adresNaam}";
                    }
                }

                $pandMap[$foto['locatiepunt']['value']] = [
                    'identifier' => $foto['locatiepunt']['value'],
                    'naam' => $pandnaam,
                    'straten' => []   # TODO nog niet geïmplementeerd, echt nodig?
                ];
            }
        }

        $filtered['straten'] = array_values($straatMap);
        $filtered['panden'] = array_values($pandMap);

        return $filtered;
    }
}