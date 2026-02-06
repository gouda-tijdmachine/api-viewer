<?php
// Serve static files from the assets directory

$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

// Map paths to actual files
if ($path === '/favicon.ico') {
    $filePath = __DIR__ . '/../assets/favicon.ico';
} elseif (preg_match('#^/assets/(.+)$#', $path, $matches)) {
    $filePath = __DIR__ . '/../assets/' . $matches[1];
} else {
    http_response_code(404);
    exit;
}

// Security: Resolve real path and check it's within assets directory
$realPath = realpath($filePath);
$assetsDir = realpath(__DIR__ . '/../assets');

if (!$realPath || !$assetsDir || strpos($realPath, $assetsDir) !== 0) {
    http_response_code(404);
    exit;
}

if (!file_exists($realPath) || !is_file($realPath)) {
    http_response_code(404);
    exit;
}

// Determine MIME type
$extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
$mimeTypes = [
    'css' => 'text/css',
    'js' => 'application/javascript',
    'json' => 'application/json',
    'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
    'ttf' => 'font/ttf',
    'eot' => 'application/vnd.ms-fontobject',
];

$mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';

// Send headers and file content
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($realPath));
header('Cache-Control: public, max-age=31536000, immutable');
header('Access-Control-Allow-Origin: *');

readfile($realPath);
exit;
