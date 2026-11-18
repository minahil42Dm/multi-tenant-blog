<?php

declare(strict_types=1);

namespace App\Bootstrappers;

use Illuminate\Support\Facades\Cache;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class PrefixCacheTenancyBootstrapper implements TenancyBootstrapper
{
    protected ?string $originalPrefix = null;

    public function bootstrap(Tenant $tenant): void
    {
        $this->originalPrefix ??= (string) config('cache.prefix');

        config([
            'cache.prefix' => $this->originalPrefix.'tenant_'.$tenant->getTenantKey().'_',
        ]);

        $this->forgetCacheDrivers();
    }

    public function revert(): void
    {
        if ($this->originalPrefix !== null) {
            config(['cache.prefix' => $this->originalPrefix]);
        }

        $this->forgetCacheDrivers();
    }

    private function forgetCacheDrivers(): void
    {
        Cache::clearResolvedInstances();

        if (app()->resolved('cache')) {
            app('cache')->forgetDriver(config('cache.default'));
        }
    }
}
