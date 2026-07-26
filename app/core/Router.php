<?php
class Router {
    private $routes = array();
    public function get($route, $action) {
        $this->routes['GET'][trim($route, '/')] = $action;
    }
    public function post($route, $action) {
        $this->routes['POST'][trim($route, '/')] = $action;
    }
    public function dispatch() {
        $method = $_SERVER['REQUEST_METHOD'];
        $url = isset($_GET['url']) ? trim($_GET['url'], '/') : '';
        if (!isset($this->routes[$method])) {
            $this->notFound();
            return;
        }
        foreach ($this->routes[$method] as $route => $action) {
            $params = $this->matchRoute($route, $url);
            if ($params !== false) {
                list($controllerName, $actionName) = explode('@', $action);
                if (!class_exists($controllerName)) {
                    $this->notFound();
                    return;
                }
                $controller = new $controllerName();
                if (method_exists($controller, $actionName)) {
                    call_user_func_array([$controller, $actionName], $params);
                    return;
                }
            }
        }
        $this->notFound();
    }
    private function matchRoute($route, $url) {
        $pattern = preg_replace('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', '([^/]+)', $route);
        $pattern = '#^' . $pattern . '$#';
        if (preg_match($pattern, $url, $matches)) {
            array_shift($matches); 
            return $matches;
        }
        return false;
    }
    private function notFound() {
        http_response_code(404);
        echo '404 - Not Found';
    }
}