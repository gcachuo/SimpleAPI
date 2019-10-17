<?php

namespace Model;

use HTTPStatusCodes;
use JsonResponse;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use mysqli_stmt;
use System;

class MySQL
{
    /**
     * @var mysqli $mysqli
     */
    private $mysqli, $dbname;

    public function __construct($dbname = null)
    {
        mysqli_report(MYSQLI_REPORT_ALL ^ MYSQLI_REPORT_INDEX);
        try {
            $filename = DIR . '/Config/database.json';
            if (file_exists($filename)) {
                $contents = file_get_contents($filename);
                $config = json_decode($contents, true);

                $host = $config['host'];
                $username = $config['username'];
                $passwd = $config['passwd'];
                $dbname = $dbname ?: $config['dbname'];

                $this->mysqli = new mysqli($host, $username, $passwd, $dbname);
                $this->dbname = $dbname;
            } else {
                JsonResponse::sendResponse(['message' => "File $filename not found."], HTTPStatusCodes::InternalServerError);
            }
        } catch (mysqli_sql_exception $exception) {
            $code = $exception->getCode();
            $error = $exception->getMessage();

            switch ($code) {
                case 1049:
                    $mysql = new MySQL('mysql');
                    $mysql->query(<<<sql
CREATE DATABASE $dbname;
sql
                    );

                    $error = "Database $dbname didn't exists and was created. try again";
                    JsonResponse::sendResponse(compact('error', 'code'), HTTPStatusCodes::NotImplemented);
                    break;
                default:
                    $type = 'MySQL';
                    JsonResponse::sendResponse(compact('error', 'code', 'type'), HTTPStatusCodes::InternalServerError);
                    break;
            }
        }
    }

    public static function default_values(&$values, $keys)
    {
        $values = array_merge(array_fill_keys($keys, null), $values);
        return $values;
    }

    function query($sql, $multi = false)
    {
        try {
            if (!empty($sql)) {
                if ($multi) {
                    return $this->mysqli->multi_query($sql);
                } else {
                    return $this->mysqli->query($sql);
                }
            }
        } catch (mysqli_sql_exception $exception) {
            JsonResponse::sendResponse(['message' => $exception->getMessage(), 'code' => $exception->getCode()], HTTPStatusCodes::InternalServerError);
        }
    }

    /**
     * @param $sql
     * @param $params
     * @return false|mysqli_result|array
     */
    function prepare($sql, $params)
    {
        try {
            $this->mysqli->select_db($this->dbname);
            $stmt = $this->mysqli->prepare($sql);
            foreach ($params as $k => &$param) {
                $array[] =& $param;
            }
            call_user_func_array(array($stmt, 'bind_param'), $params);
            $stmt->execute();
            $row = [];
            $this->stmt_bind_assoc($stmt, $row);
            $mysqli_result = [];
            if (strpos($sql, "select") !== false) {
                while ($stmt->fetch()) {
                    $mysqli_result[] = $this->array_copy($row);
                }
            }
            $stmt->free_result();
            $stmt->close();
            return $mysqli_result ?: [];

        } catch (mysqli_sql_exception $exception) {
            $code = $exception->getCode();
            $message = $exception->getMessage();
            switch ($code) {
                case 1062:
                    //Duplicate Entry
                    $message = 'Duplicate Entry.';
                    JsonResponse::sendResponse(compact('message'), HTTPStatusCodes::BadRequest);
                    break;
                default:
                    $trace = $exception->getTrace();
                    JsonResponse::sendResponse(compact('code', 'message', 'trace'), HTTPStatusCodes::InternalServerError);
                    break;
            }
        }
    }

    function array_copy(array $array)
    {
        $result = array();
        foreach ($array as $key => $val) {
            if (is_array($val)) {
                $result[$key] = $this->array_copy($val);
            } elseif (is_object($val)) {
                $result[$key] = clone $val;
            } else {
                $result[$key] = $val;
            }
        }
        return $result;
    }

    /**
     * Take a statement and bind its fields to an assoc array in PHP with the same fieldnames
     * @param mysqli_stmt $stmt
     * @param $bound_assoc
     */
    function stmt_bind_assoc(&$stmt, &$bound_assoc)
    {
        $metadata = $stmt->result_metadata();
        if ($metadata !== false) {
            $fields = array();
            $bound_assoc = array();

            $fields[] = $stmt;

            while ($field = $metadata->fetch_field()) {
                $fields[] = &$bound_assoc[$field->name];
            }
            call_user_func_array("mysqli_stmt_bind_result", $fields);
        }
    }

    /**
     * @param mysqli_result $mysqli_result
     * @param bool $index
     * @param int $type
     * @return mixed
     */
    function fetch_all($mysqli_result, $index = false, $type = MYSQLI_ASSOC)
    {
        $results = [];
        if ($type != MYSQLI_ASSOC || is_array($mysqli_result)) {
            if (is_array($mysqli_result)) {
                $results = $mysqli_result;
            } elseif ($type == MYSQLI_NUM) {
                $results = $mysqli_result->fetch_all(MYSQLI_NUM);
            }
        } else {
            while ($row = $mysqli_result->fetch_assoc()) {
                array_push($results, $row);
            }
        }

        if ($index !== false) {
            $end = [];
            foreach ($results as $result) {
                $end[$result[$index]] = $result;
            }
            return $end;
        }
        return $results;
    }

    /**
     * @param mysqli_result $mysqli_result
     * @param int $type
     * @return mixed
     */
    public function fetch_single($mysqli_result, $type = MYSQLI_ASSOC)
    {
        if (!is_array($mysqli_result)) {
            $result = $mysqli_result->fetch_array($type);
        } else {
            $result = System::isset_get($mysqli_result[0], []);
        }

        return $result;
    }

    /**
     * @param string $table
     * @param TableColumn[] $columns
     * @param string $extra_sql
     * @return bool
     */
    function create_table($table, $columns, $extra_sql = '')
    {
        $result = System::isset_get($this->fetch_all($this->query("show tables;"), 0, MYSQLI_NUM)[$table]);

        if (!$result) {
            $sql_columns = "";
            foreach ($columns as $column) {
                switch ($column->type) {
                    case ColumnTypes::TIMESTAMP:
                    case ColumnTypes::BIGINT:
                    case ColumnTypes::int:
                    case ColumnTypes::BIT:
                        $default = $column->default;
                        break;
                    default:
                        $default = "'$column->default'";
                        break;
                }
                $sql_columns .=
                    $column->name . " " .
                    $column->type . ($column->type_size ? "($column->type_size)" : '') . " " .
                    ($column->default ? "default " . $default : '') . " " .
                    ($column->auto_increment ? 'auto_increment' : '') . " " .
                    ($column->primary_key ? 'primary key' : '') . " " .
                    ($column->not_null ? 'not null' : '') . ',';
            }
            $sql_columns = trim($sql_columns, ',');

            $sql = <<<sql
CREATE TABLE IF NOT EXISTS `$table`($sql_columns);
sql;
            try {
                $this->mysqli->query("DESCRIBE `$table`");
            } catch (mysqli_sql_exception $exception) {
                $sql .= $extra_sql;
            }
            $result = $this->query($sql, true);
            return true;
        }
    }

    public function insertID()
    {
        return $this->mysqli->insert_id;
    }

    public function escape_string($string)
    {
        return $this->mysqli->real_escape_string($string);
    }

    public function last_error()
    {
        return $this->mysqli->error;
    }

    public function delete_tables(array $tables)
    {
        if (ENVIRONMENT == 'cli') {
            $mysql = new MySQL();
            foreach ($tables as $table) {
                $sql = <<<sql
drop table if exists $table;
sql;
                $mysql->query($sql);
            }
        }
    }
}

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

abstract class ColumnTypes
{
    const BIGINT = 'bigint';
    const VARCHAR = 'varchar';
    const INT = 'int';
    const int = 'int';
    const TIMESTAMP = 'timestamp';
    const DATE = 'date';
    const BIT = 'bit';
    const DECIMAL = 'decimal';
    const LONGBLOB = 'longblob';
}