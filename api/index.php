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

$router->get('/fotos', [$apiHandler, 'getFotos']);
$router->get('/foto/{identifier}', [$apiHandler, 'getFoto']);
$router->get('/panden', [$apiHandler, 'getPanden']);
$router->get('/pandgeometrieen/{jaar}', [$apiHandler, 'getJaarPanden']);
$router->get('/pand/{identifier}', [$apiHandler, 'getPand']);
$router->get('/personen', [$apiHandler, 'getPersonen']);
$router->get('/persoon/{identifier}', [$apiHandler, 'getPersoon']);
$router->get('/straten', [$apiHandler, 'getStraten']);
$router->get('/tijdvakken', [$apiHandler, 'getTijdvakken']);
$router->post('/clear_cache', [$apiHandler, 'clearCache']);

try {
    $router->dispatch();
} catch (Exception $e) {
    ResponseHelper::error($e->getMessage(), 500);
}
