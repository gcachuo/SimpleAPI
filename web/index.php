<?php
include __DIR__ . "/core/System.php";

define('SESSIONCHECK', 'user_token');

['file' => $file, 'module_file' => $module_file] = System::init_web(['WEBDIR' => __DIR__]);

System::formatDocument($file, $module_file);

