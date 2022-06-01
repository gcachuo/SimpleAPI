<?php
include __DIR__ . "/core/System.php";

define('SESSIONCHECK', "user_token");
System::init_web(['WEBDIR' => __DIR__]);

