<?php

namespace A17\CDN\Exceptions;

class FrontendChecker extends \Exception
{
    public static function unsupportedType(string $type): void
    {
        throw new self(
            "UNSUPPORTED TYPE: we cannot check if the application is on frontend using '$type'",
        );
    }
}
