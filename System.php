<?php

use Firebase\JWT\JWT;
use ForceUTF8\Encoding;
use mikehaertl\wkhtmlto\Pdf;
use Model\ColumnTypes;
use Model\MySQL;
use Model\TableColumn;
use PHPMailer\PHPMailer\PHPMailer;

class System
{
    /**
     * @var string
     */
    private static $idioma;
    /**
     * @var DOMDocument
     */
    private $dom;

    static function getRealIP()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP']))
            return $_SERVER['HTTP_CLIENT_IP'];

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))
            return $_SERVER['HTTP_X_FORWARDED_FOR'];

        return $_SERVER['REMOTE_ADDR'];
    }

    static function sessionCheck(string $name)
    {
        session_start();
        return $_SESSION[$name] ?? null;
    }

    static function decrypt(string $value_encrypted)
    {
        $value_encrypted = html_entity_decode($value_encrypted);
        return openssl_decrypt($value_encrypted, "AES-256-CBC", CONFIG['seed'], 0, str_pad(CONFIG['seed'], 16, 'X', STR_PAD_LEFT));
    }

    public static function encrypt(string $value)
    {
        return openssl_encrypt($value, "AES-256-CBC", CONFIG['seed'], 0, str_pad(CONFIG['seed'], 16, 'X', STR_PAD_LEFT));
    }

    public static function query_log(string $sql)
    {
        $date = date('Y-m-d H:i:s');
        $request = ($_SERVER['REQUEST_URI'] ?? NULL) ? trim(stristr($_SERVER['REQUEST_URI'], 'api'), '/') : '';
        if (defined('CONFIG')) {
            $path = __DIR__ . '/../Logs/' . CONFIG['project']['code'] . '/';
        } else {
            $path = __DIR__ . '/../Logs/';
        }
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        file_put_contents($path . date('Y-m-d') . '.sql', "#$date $request\n" . $sql . "\n\n", FILE_APPEND);
    }

    public static function log(string $message)
    {
        $date = date('Y-m-d H:i:s');
        $request = trim(stristr($_SERVER['REQUEST_URI'], 'api'), '/');
        if (defined('CONFIG')) {
            $path = __DIR__ . '/../Logs/' . CONFIG['project']['code'] . '/';
        } else {
            $path = __DIR__ . '/../Logs/';
        }
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        file_put_contents($path . 'custom' . date('Y-m-d') . '.log', "#$date $request\n" . $message . "\n\n", FILE_APPEND);
    }

    /**
     * @param array $to
     * @param string $body
     * @param array $options
     * @throws CoreException
     */
    public static function sendEmail(array $to, string $body, array $options)
    {
        try {
            $mail = new PHPMailer(true);

            $mail->Username = CONFIG['email']['username'];
            $mail->Password = CONFIG['email']['password'];
            $mail->Host = CONFIG['email']['host'] ?? "smtp.gmail.com"; // GMail
            $mail->Port = CONFIG['email']['port'] ?? 465;

            $mail->SMTPDebug = 1;
            $mail->SMTPSecure = 'ssl';
            $mail->IsSMTP(); // use SMTP
            $mail->SMTPAuth = true;
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';

            $mail->setFrom(CONFIG['email']['username'], CONFIG['email']['name']);

            foreach ($to as $item) {
                if (strpos($_SERVER['HTTP_USER_AGENT'], 'Postman') !== false) {
                    $item['email'] = CONFIG['email']['username'];
                }
                $mail->addAddress($item['email'], $item['name'] ?? null);
            }

            $mail->Subject = $options['subject'] ?? null;
            $mail->Body = $body;
            $mail->AltBody = $options['altbody'] ?? $body;

            foreach ($options['attachments'] ?? [] as $attachment) {
                $mail->addAttachment($attachment['path'], $attachment['name'] . '.pdf');
            }

            $mail->send();
        } catch (\PHPMailer\PHPMailer\Exception $exception) {
            JsonResponse::sendResponse($exception->getMessage(), HTTPStatusCodes::ServiceUnavailable, $exception->getTrace());
        }
    }

    public static function utf8($value)
    {
        if (mb_detect_encoding(utf8_decode($value)) === 'UTF-8') {
            // Double encoded, or bad encoding
            $value = utf8_decode($value);
        }

        return Encoding::toUTF8($value);
    }

    public static function getHost()
    {
        return $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . BASENAME;
    }

    public static function generatePDF(array $pages, string $output)
    {
        $pdf = new Pdf();

        foreach ($pages as $page) {
            $pdf->addPage($page);
        }

        // Save the PDF
        if (!$pdf->saveAs($output)) {
            $error = $pdf->getError();
            JsonResponse::sendResponse($error, HTTPStatusCodes::InternalServerError);
        }
    }

    /**
     * @param $options
     * @return mixed
     * @throws Exception
     */
    public static function curl($options)
    {
        $path = __DIR__ . '/../settings.dev.json';
        if (!file_exists($path)) {
            $path = __DIR__ . '/../settings.json';
        }
        $settings = self::json_decode(file_get_contents($path), true);

        $curl = curl_init();

        $headers = [
            "Cookie: XDEBUG_SESSION=PHPSTORM"
        ];
        if ($options['method']) {
            if ($options['method'] !== 'GET' && $options['method'] !== 'POST') {
                $headers[] = "Content-Type: application/json";
            }
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $settings['apiUrl'] . ($options['url'] ?? ''),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $options['method'] ?? "GET",
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($options['data'] ?? null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $options['data']);
        }

        $json = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);


        if ($error) {
            JsonResponse::sendResponse($error, HTTPStatusCodes::InternalServerError);
        } elseif (!self::isJson($json)) {
            JsonResponse::sendResponse($json, HTTPStatusCodes::InternalServerError);
        }

        $result = self::json_decode($json, true);

        if ($result['code'] >= 400) {
            JsonResponse::sendResponse($result['response']['message'], $result['code']);
        }

        return $result['data'];
    }

    public static function redirect(string $path = '')
    {
        $path = ltrim($path, '/');
        header('Location: ' . rtrim(BASENAME, '/') . '/' . $path);
    }

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
                JsonResponse::sendResponse("No existe el archivo de configuración $ruta", HTTPStatusCodes::InternalServerError);
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
        /*$salt = 9734 + $id;
        return 'DAR' . $salt;*/
    }

    public static function decode_id(string $id)
    {
        /*$end_decoded = str_replace('DAR', '', strtoupper($base64));
        if (!empty($end_decoded) && !intval($base64)) {
            if (intval($end_decoded)) {
                $base64 = $end_decoded - 9734;
            }
            return $end_decoded;
        }
        return $base64;*/
        $base64 = base64_decode($id);
        $end_decoded = strstr($base64, '=');
        if (!empty($base64) && $end_decoded) {
            $end_decoded = trim($end_decoded, '=');
            if (!empty($end_decoded)) {
                if (!is_nan($end_decoded)) {
                    return intval($end_decoded);
                }
                return $end_decoded;
            }
        } elseif (!is_nan((float)$id)) {
            return intval($id);
        }
        return null;
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

    public static function upload_file(array $file, string $destination): bool
    {
        if (empty($file['tmp_name'])) {
            JsonResponse::sendResponse('Filename cannot be empty.');
        }

        if (!file_exists(dirname($destination))) {
            if (!mkdir(dirname($destination), 0777, true)) {
                JsonResponse::sendResponse('Directory could not be created.', HTTPStatusCodes::InternalServerError);
            }
            if (!chmod(dirname($destination), 0777)) {
                JsonResponse::sendResponse('Directory could not be changed permissions.', HTTPStatusCodes::InternalServerError);
            }
        }

        if (!copy($file['tmp_name'], $destination)) {
            JsonResponse::sendResponse('File could not be moved.', HTTPStatusCodes::InternalServerError);
        }

        define('FILE', $destination);

        return true;
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

    public static function encode_token(array $data, array $options = [])
    {
        try {
            $jwt_key = self::get_jwt_key();

            $time = time();
            $payload = [
                'iat' => $time,
                'data' => $data
            ];

            if (!empty($options['exp_hours'])) {
                $hours = $options['exp_hours'];
                $expiration = (60 * 60) * $hours; //12 Hours
                $payload['exp'] = $time + $expiration;
            }

            return JWT::encode($payload, $jwt_key);
        } catch (DomainException $ex) {
            JsonResponse::sendResponse($ex->getMessage(), HTTPStatusCodes::InternalServerError);
        }
    }

    private static function get_jwt_key()
    {
        if (empty(JWT_KEY)) {
            if (!file_exists(DIR . '/Config/.jwt_key')) {
                JsonResponse::sendResponse('Missing file .jwt_key', HTTPStatusCodes::InternalServerError);
            }
            JsonResponse::sendResponse('JWT key is empty', HTTPStatusCodes::InternalServerError);
        }
        return JWT_KEY;
    }

    public static function decode_token($jwt)
    {
        try {
            if (empty($jwt)) {
                JsonResponse::sendResponse('Empty token.');
            }

            $jwt_key = self::get_jwt_key();

            $time = time();
            $decoded = JWT::decode($jwt, $jwt_key, ['HS256']);
            if (!empty($decoded->exp) && $decoded->exp <= $time) {
                JsonResponse::sendResponse('The token has expired.');
            }
            return json_decode(json_encode($decoded), true)['data'];
        } catch (Firebase\JWT\ExpiredException $ex) {
            JsonResponse::sendResponse($ex->getMessage());
        } catch (Firebase\JWT\SignatureInvalidException $ex) {
            JsonResponse::sendResponse($ex->getMessage());
        } catch (UnexpectedValueException $ex) {
            JsonResponse::sendResponse('Invalid token.');
        } catch (DomainException $ex) {
            JsonResponse::sendResponse('Invalid token.');
        }
    }

    public static function init($config)
    {
        self::define_constants($config);

        self::load_php_functions($config);

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
        define('ENVIRONMENT', 'init');
        self::define_constants(['DIR' => __DIR__ . '/']);
        self::load_php_functions();
        self::create_directories();
    }

    private static function load_composer()
    {
        if (ENVIRONMENT !== 'www') {
            $pathLib = DIR . "/Lib/vendor/autoload.php";
            $path = DIR . "/vendor/autoload.php";
            if (!file_exists($pathLib)) {
                JsonResponse::sendResponse('Composer is not installed on Lib.', HTTPStatusCodes::InternalServerError);
            }
            if (!file_exists($path)) {
                JsonResponse::sendResponse('Composer is not installed.', HTTPStatusCodes::InternalServerError);
            }
            require_once($pathLib);
            require_once($path);
        }
    }

    private static function load_php_functions($config = [])
    {
        ob_start();
        setcookie('XDEBUG_SESSION', 'PHPSTORM');
        if (ENVIRONMENT == 'web') {
            header('Content-Type: application/json');
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: PUT, POST, GET, OPTIONS, PATCH, DELETE');
            header('Access-Control-Allow-Headers: X-Requested-With, Content-Type, dataType, contenttype, processdata');
        }
        set_exception_handler(function ($exception) {
            ob_clean();
            $status = 'exception';
            $code = $exception->getCode() ?: 500;
            http_response_code($code);
            $response = ['message' => $exception->getMessage()];
            $error = null;
            if ($code >= 500) {
                $error = $exception->getTrace();
            }
            die(json_encode(compact('status', 'code', 'response', 'error')));
        });
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
                            ob_clean();
                            $status = 'error';
                            $code = HTTPStatusCodes::InternalServerError;
                            $response = null;
                            die(json_encode(compact('status', 'code', 'response', 'error')));
                            break;
                    }
                }
            }
        });

        $pathMySQL = "MySQL.php";
        require_once($pathMySQL);

//        error_reporting(E_ALL ^ E_DEPRECATED);
        ini_set('memory_limit', '2048M');
        ini_set('display_errors', 'On');
        ini_set('always_populate_raw_post_data', '-1');
        ini_set('max_execution_time', '300');
        date_default_timezone_set($config['TIMEZONE'] ?? 'America/Mexico_City');
        spl_autoload_register(function ($class) {
            if (defined('WEBDIR') && !defined('DIR')) {
                define('DIR', WEBDIR . '/api');
            }
            $file = str_replace('\\', '/', $class);
            $path = DIR . "/$file.php";
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

        if (file_exists(__DIR__ . '/../offline')) {
            JsonResponse::sendResponse('We are updating the app, please be patient.', HTTPStatusCodes::ServiceUnavailable);
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
//            file_put_contents(DIR . '/../Config/database.json', json_encode(["host" => "", "username" => "", "passwd" => "", "dbname" => ""]));
            file_put_contents(DIR . '/../Config/.gitignore', join("\n", ['.jwt_key', 'database.json', '*.json', '!default.json']));
//            copy(DIR . '/../Config/database.json', DIR . '/../Config/database.prod.json');
            @chmod(DIR . '/../Config/.jwt_key', 0777);
//            @chmod(DIR . '/../Config/database.json', 0777);
//            @chmod(DIR . '/../Config/database.prod.json', 0777);
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


        if (!defined('ENVIRONMENT')) {
            if ($config['ENV'] ?? null)
                define('ENVIRONMENT', $config['ENV']);
            else
                define('ENVIRONMENT', isset($_SERVER['SHELL']) || isset($_SERVER['argv']) ? 'cli' : 'web');
        }

        if (!defined('REQUEST_METHOD'))
            define('REQUEST_METHOD', System::isset_get($_SERVER['REQUEST_METHOD']));

        if (!defined('DEBUG_MODE'))
            define('DEBUG_MODE', ENVIRONMENT == 'cli' || preg_match('/Mozilla/', $_SERVER['HTTP_USER_AGENT'] ?? '') != 1);

        if (!defined('DIR'))
            define('DIR', $config['DIR']);

        if (!defined('JWT_KEY'))
            define('JWT_KEY', file_exists(DIR . '/Config/.jwt_key') ? file_get_contents(DIR . '/Config/.jwt_key') : null);

        if (!defined('BASENAME')) {
            define('BASENAME', dirname($_SERVER['SCRIPT_NAME']));
        }

        if (ENVIRONMENT !== 'www') {
            $project = defined('PROJECT') ? PROJECT : getenv('PROJECT');
            if (empty($project)) {
                $project_config = file_exists(DIR . '/Config/default.json')
                    ? file_get_contents(DIR . '/Config/default.json')
                    : null;
                if ($project_config) {
                    $project_config = json_decode($project_config, true);
                    define('PROJECT', $project_config['project']['code']);
                    self::define_constants($config);
                } else {
                    $project_config = [
                        "project" => [
                            "name" => "default",
                            "code" => "default"
                        ],
                        "database" => [
                            "host" => "",
                            "username" => "",
                            "passwd" => "",
                            "dbname" => ""
                        ],
                        'email' => [
                            'username' => '',
                            'password' => ''
                        ]
                    ];
                    if (ENVIRONMENT == 'init') {
                        return;
                    }
                    file_put_contents(DIR . '/Config/default.json', json_encode($project_config));

                    header('Content-Type: application/json');
                    JsonResponse::sendResponse("default.json not found", HTTPStatusCodes::InternalServerError);
                }
            } else {
                $project_config = file_exists(DIR . '/Config/' . $project . '.json')
                    ? file_get_contents(DIR . '/Config/' . $project . '.json')
                    : null;
                if ($project_config) {
                    define('CONFIG', json_decode($project_config, true));
                } else {
                    header('Content-Type: application/json');
                    JsonResponse::sendResponse("Config not found for project '$project'", HTTPStatusCodes::InternalServerError);
                }
            }
        }

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
        $request = trim(stristr($_SERVER['REQUEST_URI'], 'api'), '/');
        if (strpos($request, '?') !== false) {
            $request = stristr($request, '?', true);
        }
        preg_match_all('/\/\b([a-z-]+?)\/+([a-z-]+)(?:\W+(.+))?/i', $request, $request, PREG_SET_ORDER, 0);
        if (!empty($request)) {
            $request = array_splice($request[0], 1);
            [$controller, $action] = $request;
            $id = System::isset_get($request[2]);
            if (!empty($id)) {
                $id = System::decode_id($id);
            }
        } else {
            $request = explode('/', trim(stristr($_SERVER['REQUEST_URI'], 'api/'), '/'));
            $controller = $request[0];
            $action = $request[1] ?? null;
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
                    JsonResponse::sendResponse('Request Method PATCH needs an ID to work', HTTPStatusCodes::BadRequest);
                }
                break;
        }
        global $_PATCH;
        $response = null;
        $namespace = "Controller\\" . ucfirst($controller);
        if (class_exists($namespace)) {
            /** @var $class Controller */
            $class = new $namespace();
            $response = $class->call($action, [$id]);

            $message = 'Completed.';
            if (!$response) {
                JsonResponse::sendResponse($message, HTTPStatusCodes::OK);
            } else if (is_scalar($response)) {
                JsonResponse::sendResponse($response, HTTPStatusCodes::OK);
            } else {
                $data = $response;
                JsonResponse::sendResponse($message, HTTPStatusCodes::OK, $data);
            }
        }
        if ($controller == 'api') {
            switch ($action) {
                default:
                case "version":
                    $name = CONFIG['project']['name'];
                    $version = VERSION;
                    JsonResponse::sendResponse('Completed.', HTTPStatusCodes::OK, compact('name', 'version'));
                    break;
                case "logs":
                    $path = DIR . '/Logs/' . CONFIG['project']['code'] . '/' . date('Y-m-d', strtotime(System::isset_get($_GET['date'], date('Y-m-d')))) . '.log';
                    if (!file_exists($path)) {
                        file_put_contents($path, '');
                    }
                    $log = explode("\n", file_get_contents($path));
                    rsort($log);
                    $log = array_filter($log);
                    array_walk($log, function (&$entry) {
                        $entry = preg_split('/\] \[|] |^\[/m', $entry);
                        $entry = array_values(array_filter($entry));
                        array_walk($entry, function (&$value) use ($entry) {
                            if (System::isset_get($_GET['errors']) === 'true' && count($entry) < 5) {
                                $value = null;
                            }
                            $value = trim($value, '[] ');
                            if (self::isJson($value)) {
                                $value = self::json_decode($value, true);
                            }
                        });
                        $entry = array_values(array_filter($entry));
                    });
                    $log = array_values(array_filter($log));
                    JsonResponse::sendResponse(compact('log'), HTTPStatusCodes::OK);
                    break;
                case "decodeToken":
                    if (REQUEST_METHOD === 'POST' && $_POST['token']) {
                        $data = System::decode_token($_POST['token']);
                        JsonResponse::sendResponse('Completed.', HTTPStatusCodes::OK, compact('data'));
                    } else {
                        JsonResponse::sendResponse("Endpoint not found.  [$namespace]", HTTPStatusCodes::NotFound);
                    }
                    break;
            }
        } else {
            JsonResponse::sendResponse("Endpoint not found.  [$namespace]", HTTPStatusCodes::NotFound);
        }
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
            if (!isset($array[$key]) || empty($array[$key]) && $array[$key] !== 0) {
                $empty_values .= $key . ', ';
            }
        }
        $empty_values = trim($empty_values, ', ');
        if (!empty($empty_values)) {
            JsonResponse::sendResponse($message . ' ' . "[$empty_values]", HTTPStatusCodes::BadRequest);
        }

        foreach ($intersect as $key => $value) {
            $value = is_string($value) ? trim($value) : $value;
            if (empty($value) and $value != 0) {
                $empty_values .= $key . ', ';
            }
        }
        $empty_values = trim($empty_values, ', ');
        if (!empty($empty_values)) {
            JsonResponse::sendResponse($message . ' ' . "[$empty_values]", HTTPStatusCodes::BadRequest);
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

    public static function request_log()
    {
        $explode = explode('/', $_SERVER['REQUEST_URI']);
        if (end($explode) === 'errors') {
            return;
        }

        $data = '[' . date('Y-m-d H:i:s') . '] ';
        $data .= '[' . $_SERVER['HTTP_HOST'] . '] ';
        $data .= '[' . $_SERVER['REQUEST_METHOD'] . '] ';
        $data .= '[' . strstr($_SERVER['REQUEST_URI'], 'api/') . ']';

        $data .= ' ' . preg_replace('/\s/', '', file_get_contents('php://input'));
        if ($_POST) {
            $data .= ' ' . preg_replace('/\s/', '', json_encode($_POST));
        }
        if ($_FILES) {
            foreach ($_FILES as $files) {
                if (is_array($files['name'])) {
                    $data .= ' ' . implode(',', $files['name']);
                } else {
                    $data .= ' ' . $files['name'];
                }
            }
        }

        if (defined('CONFIG')) {
            $path = __DIR__ . '/../Logs/' . CONFIG['project']['code'] . '/' . date('Y-m-d') . '.log';
        } else {
            $path = __DIR__ . '/../Logs/' . date('Y-m-d') . '.log';
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true)) {
                $code = HTTPStatusCodes::InternalServerError;
                $status = 'error';
                $error = [
                    'message' => "Error creating dir [$dir]"
                ];
                JsonResponse::sendResponse(compact('status', 'code', 'response', 'error'), $code);
            }
            @chmod($dir, 0777);
        }

        if (!file_put_contents($path, $data . "\n", FILE_APPEND)) {
            if (file_exists($path)) {
                if (!unlink($path)) {
                    $code = HTTPStatusCodes::InternalServerError;
                    $status = 'error';
                    $response = [
                        'message' => "Error deleting file [$path]"
                    ];
                    System::log_error(compact('status', 'code', 'response'));
                }
                self::request_log();
            }
        }
    }

    public static function log_error(array $response)
    {
        global $_PUT, $_PATCH;

        if (!defined('REQUEST_METHOD'))
            define('REQUEST_METHOD', $_SERVER['REQUEST_METHOD']);

        $data = '[' . date('Y-m-d H:i:s') . '] ';
        $data .= '[' . $_SERVER['REQUEST_METHOD'] . '] ';
        if (ENVIRONMENT == 'web') {
            $data .= '[' . strstr($_SERVER['REQUEST_URI'], 'api/') . '] ';
        } elseif (ENVIRONMENT == 'cli') {
            $data .= '[' . System::isset_get($_SERVER['argv'][5]) . '] ';
        }
        $data .= '[' . $response['code'] . '] ';
        $data .= '[' . json_encode($response['response']) . '] ';
        $data .= json_encode([
                'GET' => $_GET,
                'POST' => $_POST,
                'PUT' => $_PUT,
                'PATCH' => $_PATCH,
            ][REQUEST_METHOD] ?? ENVIRONMENT);

        if (defined('CONFIG')) {
            $path = __DIR__ . '/../Logs/' . CONFIG['project']['code'] . '/' . date('Y-m-d') . '.log';
        } else {
            $path = __DIR__ . '/../Logs/' . date('Y-m-d') . '.log';
        }

        if (!file_put_contents($path, $data . "\n", FILE_APPEND)) {
            if (file_exists($path)) {
                if (!unlink($path)) {
                    $code = HTTPStatusCodes::InternalServerError;
                    $status = 'error';
                    $response = [
                        'message' => "Error deleting file [$path]"
                    ];
                    $error = [
                        'message' => "Error deleting file [$path]"
                    ];
                    self::log_error(compact('status', 'code', 'response', 'error'));
                    return;
                }
                self::log_error($response);
            }
        }
    }

    /**
     * @param array $constants
     * @return void
     */
    public function init_web(array $constants)
    {
        try {
            define('WEBDIR', $constants['WEBDIR']);
            define('ENVIRONMENT', 'www');
            if (!defined('JWT_KEY'))
                define('JWT_KEY', file_exists(WEBDIR . '/../api/Config/.jwt_key') ? file_get_contents(WEBDIR . '/../api/Config/.jwt_key') : null);

            if (!file_exists(WEBDIR . '/config.json')) {
                die('config.json does not exist');
            }

            [
                'project' => $project,
                'entry' => $entry,
                'theme' => $dir,
                'default' => $default,
                'modules' => $module_list,
                'breadcrumbs' => $breadcrumbs
            ] = json_decode(file_get_contents(WEBDIR . '/config.json'), true);

            define('BREADCRUMBS', $breadcrumbs);

            if ($constants['BASENAME']) {
                define('BASENAME', $constants['BASENAME']);
            } else {
                define('BASENAME', '/' . (trim(dirname($_SERVER['SCRIPT_NAME']), '/') ?: '.') . '/');
            }

            self::load_php_functions();
            if (file_exists(__DIR__ . "/vendor/autoload.php")) {
                require __DIR__ . "/vendor/autoload.php";
            }

            $this->dom = new DOMDocument();
            libxml_use_internal_errors(true);

            $module_file = System::isset_get($_GET['module'], $default) . (System::isset_get($_GET['action']) ? '/' . $_GET['action'] : '');
            if (strpos($module_file, '/')) {
                $module_file_array = explode('/', $module_file);

                $basename = explode('/', BASENAME);
                $basename = array_values(array_filter($basename));

                $module_file_intersect = array_diff($module_file_array, $basename);
                $module_file_intersect = array_values($module_file_intersect);

                $module_file = implode('/', $module_file_intersect);
            }

            $file = $module_list[$module_file]['file'] ?? $entry;

            if (!file_exists($dir . $file)) {
                die($dir . $file . ' does not exist');
            }

            $this->dom->loadHTMLFile($dir . $file);

            /** @var DOMElement $link */
            foreach ($this->dom->getElementsByTagName('link') as $link) {
                $old_link = $link->getAttribute("href");
                if (strpos($old_link, 'http') !== false) {
                    continue;
                }
                if ($old_link === 'manifest.json') {
                    $link->setAttribute('href', BASENAME . $old_link);
                } else {
                    $link->setAttribute('href', BASENAME . $dir . $old_link);
                }
            }
            foreach ($this->dom->getElementsByTagName('div') as $link) {
                $old_link = $link->getAttribute("ui-include");
                if ($old_link) {
                    $old_link = str_replace("'", '', $old_link);
                    $link->setAttribute('ui-include', "'" . BASENAME . $dir . $old_link . "'");
                }
            }
            foreach ($this->dom->getElementsByTagName('img') as $link) {
                $old_link = $link->getAttribute("src");
                $link->setAttribute('src', BASENAME . $dir . $old_link);
            }
            foreach ($this->dom->getElementsByTagName('script') as $link) {
                $old_link = $link->getAttribute("src");
                if ($old_link) {
                    if (strpos($old_link, 'http') !== false) {
                        continue;
                    }
                    $link->setAttribute('src', BASENAME . $dir . $old_link);
                }
            }

            if ($this->dom->getElementsByTagName('title')->item(0)) {
                $this->dom->getElementsByTagName('title')->item(0)->nodeValue = $project;
            }
            if ($this->dom->getElementById('project-title')) {
                $this->dom->getElementById('project-title')->nodeValue = $project;
            }
            if ($this->dom->getElementById('project-user')) {
                session_start();
                try {
                    $user = System::curl(['url' => 'decodeToken', 'method' => 'POST', 'data' => ['token' => $_SESSION['user_token']]])['data'];
                    $this->dom->getElementById('project-user')->nodeValue = $user['name'] ?? 'User not logged in';
                } catch (CoreException $exception) {
                    //do nothing
                }
                session_write_close();
            }

            if ($this->dom->getElementById('favicon')) {
                $favicon = $this->dom->getElementById('favicon');
                $favicon->setAttribute('href', 'favicon.png');
            }

            if ($this->getElementsByClass($this->dom, 'img', 'project-img')) {
                $imgs = ($this->getElementsByClass($this->dom, 'img', 'project-img'));
                foreach ($imgs as $img) {
                    $img->setAttribute('src', BASENAME . 'logo.png');
                }
            }

            if ($file != $entry) {
                $fragment = $this->dom->createDocumentFragment();
                $fragment->appendXML(<<<html
<script src="assets/js/$module_file.js"></script>
html
                );

                $body = $this->dom->getElementsByTagName('body');
                $body->item(0)->appendChild($fragment);
            } else {
                $fragment = $this->dom->createDocumentFragment();

                $module_list = $module_list ?: [['name' => 'Dashboard', 'icon' => 'dashboard', 'href' => 'dashboard', 'disabled' => '']];
                define('MODULES', $module_list);

                foreach ($module_list as $module) {
                    ['name' => $name, 'icon' => $icon, 'href' => $href] = $module;
                    $disabled = System::isset_get($module['disabled']) ? 'disabled' : '';
                    $hidden = System::isset_get($module['hidden']) ? 'none' : 'unset';
                    $file = System::isset_get($module['file'], $entry);
                    if ($file != $entry) {
                        continue;
                    }
                    $fragment->appendXML(<<<html
<li class="nav-item" style="display: $hidden">
    <a href="$href" class="$disabled nav-link" style="display: flex; align-items: center">
        <span class="nav-icon">
            <i class="material-icons">$icon</i>
        </span>
        <span class="nav-text">$name</span>
    </a>
</li>
html
                    );
                }
                $modules = $this->dom->createElement('ul');
                $modules->setAttribute('class', 'nav');
                $modules->appendChild($fragment);

                /** @var DOMElement $nav */
                $nav = $this->dom->getElementsByTagName('nav')[0];
                if ($nav) {
                    if ($nav->parentNode) {
                        $modules->setAttribute('id', $nav->getAttribute('id'));
                        $modules->setAttribute('class', $nav->getAttribute('class'));
                        $nav->parentNode->replaceChild($modules, $nav);
                    }
                }

                $this->load_module($module_file);
            }

            libxml_clear_errors();
            $this->print_page();
        } catch (DOMException $exception) {
            $message = $exception->getMessage();
            echo <<<html
<script>
alert('$message');
</script>
html;
        }
    }

    function getElementsByClass(&$parentNode, $tagName, $className)
    {
        $nodes = array();

        $childNodeList = $parentNode->getElementsByTagName($tagName);
        for ($i = 0; $i < $childNodeList->length; $i++) {
            $temp = $childNodeList->item($i);
            if (stripos($temp->getAttribute('class'), $className) !== false) {
                $nodes[] = $temp;
            }
        }

        return $nodes;
    }

    public function load_module($file)
    {
        $pathinfo = pathinfo($file);//['extension'];
        if (!($pathinfo['extension'] ?? null)) {
            if ($file[strlen($file) - 1] === '/') {
                $file .= 'index';
            }
            $file .= '.php';
        }
        $module_path = WEBDIR . '/modules/' . $file;
        if (!file_exists($module_path)) {
            $code = HTTPStatusCodes::NotFound;
            $status = 'error';
            $response = [
                'message' => "File not found [$module_path]"
            ];
            System::log_error(compact('status', 'code', 'response'));
            if ($_SERVER['REQUEST_URI'] != BASENAME) {
                System::redirect('/');
            }
        }

        ob_start();
        include $module_path;
        $contents = ob_get_contents();
        ob_end_clean();

        $href = strstr($file, '.php', true);
        if (strpos($href, '/') !== false) {
            $href = strstr($href, '/', true);
        }
        $module = ucfirst(strtolower(MODULES[$href]['name'] ?? ''));

        $breadcrumbs = BREADCRUMBS ? '' : 'd-none';
        $chunk = <<<html
<div class="row justify-content-center">
    <div class="col-12" style="padding: 0 25px">
        <p class="text-left breadcrumbs $breadcrumbs">
            <span class="text-muted">Usted se encuentra en:</span> <span>$module</span>
        </p>
        <div class="container-fluid">
            $contents
        </div>
    </div>
</div>
html;

        $fragment = new DOMDocument();
        $fragment->loadHTML(mb_convert_encoding($chunk, 'HTML-ENTITIES', 'UTF-8'), 8192 | 4);

        $module = $this->dom->createElement('div');
        $module->setAttribute('id', 'view');
        $module->setAttribute('class', 'app-body');
        $module->appendChild($this->dom->importNode($fragment->documentElement, true));

        if ($this->dom->getElementById('view')) {
            $view = $this->dom->getElementById('view');
            $class = $view->getAttribute('class');
            $module->setAttribute('class', $class);
            if ($view->parentNode) {
                $view->parentNode->replaceChild($module, $view);
            }
        }
    }

    public function print_page()
    {
        echo $this->dom->saveHTML();
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

    public function call(string $action, array $arguments)
    {
        $name = System::isset_get($this->_methods[REQUEST_METHOD][$action]);
        if ($name) {
            return $this->$name(...($arguments ?: [null]));
        } else {
            $name = $action;
        }
        JsonResponse::sendResponse("Endpoint not found. [$name]", HTTPStatusCodes::NotFound);
    }

    /*public function __call($action, $arguments)
    {
        if (ENVIRONMENT == 'web') {
            $name = System::isset_get($this->_methods[REQUEST_METHOD][$action]);
            if ($name) {
                return $this->$name(...$arguments);
            }
            JsonResponse::sendResponse("Endpoint not found. [$name]", HTTPStatusCodes::NotFound);
        }
        return $this->$action(...$arguments);
    }*/

    private function allowed_methods(array $methods)
    {
        if (ENVIRONMENT == 'web') {
            if (!isset($methods[REQUEST_METHOD])) {
                JsonResponse::sendResponse('Method Not Allowed', HTTPStatusCodes::MethodNotAllowed);
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

    /**
     * @param string $message
     * @param int $code
     * @param array $data
     * @throws CoreException
     */
    static function sendResponse(string $message, $code = 400, array $data = [])
    {
        if ($code) {
            http_response_code($code);
        } else {
            $code = http_response_code();
        }

        $response = compact('data');
        $response = self::encode_items(compact('message', 'data', 'code', 'response'));

        if ($code < HTTPStatusCodes::BadRequest) {
            ob_clean();
            die(json_encode($response));
        }

        $status = 'error';
        $error = error_get_last();

        if (defined('FILE')) unlink(FILE);

        System::log_error(compact('status', 'code', 'response', 'error'));

        throw new CoreException($response['message'] ?? $response['error'], $code, $data);
    }

    private function send_response()
    {
        //Log error in file

        /* if (ENVIRONMENT == 'web') {
             ob_clean();
             die(self::$json);
         } else if (ENVIRONMENT == 'www') {
             ob_clean();
 //            var_dump(json_decode(self::$json, true));
 //            exit;
             header('Content-Type: application/json');
             die(self::$json);
         }
         $exception = json_decode(self::$json, true);

         if ($exception['code'] !== HTTPStatusCodes::OK) {
             $error = '';
             if (is_array($exception['error'])) {
                 $error = $exception['error']['message'];
             } elseif (System::isset_get($exception['response']['message'])) {
                 $error = $exception['response']['message'];
             } elseif (System::isset_get($exception['response']['error'])) {
                 $error = '[' . $exception['response']['type'] . '] ' . $exception['response']['error'];
             }

             throw new Exception($error, $exception['code']);
         } else {
             die($exception['status']);
         }*/
    }

    private function json_encode()
    {
        if (!defined('DEBUG_MODE'))
            define('DEBUG_MODE', true);

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

    private static function encode_items($array)
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = self::encode_items($value);
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

class CoreException extends Exception
{
    public $data;

    public function __construct($message = "", $code = 0, array $data = null)
    {
        $this->data = $data;
        parent::__construct($message, $code);
    }
}
