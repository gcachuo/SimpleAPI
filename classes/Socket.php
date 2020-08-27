<?php

use Controller\Notifications;
use Ratchet\ConnectionInterface;

abstract class Socket
{
    public function __construct()
    {
        /*$server = $_SERVER;
        if ($_SERVER['HTTP_UPGRADE'] == 'websocket') {
            try {

            } catch (Exception $exception) {
                throw new CoreException('Stop socket: ' . $exception->getMessage(), 500, compact('server'));
            }
        }
        throw new CoreException('This is not a websocket connection', 500, compact('server'));*/
    }

    public static function open()
    {
        try {
            $app = new Ratchet\App('localhost', 8080);
            $app->route('/notifications', new Notifications, ['*']);

            return $app;
        } catch (RuntimeException $exception) {
            throw new CoreException($exception->getMessage(), 500, compact('exception'));
        }
    }
}
