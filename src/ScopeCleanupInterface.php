<?php

declare(strict_types=1);

namespace PHPAML;

interface ScopeCleanupInterface
{
    public function endScope(): void;
}
