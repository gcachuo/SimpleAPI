<script src="assets/dist/bundle.js"></script>
<?php
include __DIR__ . "/core/System.php";

define('SESSIONCHECK', false);
System::init_web(['WEBDIR' => __DIR__]);
?>
