<?php
class Router {
    private $routes = array();

    public function get($route, $action){
        $this->routes['GET'][trim($route, '/')] = $action;
    }
    public function post($route, $action){
        $this->routes['POST'][trim($route, '/')] = $action;
    }
    public function dispatch(){
        $method = $_SERVER['REQUEST_METHOD'];
        $url = isset($_GET['url']) ? trim($_GET['url'], '/') : '';
        if (isset($this->routes[$method][$url])) {
            list($controllerName, $action) = explode('@', $this->routes[$method][$url]);
            $controller = new $controllerName();
            if (method_exists($controller, $action)) {
                $controller->$action();
                return;
            }
        }
        http_response_code(404);
        echo '404 - Not Found';
    }
}