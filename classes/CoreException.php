<?php

class CoreException extends Exception
{
    private $data;

    public function __construct($message, $code, array $data = null)
    {
        $status = 'exception';
        $error = $this->getTrace();
        $this->data = $data;
        $response = compact('message', 'data');
        System::log_error(compact('status', 'code', 'response', 'error'));
        parent::__construct($message, $code);
    }

    function getData($value = null)
    {
        if ($value && $this->data && $this->data[$value]) {
            return $this->data[$value];
        }
        return $this->data;
    }
}
