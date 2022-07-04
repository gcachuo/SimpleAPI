<?php

class Webhook
{
    public function __construct()
    {
        if (!file_exists(DIR . '/Config/webhook_events.json')) {
            throw new CoreException('Missing config file webhook_events.json', 500);
        }
    }

    /**
     * @param string $platform
     * @return array
     * @throws CoreException
     */
    public function callAction(string $platform)
    {
        $webhook_events = json_decode(file_get_contents(DIR . '/Config/webhook_events.json'), true);
        System::check_value_empty($webhook_events, [$platform], 'Webhook not found', 404);
        ['events' => $events, 'key' => $key] = $webhook_events[$platform];
        System::check_value_empty(compact('events', 'key'), ['events', 'key'], 'Missing config data', 500);
        System::check_value_empty($_POST, [$key]);
        System::check_value_empty($events, [$_POST[$key]], 'Event does not exist', 500);
        System::check_value_empty($events[$_POST[$key]], ['controller', 'action'], 'Empty event: ' . $_POST[$key], 500);
        ['controller' => $controller, 'action' => $action] = $events[$_POST[$key]];

        if ($controller == 'webhook') {
            return $this->call($action, [$_POST]);
        } else {
            $controller = 'Controller\\' . $controller;

            /** @var Controller $class */
            $class = new $controller();
            return $class->call($action, [$_POST]);
        }
    }

    /**
     * @param $action
     * @param array $arguments
     * @return array
     */
    private function call($action, array $arguments)
    {
        return $this->$action(...($arguments ?: [null]));
    }

    private function ping($data)
    {
        JsonResponse::sendResponse('Completed.', HTTPStatusCodes::OK, $data);
    }
}
