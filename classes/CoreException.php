<?php

class CoreException extends Exception
{
    private $data;

    public function __construct($message, $code, array $data = null)
    {
        if (is_array($message)) {
            $message = implode(' ', $message);
        }
        $message = (string)($message ?? '');
        $code = (int)$code;

        parent::__construct($message, $code);
        $status = 'exception';
        $this->data = $data;
        $response = compact('message', 'data');
        $error = System::exceptionContext($this);
        System::log_error(compact('status', 'code', 'response', 'error'));
    }

    function getData($value = null)
    {
        if ($value && $this->data && $this->data[$value]) {
            return $this->data[$value];
        }
        return $this->data;
    }
}
