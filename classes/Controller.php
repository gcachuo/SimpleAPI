<?php

class Controller
{
    private $_methods;
    private static $_response;

    public function getMethods()
    {
        return $this->_methods;
    }

    public function __construct($methods)
    {
        $this->_methods = $methods;
        $this->allowed_methods($methods);
    }

    public function webhook()
    {
        $this->_methods[REQUEST_METHOD]['webhook'];
    }

    public function call(string $action, array $arguments)
    {
        $name = System::isset_get($this->_methods[REQUEST_METHOD][$action]);
        if ($name) {
            return $this->$name(...($arguments ?: [null]));
        } else {
            $name = $action;
        }
        JsonResponse::sendResponse("Endpoint not found. [$name]", HTTPStatusCodes::NotFound);
    }

    public function method_exists(Controller $class, $action)
    {
        return method_exists($class, $this->_methods[REQUEST_METHOD][$action]);
    }

    private function allowed_methods(array $methods)
    {
        if (ENVIRONMENT == 'web' && ENDPOINT !== 'api/endpoints') {
            if (!isset($methods[REQUEST_METHOD])) {
                JsonResponse::sendResponse('Method Not Allowed', HTTPStatusCodes::MethodNotAllowed);
            }
        }
    }
}
