<?php

use Firebase\JWT\JWT;
use ForceUTF8\Encoding;
use mikehaertl\wkhtmlto\Pdf;
use Model\MySQL;
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
    private static $dom;
    /**
     * @var int
     */
    private static $error_code;
    private static $error_message;
    /**
     * @var mixed
     */
    private static $error_button;

    public static function is_email($email): bool
    {
        preg_match('/^([\w-]+(?:\.[+\w-]+)*)@((?:[\w-]+\.)*\w[\w-]{0,66})\.([a-z]{2,6}(?:\.[a-z]{2})?)$/i', $email, $matches);
        return !!($matches);
    }

    public static function getRealIP()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP']))
            return $_SERVER['HTTP_CLIENT_IP'];

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))
            return $_SERVER['HTTP_X_FORWARDED_FOR'];

        return $_SERVER['REMOTE_ADDR'];
    }

    public static function sessionCheck(string $name, string $token = null)
    {
        if (ENVIRONMENT !== 'www') {
            throw new CoreException('sessionCheck can only be used on web.');
        }
        session_start();
        $check = $_SESSION[$name] ?? $token ?? null;

        $module = $_GET['module'];
        if (!$check && ($module !== 'login' && $module !== 'signup')) {
            System::redirect('login');
        }

        $user = System::curlDecodeToken($check);
        $user['permissions'] = $user['permissions'] ? System::json_decode($user['permissions']) : null;
        $user['token'] = $check;

        $_SESSION['modules'] = [];
        foreach ($user['permissions'] ?? [] as $key => $permissions) {
            if (MODULES[$key]['modules'] ?? null) {
                $_SESSION['modules'][$key] = MODULES[$key];
                foreach (MODULES[$key]['modules'] as $permission => $module) {
                    if (($permissions[$permission] ?? null) === null) {
                        unset($_SESSION['modules'][$key]['modules'][$permission]);
                    }
                }
                if (empty($_SESSION['modules'][$key]['modules'])) {
                    unset($_SESSION['modules'][$key]);
                }
            } elseif (MODULES[$key] ?? null) {
                $_SESSION['modules'][$key] = MODULES[$key];
            }
        }
        session_write_close();

        return $user;
    }

    public static function sessionSet(string $name, $value)
    {
        session_start();
        $_SESSION[$name] = $value;
        session_write_close();

        return self::sessionCheck($name);
    }

    public static function decrypt(string $value_encrypted)
    {
        $value_encrypted = html_entity_decode($value_encrypted);
        return trim(strstr(openssl_decrypt($value_encrypted, "AES-256-CBC", CONFIG['seed'], 0, str_pad(CONFIG['seed'], 16, 'X', STR_PAD_LEFT)), 'º'), 'º');
    }

    public static function encrypt(string $value)
    {
        return openssl_encrypt(uniqid() . 'º' . $value, "AES-256-CBC", CONFIG['seed'], 0, str_pad(CONFIG['seed'], 16, 'X', STR_PAD_LEFT));
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
            $mail->SMTPSecure = CONFIG['email']['protocol'] ?? 'ssl';
            $mail->IsSMTP(); // use SMTP
            $mail->SMTPAuth = true;
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';

            $mail->setFrom(CONFIG['email']['username'], CONFIG['email']['name']);

            foreach ($to as $item) {
                if (strpos($_SERVER['HTTP_USER_AGENT'], 'Postman') !== false) {
                    $item['email'] = CONFIG['email']['username'];
                }

                if (!$item['email']) {
                    JsonResponse::sendResponse('Invalid email format: ' . "'$item[email]'");
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
            throw new CoreException($exception->getMessage(), HTTPStatusCodes::ServiceUnavailable);
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

    public static function getTemplate(string $filename, $data)
    {
        ob_start();
        include_once __DIR__ . '/../templates/' . $filename . '.php';
        $contents = ob_get_contents();
        ob_end_clean();

        return $contents;
    }

    public static function getSettings()
    {
        $path = __DIR__ . '/../settings.dev.json';
        if (!file_exists($path)) {
            $path = __DIR__ . '/../settings.json';
        }

        return self::json_decode(file_get_contents($path), true);
    }

    /**
     * @param $options
     * @return mixed
     * @throws CoreException
     */
    public static function curl($options, $select = null)
    {
        if (ENVIRONMENT !== 'www') {
            throw new CoreException('curl can only be used on web');
        }
        $settings = self::getSettings();

        $curl = curl_init();

        $headers = [
            "Cookie: XDEBUG_SESSION=PHPSTORM",
            "X-Client: " . WEBCONFIG['code'],
            "Authorization: Bearer " . $_SESSION['user_token'],
        ];
        $options['method'] = mb_strtoupper($options['method'] ?? 'get');

        $options['url'] = str_replace(' ', '%20', $options['url']);
        curl_setopt_array($curl, [
            CURLOPT_URL => $settings['apiUrl'] . ($options['url'] ?? ''),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $options['method'] ?? "GET",
        ]);
        if ($options['data'] ?? null) {
            $data = json_encode($options['data']);

            $headers[] = "Content-Type: application/json";
            $headers[] = 'Content-Length: ' . strlen($data);

            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, array_merge($headers, $options['headers'] ?? []));

        $json = curl_exec($curl);
        $error = curl_error($curl);
        $info = curl_getinfo($curl);
        curl_close($curl);

        $result = ['data' => []];

        if ($error) {
            JsonResponse::sendResponse($error, HTTPStatusCodes::InternalServerError);
        } elseif ($json) {
            if (!self::isJson($json)) {
                if ($info['http_code'] >= 500) {
                    JsonResponse::sendResponse('', $info['http_code']);
                } elseif ($info['http_code'] >= 400) {
                    JsonResponse::sendResponse('', $info['http_code']);
                } else {
                    throw new CoreException('', $info['http_code'], ['data' => $json]);
                }
            } else {
                $result = self::json_decode($json);
                $code = $result['code'] ?? $result['status'] ?? $info['http_code'];
                if (($code ?: 500) >= 400) {
                    if (!$code) {
                        throw new CoreException('Response Code not defined', 500);
                    }
                    if (is_array($result['message'])) {
                        $result['message'] = implode(' ', $result['message']);
                    }
                    throw new CoreException($result['message'], $code, $result['data']);
                }
            }
        } else {
            JsonResponse::sendResponse('Empty response: ' . $options['url'], HTTPStatusCodes::ServiceUnavailable);
        }

        if ($select && key_exists($select, $result['data'])) {
            return $result['data'][$select];
        }

        return $result['data'];
    }

    public static function redirect(string $path = '', bool $external = false)
    {
        $request_uri = str_replace(BASENAME, '', $_SERVER['REQUEST_URI']);
        $pathinfo = pathinfo($request_uri);
        if ($pathinfo['dirname'] !== $path && $pathinfo['filename'] !== $path && !($pathinfo['extension'] ?? null)) {
            if (!$external) {
                $path = rtrim(BASENAME, '/') . '/' . ltrim($path, '/');
            }
            header('Location: ' . $path);
            exit;
        }
    }

    /**
     * @param string $json
     * @param bool $assoc
     * @return object|array
     */
    public static function json_decode(string $json, bool $assoc = true)
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
    }

    public static function decode_id(string $id)
    {
        $base64 = base64_decode($id);
        $end_decoded = strstr($base64, '=');
        if (!empty($base64) && (strlen($end_decoded) > 1)) {
            $end_decoded = trim($end_decoded, '=');
            if (!empty($end_decoded)) {
                if (!is_nan($end_decoded)) {
                    return intval($end_decoded);
                }
                return $end_decoded;
            }
        } elseif (!is_string($id)) {
            return intval($id);
        }
        return $id;
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

    /**
     * @param string $jwt
     * @return mixed
     * @throws CoreException
     */
    public static function decode_token(string $jwt = null)
    {
        try {
            if (empty($jwt)) {
                JsonResponse::sendResponse('The token sent is empty.');
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

    public static function curlDecodeToken($token)
    {
        if ($_SESSION['user'] ?? null) {
            return $_SESSION['user'];
        }
        if ($token) {
            try {
                return System::curl([
                    'url' => 'api/decodeToken',
                    'method' => 'POST',
                    'data' => ['token' => $token]
                ]);
            } catch (CoreException $exception) {
                unset($_SESSION['user_token']);
                return [];
            }
        } else {
            return [];
        }
    }

    public static function init($config)
    {
        if (defined('ENVIRONMENT')) {
            if (ENVIRONMENT === 'www') {
                throw new CoreException('Not allowed', HTTPStatusCodes::InternalServerError);
            }
        }

        self::define_constants($config);

        set_exception_handler(function ($exception) {
            $status = 'exception';
            $code = $exception->getCode() ?: 500;
            if (!is_int($code)) {
                $code = 500;
            } else {
                http_response_code($code);
            }
            $message = $exception->getMessage();
            if (method_exists($exception, 'getData')) {
                $data = $exception->getData() ?? [];
            } else {
                $data = [
                    'exception' => get_class($exception)
                ];
            }
            $error = null;
            if ($code >= 500) {
                $error = $exception->getTrace();
            }
            if (ENVIRONMENT === 'web' || ENVIRONMENT === 'www') {
                if (ob_get_contents()) ob_end_clean();
                $response = compact('message');
                header('Content-Type: application/json');
                die(json_encode(compact('code', 'message', 'data', 'error', 'response'), JSON_UNESCAPED_SLASHES));
            } else {
                die("\033[31m" . $message . "\033");
            }
        });


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
            header('Access-Control-Allow-Headers: X-Requested-With, Content-Type, dataType, contenttype, processdata, authorization, x-client');
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
                            break;
                        case 8:
                            break;
                        case 32:
                            //Module '<module>' already loaded
                            switch ($error['message']) {
                                case "Module 'sqlite3' already loaded":
                                    break 2;
                            }
                            break;
                        default:
                            if (ob_get_contents()) ob_clean();
                            $status = 'error';
                            $code = HTTPStatusCodes::InternalServerError;
                            $response = null;
                            die(json_encode(compact('status', 'code', 'response', 'error')));
                            break;
                    }
                }
            }
        });

        $classes = glob(__DIR__ . "/classes/*.php");
        foreach ($classes as $class) {
            require_once($class);
        }

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
                include_once $path;
            }
        });
        if (function_exists('xdebug_disable')) {
            //Disables stack traces
            //Disable showing stack traces on error conditions.
            xdebug_disable();
        }

        if (file_exists(__DIR__ . '/../offline')) {
            throw new CoreException('We are updating the app, please be patient.', HTTPStatusCodes::ServiceUnavailable);
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

        JsonResponse::sendResponse('', HTTPStatusCodes::OK);
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
                    if (!defined('PROJECT')) define('PROJECT', $project_config['project']['code']);
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
                            'name' => '',
                            'username' => '',
                            'password' => '',
                            'host' => 'smtp.gmail.com',
                            'port' => '465',
                        ]
                    ];
                    if (ENVIRONMENT == 'init') {
                        return;
                    }
                    file_put_contents(DIR . '/Config/default.json', json_encode($project_config));

                    header('Content-Type: application/json');
                    http_response_code(500);
                    $message = "default.json not found";
                    die(json_encode(compact('message')));
                }
            } else {
                $project_config = file_exists(DIR . '/Config/' . $project . '.json')
                    ? file_get_contents(DIR . '/Config/' . $project . '.json')
                    : null;
                if ($project_config) {
                    define('CONFIG', json_decode($project_config, true));
                } else {
                    copy(DIR . '/Config/default.json', DIR . "/Config/$project.json");

                    header('Content-Type: application/json');
                    http_response_code(500);
                    die(json_encode(['message' => "Config not found for project '$project'"]));
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
        $request = trim($_SERVER['REQUEST_URI'], '/');
        $request = str_replace('//', '/', $request);
        if (BASENAME !== '/') {
            $request = str_replace(trim(BASENAME, '/'), '', $request);
            if (empty($request)) {
                $request = 'api/version';
            }
        }
        if (strpos($request, '?') !== false) {
            $request = stristr($request, '?', true);
        }
        preg_match_all('/\/?\b([a-z-]+?)\/+([a-z-]+)(?:\W+(.+))?/i', $request, $request, PREG_SET_ORDER, 0);
        if (!empty($request)) {
            $request = array_splice($request[0], 1);
            [$controller, $action] = $request;
            $id = System::isset_get($request[2]);
            if (!empty($id)) {
                $id = System::decode_id($id);
            }
        } else {
            $request = trim($_SERVER['REQUEST_URI'], '/');
            $request = trim($request, '/');
            if (strpos($request, 'api/') !== false) {
                $request = stristr($request, 'api/');
            } elseif (strpos($request, '/') === false) {
                $request = 'api/' . $request;
            }
            $request = explode('/', $request);

            $controller = $request[0];
            $action = $request[1] ?? null;
        }
        define('ENDPOINT', $request[0] . '/' . $request[1]);
    }

    private static function call_action($controller, $action, $id)
    {
        switch (REQUEST_METHOD) {
            case 'OPTIONS':
                JsonResponse::sendResponse('Completed.', HTTPStatusCodes::OK);
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

                    $errors = !!($_GET['errors'] ?? null);

                    array_walk($log, function (&$entry) use ($errors) {
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

                        $error_code = $entry[2];
                        if (!is_int($error_code) && $errors) {
                            $entry = null;
                        } elseif (is_int($error_code) && !$errors) {
                            $entry = null;
                        }
                    });
                    $log = array_values(array_filter($log));
                    JsonResponse::sendResponse('Logs', HTTPStatusCodes::OK, compact('log'));
                    break;
                case "decodeToken":
                    if (REQUEST_METHOD === 'POST') {
                        if ($_POST['token'] ?? null) {
                            $data = System::decode_token($_POST['token']);
                            JsonResponse::sendResponse('Completed.', HTTPStatusCodes::OK, $data);
                        }
                    } else {
                        JsonResponse::sendResponse("Endpoint not found.  [$namespace]", HTTPStatusCodes::NotFound);
                    }
                    break;
                case "backup":
                    $mysql = new MySQL();
                    $data = $mysql->backupDB();
                    JsonResponse::sendResponse('Completed.', HTTPStatusCodes::OK, $data);
                    break;
                case "endpoints":
                    $files = glob(__DIR__ . '/../Controller/*.{php}', GLOB_BRACE);
                    $paths = [];
                    foreach ($files as $file) {
                        $basename = basename($file, '.php');
                        $class = 'Controller\\' . $basename;
                        $controller = new $class();
                        $methods = $controller->getMethods();
                        foreach ($methods as $method => $functions) {
                            $functions = array_values(array_flip($functions));
                            foreach ($functions as $endpoint) {
                                $lower = strtolower(str_replace('Controller' . '\\', '', get_class($controller)));
                                $endpoint = $lower . '/' . $endpoint;
                                $paths['/' . $endpoint][strtolower($method)] = [
                                    'produces' => ['application/json'],
                                    'responses' => [200 => ['description' => 'Success']]
                                ];
                            }
                        }
                    }
                    $swagger = '2.0';
                    $info = [
                        'description' => ucfirst(PROJECT),
                        'version' => VERSION,
                        'title' => ucfirst(PROJECT),
                    ];
                    $host = $_SERVER['SERVER_NAME'];
                    $basePath = BASENAME;
                    JsonResponse::sendResponse('Endpoints', HTTPStatusCodes::OK, compact('swagger', 'info', 'host', 'basePath', 'paths'));
                    break;
                case "webhook":
                    if (REQUEST_METHOD === 'POST' && $id) {
                        include_once __DIR__ . '/classes/Webhook.php';
                        new Webhook($id);
                        JsonResponse::sendResponse('Webhook', 200, $_POST);
                    } else {
                        $method = REQUEST_METHOD;
                        throw new CoreException("Endpoint not found.  [$controller/$action]", 404, compact('method', 'controller', 'action'));
                    }
                    break;
                case "socket":
                    new \Controller\Notifications();
                    break;
            }
        } else {
            $method = REQUEST_METHOD;
            $endpoint = $controller . '/' . $action;
            throw new CoreException("Endpoint not found.  [$endpoint]", HTTPStatusCodes::NotFound, compact('endpoint', 'method'));
        }
    }

    /**
     * @param array $array
     * @param array $required
     * @param string $message
     * @param int $code
     * @throws CoreException
     */
    public static function check_value_empty($array, $required, $message = 'Missing Data.', $code = 400)
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
            throw new CoreException($message . ' ' . "[$empty_values]", $code);
        }

        foreach ($intersect as $key => $value) {
            $value = is_string($value) ? trim($value) : $value;
            if (empty($value) and $value != 0) {
                $empty_values .= $key . ', ';
            }
        }
        $empty_values = trim($empty_values, ', ');
        if (!empty($empty_values)) {
            JsonResponse::sendResponse($message . ' ' . "[$empty_values]", $code);
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
        $uri = explode('/', $_SERVER['REQUEST_URI']);
        if (end($uri) === 'errors') {
            return;
        }

        array_shift($uri);
        array_shift($uri);

        $data = '[' . date('Y-m-d H:i:s') . '] ';
        $data .= '[' . $_SERVER['HTTP_HOST'] . '] ';
        $data .= '[' . implode('/', $uri) . '] ';
        $data .= '[' . $_SERVER['REQUEST_METHOD'] . '] ';

        $data .= '[' . preg_replace('/\s/', '', file_get_contents('php://input')) . ']';

        if ($_FILES) {
            foreach ($_FILES as $files) {
                if (is_array($files['name'])) {
                    $data .= ' ' . implode(',', $files['name']);
                } else {
                    $data .= ' ' . $files['name'];
                }
            }
        }

        if (!is_dir(__DIR__ . '/../Logs/')) {
            mkdir(__DIR__ . '/../Logs/', 0777, true);
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
                JsonResponse::sendResponse("Error creating dir [$dir]", $code, compact('status', 'code', 'response', 'error'));
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
        $data .= '[' . ($_SERVER['REQUEST_METHOD'] ?? 'SHELL') . '] ';
        if (ENVIRONMENT == 'web') {
            $data .= '[' . strstr(($_SERVER['REQUEST_URI']), 'api/') . '] ';
        } elseif (ENVIRONMENT == 'cli') {
            $data .= '[' . System::isset_get($_SERVER['argv'][5]) . '] ';
        }
        $data .= '[' . $response['code'] . '] ';
        $data .= '[' . json_encode($response['response']) . '] ';
        $data .= '[' . json_encode([
                    'GET' => $_GET,
                    'POST' => $_POST,
                    'PUT' => $_PUT,
                    'PATCH' => $_PATCH,
                ][REQUEST_METHOD] ?? ENVIRONMENT) . '] ';
        /* if ($response['error'] ?? null) {
             $data .= '[' . json_encode($response['error']) . '] ';
         }*/

        if (!is_dir(__DIR__ . '/../Logs/')) {
            mkdir(__DIR__ . '/../Logs/', 0777, true);
        }
        if (defined('CONFIG')) {
            $path = __DIR__ . '/../Logs/' . CONFIG['project']['code'] . '/' . date('Y-m-d') . '.log';
        } elseif (defined('WEBCONFIG')) {
            $path = __DIR__ . '/../Logs/' . WEBCONFIG['code'] . '/' . date('Y-m-d') . '.log';
        } else {
            $path = __DIR__ . '/../Logs/' . date('Y-m-d') . '.log';
        }

        if (!file_exists($path)) {
            chmod(__DIR__ . '/../', 0755);
            mkdir(dirname($path), 0755, true);
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
     * @throws CoreException
     */
    public static function init_web(array $constants)
    {
        set_exception_handler(function ($exception) {
            ob_clean();
            $status = 'exception';
            $code = $exception->getCode() ?: 500;
            $message = $exception->getMessage();
            http_response_code($code);
            $response = ['message' => $message];
            $error = null;
            if ($code >= 500) {
                $error = $exception->getTrace();
            }

            self::log_error(compact('status', 'code', 'response', 'error'));

            self::$error_code = $code;

            self::$error_message = WEBCONFIG['error']['messages'][$code];
            switch ($code) {
                case 404:
                    parse_str($_SERVER['QUERY_STRING'], $query_string);
                    $endpoint = $exception->getData('endpoint');
                    $message = $endpoint ?? ($query_string['action'] ?? null) ?? ($query_string['module'] ?? null);
                    if ($message) {
                        self::$error_message .= ' [' . $message . ']';
                    }
                    break;
                default:
                    self::$error_message .= ($message ? " [$message]" : '');
                    break;
            }

            self::$error_button = WEBCONFIG['error']['button'];
            self::formatDocument(WEBCONFIG['error']['file']);
            exit;
        });

        define('WEBDIR', $constants['WEBDIR']);
        define('ENVIRONMENT', 'www');
        if (!defined('JWT_KEY'))
            define('JWT_KEY', file_exists(WEBDIR . '/../api/Config/.jwt_key') ? file_get_contents(WEBDIR . '/../api/Config/.jwt_key') : null);

        if (!file_exists(WEBDIR . '/config.json')) {
            die('config.json does not exist');
        }

        $config = json_decode(file_get_contents(WEBDIR . '/config.json'), true);

        $env = getenv(mb_strtoupper($config['code']) . '_CONFIG');
        if (file_exists(WEBDIR . '/.env')) {
            $env = trim(file_get_contents(WEBDIR . '/.env'));
        }

        if ($env) {
            $env_config = file_get_contents(WEBDIR . "/settings/$env/" . "config.json");
            $env_config = json_decode($env_config, true);
            $env_config = array_merge($config, $env_config);
        }
        if ($env_config ?? null) {
            $config = $env_config;
        }
        define('WEBCONFIG', $config);

        [
            'entry' => $entry,
            'default' => $default,
            'modules' => $module_list,
            'breadcrumbs' => $breadcrumbs
        ] = WEBCONFIG;

        define('BREADCRUMBS', $breadcrumbs);

        if ($constants['BASENAME'] ?? null) {
            define('BASENAME', $constants['BASENAME']);
        } else {
            define('BASENAME', '/' . (trim(dirname($_SERVER['SCRIPT_NAME']), '/') ?: '.') . '/');
        }

        self::load_php_functions();
        if (file_exists(__DIR__ . "/vendor/autoload.php")) {
            require __DIR__ . "/vendor/autoload.php";
        }

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

        self::formatDocument($file, $module_file);
    }

    /**
     * @param $file
     * @param null $module_file
     * @throws CoreException
     */
    public static function formatDocument($file, $module_file = null)
    {
        try {
            [
                'project' => $project,
                'description' => $description,
                'keywords' => $keywords,
                'author' => $author,
                'copyright' => $copyright,
                'entry' => $entry,
                'error' => $error_file,
                'theme' => $dir,
                'modules' => $module_list,
            ] = WEBCONFIG;

            if (!file_exists($dir . $file)) {
                die($dir . $file . ' does not exist');
            }

            self::$dom = new DOMDocument();
            libxml_use_internal_errors(true);
            self::$dom->loadHTMLFile($dir . $file);

            /** @var DOMElement $link */
            foreach (self::$dom->getElementsByTagName('link') as $link) {
                $old_link = $link->getAttribute("href");
                if (strpos($old_link, 'http') !== false) {
                    continue;
                }

                if ($old_link === 'manifest.json') {
                    $env = 'settings/' . WEBCONFIG['code'] . '/';
                    $new_link = BASENAME . $env . $old_link;
                    if (!file_exists($new_link)) {
                        $new_link = BASENAME . $old_link;
                    }
                    $link->setAttribute('href', $new_link);
                } else {
                    $link->setAttribute('href', BASENAME . $dir . $old_link);
                }
            }
            foreach (self::$dom->getElementsByTagName('div') as $link) {
                $old_link = $link->getAttribute("ui-include");
                if ($old_link) {
                    $old_link = str_replace("'", '', $old_link);
                    $link->setAttribute('ui-include', "'" . BASENAME . $dir . $old_link . "'");
                }
            }
            foreach (self::$dom->getElementsByTagName('img') as $link) {
                $old_link = $link->getAttribute("src");
                if ($old_link) {
                    if (strpos($old_link, 'http') !== false) {
                        continue;
                    }
                    $link->setAttribute('src', BASENAME . $dir . $old_link);
                }
                $old_link = $link->getAttribute("data-src");
                if ($old_link) {
                    if (strpos($old_link, 'http') !== false) {
                        continue;
                    }
                    $link->setAttribute('data-src', BASENAME . $dir . $old_link);
                }
                $old_links = $link->getAttribute("srcset");
                if ($old_links) {
                    $new_links = [];
                    foreach (explode(', ', $old_links) as $old_link) {
                        if (strpos($old_link, 'http') !== false) {
                            $new_links[] = $old_link;
                            continue;
                        }
                        $new_links[] = BASENAME . $dir . $old_link;
                    }
                    $link->setAttribute('srcset', implode(', ', $new_links));
                }
            }
            foreach (self::$dom->getElementsByTagName('source') as $link) {
                $old_link = $link->getAttribute("src");
                $link->setAttribute('src', BASENAME . $dir . $old_link);
            }
            foreach (self::$dom->getElementsByTagName('script') as $link) {
                $old_link = $link->getAttribute("src");
                if ($old_link) {
                    if (strpos($old_link, 'http') !== false) {
                        continue;
                    }
                    $link->setAttribute('src', BASENAME . $dir . $old_link);
                }
            }

            if (self::$dom->getElementsByTagName('head')->item(0)) {
                if (!file_exists(WEBDIR . '/assets/dist/bundle.js')) {
                    throw new CoreException('Assets not generated', 500, ['dir' => WEBDIR . '/assets/dist/bundle.js']);
                }
                $fragment = self::$dom->createDocumentFragment();
                $fragment->appendXML('<script src="assets/dist/bundle.js"></script>');
                self::$dom->getElementsByTagName('head')->item(0)->insertBefore($fragment, self::$dom->getElementsByTagName('title')->item(0));
            }
            if (self::$dom->getElementsByTagName('title')->item(0)) {
                self::$dom->getElementsByTagName('title')->item(0)->nodeValue = $project;
            }
            if (self::$dom->getElementById('tag-title')) {
                self::$dom->getElementById('tag-title')->setAttribute('content', $project);
            }
            if (self::$dom->getElementById('tag-description')) {
                self::$dom->getElementById('tag-description')->setAttribute('content', $description);
            }
            if (self::$dom->getElementById('tag-keywords')) {
                self::$dom->getElementById('tag-keywords')->setAttribute('content', $keywords);
            }
            if (self::$dom->getElementById('tag-author')) {
                self::$dom->getElementById('tag-author')->setAttribute('content', $author);
            }
            if (self::$dom->getElementById('project-title')) {
                self::$dom->getElementById('project-title')->nodeValue = $project;
            }
            if (self::$dom->getElementById('project-error-code')) {
                self::$dom->getElementById('project-error-code')->nodeValue = self::$error_code;
            }
            if (self::$dom->getElementById('project-error-message')) {
                self::$dom->getElementById('project-error-message')->nodeValue = self::$error_message;
            }
            if (self::$dom->getElementById('project-error-button')) {
                self::$dom->getElementById('project-error-button')->nodeValue = self::$error_button;
            }

            if (self::$dom->getElementById('tag-code')) {
                $env = WEBCONFIG['code'];
                self::$dom->getElementById('tag-code')->setAttribute('content', $env);
            }
            if (self::$dom->getElementById('favicon')) {
                $env = WEBCONFIG['code'];

                $logo = 'favicon.ico';
                if (file_exists('settings/' . $env . '/img/' . $logo)) {
                    $logo = 'settings/' . $env . '/img/' . $logo;
                }

                $favicon = self::$dom->getElementById('favicon');
                $favicon->setAttribute('href', '/' . $logo);
            }

            if (self::getElementsByClass(self::$dom, 'div', 'copyright')) {
                $e_copyrights = (self::getElementsByClass(self::$dom, 'div', 'copyright'));
                /** @var DOMElement $e_copyright */
                foreach ($e_copyrights as $e_copyright) {
                    $e_copyright->getElementsByTagName('p')->item(0)->nodeValue = $copyright;
                }
            }

            if (self::getElementsByClass(self::$dom, 'img', 'project-img')) {
                $imgs = (self::getElementsByClass(self::$dom, 'img', 'project-img'));
                $config = WEBCONFIG;
                foreach ($imgs as $img) {
                    $env = $config['code'];
                    $logo = BASENAME . 'logo.png';
                    if (file_exists(__DIR__ . '/../settings/' . $env . '/img/logo.png')) {
                        $logo = BASENAME . 'settings/' . $env . '/img/logo.png';
                    }
                    $img->setAttribute('src', $logo);
                }
            }

            if (self::$dom->getElementById('btnSignUp') && !($module_list['signup'] ?? null)) {
                $btnSignUp = self::$dom->getElementById('btnSignUp');
                $btnSignUp->parentNode->removeChild($btnSignUp);
            }

            if (self::$dom->getElementById('project-user')) {
                session_start();
                if ($_SESSION['user_token'] ?? null) {
                    $user = System::curlDecodeToken($_SESSION['user_token']);
                    if ($user) {
                        $user_name = $user['name'] ?? 'No Name';
                        self::$dom->getElementById('project-user')->nodeValue = $user_name;
                    } else {
                        System::redirect('login');
                    }
                }
                session_write_close();
            }

            $module_list = $module_list ?: [['name' => 'Dashboard', 'icon' => 'dashboard', 'href' => 'dashboard', 'disabled' => '']];
            if (!defined('MODULES')) {
                define('MODULES', $module_list);
            }

            if (($_GET['logout'] ?? '') === 'true') {
                session_start();
                unset($_SESSION['user_token']);
                session_write_close();
                System::redirect('login');
            }

            if ($file != $entry) {
                $fragment = self::$dom->createDocumentFragment();

                $body = self::$dom->getElementsByTagName('body');
                if ($body->length > 0 && $fragment->textContent) {
                    $body->item(0)->appendChild($fragment);
                }
            } elseif ($module_file ?? null) {
                $fragment = self::$dom->createDocumentFragment();

                if (defined('SESSIONCHECK') && SESSIONCHECK && pathinfo($module_file, PATHINFO_EXTENSION) !== 'js') {
                    $user = System::sessionCheck("user_token");
                    if (($user['permissions'] ?? null) !== null) {
                        $module_list = ($_SESSION['modules'] ?? []) + array_filter(MODULES, function ($module) {
                                return ($module['permissions'] ?? true) === false;
                            });
                        if ($module_file !== 'dashboard' && !($module_list[$module_file] ?? null)) {
                            [$module, $action] = explode('/', $module_file);
                            if (empty($module_list[$module]) && !empty(MODULES[$module])) {
                                throw new CoreException($module_file, HTTPStatusCodes::Forbidden);
                            }
                            switch (true) {
                                case $module_list[$module]['modules'][$action]:
                                case $module_list[$module]['action']['href'] === $action:
                                    break;
                                case !MODULES[$module]['modules'][$action]:
                                    throw new CoreException($module_file, HTTPStatusCodes::NotFound);
                                default:
                                    throw new CoreException($module_file, HTTPStatusCodes::Forbidden);
                            }
                        }
                    }
                    if (self::$dom->getElementById('tag-user-token')) {
                        self::$dom->getElementById('tag-user-token')->setAttribute('content', $user['token']);
                    }
                }

                $settings = self::getSettings();
                foreach ($module_list as $module) {
                    $modal = $module['modal'] ?? false;
                    $href = $module['href'] ?? null;
                    $name = $module['name'] ?? null;
                    $icon = $module['icon'] ?? null;
                    $children = $module['modules'] ?? null;
                    $onclick = $module['onclick'] ?? null;

                    $nav_icon = '';
                    if (WEBCONFIG['module-icons'] ?? true) {
                        $nav_icon = <<<html
<span class="nav-icon">
    <i class="material-icons">$icon</i>
</span>
html;
                    }

                    if ($modal) {
                        $module['hidden'] = true;
                        $module['file'] = '';
                    }

                    $disabled = System::isset_get($module['disabled']) ? 'disabled' : '';
                    $hidden = System::isset_get($module['hidden']) ? 'none' : 'unset';
                    $file = System::isset_get($module['file'], $entry);

                    if ($file != $entry) {
                        continue;
                    }

                    if (!$href && $children) {
                        $children_html = '';
                        foreach ($children as $child) {
                            $child_modal = $child['modal'] ?? false;
                            $child_href = $child['href'] ?? null;
                            $child_name = $child['name'] ?? null;
                            $child_icon = $child['icon'] ?? null;
                            $child_onclick = $child['onclick'] ?? null;

                            if ($child_modal) {
                                $child['hidden'] = true;
                                $child['file'] = '';
                            }

                            $child_disabled = System::isset_get($child['disabled']) ? 'disabled' : '';
                            $child_hidden = System::isset_get($child['hidden']) ? 'none' : 'unset';

                            preg_match_all('/###(.+)###/m', $child_href, $matches, PREG_SET_ORDER, 0);
                            foreach ($matches as $match) {
                                $match = $match[1];
                                $child_href = str_replace('###' . $match . '###', $settings[$match], $child_href);
                            }

                            $child_nav_icon = <<<html
<span class="nav-icon">
    <i class="material-icons">$child_icon</i>
</span>
html;

                            $children_html .= <<<html
<li style="display: $child_hidden">
    <a href="$child_href" class="$child_disabled" style="display: flex; align-items: center" onclick="$child_onclick">
        $child_nav_icon
        <span class="nav-text">$child_name</span>
    </a>
</li>
html;
                        }
                        $html = <<<html
<li>
    <a class="parent-module">
        <span class="nav-caret">
            <i class="fas fa-caret-down"></i>
        </span>
        $nav_icon
        <span class="nav-text">$name</span>
    </a>
    <ul class="nav-sub">
        $children_html
    </ul>
</li>
html;
                    } else {
                        $html = <<<html
<li style="display: $hidden">
    <a href="$href" class="$disabled" style="display: flex; align-items: center" onclick="$onclick">
        $nav_icon
        <span class="nav-text">$name</span>
    </a>
</li>
html;
                    }
                    $fragment->appendXML($html);
                }
                $modules = self::$dom->createElement('ul');
                $modules->setAttribute('class', 'nav');
                if ($fragment->textContent) {
                    $modules->appendChild($fragment);
                }

                /** @var DOMElement $nav */
                $nav = self::$dom->getElementsByTagName('nav')[0];
                if ($nav) {
                    if ($nav->parentNode) {
                        $modules->setAttribute('id', $nav->getAttribute('id'));
                        $modules->setAttribute('class', $nav->getAttribute('class'));
                        $nav->parentNode->replaceChild($modules, $nav);
                    }
                }

                self::load_module($module_file);
            } else {
                self::formatDocument($error_file);
                return;
            }

            libxml_clear_errors();
            self::print_page();
        } catch (DOMException $exception) {
            $message = $exception->getMessage();
            echo <<<html
<script>
alert('$message');
</script>
html;
        }
    }

    public static function getElementsByClass(&$parentNode, $tagName, $className)
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

    public static function load_module($file)
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
            throw new CoreException('Not found', 404);
        }

        ob_start();
        include_once $module_path;
        $contents = ob_get_contents();
        ob_end_clean();

        $href = strstr($file, '.php', true);
        if (strpos($href, '/') !== false) {
            $href = explode('/', trim($href, '/'));
        }

        $modules = MODULES;
        if (is_array($href)) {
            $o_module = $modules[$href[0]] ?? '';
            $module_name = ucfirst(strtolower($o_module['name'] ?? ''));
            if (!!($href[1] ?? null) && !!($o_module['modules'] ?? null)) {
                $module_name .= ' / ' . ($o_module['modules'][$href[1]]['name'] ?? '');
            }
            foreach ($href as $item) {
                $o_module = $o_module['modules'][$item] ?? $o_module;
            }
        } else {
            $o_module = $modules[$href] ?? '';
            $module_name = ucfirst(strtolower(($o_module['name'] ?? null) ?: $o_module['breadcrumbs'] ?? ''));
        }

        $breadcrumbs = 'none';
        if (BREADCRUMBS && !($o_module['modal'] ?? false)) {
            $breadcrumbs = 'unset';
        }

        $module = self::createElement('div', <<<html
    <p class="text-left breadcrumbs $breadcrumbs" style="display: $breadcrumbs">
        <span class="text-muted">Usted se encuentra en:</span> <span>$module_name</span>
    </p>
    $contents
html
        );

        if (self::$dom->getElementById('view')) {
            $view = self::$dom->getElementById('view');
            $body = self::$dom->getElementsByTagName('body')[0];
            $html = self::$dom->getElementsByTagName('html')[0];

            $class = $view->getAttribute('class');
            $module->setAttribute('id', 'view');
            $html->setAttribute('class', WEBCONFIG['code']);

            if (is_array($href)) {
                $module->setAttribute('class', $class . ' ' . $href[0] . '-' . $href[1]);
                $body->setAttribute('id', $href[0] . '-' . $href[1]);
            } else {
                $module->setAttribute('class', $class . ' ' . $href);
                $body->setAttribute('id', $href);
            }

            if ($view->parentNode) {
                $view->parentNode->replaceChild($module, $view);
            }

            if (file_exists("settings/" . WEBCONFIG['code'] . "/css/index.css")) {
                $code = WEBCONFIG['code'];
                $fragment = self::$dom->createDocumentFragment();
                $fragment->appendXML(<<<html
<link rel="stylesheet" href="settings/$code/css/index.css"/>
html
                );
                $html->appendChild($fragment);
            }

            if ($o_module['action'] ?? null) {
                $o_action = $o_module['action'];
                if (self::$dom->getElementById('project-action')) {
                    $action = self::$dom->getElementById('project-action');
                    $class = ($action->childNodes->item(1))->getAttribute('class');

                    $icon = $o_action['icon'] ?? 'add_circle';
                    $module = self::createElement('div', <<<html
<a href="?action=$o_action[href]">
    <i class="material-icons">$icon</i>
    <span>$o_action[name]</span>
</a>
html
                    );
                    $module->setAttribute('class', $action->getAttribute('class'));
                    $module->firstChild->setAttribute('class', $class);
                    if ($action->parentNode) {
                        $action->parentNode->replaceChild($module, $action);
                    }
                }
            } else {
                if (self::$dom->getElementById('project-action')) {
                    $action = self::$dom->getElementById('project-action');
                    $action->parentNode->replaceChild(self::createElement('div', '<a></a>'), $action);
                }
            }
        }
    }

    /**
     * @param string $element
     * @param string $html
     * @return DOMElement
     */
    private static function createElement(string $element, string $html)
    {
        $fragment = new DOMDocument();
        $fragment->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), 8192 | 4);

        $module = self::$dom->createElement($element);
        $module->appendChild(self::$dom->importNode($fragment->documentElement, true));

        return $module;
    }

    public static function print_page()
    {
        echo self::$dom->saveHTML();
    }

    public static function getPermissions(array $permission_list = null)
    {
        if (ENVIRONMENT !== 'www') {
            throw new CoreException('getPermissions can only be used on web.');
        }
        if (session_id() == '') {
            session_start();
        }
        $user = self::curlDecodeToken($_SESSION['user_token']);
        session_write_close();

        $permissions = self::json_decode($user['permissions'] ?? '[]', true);

        $permissions = array_flip($permissions);

        array_walk($permissions, function (&$permission) {
            $permission = true;
        });

        if ($permission_list) {
            foreach ($permission_list as $permission) {
                $permissions[$permission] = $permissions[$permission] ?? empty($permissions);
            }
        }

        return $permissions;
    }

    static function getQR(string $chl)
    {
        $url = http_build_query([
            'chl' => $chl,
            'cht' => 'qr',
            'chs' => '500x500',
            'choe' => 'UTF-8',
            'chld' => 'H|4',
        ]);

        $chart = 'https://chart.googleapis.com/chart?' . $url;
        return compact('chart', 'chl');
    }

    static function parseRows($name, $path, $template): array
    {
        $parse = SimpleXLSX::parse($path);

        if (!$parse) {
            $error = SimpleXLSX::parseError();
            throw new CoreException($error . ': ' . $name, 400, compact('name', 'path'));
        }

        $rows = $parse->rows();
        $headers = $rows[0];

        //Validar headers
        $template = SimpleXLSX::parse($template);
        if (!$template) {
            $error = SimpleXLSX::parseError();
            throw new CoreException($error . ': ' . $name, 400, compact('name', 'path'));
        }
        $template_headers = $template->rows()[0];
        $diff = array_diff($template_headers, $headers);
        if (!empty($diff)) {
            throw new CoreException('El archivo no tiene el formato correcto. Descarge el formato de nuevo.', 400, compact('diff'));
        }

        array_splice($rows, 0, 1);

        array_walk($rows, function (&$row) use ($headers) {
            $row = array_combine($headers, $row);
        });

        return compact('headers', 'rows');
    }

    static function array_fill(array $keys, $value = null): array
    {
        return array_fill_keys($keys, $value);
    }
}
