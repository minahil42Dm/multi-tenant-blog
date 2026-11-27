<?php

namespace App\Models\Concerns;

use RuntimeException;

trait RequiresTenant
{
    public function getConnectionName(): ?string
    {
        if (! tenancy()->initialized) {
            throw new RuntimeException('Tenant queries require an initialized tenant.');
        }

        return parent::getConnectionName();
    }
}
