<?php

class CoreException extends Exception
{
    public $data;

    public function __construct($message = "", $code = 0, array $data = null)
    {
        $status = 'exception';
        $error = $this->getTrace();
        $this->data = $data;
        $response = compact('message', 'data');
        System::log_error(compact('status', 'code', 'response', 'error'));
        parent::__construct($message, $code);
    }
}
