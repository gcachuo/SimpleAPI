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

    /**
     * @param string $action
     * @param array $arguments
     * @return mixed
     * @throws CoreException
     */
    public function call(string $action, array $arguments)
    {
        if ($this->method_exists($action)) {
            $function = $this->_methods[REQUEST_METHOD][$action];
            return $this->$function(...($arguments ?: [null]));
        }

        $endpoint = ENDPOINT;
        $request_method = REQUEST_METHOD;
        $class = get_class($this);

        throw new CoreException("Endpoint not found. [$request_method][$endpoint]", HTTPStatusCodes::NotFound, compact('class', 'function', 'endpoint', 'request_method'));
    }

    public function method_exists($action)
    {
        return method_exists($this, $this->_methods[REQUEST_METHOD][$action]);
    }

    private function allowed_methods(array $methods)
    {
        if (ENVIRONMENT == 'web' && ENDPOINT !== 'api/endpoints') {
            if (!isset($methods[REQUEST_METHOD])) {
                throw new CoreException('Method Not Allowed', HTTPStatusCodes::MethodNotAllowed);
            }
        }
    }
}
