<?php

class Router
{
    private $routes = [];

    public function get($path, $handler)
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post($path, $handler)
    {
        $this->routes['POST'][$path] = $handler;
    }

    #    public function put($path, $handler)
    #    {
    #        $this->routes['PUT'][$path] = $handler;
    #    }

    #    public function delete($path, $handler)
    #    {
    #        $this->routes['DELETE'][$path] = $handler;
    #    }

    public function dispatch()
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'];
        $requestUri = $_SERVER['REQUEST_URI'];

        $parsedUri = parse_url($requestUri);
        $path = $parsedUri['path'];

        $basePath = '/viewer-api';
        if (strpos($path, $basePath) === 0) {
            $path = substr($path, strlen($basePath));
        }

        if (empty($path)) {
            $path = '/';
        }

        if (!isset($this->routes[$requestMethod])) {
            http_response_code(405);
            echo json_encode(['error' => 'Method Not Allowed']);
            return;
        }

        $routeFound = false;
        $params = [];

        foreach ($this->routes[$requestMethod] as $routePath => $handler) {
            $pattern = $this->convertToRegex($routePath);
            if (preg_match($pattern, $path, $matches)) {
                $routeFound = true;

                preg_match_all('/\{([^}]+)\}/', $routePath, $paramNames);
                for ($i = 1; $i < count($matches); $i++) {
                    $paramName = $paramNames[1][$i - 1];
                    $params[$paramName] = urldecode($matches[$i]);
                }

                if (is_array($handler)) {
                    call_user_func($handler, $params);
                } else {
                    $handler($params);
                }
                break;
            }
        }

        #error_log("Route found: " . ($routeFound ? 'yes' : 'no') . " for path: $path");

        if (!$routeFound) {
            $this->serveDocs();
        }
    }

    private function convertToRegex($path)
    {
        $pattern = preg_replace('/\{[^}]+\}/', '([^/]+)', $path);
        $pattern = str_replace('/', '\/', $pattern);
        return '/^' . $pattern . '$/';
    }

    private function serveDocs()
    {
        $docsPath = __DIR__ . '/../docs.html';
        if (file_exists($docsPath)) {
            header('Content-Type: text/html; charset=utf-8');
            readfile($docsPath);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Route not found']);
        }
    }
}
