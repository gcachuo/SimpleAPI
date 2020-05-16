<?php
include __DIR__ . "/core/System.php";
$system = new System();
$system->init_web(['WEBDIR' => __DIR__]);
if (!System::sessionCheck("user_token")) {
    $pathinfo = pathinfo($_SERVER['REQUEST_URI']);
    if ($pathinfo['basename'] !== 'login' && !($pathinfo['extension'] ?? null)) {
        System::redirect('login');
    }
}
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
