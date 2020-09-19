<?php

use GuzzleHttp\Psr7\Uri;
use Ratchet\App;
use Ratchet\ConnectionInterface;
use WebSocket\BadOpcodeException;
use WebSocket\BadUriException;
use WebSocket\Client;
use WebSocket\ConnectionException;

abstract class Socket
{
    protected static $connections;
    /** @var App */
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

    /**
     * @param $uri
     * @param $event_name
     * @param $data_object
     * @throws CoreException
     * @throws BadOpcodeException
     */
    protected function send_message($uri, $event_name, $data_object)
    {
        try {
            $url = CONFIG['websocket']['url'] ?? '';

            $client = new Client($url . '/' . $uri);
            $client->send(json_encode([$event_name, $data_object]));
            $client->close();
        } catch (BadUriException $exception) {
            throw new CoreException('Invalid URL', 500, ['config' => CONFIG['websockets']]);
        } catch (ConnectionException $exception) {
            throw new CoreException('Cannot connect to websocket.', 500, ['config' => CONFIG['websockets']]);
        }
    }

    private static function mapRoutes()
    {
        $routes = CONFIG['websocket']['routes'];
        foreach ($routes as $route) {
            $class = "Socket\\" . ucfirst($route);
            self::$app->route('/' . $route, new $class(), ['*']);
        }
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        [$event_name, $data_object] = json_decode($msg, true);

        /** @var Uri $route */
        $route = $from->httpRequest->getUri()->getPath();

        $payload = str_replace(["\r", "\n"], '', preg_replace("/\s+/m", ' ', (print_r($data_object, true))));
        echo "\033[34m[$route] \033[32m" . $event_name . ":\033[0m " . $payload . PHP_EOL;

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

        /** @var Uri $route */
        $route = $conn->httpRequest->getUri()->getPath();
        $address = $conn->remoteAddress;

        echo "\033[34m[$route] \033[32mNew Connection:\033[0m " . $address . PHP_EOL;
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
