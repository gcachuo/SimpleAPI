<?php

try {
    include __DIR__ . "/../System.php";
    System::init(['DIR' => __DIR__ . "/../.."]);

    $loop = Socket::start();

    echo "Listening..." . PHP_EOL;
    $loop->run();
} catch (CoreException $exception) {
    echo $exception->getMessage();
}
