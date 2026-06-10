<?php
class Router {
    private $routes = [];
    public function get($route, $controllerAction) {
        $route = trim($route, '/');
        $this->routes[$route] = $controllerAction;
    }
    public function dispatch() {
        $url = isset($_GET['url']) ? trim($_GET['url'], '/') : '';
        if (array_key_exists($url, $this->routes)) {
            $action = $this->routes[$url];
            list($controllerName, $method) = explode('@', $action);
            if (file_exists(__DIR__ . '/../controllers/' . $controllerName . '.php')) {
                require_once __DIR__ . '/../controllers/' . $controllerName . '.php';
                $controller = new $controllerName();
                if (method_exists($controller, $method)) {
                    $controller->$method();
                    return;
                }
            }
        }
        http_response_code(404);
        echo "404 - Not Found";
    }
}