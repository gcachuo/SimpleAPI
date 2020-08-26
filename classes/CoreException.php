<?php

class CoreException extends Exception
{
    public $data;

    public function __construct($message = "", $code = 0, array $data = null)
    {
        $this->data = $data;
        parent::__construct($message, $code);
    }
}
