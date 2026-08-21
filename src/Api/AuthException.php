<?php

declare(strict_types=1);

namespace PHPAML\Api;

use RuntimeException;

final class AuthException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
