<?php

declare(strict_types=1);

namespace PHPAML\Data;

abstract class Migration
{
    abstract public function up(Connection $connection): void;

    abstract public function down(Connection $connection): void;
}
