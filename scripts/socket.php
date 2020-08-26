<?php

try {
    include __DIR__ . "/../System.php";
    System::init(['DIR' => __DIR__ . "/../.."]);

    $loop = Socket::open();

    $loop->run();
} catch (CoreException $exception) {
    echo $exception->getMessage();
}
