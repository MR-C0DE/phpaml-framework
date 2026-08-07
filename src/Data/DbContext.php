<?php

declare(strict_types=1);

namespace PHPAML\Data;

abstract class DbContext
{
    protected QueryBuilder $query;

    public function __construct(protected Connection $connection)
    {
        $this->query = new QueryBuilder($connection);
    }
}
