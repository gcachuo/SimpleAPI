<?php
include __DIR__ . "/core/System.php";

define('SESSIONCHECK', true);
System::init_web(['WEBDIR' => __DIR__]);
?>
<script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('service-worker.js');
    }
</script>
