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
            $socket = new Server('127.0.0.1:8080', $loop);
            $socket->on('connection', function (ConnectionInterface $connection) {
                $connection->write("Hello " . $connection->getRemoteAddress() . "!\n");
                $connection->write("Welcome to this amazing server!\n");
                $connection->write("Here's a tip: don't say anything.\n");

                $connection->on('data', function ($data) use ($connection) {
                    $connection->close();
                });
            });

            echo 'Listening on ' . $socket->getAddress() . PHP_EOL;

            return $loop;
        } catch (RuntimeException $exception) {
            throw new CoreException($exception->getMessage(), 500, compact('exception'));
        }
    }

    public static function on_connection(ConnectionInterface $connection)
    {

    }
}
