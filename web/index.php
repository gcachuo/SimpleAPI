<?php
include __DIR__ . '/core/System.php';

['file' => $file, 'module_file' => $module_file, 'theme' => $theme] = System::init_web(['WEBDIR' => __DIR__]);

System::formatDocument($file, $module_file, $theme);

