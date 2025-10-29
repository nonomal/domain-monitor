<?php

namespace Core;

class Router
{
    protected array $routes = [];

    public function get(string $path, $callback)
    {
        $this->routes['GET'][$path] = $callback;
    }

    public function post(string $path, $callback)
    {
        $this->routes['POST'][$path] = $callback;
    }

    public function resolve()
    {
        $path = $_SERVER['REQUEST_URI'] ?? '/';
        $method = $_SERVER['REQUEST_METHOD'];

        // Remove query string
        $position = strpos($path, '?');
        if ($position !== false) {
            $path = substr($path, 0, $position);
        }

        // Try exact match first
        $callback = $this->routes[$method][$path] ?? null;
        $params = [];

        // If no exact match, try pattern matching for dynamic segments
        if ($callback === null) {
            foreach ($this->routes[$method] ?? [] as $route => $handler) {
                // Convert route pattern to regex
                $pattern = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '([^/]+)', $route);
                $pattern = '#^' . $pattern . '$#';
                
                if (preg_match($pattern, $path, $matches)) {
                    $callback = $handler;
                    
                    // Extract parameter names from route
                    preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $route, $paramNames);
                    
                    // Map parameter names to values
                    array_shift($matches); // Remove full match
                    foreach ($paramNames[1] as $index => $name) {
                        $params[$name] = $matches[$index] ?? null;
                    }
                    break;
                }
            }
        }

        if ($callback === null) {
            http_response_code(404);
            // Render 404 via Twig
            $twig = \App\Services\TemplateService::get();
            echo $twig->render('errors/404.twig', [
                'title' => 'Page Not Found'
            ]);
            return;
        }

        if (is_array($callback)) {
            $controller = new $callback[0]();
            $callback[0] = $controller;
        }

        // Pass params to the callback
        if (!empty($params)) {
            call_user_func($callback, $params);
        } else {
            call_user_func($callback);
        }
    }
}

