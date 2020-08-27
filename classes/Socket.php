<?php

use React\EventLoop\Factory;
use React\Socket\ConnectionInterface;
use React\Socket\Server;

class Socket
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
            $loop = Factory::create();
            $server = new Server(8080, $loop);
            $server->on('connection', function (ConnectionInterface $connection) {
                self::on_connection($connection);
            });

            echo 'Listening on ' . $server->getAddress() . PHP_EOL;

            return $loop;
        } catch (RuntimeException $exception) {
            throw new CoreException($exception->getMessage(), 500, compact('exception'));
        }
    }

    public static function on_connection(ConnectionInterface $connection)
    {
        echo 'new connection: ' . $connection->getRemoteAddress() . PHP_EOL;
    }
}
