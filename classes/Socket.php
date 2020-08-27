<?php

use Controller\Notifications;
use Ratchet\ConnectionInterface;

abstract class Socket
{
    protected static $connections;

    public function __construct()
    {
        self::$connections = new SplObjectStorage();

        /*$server = $_SERVER;
        if ($_SERVER['HTTP_UPGRADE'] == 'websocket') {
            try {

            } catch (Exception $exception) {
                throw new CoreException('Stop socket: ' . $exception->getMessage(), 500, compact('server'));
            }
        }
        throw new CoreException('This is not a websocket connection', 500, compact('server'));*/
    }

    function onMessage(ConnectionInterface $from, $msg)
    {
        echo $msg . PHP_EOL;
        /** @var ConnectionInterface $client */
        foreach (self::$connections as $client) {
            if ($from !== $client) {
                // The sender is not the receiver, send to each client connected
                [$event_name, $data_object] = json_decode($msg, true);
                $client->send(json_encode([$event_name, $data_object]));
            }
        }
    }

    public function onOpen(ConnectionInterface $conn)
    {
        self::$connections->attach($conn);

        echo 'new connection' . PHP_EOL;
        $conn->send(json_encode(['message', ['message' => 'Welcome!']]));
    }

    function onClose(ConnectionInterface $conn)
    {
        self::$connections->detach($conn);
    }

    function onError(ConnectionInterface $conn, Exception $e)
    {
        // TODO: Implement onError() method.
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
