<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    /**
     * Lifecycle columns change only through suspend() and reactivate(), never mass assignment.
     * A non-empty $guarded would also drop stancl's virtual `data` attributes (name, branding),
     * so they are filtered here instead.
     */
    private const LIFECYCLE_ATTRIBUTES = ['status', 'suspended_at'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'status',
            'suspended_at',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function fill(array $attributes)
    {
        if (! static::isUnguarded()) {
            $attributes = array_diff_key($attributes, array_flip(self::LIFECYCLE_ATTRIBUTES));
        }

        return parent::fill($attributes);
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === TenantStatus::Suspended;
    }

    /**
     * Returns false when the tenant was already suspended.
     */
    public function suspend(): bool
    {
        return $this->transitionStatus(TenantStatus::Active, TenantStatus::Suspended, now());
    }

    /**
     * Returns false when the tenant was already active.
     */
    public function reactivate(): bool
    {
        return $this->transitionStatus(TenantStatus::Suspended, TenantStatus::Active, null);
    }

    /**
     * The status is updated with a conditional query so concurrent requests cannot both apply.
     */
    private function transitionStatus(TenantStatus $from, TenantStatus $to, mixed $suspendedAt): bool
    {
        $timestamp = $this->freshTimestamp();

        $updated = static::query()
            ->whereKey($this->getKey())
            ->where('status', $from->value)
            ->update([
                'status' => $to->value,
                'suspended_at' => $suspendedAt,
                'updated_at' => $timestamp,
            ]);

        if ($updated === 0) {
            $this->refresh();

            return false;
        }

        $this->forceFill([
            'status' => $to,
            'suspended_at' => $suspendedAt,
            'updated_at' => $timestamp,
        ])->syncOriginal();

        return true;
    }

    /**
     * @param  Builder<Tenant>  $query
     */
    public function scopeWithStatus(Builder $query, TenantStatus $status): void
    {
        $query->where('status', $status->value);
    }

    /**
     * @return HasOne<Domain, $this>
     */
    public function primaryDomain(): HasOne
    {
        return $this->hasOne(config('tenancy.domain_model'), 'tenant_id')->where('is_primary', true);
    }

    public function primaryDomainName(): ?string
    {
        $domains = $this->relationLoaded('domains') ? $this->domains : $this->domains()->get();

        return ($domains->firstWhere('is_primary', true) ?? $domains->sortBy('id')->first())?->domain;
    }

    /**
     * Stancl's TenantRun::run() skips restoring the previous context when initialization
     * or the callback throws. Initialization can fail after tenancy is marked initialized.
     *
     * @return mixed
     */
    public function run(callable $callback)
    {
        $originalTenant = tenant();

        try {
            tenancy()->initialize($this);

            return $callback($this);
        } finally {
            if ($originalTenant) {
                tenancy()->initialize($originalTenant);
            } else {
                tenancy()->end();
            }
        }
    }
}
