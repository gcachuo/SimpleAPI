<?php

class HTTPStatusCodes
{
    public const __default = self::OK;

    public const OK = 200;
    public const BadRequest = 400;
    public const Unauthorized = 401;
    public const NotFound = 404;
    public const MethodNotAllowed = 405;
    public const InternalServerError = 500;
    public const NotImplemented = 501;
    public const ServiceUnavailable = 503;
    public const Forbidden = 403;
}
