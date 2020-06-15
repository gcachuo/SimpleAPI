<?php
include __DIR__ . "/core/System.php";

System::init_web(['WEBDIR' => __DIR__]);
System::sessionCheck("user_token");
?>
<script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('service-worker.js')
            .then(function (registration) {
                console.log('Registration successful, scope is:', registration.scope);
            })
            .catch(function (error) {
                console.log('Service worker registration failed, error:', error);
            });
    }
</script>
