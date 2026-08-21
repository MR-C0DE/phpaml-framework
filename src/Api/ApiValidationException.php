<?php

declare(strict_types=1);

namespace PHPAML\Api;

use RuntimeException;

final class ApiValidationException extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(private array $errors)
    {
        parent::__construct('Les données sont invalides.');
    }

    /** @return array<string, list<string>> */
    public function errors(): array { return $this->errors; }
}
