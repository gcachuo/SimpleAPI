<?php

class Webhook
{
    public function __construct(string $platform)
    {
        if (!file_exists(DIR . '/Config/webhook_events.json')) {
            throw new CoreException('Missing config file webhook_events.json', 500);
        }
        ['events' => $events, 'key' => $key] = json_decode(file_get_contents(DIR . '/Config/webhook_events.json'), true)[$platform];

        System::check_value_empty(compact('events', 'key'), ['events', 'key'], 'Missing config data', 500);

        ['controller' => $controller, 'action' => $action] = $events[$_POST[$key]];

        if (empty($controller)) {
            throw new CoreException('Empty Controller', 500, $events[$_POST[$key]]);
        }
        if (empty($action)) {
            throw new CoreException('Empty Action', 500, $events[$_POST[$key]]);
        }
        if ($controller == 'webhook') {
            $this->call($action, [$_POST]);
        } else {
            $controller = 'Controller\\' . $controller;

            /** @var Controller $class */
            $class = new $controller();
            $class->call($action, [$_POST]);
        }
    }

    private function call($action, array $arguments)
    {
        return $this->$action(...($arguments ?: [null]));
    }

    private function ping($data)
    {
        JsonResponse::sendResponse('Completed.', HTTPStatusCodes::OK, $data);
    }
}
