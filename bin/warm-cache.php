<?php

declare(strict_types=1);

/**
 * Cache-warmer voor de api-viewer.
 *
 * Leegt de Redis-cache en vult hem opnieuw via de DataService (zelfde
 * cache-sleutels als de HTTP-API): de lijsten, de default-zoekresultaten
 * (de "tien interessante" panden/personen/foto's), alle detailpagina's
 * van die zoekresultaten en de pandgeometrieën per jaar.
 * Vrije-tekst-zoekopdrachten (q=...) zijn niet voor te verwarmen.
 *
 * Gebruik:  php8.5 bin/warm-cache.php [opties]
 *   --zonder-wissen        bestaande cache laten staan (alleen bijvullen)
 *   --zonder-geometrieen   pandgeometrieën per jaar overslaan
 *   --jaren=1880-1900      geometrie-jaren beperken (default: tijdvakbereik)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("alleen via de command line\n");
}

# vóór config.php: geen sparql.log laten groeien tijdens het warmen
# (config's eigen define geeft dan een 'already defined'-warning, die
# onderdrukken we hier bewust)
error_reporting(E_ALL & ~E_WARNING);
define('SPARQL_LOG', 0);

chdir(__DIR__ . '/../api');
require_once 'config.php';
error_reporting(E_ALL & ~E_WARNING);
require_once 'classes/CacheService.php';
require_once 'classes/DataService.php';

$opties = getopt('', ['zonder-wissen', 'zonder-geometrieen', 'jaren:']);
$start = microtime(true);
$fouten = 0;

function melding(string $tekst): void
{
    printf("[%s] %s\n", date('H:i:s'), $tekst);
}

$dataService = new DataService();
$cache = new CacheService();

# --- 1. cache legen ---------------------------------------------------------
if (isset($opties['zonder-wissen'])) {
    melding('cache wissen overgeslagen (--zonder-wissen)');
} else {
    $gewist = $cache->clear_cache();
    melding("cache geleegd: $gewist sleutels gewist");
}

# --- 2. lijsten -------------------------------------------------------------
$straten = $dataService->getStraten();
$tijdvakken = $dataService->getTijdvakken();
melding(sprintf('lijsten: %d straten, %d tijdvakken', count($straten), count($tijdvakken)));

# --- 3. zoekfuncties (default: de "tien interessante" per soort) -----------
const LIMIT = 10;

$identifiers = ['pand' => [], 'persoon' => [], 'foto' => []];

function oogst(array $resultaat, array &$identifiers): void
{
    foreach ($resultaat['panden'] ?? [] as $item) {
        $identifiers['pand'][$item['identifier']] = true;
    }
    foreach ($resultaat['personen'] ?? [] as $item) {
        $identifiers['persoon'][$item['identifier']] = true;
        foreach ($item['pandidentifiers'] ?? [] as $pid) {
            $identifiers['pand'][$pid] = true;
        }
    }
    foreach ($resultaat['fotos'] ?? [] as $item) {
        $identifiers['foto'][$item['identifier']] = true;
        foreach ($item['pandidentifiers'] ?? [] as $pid) {
            $identifiers['pand'][$pid] = true;
        }
    }
}

melding('zoekfuncties: default (top-tien per soort)');
foreach (['getPanden', 'getPersonen', 'getFotos'] as $methode) {
    try {
        if ($methode === 'getPanden') {
            $resultaat = $dataService->getPanden(null, null, null, 'alle', LIMIT, 0);
        } else {
            $resultaat = $dataService->$methode(null, null, null, LIMIT, 0);
        }
        oogst($resultaat, $identifiers);
    } catch (Throwable $e) {
        $fouten++;
        error_log("warm-cache $methode: " . $e->getMessage());
    }
}

melding(sprintf('identifier-oogst: %d panden, %d personen, %d foto\'s',
    count($identifiers['pand']), count($identifiers['persoon']), count($identifiers['foto'])));

# --- 4. detailpagina's ------------------------------------------------------
$detailMethodes = ['pand' => 'getPand', 'persoon' => 'getPersoon', 'foto' => 'getFoto'];
foreach ($detailMethodes as $soort => $methode) {
    $n = 0;
    foreach (array_keys($identifiers[$soort]) as $identifier) {
        try {
            $dataService->$methode($identifier);
        } catch (Throwable $e) {
            $fouten++;
            error_log("warm-cache $methode($identifier): " . $e->getMessage());
        }
        if (++$n % 250 === 0) {
            melding("  ... $n {$soort}en gedaan");
        }
    }
    melding("details: $n {$soort}(en) gewarmd");
}

# --- 5. pandgeometrieën per jaar --------------------------------------------
if (isset($opties['zonder-geometrieen'])) {
    melding('pandgeometrieën overgeslagen (--zonder-geometrieen)');
} else {
    $jaarVan = min(array_map(fn ($t) => (int) $t['jaar_start'], $tijdvakken));
    $jaarTot = max(array_map(fn ($t) => (int) $t['jaar_einde'], $tijdvakken));
    if (!empty($opties['jaren']) && preg_match('/^(\d{4})-(\d{4})$/', $opties['jaren'], $m)) {
        [$jaarVan, $jaarTot] = [(int) $m[1], (int) $m[2]];
    }
    melding("pandgeometrieën: $jaarVan t/m $jaarTot");
    for ($jaar = $jaarVan; $jaar <= $jaarTot; $jaar++) {
        try {
            $dataService->getJaarPanden($jaar);
        } catch (Throwable $e) {
            $fouten++;
            error_log("warm-cache getJaarPanden($jaar): " . $e->getMessage());
        }
        if ($jaar % 100 === 0) {
            melding("  ... t/m $jaar");
        }
    }
}

# --- samenvatting -----------------------------------------------------------
melding(sprintf('klaar in %.0f s, %d fout(en)', microtime(true) - $start, $fouten));
exit($fouten > 0 ? 1 : 0);
