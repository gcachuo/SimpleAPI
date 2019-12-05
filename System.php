<?php

use Firebase\JWT\JWT;
use Model\ColumnTypes;
use Model\MySQL;
use Model\TableColumn;

class System
{
    /**
     * @var string
     */
    private static $idioma;

    /**
     * @param string $json
     * @param bool $assoc
     * @return object|array
     */
    static function json_decode($json, $assoc)
    {
        $json = json_decode($json, $assoc);
        $error = json_last_error();
        switch ($error) {
            case 0:
                //No Error
                break;
            case 5:
                //Malformed UTF-8 characters, possibly incorrectly encoded
                array_walk_recursive($array, function (&$item) {
                    $item = utf8_encode($item);
                });
                $json = json_decode($json, $assoc);
                break;
            default:
                $json = json_last_error_msg();
                break;
        }
        return $json;
    }

    public static function admin_log(int $id_usuario, string $mensaje)
    {
        try {
            $mysql = new MySQL();
            $mysql->create_table('_admin_log', [
                new TableColumn('id_admin_log', ColumnTypes::BIGINT, 20, true, null, true, true),
                new TableColumn('id_usuario', ColumnTypes::BIGINT, 20, true),
                new TableColumn('fecha', ColumnTypes::TIMESTAMP, 0, false, 'current_timestamp'),
                new TableColumn('mensaje', ColumnTypes::LONGBLOB)
            ]);
            $mysql->prepare(<<<sql
insert into _admin_log values(null,?,current_timestamp,?);
sql
                , ['is', $id_usuario, $mensaje]);
        } catch (Exception $ex) {
            ob_clean();
            die(print_r($ex, true));
        }
    }

    public static function cli_echo(string $string, string $color = null)
    {
        $color = [
                'red' => '01;31',
                'green' => '0;32',
                'blue' => '0;34',
                'yellow' => '1;33'
            ][$color] ?? '';
        echo "\033[{$color}m " . $string . " \033[0m" . "\n";
    }

    public static function get_config()
    {
        $path = DIR . "/Config";
        $env = file_exists("$path/config.dev.json") ? "dev" : "prod";

        $ruta = "$path/config.$env.json";
        if (!file_exists($ruta)) {
            $ruta = $path . "/config.$env.json";
            if (!file_exists($ruta)) {
                JsonResponse::sendResponse(['message' => "No existe el archivo de configuración $ruta"], HTTPStatusCodes::InternalServerError);
            }
        }
        $json = file_get_contents($ruta);
        return json_decode($json, false);
    }

    public static function set_language(stdClass $idioma)
    {
        self::$idioma = $idioma;
    }

    public static function encode_id($id)
    {
        return base64_encode(rand(10000, 99999) . '=' . $id);
    }

    public static function format_date(string $format, $value)
    {
        $value = strtotime($value);
        return date($format, $value);
    }

    public static function format_date_locale(string $format, $locale, $value)
    {
        setlocale(LC_TIME, $locale);
        return strftime($format, strtotime($value));
    }

    public static function decode_id(string &$base64)
    {
        $end_decoded = trim(strstr(base64_decode($base64), '='), '=');
        if (!empty($end_decoded)) {
            if (!is_nan($end_decoded)) {
                $base64 = $end_decoded;
            }
            return $end_decoded;
        }
        return $base64;
    }

    public static function request_log()
    {
        $data = '[' . date('Y-m-d H:i:s') . '] ';
        $data .= '[' . $_SERVER['REQUEST_METHOD'] . '] ';
        $data .= '[' . strstr($_SERVER['REQUEST_URI'], 'api/') . '] ';

        $data .= preg_replace('/\s/', '', file_get_contents('php://input'));

        file_put_contents(__DIR__ . '/../Logs/' . date('Y-m-d') . '.log', $data . "\n", FILE_APPEND);
    }

    /**
     * @param $variable
     * @param null $return
     * @return int|string|null|array
     */
    public static function isset_get(&$variable, $return = null)
    {
        if (isset($variable)) {
            if (empty($variable)) {
                return $return;
            }
            $variable = is_string($variable) ? trim($variable) : $variable;
            return $variable;
        }
        unset($variable);
        return $return;
    }

    public static function encode_token(array $data)
    {
        $jwt_key = self::get_jwt_key();

        $expiration = (60 * 60) * 12; //12 Hours

        $time = time();
        $token = [
            'iat' => $time,
            'exp' => $time + $expiration,
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
            if (empty($jwt)) {
                JsonResponse::sendResponse(['message' => 'Empty token.']);
            }

            $jwt_key = self::get_jwt_key();

            $time = time();
            $decoded = JWT::decode($jwt, $jwt_key, ['HS256']);
            if ($decoded->exp <= $time) {
                JsonResponse::sendResponse(['message' => 'The token has expired.']);
            }
            return json_decode(json_encode($decoded), true)['data'];
        } catch (Firebase\JWT\ExpiredException $ex) {
            JsonResponse::sendResponse(['message' => $ex->getMessage()]);
        } catch (Firebase\JWT\SignatureInvalidException $ex) {
            JsonResponse::sendResponse(['message' => $ex->getMessage()]);
        } catch (UnexpectedValueException $ex) {
            JsonResponse::sendResponse(['message' => 'Invalid token.']);
        } catch (DomainException $ex) {
            JsonResponse::sendResponse(['message' => 'Invalid token.']);
        }
    }

    public function init($config)
    {
        self::define_constants($config);

        self::load_php_functions();

        self::load_composer();

        if (ENVIRONMENT == 'web') {
            System::request_log();

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
                        case 2:
                            switch ($error['message']) {
                                case "session_start(): Cannot start session when headers already sent":
                                    break 2;
                            }
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
        ini_set('max_execution_time', 300);
        spl_autoload_register(function ($class) {
            $file = str_replace('\\', '/', $class);
            $path = __DIR__ . "/../$file.php";
            if (file_exists($path)) {
                include $path;
            }
//            die($path);
        });
        if (function_exists('xdebug_disable')) {
            //Disables stack traces
            //Disable showing stack traces on error conditions.
            xdebug_disable();
        }
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
            copy(DIR . '/../Config/database.json', DIR . '/../Config/database.prod.json');
            @chmod(DIR . '/../Config/.jwt_key', 0777);
            @chmod(DIR . '/../Config/database.json', 0777);
            @chmod(DIR . '/../Config/database.prod.json', 0777);
            @chmod(DIR . '/../Config/.gitignore', 0777);
        }

        createDir('Config');
        createDir('Controller');
        createDir('Model');
        createDir('Helper');
        createDir('Data');
        createDir('public');
        createDir('Tests');
        createDir('Tests/Data');
        createDir('Logs');

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
        global $_PATCH, $_PUT, $_DELETE;

        if (!defined('ENVIRONMENT'))
            define('ENVIRONMENT', isset($_SERVER['SHELL']) || isset($_SERVER['argv']) ? 'cli' : 'web');

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

            parse_str($entry, ${'_' . REQUEST_METHOD});
            if (REQUEST_METHOD === 'POST') {
                parse_str($entry, $_POST);
                if (!empty($_POST['form'])) {
                    parse_str($_POST["form"], $_POST["form"]);
                    $_POST = array_merge($_POST, $_POST["form"]);
                    unset($_POST["form"]);
                }
                if (!empty($_POST['aside'])) {
                    parse_str($_POST["aside"], $_POST["aside"]);
                    $_POST = array_merge($_POST, $_POST["aside"]);
                    unset($_POST["aside"]);
                }
                if (!empty($_POST['post'])) {
                    if (is_string($_POST['post'])) {
                        parse_str($_POST["post"], $_POST["post"]);
                    }
                    $_POST = array_merge($_POST, $_POST["post"]);
                    unset($_POST["post"]);
                }
            }
            if (REQUEST_METHOD === 'GET') {
                parse_str($entry, $_GET);
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
            System::decode_id($end);
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
                if (empty($id)) {
                    JsonResponse::sendResponse(['message' => 'Request Method PATCH needs an ID to work'], HTTPStatusCodes::BadRequest);
                }
                break;
        }
        global $_PATCH;
        $response = null;
        $namespace = "Controller\\" . ucfirst($controller);
        if (class_exists($namespace)) {
            /** @var $class Controller */
            $class = new $namespace();
            $response = $class->$action($id);
            if (!is_array($response)) {
                $message = $response;
            } else {
                $message = 'Completed.';
                if ($response) {
                    $data = $response;
                    JsonResponse::sendResponse(compact('message', 'data'), HTTPStatusCodes::OK);
                }
            }
            JsonResponse::sendResponse(compact('message'), HTTPStatusCodes::OK);
        }
        JsonResponse::sendResponse(['message' => "Endpoint not found. [$namespace]"], HTTPStatusCodes::NotFound);
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

        foreach ($required as $key => $value) {
            if (!System::isset_get($array[$key])) {
                $empty_values .= $key . ', ';
            }
        }
        $empty_values = trim($empty_values, ', ');
        if (!empty($empty_values)) {
            JsonResponse::sendResponse(['message' => $message . ' ' . "[$empty_values]"], HTTPStatusCodes::BadRequest);
        }

        foreach ($intersect as $key => $value) {
            $value = is_string($value) ? trim($value) : $value;
            if (empty($value) and $value !== "0") {
                $empty_values .= $key . ', ';
            }
        }
        $empty_values = trim($empty_values, ', ');
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
                new TableColumn('linea', ColumnTypes::INTEGER, 11),
                new TableColumn('codigo', ColumnTypes::INTEGER, 11),
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
        } catch (mysqli_sql_exception $ex) {
            ob_clean();
            die(print_r($ex, true));
        }
    }
}

class Controller
{
    private $_methods;
    private static $_response;

    public function __construct($methods)
    {
        $this->_methods = $methods;
        $this->allowed_methods($methods);
    }

    public function __call($action, $arguments)
    {
        if (ENVIRONMENT == 'web') {
            $name = System::isset_get($this->_methods[REQUEST_METHOD][$action]);
            if ($name) {
                return $this->$name(...$arguments);
            }
            JsonResponse::sendResponse(['message' => "Endpoint not found. [$name]"], HTTPStatusCodes::NotFound);
        }
        return $this->$action(...$arguments);
    }

    private function allowed_methods(array $methods)
    {
        if (ENVIRONMENT == 'web') {
            if (!isset($methods[REQUEST_METHOD])) {
                JsonResponse::sendResponse(['message' => 'Method Not Allowed'], HTTPStatusCodes::MethodNotAllowed);
            }
        }
    }

    public function method_exists(Controller $class, $action)
    {
        return method_exists($class, $this->_methods[REQUEST_METHOD][$action]);
    }
}

class Stopwatch
{
    private $lap_start = 0;
    private $begin = 0;
    public $measure_points = array();
    private static $_Instance;
    private $total;

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

        $total = 0;
        foreach ($this->measure_points as $key => $data) {
            $total = $total + $data;
        }
        $this->total = $total;

        self::$_Instance[$name] = $this;
    }

    static function report($name = null)
    {
        if (ENVIRONMENT !== 'cli') {
            return null;
        }
        if (!$name) {
            return self::$_Instance;
        }
        System::cli_echo(str_pad($name, 35), 'yellow');
        $_this = self::$_Instance[$name];
        foreach ($_this->measure_points as $key => $data) {
            $percent = $data / ($_this->total / 100);
            System::cli_echo(str_pad($key, 35) . ' : ' . number_format($data, 8) . ' (' . number_format($percent, 2) . '%)', 'blue');
        }

        System::cli_echo(str_pad('Total', 35) . ' : ' . number_format($_this->total, 8), 'green');
        return $_this->total;
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
        if ($this->code >= HTTPStatusCodes::BadRequest) {
            $code = $this->code;
            $status = 'error';
            $response = $this->encode_items($this->response);
            $error = error_get_last();
            System::log_error(compact('status', 'code', 'response', 'error'));
        }
        if (ENVIRONMENT == 'web') {
            ob_clean();
            die(self::$json);
        }
        $exception = json_decode(self::$json, true);

        if ($exception['code'] !== HTTPStatusCodes::OK) {
            $error = '';
            if (is_array($exception['error'])) {
                $error = $exception['error']['message'];
            } elseif (System::isset_get($exception['response']['message'])) {
                $error = $exception['response']['message'];
            }

            throw new JsonException($error, $exception['code']);
        } else {
            die($exception['status']);
        }
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