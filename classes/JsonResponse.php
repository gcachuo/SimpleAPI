<?php

class JsonResponse
{
    private $response, $error, $code;
    private static $json;
    private static bool $alreadySent = false;

    /**
     * @param string $message
     * @param array $data
     * @param int $code
     * @param array $body
     */
    public static function sendResponse(string $message, array $data = [], int $code = 200, array $body = []): void
    {
        if (!$code) {
            $code = http_response_code();
        }
        http_response_code($code);

        $data = self::encode_items($data);
        if (empty($data)) {
            unset($data);
        }
        if (empty($body)) {
            unset($body);
        }
        $response = compact('message', 'code', 'data', 'body');

        if ($code >= 200) {
            $status = 'error';
            $error = error_get_last();
            if (defined('FILE')) unlink(FILE);

            if (ENDPOINT == 'api/logs') {
                System::log_error(compact('status', 'code'));
            } else {
                System::log_error(compact('status', 'code', 'response', 'error'));
            }

        }

        ob_clean();
        header('Content-Type: application/json');
        die(json_encode($response, JSON_UNESCAPED_SLASHES));
    }

    private function json_encode()
    {
        if (!defined('DEBUG_MODE'))
            define('DEBUG_MODE', true);

        $response = $this->encode_items($this->response);
        $code = $this->code;
        $error = '';
        if (DEBUG_MODE) $error = $this->error;
        $status = $code !== HTTPStatusCodes::OK ? 'error' : 'success';
        if ($status === 'error') {
            self::$alreadySent = true;
        }
        $json = json_encode(compact('status', 'code', 'response', 'error'));
        if (!$json) {
            $message = 'Fatal error. JSON response malformed.';
            if (DEBUG_MODE) $error = json_last_error_msg();
            JsonResponse::sendResponse(compact('message', 'error'), HTTPStatusCodes::InternalServerError);
        } else {
            self::$json = $json;
        }
    }

    private static function encode_items($array)
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = self::encode_items($value);
            } elseif (is_object($value)) {

            } else {
                if (!mb_detect_encoding($value, 'UTF-8', true)) {
                    $array[$key] = utf8_encode($value);
                } elseif (gettype($value) == 'boolean') {
                    $array[$key] = (boolean)$value;
                } elseif (is_numeric($value)) {
                    $array[$key] = +$value;
                } elseif (gettype($value) == 'string') {
                    $array[$key] = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                }
            }
        }

        return $array;
    }

    /**
     * @param $array
     * @return mixed
     * @deprecated
     */
    private static function encode_items2($array)
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = self::encode_items2($value);
            } elseif (is_object($value)) {

            } else {
                if (!mb_detect_encoding($value, 'UTF-8', true)) {
                    $array[$key] = utf8_encode($value);
                } elseif (gettype($value) == 'boolean') {
                    $array[$key] = $value ? 'true' : 'false';
                } else {
                    $array[$key] = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                }
            }
        }

        return $array;
    }
}
