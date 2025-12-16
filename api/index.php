<?php

include 'config.php';

require_once 'classes/Router.php';
require_once 'classes/ApiHandler.php';
require_once 'classes/ResponseHelper.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$router = new Router();
$apiHandler = new ApiHandler();

$router->get('/api-viewer/fotos', [$apiHandler, 'getFotos']);
$router->get('/api-viewer/foto/{identifier}', [$apiHandler, 'getFoto']);
$router->get('/api-viewer/panden', [$apiHandler, 'getPanden']);
$router->get('/api-viewer/pandgeometrieen/{jaar}', [$apiHandler, 'getJaarPanden']);
$router->get('/api-viewer/pand/{identifier}', [$apiHandler, 'getPand']);
$router->get('/api-viewer/personen', [$apiHandler, 'getPersonen']);
$router->get('/api-viewer/persoon/{identifier}', [$apiHandler, 'getPersoon']);
$router->get('/api-viewer/straten', [$apiHandler, 'getStraten']);
$router->get('/api-viewer/tijdvakken', [$apiHandler, 'getTijdvakken']);
$router->post('/api-viewer/clear_cache', [$apiHandler, 'clearCache']);

try {
    $router->dispatch();
} catch (Exception $e) {
    ResponseHelper::error($e->getMessage(), 500);
}
