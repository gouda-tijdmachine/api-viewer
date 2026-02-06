<?php
// Serve static files from the assets directory

// DEBUG - log what we receive
error_log('static.php called with REQUEST_URI: ' . ($_SERVER['REQUEST_URI'] ?? 'undefined'));
error_log('static.php GET params: ' . json_encode($_GET));

// Get the requested file from query parameter (passed by Vercel routing)
$requestedFile = $_GET['file'] ?? '';

// Map paths to actual files
if ($requestedFile === 'favicon.ico' || $requestedFile === '/favicon.ico') {
    $filePath = __DIR__ . '/../assets/favicon.ico';
} elseif (!empty($requestedFile)) {
    // Remove leading slash if present
    $requestedFile = ltrim($requestedFile, '/');
    $filePath = __DIR__ . '/../assets/' . $requestedFile;
} else {
    // DEBUG output if no file specified
    header('Content-Type: text/plain');
    echo "DEBUG: No file specified\n";
    echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'undefined') . "\n";
    echo "GET params: " . json_encode($_GET) . "\n";
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
