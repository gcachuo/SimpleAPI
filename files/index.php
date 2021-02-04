<?php
define('VERSION', '1.0.1');

include_once "Lib/System.php";

$path = "vendor/autoload.php";
if (file_exists($path)) {
    require_once($path);
}

$headers = apache_request_headers();
if ($headers['Authorization'] ?? null) {
    define('PROJECT', $headers['Authorization']);
}

$system = new System();
$system->init(['DIR' => __DIR__]);
