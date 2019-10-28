<?php

use Firebase\JWT\JWT;
use Model\ColumnTypes;
use Model\MySQL;
use Model\TableColumn;

class System
{
    public static function allowed_methods(array $methods)
    {
        if (!in_array(REQUEST_METHOD, $methods)) {
            JsonResponse::sendResponse(['message' => 'Method Not Allowed'], HTTPStatusCodes::MethodNotAllowed);
        }
    }

    /**
     * @param $variable
     * @param null $return
     * @return int|string|null|array
     */
    public static function isset_get(&$variable, $return = null)
    {
        if (isset($variable)) {
            $variable = is_string($variable) ? trim($variable) : $variable;
            return empty($variable) && !is_numeric($variable) ? null : $variable;
        }
        unset($variable);
        return $return;
    }

    public static function encode_token(array $data)
    {
        $jwt_key = self::get_jwt_key();

        $time = time();
        $token = [
            'iat' => $time,
            'exp' => $time + (60 * 60),
            'data' => $data
        ];
        return JWT::encode($token, $jwt_key);
    }

    private static function get_jwt_key()
    {
        if (empty(JWT_KEY)) {
            if (!file_exists(DIR . '/Config/.jwt_key')) {
                JsonResponse::sendResponse(['message' => 'Missing file .jwt_key'], HTTPStatusCodes::InternalServerError);
            }
            JsonResponse::sendResponse(['message' => 'JWT key is empty'], HTTPStatusCodes::InternalServerError);
        }
        return JWT_KEY;
    }

    public static function decode_token($jwt)
    {
        try {
            $jwt_key = self::get_jwt_key();

            $time = time();
            $decoded = JWT::decode($jwt, $jwt_key, ['HS256']);
            if ($decoded->exp <= $time) {
                JsonResponse::sendResponse(['message' => 'The token has expired.'], HTTPStatusCodes::BadRequest);
            }
            return json_decode(json_encode($decoded), true)['data'];
        } catch (Firebase\JWT\ExpiredException $ex) {
            JsonResponse::sendResponse(['message' => $ex->getMessage()], HTTPStatusCodes::BadRequest);
        } catch (Firebase\JWT\SignatureInvalidException $ex) {
            JsonResponse::sendResponse(['message' => $ex->getMessage()], HTTPStatusCodes::BadRequest);
        } catch (UnexpectedValueException $ex) {
            JsonResponse::sendResponse(['message' => 'Invalid token.'], HTTPStatusCodes::BadRequest);
        }
    }

    public function init($config)
    {
        global $_PATCH;

        self::define_constants($config);

        self::load_php_functions();

        self::load_composer();

        if (ENVIRONMENT == 'web') {
            self::convert_endpoint($controller, $action, $id);

            self::call_action($controller, $action, $id);
        } else {
            ob_end_clean();
        }
    }

    public function startup()
    {
        self::define_constants(['DIR' => __DIR__ . '/']);
        self::load_php_functions();
        self::create_directories();
    }

    private static function load_composer()
    {
        $pathLib = DIR . "/Lib/vendor/autoload.php";
        $path = DIR . "/vendor/autoload.php";
        if (!file_exists($pathLib)) {
            JsonResponse::sendResponse(['message' => 'Composer is not installed on Lib.'], HTTPStatusCodes::InternalServerError);
        }
        if (!file_exists($path)) {
            JsonResponse::sendResponse(['message' => 'Composer is not installed.'], HTTPStatusCodes::InternalServerError);
        }
        require_once($pathLib);
        require_once($path);
    }

    private static function load_php_functions()
    {
        ob_start();
        if (ENVIRONMENT == 'web') {
            header('Content-Type: application/json');
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PATCH, DELETE');
            header('Access-Control-Allow-Headers: Content-Type, dataType, contenttype, processdata');
            setcookie('XDEBUG_SESSION', 'PHPSTORM');
        }
        register_shutdown_function(function () {
            if (error_get_last()) {
                $error = error_get_last();
                if (!strpos($error['file'], 'vendor')) {
                    switch ($error['type']) {
                        case 8:
                            break;
                        default:
                            JsonResponse::sendResponse(['message' => 'A fatal error ocurred.', 'error' => $error], HTTPStatusCodes::InternalServerError);
                            break;
                    }
                }
            }
        });

        $pathMySQL = "MySQL.php";
        require_once($pathMySQL);

        error_reporting(E_ALL ^ E_DEPRECATED);
        ini_set('display_errors', 1);
        ini_set('always_populate_raw_post_data', -1);
        spl_autoload_register(function ($class) {
            $split = explode('\\', $class);
            $dir = $split[0];
            $file = ucfirst(System::isset_get($split[1]));
            $path = "$dir/$file.php";
            if (file_exists($path)) {
                include $path;
            }
        });
    }

    private static function create_directories()
    {
        function createDir($dir)
        {
            if (!is_dir(DIR . "/../$dir/")) {
                mkdir(DIR . "/../$dir/", 0777, true);
                @chmod(DIR . "/../$dir/", 0777);
            }
        }

        function createFile($file)
        {
            if (!file_exists(DIR . "/../$file")) {
                copy(DIR . "/files/$file", DIR . "/../$file");
                @chmod(DIR . "/../$file", 0777);
            }
        }

        function createConfig()
        {
            file_put_contents(DIR . '/../Config/.jwt_key', '');
            file_put_contents(DIR . '/../Config/database.json', json_encode(["host" => "", "username" => "", "passwd" => "", "dbname" => ""]));
            file_put_contents(DIR . '/../Config/.gitignore', join("\n", ['.jwt_key', 'database.json']));
            @chmod(DIR . '/../Config/.jwt_key', 0777);
            @chmod(DIR . '/../Config/database.json', 0777);
            @chmod(DIR . '/../Config/.gitignore', 0777);
        }

        createDir('Config');
        createDir('Controller');
        createDir('Model');
        createDir('Helper');
        createDir('Data');
        createDir('public');

        createConfig();
        createFile('.htaccess');
        createFile('index.php');
        createFile('composer.json');
        createFile('.gitignore');

        createFile('public/.htaccess');

        shell_exec('cd .. && composer install && cd .. && composer install');

        JsonResponse::sendResponse([], HTTPStatusCodes::OK);
    }

    private static function define_constants($config)
    {
        global $_PATCH;

        if (!defined('ENVIRONMENT'))
            define('ENVIRONMENT', isset($_SERVER['SHELL']) ? 'cli' : 'web');

        if (!defined('REQUEST_METHOD'))
            define('REQUEST_METHOD', System::isset_get($_SERVER['REQUEST_METHOD']));

        if (!defined('DEBUG_MODE'))
            define('DEBUG_MODE', ENVIRONMENT == 'cli' || preg_match('/Mozilla/', System::isset_get($_SERVER['HTTP_USER_AGENT'])) != 1);

        if (!defined('DIR'))
            define('DIR', $config['DIR']);

        if (!defined('JWT_KEY'))
            define('JWT_KEY', file_exists(DIR . '/Config/.jwt_key') ? file_get_contents(DIR . '/Config/.jwt_key') : null);

        $entry = (file_get_contents('php://input'));
        if (!empty($entry)) {
            if (self::isJson($entry)) {
                $entry = http_build_query(json_decode($entry, true));
            }
            if (REQUEST_METHOD === 'POST') {
                parse_str($entry, $_POST);
            } else if (REQUEST_METHOD === 'PATCH') {
                parse_str($entry, $_PATCH);
            }
        }
    }

    private static function convert_endpoint(&$controller, &$action, &$id)
    {
        $request = explode('/', trim($_SERVER['REQUEST_URI'], '/'));
        if (count($request) > 2) {
            $end = end($request);
            if (strpos($end, '?')) {
                $end = stristr($end, '?', true);
            }
            $count = ctype_digit($end) ? 3 : 2;
            $request = array_slice($request, -$count, $count, false);
            if (count($request) == 3) {
                $id = (int)$end;
            }
        }
        $controller = strtolower($request[0]);
        $action = System::isset_get($request[1]);
        if (strpos($action, '?')) {
            $action = stristr($action, '?', true);
        }
    }

    private static function call_action($controller, $action, $id)
    {
        switch (REQUEST_METHOD) {
            case 'OPTIONS':
                JsonResponse::sendResponse([], HTTPStatusCodes::OK);
                break;
            case 'PATCH':
                global $_PATCH;

                if (!empty($_PATCH)) {
                    $_POST = $_PATCH;
                }
                if (empty($id)) {
                    JsonResponse::sendResponse(['message' => 'Request Method PATCH needs an ID to work'], HTTPStatusCodes::BadRequest);
                }
                break;
        }
        global $_PATCH;
        $response = null;
        $namespace = "Controller\\$controller";
        if (class_exists($namespace)) {
            $class = new $namespace();
            if (method_exists($class, $action)) {
                $response = $class->$action($id);
                if (!is_array($response)) {
                    $message = $response;
                    $data = null;
                } else {
                    $message = 'Completed.';
                    $data = $response;
                }
                JsonResponse::sendResponse(compact('message', 'data'), HTTPStatusCodes::OK);
            }
        }
        JsonResponse::sendResponse(['message' => "Endpoint not found."], HTTPStatusCodes::NotFound);
    }

    /**
     * @param array $array
     * @param array $required
     * @param string $message
     */
    public static function check_value_empty($array, $required, $message)
    {
        $required = array_flip($required);
        $intersect = array_intersect_key($array ?: $required, $required);
        $empty_values = '';
        foreach ($intersect as $key => $value) {
            $value = is_string($value) ? trim($value) : $value;
            if (empty($value) and $value !== "0") {
                $empty_values .= $key . ',';
            }
        }
        $empty_values = trim($empty_values, ',');
        if (!empty($empty_values)) {
            JsonResponse::sendResponse(['message' => $message . ' ' . "[$empty_values]"], HTTPStatusCodes::BadRequest);
        }
    }

    private static function isJson($string)
    {
        json_decode($string);
        $isJson = (json_last_error() == JSON_ERROR_NONE);
        if (!$isJson) {
            $error = json_last_error_msg();
        }
        return $isJson;
    }

    public static function log_error(array $response)
    {
        try {
            $mysql = new MySQL();
            $mysql->create_table("_errores", [
                new TableColumn('id', ColumnTypes::BIGINT, 20, true, null, true, true),
                new TableColumn('fecha', ColumnTypes::TIMESTAMP, 0, true, 'current_timestamp'),
                new TableColumn('mensaje', ColumnTypes::VARCHAR, 2000, true),
                new TableColumn('archivo', ColumnTypes::VARCHAR, 255),
                new TableColumn('linea', ColumnTypes::int, 11),
                new TableColumn('codigo', ColumnTypes::int, 11),
                new TableColumn('_post', ColumnTypes::LONGBLOB, 0),
                new TableColumn('_get', ColumnTypes::VARCHAR, 2000),
                new TableColumn('_server', ColumnTypes::VARCHAR, 2000),
                new TableColumn('_session', ColumnTypes::VARCHAR, 2000),
            ]);
            $mysql->prepare("insert into _errores values(?,?,?,?,?,?,?,?,?,?)", [
                'isssiissss',
                null,//id
                null,//fecha
                System::isset_get($response['error']['message'], $response['response']['message']),//mensaje
                System::isset_get($response['error']['file']),//archivo
                System::isset_get($response['error']['line']),//linea
                System::isset_get($response['error']['type'], $response['code']),//codigo
                $mysql->escape_string(print_r($_POST, true)),//_post
                $mysql->escape_string(print_r($_GET, true)),//_get
                $mysql->escape_string(print_r($_SERVER, true)),//_server
                $mysql->escape_string(print_r(System::isset_get($_SESSION), true)),//_session
            ]);
        } catch (Exception $ex) {
            ob_clean();
            die(print_r($ex, true));
        }
    }
}

class Stopwatch
{
    private $lap_start = 0;
    private $begin = 0;
    private $measure_points = array();

    function start()
    {
        $this->begin = microtime(true);
        $this->lap_start = $this->begin;
    }

    function lap_end($name)
    {
        $time = microtime(true) - $this->lap_start;
        $this->measure_points[$name] = $time;
        $this->lap_start = microtime(true);
    }

    function end($name)
    {
        $time = microtime(true) - $this->lap_start;
        $this->measure_points[$name] = $time;
    }

    function report()
    {
        if (ENVIRONMENT !== 'cli') {
            return;
        }
        $total = 0;
        foreach ($this->measure_points as $key => $data) {
            $total = $total + $data;
        }
        foreach ($this->measure_points as $key => $data) {
            $percent = $data / ($total / 100);
            echo (str_pad($key, 35) . ' : ' . number_format($data, 8) . ' (' . number_format($percent, 2) . '%)') . "\n";
        }
        echo (str_pad('Total', 35) . ' : ' . number_format($total, 8)) . "\n";
    }
}

class JsonResponse
{
    private $response, $error, $code;
    private static $alreadySent = false, $json;

    static function sendResponse(array $response, $code = 400)
    {
        new JsonResponse($response, $code);
    }

    private function __construct(array $response, $code = null)
    {
        if (self::$alreadySent and $code !== HTTPStatusCodes::OK) {
            $this->send_response();
            exit;
        }
        if (!empty($code)) {
            http_response_code($code);
            $this->code = $code;
        } else {
            $this->code = http_response_code();
        }

        $this->response = $response;
        $this->error = error_get_last();
        $this->json_encode();
        $this->send_response();
    }

    private function send_response()
    {
        if ($this->code >= HTTPStatusCodes::InternalServerError) {
            $code = $this->code;
            $status = 'error';
            $response = $this->encode_items($this->response);
            $error = error_get_last();
            System::log_error(compact('status', 'code', 'response', 'error'));
        }
        ob_clean();
        if (ENVIRONMENT == 'web') {
            die(self::$json);
        }
        die(print_r(json_decode(self::$json, true), true));
    }

    private function json_encode()
    {
        $response = $this->encode_items($this->response);
        $code = $this->code;
        $error = '';
        if (DEBUG_MODE) $error = $this->error;
        $status = $code !== HTTPStatusCodes::OK ? 'error' : 'success';
        if ($status === 'error') {
            self::$alreadySent = true;
        }
        $json = json_encode(compact('status', 'code', 'response', 'error'));
        if (!$json) {
            $message = 'Fatal error. JSON response malformed.';
            if (DEBUG_MODE) $error = json_last_error_msg();
            JsonResponse::sendResponse(compact('message', 'error'), HTTPStatusCodes::InternalServerError);
        } else {
            self::$json = $json;
        }
    }

    private function encode_items($array)
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->encode_items($value);
            } elseif (is_object($value)) {

            } else {
                if (!mb_detect_encoding($value, 'UTF-8', true)) {
                    $array[$key] = utf8_encode($value);
                } else {
                    $array[$key] = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                }
            }
        }

        return $array;
    }
}

class HTTPStatusCodes
{
    const __default = self::OK;

    const OK = 200;
    const BadRequest = 400;
    const Unauthorized = 401;
    const NotFound = 404;
    const MethodNotAllowed = 405;
    const InternalServerError = 500;
    const NotImplemented = 501;
    const ServiceUnavailable = 503;
    const Forbidden = 403;
}