<?php

declare(strict_types=1);

namespace PHPAML\Http;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(
        private int $statusCode,
        private string $publicMessage
    ) {
        parent::__construct($publicMessage);
    }

    public function statusCode(): int { return $this->statusCode; }
    public function publicMessage(): string { return $this->publicMessage; }
}
