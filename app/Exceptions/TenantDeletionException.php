<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class TenantDeletionException extends RuntimeException
{
    public function __construct(
        public readonly bool $tenantRecordRemains,
        public readonly string $databaseName,
        ?Throwable $previous = null,
    ) {
        parent::__construct('The tenant could not be deleted completely.', 0, $previous);
    }
}
