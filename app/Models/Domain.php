<?php

namespace App\Models;

use Stancl\Tenancy\Database\Models\Domain as BaseDomain;

/**
 * Stancl's domain model with a primary flag. Exactly one domain per tenant is primary;
 * the first domain a tenant receives becomes primary automatically.
 */
class Domain extends BaseDomain
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Domain $domain): void {
            if ($domain->is_primary) {
                return;
            }

            $domain->is_primary = ! static::query()
                ->where('tenant_id', $domain->tenant_id)
                ->where('is_primary', true)
                ->exists();
        });
    }
}
