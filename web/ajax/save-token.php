<?php
include __DIR__ . '/../core/System.php';
System::init(['DIR' => __DIR__ . '/..', 'ENV' => 'www']);
System::check_value_empty($_GET, ['token']);
System::sessionSet('user_token', $_GET['token']);
JsonResponse::sendResponse('Completed', ['token' => $_GET['token']]);
