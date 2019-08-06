<?php

use Firebase\JWT\JWT;
use Model\ColumnTypes;
use Model\MySQL;
use Model\TableColumn;

class System
{
    public static function isset_get(&$variable, $return = null)
    {
        if (isset($variable)) {
            return $variable;
        }
        unset($variable);
        return $return;
    }

    public static function encode_token(array $data)
    {
        $time = time();
        $token = [
            'iat' => $time,
            'exp' => $time + (60 * 60),
            'data' => $data
        ];
        return JWT::encode($token, JWT_KEY);
    }

    public static function decode_token($jwt)
    {
        try {

            if (empty(JWT_KEY)) {
                if (!file_exists('Config/.jwt_key')) {
                    JsonResponse::sendResponse(['message' => 'Missing file .jwt_key'], HTTPStatusCodes::InternalServerError);
                }
                JsonResponse::sendResponse(['message' => 'JWT key is empty'], HTTPStatusCodes::InternalServerError);
            }

            $time = time();
            $decoded = JWT::decode($jwt, JWT_KEY, ['HS256']);
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

        self::convert_endpoint($controller, $action, $id);

        self::call_action($controller, $action, $id);
    }

    public function startup()
    {
        self::define_constants(['DIR' => __DIR__]);
        self::load_php_functions();
        self::create_directories();
    }

    private static function load_composer()
    {
        $path = getcwd() . "/Lib/vendor/autoload.php";
        if (!file_exists($path)) {
            JsonResponse::sendResponse(['message' => 'Composer is not installed.'], HTTPStatusCodes::InternalServerError);
        }
        require_once($path);
    }

    private static function load_php_functions()
    {
        ob_start();
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PATCH, DELETE');
        header('Access-Control-Allow-Headers: Content-Type');
        register_shutdown_function(function () {
            if (error_get_last()) {
                JsonResponse::sendResponse(['message' => 'A fatal error ocurred.'], HTTPStatusCodes::InternalServerError);
            }
        });
        include_once("MySQL.php");
        setcookie('XDEBUG_SESSION', 'PHPSTORM');
        error_reporting(E_ALL);
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

        createConfig();
        createFile('.htaccess');
        createFile('index.php');

        shell_exec('cd .. && composer install');

        JsonResponse::sendResponse([], HTTPStatusCodes::OK);
    }

    private static function define_constants($config)
    {
        global $_PATCH;

        define('REQUEST_METHOD', System::isset_get($_SERVER['REQUEST_METHOD']));
        define('DEBUG_MODE', preg_match('/Mozilla/', System::isset_get($_SERVER['HTTP_USER_AGENT'])) != 1);
        define('JWT_KEY', file_exists('Config/.jwt_key') ? file_get_contents('Config/.jwt_key') : null);
        define('DIR', $config['DIR']);

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
                JsonResponse::sendResponse([]);
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
            $value = trim($value);
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

    public static function log_error(array $error)
    {
        try {
            $mysql = new MySQL();
            $error = $mysql->escape_string(print_r($error, true));
            $mysql->create_table("_errores", [
                new TableColumn('id', ColumnTypes::BIGINT, 20, true, null, true, true),
                new TableColumn('error', ColumnTypes::VARCHAR, 2000, true),
                new TableColumn('fecha', ColumnTypes::TIMESTAMP, 0, true, 'current_timestamp')
            ]);
            $mysql->query("insert into _errores(error) values('$error')");
        } catch (Exception $ex) {
            die(print_r($ex, true));
        }
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
        ob_clean();
        die(self::$json);
    }

    private function json_encode()
    {
        $response = $this->encode_items($this->response);
        $code = $this->code;
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
        if (!empty($this->error)) {
            $error = $this->error;
            System::log_error(compact('status', 'code', 'response', 'error'));
        }
    }

    private function encode_items($array)
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->encode_items($value);
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
    const ServiceUnavailable = 503;
}