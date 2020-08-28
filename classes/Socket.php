<?php

use Ratchet\ConnectionInterface;
use WebSocket\BadUriException;
use WebSocket\Client;

abstract class Socket
{
    protected static $connections;
    /** @var \Ratchet\App */
    private static $app;

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

    public static function start()
    {
        try {
            self::$app = new Ratchet\App('localhost', 8080);

            self::mapRoutes();

            return self::$app;
        } catch (RuntimeException $exception) {
            throw new CoreException($exception->getMessage(), 500, compact('exception'));
        }
    }

    protected function send_message($uri, $event_name, $data_object)
    {
        try {
            $url = CONFIG['websockets']['url'] ?? '';
            $client = new Client($url . '/' . $uri);
            $client->send(json_encode([$event_name, $data_object]));
            $client->close();
        } catch (BadUriException $exception) {
            throw new CoreException('Invalid URL', 500, ['config' => CONFIG['websockets']]);
        }
    }

    private static function mapRoutes()
    {
        self::$app->route('/notifications', new Socket\Notifications(), ['*']);
        self::$app->route('/order', new Socket\Order(), ['*']);
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        [$event_name, $data_object] = json_decode($msg, true);

        $payload = str_replace(["\r", "\n"], '', preg_replace("/\s+/m", ' ', (print_r($data_object, true))));
        echo "\033[32m" . $event_name . ":\033[0m " . $payload . PHP_EOL;

        /** @var ConnectionInterface $client */
        foreach (self::$connections as $client) {
            if ($from !== $client) {
                // The sender is not the receiver, send to each client connected
                $client->send(json_encode([$event_name, $data_object]));
            }
        }
    }

    /**
     * @param ConnectionInterface $conn
     */
    public function onOpen(ConnectionInterface $conn)
    {
        self::$connections->attach($conn);

        echo "\033[32mNew Connection:\033[0m " . $conn->remoteAddress . PHP_EOL;
        $conn->send(json_encode(['message', ['message' => 'Welcome!']]));
    }

    public function onClose(ConnectionInterface $conn)
    {
        self::$connections->detach($conn);
    }

    public function onError(ConnectionInterface $conn, Exception $e)
    {
        throw new CoreException($e->getMessage(), 500, compact('conn'));
    }
}
