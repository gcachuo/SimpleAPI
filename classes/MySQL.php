<?php declare(strict_types=1);

namespace Model;

use CoreException;
use HTTPStatusCodes;
use JsonResponse;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use mysqli_stmt;
use PDO;
use PDOException;
use PDOStatement;
use System;

class MySQL
{
    const PARAM_INT = PDO::PARAM_INT;
    /** @var PDO */
    private $pdo;
    /** @var string */
    private static $dbname;
    private $username, $passwd, $host, $port;
    /** @var PDOStatement */
    private $stmt;
    /**
     * @deprecated
     * @var mysqli
     */
    private $mysqli;

    static function unset_database()
    {
        self::$dbname = null;
    }

    /**
     * @throws CoreException
     */
    public function __construct($dbname = null)
    {
        mysqli_report(MYSQLI_REPORT_ALL ^ MYSQLI_REPORT_INDEX);
        try {
            if (defined('CONFIG')) {
                $filename = DIR . '/Config/' . PROJECT . '.json';
            } else {
                $filename = DIR . '/Config/default.json';
            }
            if (file_exists($filename)) {
                $contents = file_get_contents($filename);
                $config = json_decode($contents, true);
                $config = $config['database'];

                $host = $config['host'];
                $username = $config['username'];
                $passwd = $config['passwd'];
                $port = $config['port'] ?? 3306;
                $config['dbname'] = $dbname ?: self::$dbname ?: (getenv('DATABASE') ?: $config['dbname'] ?? (defined('DATABASE') ? DATABASE : null));

                System::check_value_empty($config, ['host', 'username', 'passwd', 'dbname'], 'Missing data in config file.', HTTPStatusCodes::InternalServerError);

                $dbname = $config['dbname'];

                //$this->mysqli = new mysqli($host, $username, $passwd, $dbname);
                $this->pdo = new PDO("mysql:host=$host;dbname=$dbname;port=$port;", $username, $passwd);

                $this->pdo->query("set names 'utf8'");

                $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $this->host = $host;
                self::$dbname = $dbname;
                $this->username = $username;
                $this->passwd = $passwd;
                $this->port = $port;
            } else {
                throw new CoreException("File $filename not found.", HTTPStatusCodes::InternalServerError);
            }
        } catch (mysqli_sql_exception $exception) {
            $code = $exception->getCode();
            $error = $exception->getMessage();

            switch ($code) {
                case 1049:
                    $this->create_database($dbname);

                    $error = "Database $dbname didn't exists and was created. try again";
                    throw new CoreException($error, HTTPStatusCodes::NotImplemented, compact('error', 'code'));
                default:
                    $type = 'MySQL';
                    throw new CoreException($error, HTTPStatusCodes::InternalServerError, compact('error', 'code', 'type'));
            }
        }
    }

    public function query($sql, $multi = false)
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
            JsonResponse::sendResponse($exception->getMessage(), HTTPStatusCodes::InternalServerError);
        }
    }

    public static function create_database($dbname)
    {
        $mysql = new MySQL('mysql');
        $mysql->query(<<<sql
CREATE DATABASE $dbname;
sql
        );
        self::$dbname = $dbname;
    }

    public static function default_values(&$values, $keys)
    {
        $values = array_merge(array_fill_keys($keys, null), $values);
        return $values;
    }

    public function database()
    {
        return self::$dbname;
    }

    /**
     * @param mysqli_result $mysqli_result
     * @param bool $index
     * @param int $type
     * @return mixed
     * @deprecated
     */
    public function fetch_all($mysqli_result, $index = false, $type = MYSQLI_ASSOC)
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
     * @param string $table
     * @param TableColumn[] $columns
     * @param string $extra_sql
     * @return bool
     */
    public function create_table($table, $columns, $extra_sql = '')
    {
        $table_exists = array_flip(array_column($this->prepare2('show tables;')->fetchAll(PDO::FETCH_NUM), 0))[$table];

        if (!$table_exists) {
            $sql_columns = '';
            foreach ($columns as $column) {
                $sql_column = $this->parsed_sql_column($column);
                $sql_columns .= $sql_column;
            }
            $sql_columns = trim($sql_columns, ',');

            $sql = <<<sql
CREATE TABLE IF NOT EXISTS `$table`($sql_columns) 
ENGINE = InnoDB
CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
sql;
            try {
                $this->pdo->query("DESCRIBE `$table`");
            } catch (PDOException $exception) {
                $sql .= $extra_sql;
            }
            $this->prepare2($sql);
            return false;
        } else {
            try {
                $sql_columns = implode(',', array_column($columns, 'name'));
                $this->prepare2("select $sql_columns from $table");
            } catch (CoreException $exception) {
                $code = $exception->getData('code');
                $message = $exception->getMessage();
                switch ($code) {
                    case 1054:
                        preg_match('/Unknown column \'(.+)\' in \'field list\'/', $message, $matches);
                        [$message, $column_name] = $matches;
                        $index = array_search($column_name, array_column($columns, 'name'));
                        $sql_column = trim($this->parsed_sql_column($columns[$index]), ',');
                        $this->prepare2("ALTER TABLE $table ADD $sql_column;");
                        break;
                    default:
                        throw $exception;
                }
            }
        }

        return true;
    }

    public function fetchAll($fetch_style = null)
    {
        return $this->stmt->fetchAll($fetch_style ?: PDO::FETCH_ASSOC);
    }

    /**
     * @param string $sql
     * @param array $params
     * @return $this
     * @throws CoreException
     */
    public function prepare2(string $sql, array $params = [])
    {
        try {
            $stmt = $this->pdo->prepare($sql);

            foreach ($params as $key => &$val) {
                $type = $this->parseValue($val);
                $stmt->bindParam($key, $val, $type);
            }

            $stmt->execute();
            $this->stmt = $stmt;

            System::query_log(self::interpolate_query($sql, $params, false));

            return $this;
        } catch (PDOException $exception) {
            [$pdoerror, $code, $message] = $exception->errorInfo;

            $message = $message ?: $exception->getMessage();

            System::query_log(self::interpolate_query($sql, $params));
            System::query_log('#' . $message);

            $trace = $exception->getTrace();
            foreach ($params as $key => &$val) {
                $this->parseValue($val);
            }
            $parsed_sql = self::interpolate_query($sql, $params);
            throw new CoreException($message, HTTPStatusCodes::InternalServerError, compact('code', 'pdoerror', 'trace', 'params', 'sql', 'parsed_sql'));
        }
    }

    /**
     * @param string $sql
     * @param array $params
     * @return false|mysqli_result|array
     * @deprecated Use prepare2
     */
    public function prepare(string $sql, array $params = [])
    {
        try {
            System::query_log(self::interpolate_query($sql, $params, true));

            if (empty($params)) {
                return $this->query($sql);
            }

            $dbname = self::$dbname ?? '';
            $this->mysqli->select_db($dbname);
            $stmt = $this->mysqli->prepare($sql);
            foreach ($params as $k => &$param) {
                $array[] =& $param;
            }
            call_user_func_array(array($stmt, 'bind_param'), $params);
            $stmt->execute();
            $row = [];
            $this->stmt_bind_assoc($stmt, $row);
            $mysqli_result = [];
            if (stripos($sql, 'select') !== false) {
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
                    $message = 'Duplicate entry.';
                    JsonResponse::sendResponse($message, 400, compact('message'));
                    break;
                case 1452:
                    //Foreign Key
                    $message = 'A Foreign Key constraint fails.';
                    JsonResponse::sendResponse($message, 400, compact('message'));
                    break;
                default:
                    $trace = $exception->getTrace();
                    JsonResponse::sendResponse($message, HTTPStatusCodes::InternalServerError, compact('code', 'message', 'trace'));
                    break;
            }
        }
    }

    private static function interpolate_query($query, $params, $splice = false)
    {
        if ($splice) {
            $params = array_splice($params, 1);
        }

        $keys = array();
        $values = $params;

        # build a regular expression for each parameter
        foreach ($params as $key => $value) {
            if (is_string($key)) {
                $keys[] = '/' . $key . '(?=[^_])/';
            } else {
                $keys[] = '/[?]/';
            }

            if (is_array($value)) {
                $value = $value[0];
            }

            if (is_string($value))
                $values[$key] = "'" . $value . "'";

            if (is_array($value))
                $values[$key] = "'" . implode("','", $value) . "'";

            if (is_null($value))
                $values[$key] = 'NULL';

            if (is_bool($value))
                $values[$key] = $value ? 'true' : 'false';
        }

        $query = @preg_replace($keys, $values, $query);

        return $query;
    }

    /**
     * Take a statement and bind its fields to an assoc array in PHP with the same fieldnames
     * @param mysqli_stmt $stmt
     * @param $bound_assoc
     */
    public function stmt_bind_assoc(&$stmt, &$bound_assoc)
    {
        $metadata = $stmt->result_metadata();
        if ($metadata !== false) {
            $fields = array();
            $bound_assoc = array();

            $fields[] = $stmt;

            while ($field = $metadata->fetch_field()) {
                $fields[] = &$bound_assoc[$field->name];
            }
            call_user_func_array('mysqli_stmt_bind_result', $fields);
        }
    }

    public function fetch()
    {
        return $this->stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function array_copy(array $array)
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

    private function parseValue(&$val): int
    {
        $type = PDO::PARAM_STR;

        if ($val === '') {
            $val = null;
        } elseif (is_int($val)) {
            $val = intval($val);
        } elseif (is_numeric($val)) {
            if (strpos((string)floatval($val), 'E') === false) {
                $val = floatval($val);
            }
        } elseif (is_array($val)) {
            $val = json_encode($val);
        } elseif (is_bool($val)) {
            $type = PDO::PARAM_BOOL;
            $val = $val ? 1 : 0;
        }

        return $type;
    }

    private function parsed_sql_column($column)
    {
        switch ($column->type) {
            case ColumnTypes::TIMESTAMP:
            case ColumnTypes::BIGINT:
            case ColumnTypes::INTEGER:
            case ColumnTypes::BIT:
                $default = $column->default;
                break;
            default:
                $default = "'$column->default'";
                break;
        }
        return $column->name . ' ' .
            $column->type . ($column->type_size ? "($column->type_size)" : '') . ' ' .
            ($column->default ? 'default ' . $default : '') . ' ' .
            ($column->auto_increment ? 'auto_increment' : '') . ' ' .
            ($column->primary_key ? 'primary key' : '') . ' ' .
            ($column->not_null ? 'not null' : '') . ',';
    }

    /**
     * @return mixed
     * @deprecated
     */
    public function insertID()
    {
        return $this->mysqli->insert_id;
    }

    /**
     * @return mixed
     */
    public function lastInsertId()
    {
        return $this->pdo->lastInsertId();
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

    /**
     * @return int
     */
    public function rowCount()
    {
        $sql = <<<sql
SELECT ROW_COUNT() rowCount;
sql;
        return $this->fetch_single($this->query($sql))['rowCount'];
    }

    /**
     * @param mysqli_result $mysqli_result
     * @param int $type
     * @return mixed
     * @deprecated
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

    public function from_file($filename)
    {
        $path = DIR . "/Model/Data/$filename.sql";
        $sql = file_get_contents($path);
        return $sql;
    }

    public function encrypt_data($user_id, array $data)
    {
        // decrypt secret key with user private key
        $key = 'super secret key';
        // encrypt data using secret key
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted_data = [];
        foreach ($data as $data_key => $data_value) {
            $encrypted_data[$data_key] = base64_encode(openssl_encrypt($data_value, 'aes-256-cbc', $key, 0, $iv) . '::' . $iv);
        }
        return $encrypted_data;
    }

    public function decrypt_data(int $user_id, array $data)
    {
        // decrypt secret key with user private key
        $key = 'super secret key';
        // decrypt data using secret key
        $decrypted_data = [];
        foreach ($data as $data_key => $data_value) {
            list($decrypted, $iv) = explode('::', base64_decode($data_value), 2);
            $decrypted_data[$data_key] = openssl_decrypt($decrypted, 'aes-256-cbc', $key, 0, $iv);
        }
        return $decrypted_data;
    }

    /**
     * @param int $column
     * @return mixed
     */
    public function fetchColumn(int $column = 0)
    {
        return $this->stmt->fetchColumn($column);
    }

    public function convertEncoding(string $table, string $field)
    {
        $sql = <<<sql
UPDATE $table SET $field = CONVERT(CAST(CONVERT($field USING latin1) AS BINARY) USING utf8mb4);
sql;
        $this->prepare2($sql);
    }

    /**
     * @return array
     */
    public function backupDB(): array
    {

        $filename = time() . '.' . $this->dbname . '.sql';
        $result_file = __DIR__ . '/../Backup/' . $filename;
        if (!is_dir(dirname($result_file))) {
            if (!mkdir(dirname($result_file))) {
                JsonResponse::sendResponse('Error creating dir: ' . dirname($result_file));
            }
        }
        $command = /** @lang bash */
            <<<bash
mysqldump $this->dbname --result-file="$result_file" --skip-lock-tables --complete-insert --skip-add-locks --disable-keys -u$this->username -p$this->passwd -h$this->host
bash;
        exec($command . ' 2>&1', $output);

        $filename = 'api/public/backup/' . $filename;
        return compact('filename', 'output');
    }
}
