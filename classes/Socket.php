<?php

use Controller\Notifications;
use Ratchet\ConnectionInterface;
use WebSocket\BadUriException;
use WebSocket\Client;

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

    public static function send_message($event_name, $data_object)
    {
        try {
            $client = new Client(CONFIG['websockets']['url'] ?? '');
            $client->send(json_encode([$event_name, $data_object]));
            $client->close();
        } catch (BadUriException $exception) {
            throw new CoreException('Invalid URL', 500, ['config' => CONFIG['websockets']]);
        }
    }

public function onMessage(ConnectionInterface $from, $msg)
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

public function onClose(ConnectionInterface $conn)
    {
        self::$connections->detach($conn);
    }

public function onError(ConnectionInterface $conn, Exception $e)
    {
        // TODO: Implement onError() method.
    }

    public static function open()
    {
        try {
            $app = new Ratchet\App('localhost', 8080);
            $app->route('/notifications', new Notifications(), ['*']);

            return $app;
        } catch (RuntimeException $exception) {
            throw new CoreException($exception->getMessage(), 500, compact('exception'));
        }
    }
}
