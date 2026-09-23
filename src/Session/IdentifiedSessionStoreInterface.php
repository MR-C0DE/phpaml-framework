<?php

declare(strict_types=1);

namespace PHPAML\Session;

interface IdentifiedSessionStoreInterface extends SessionStoreInterface
{
    public function id(): string;
}
