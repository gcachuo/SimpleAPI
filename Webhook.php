<?php

class Webhook
{
    public function __construct()
    {
        ['events' => $events, 'key' => $key] = json_decode(file_get_contents(DIR . '/Config/webhook_events.json'), true);
        ['controller' => $controller, 'action' => $action] = $events[$_POST[$key]];
        $controller = 'Controller\\' . $controller;

        /** @var Controller $class */
        $class = new $controller();
        $class->call($action, [$_POST]);
    }
}
