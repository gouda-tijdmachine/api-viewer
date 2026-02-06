<?php

// Serve static files FIRST, before any other processing
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH);

if (preg_match('#^/assets/(.+)$#', $path, $matches) || $path === '/favicon.ico') {
    $file = ($path === '/favicon.ico') ? 'favicon.ico' : $matches[1];
    $filePath = __DIR__ . '/../assets/' . $file;

    // Security check
    $realPath = realpath($filePath);
    $assetsDir = realpath(__DIR__ . '/../assets');

    if ($realPath && $assetsDir && strpos($realPath, $assetsDir) === 0 && is_file($realPath)) {
        $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        $mimes = [
            'css' => 'text/css', 'js' => 'application/javascript',
            'json' => 'application/json', 'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon', 'png' => 'image/png',
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'woff' => 'font/woff', 'woff2' => 'font/woff2'
        ];

        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=31536000');
        header('Access-Control-Allow-Origin: *');
        readfile($realPath);
        exit;
    }
}

include 'config.php';

require_once 'classes/Router.php';
require_once 'classes/ApiHandler.php';
require_once 'classes/ResponseHelper.php';
require_once 'classes/CacheService.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
	# print_r($_ENV);
    exit;
}

$router = new Router();
$apiHandler = new ApiHandler();

$router->get('/fotos', [$apiHandler, 'getFotos']);
$router->get('/foto/{identifier}', [$apiHandler, 'getFoto']);
$router->get('/foto', [$apiHandler, 'getFoto']);
$router->get('/panden', [$apiHandler, 'getPanden']);
$router->get('/pandgeometrieen/{jaar}', [$apiHandler, 'getJaarPanden']);
$router->get('/pand/{identifier}', [$apiHandler, 'getPand']);
$router->get('/pand', [$apiHandler, 'getPand']);
$router->get('/personen', [$apiHandler, 'getPersonen']);
$router->get('/persoon/{identifier}', [$apiHandler, 'getPersoon']);
$router->get('/persoon', [$apiHandler, 'getPersoon']);
$router->get('/straten', [$apiHandler, 'getStraten']);
$router->get('/tijdvakken', [$apiHandler, 'getTijdvakken']);
$router->post('/clear_cache', [$apiHandler, 'clearCache']);

try {
    $router->dispatch();
} catch (Exception $e) {
    ResponseHelper::error($e->getMessage(), 500);
}
