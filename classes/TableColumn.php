<?php

namespace Model;

class TableColumn
{
    public $name;
    public $type;
    public $type_size = 0;
    public $auto_increment = false;
    public $primary_key = false;
    public $not_null = false;
    public $default = null;

    /**
     * TableColumn constructor.
     * @param string $name
     * @param string $type
     * @param int $type_size
     * @param bool $not_null
     * @param int|string|null $default
     * @param bool $auto_increment
     * @param bool $primary_key
     */
    public function __construct($name, $type, $type_size = 0, $not_null = false, $default = null, $auto_increment = false, $primary_key = false)
    {
        $this->name = $name;
        $this->type = $type;
        $this->type_size = $type_size;
        $this->auto_increment = $auto_increment;
        $this->primary_key = $primary_key;
        $this->not_null = $not_null;
        $this->default = $default;
    }
}
