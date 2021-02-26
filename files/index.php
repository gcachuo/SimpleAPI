<?php
define('VERSION', '1.0.1');

include_once "Lib/System.php";

$path = "vendor/autoload.php";
if (file_exists($path)) {
    require_once($path);
}

$headers = apache_request_headers();
if ($headers['X-Client'] ?? null) {
    if (!defined('PROJECT')) define('PROJECT', $headers['X-Client']);
}
if ($headers['X-Database'] ?? null) {
    if (!defined('DATABASE')) define('DATABASE', $headers['X-Database']);
}
if ($headers['Authorization'] ?? null) {
    $user_token = trim(strstr($headers['Authorization'], ' '));
    define('USER_TOKEN', $user_token);
}

$system = new System();
$system->init(['DIR' => __DIR__]);
