<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\Domain;
use Stancl\Tenancy\Events\DeletingTenant;
use Stancl\Tenancy\Events\DomainCreated;
use Stancl\Tenancy\Events\DomainDeleted;
use Throwable;

/**
 * Registers tenant domains with Laravel Herd so they resolve locally.
 * Herd's `link` command also writes the Windows hosts entry, which does not support wildcards.
 */
class SyncHerdSites
{
    public function linkDomain(DomainCreated $event): void
    {
        $this->run('link', $event->domain);
    }

    public function unlinkDomain(DomainDeleted $event): void
    {
        $this->run('unlink', $event->domain);
    }

    /**
     * Tenant deletion cascades domains in the database without firing DomainDeleted.
     */
    public function unlinkTenantDomains(DeletingTenant $event): void
    {
        $event->tenant->domains->each(fn (Domain $domain) => $this->run('unlink', $domain));
    }

    private function run(string $command, Domain $domain): void
    {
        if (! config('services.herd.link_tenant_domains')) {
            return;
        }

        $suffix = '.'.config('services.herd.tld');

        if (! str_ends_with($domain->domain, $suffix)) {
            return;
        }

        $site = Str::beforeLast($domain->domain, $suffix);

        try {
            $result = Process::path(base_path())
                ->timeout(60)
                ->run([config('services.herd.binary'), $command, $site]);
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        if ($result->failed()) {
            Log::warning("Herd {$command} failed for tenant domain [{$domain->domain}].", [
                'output' => trim($result->errorOutput() ?: $result->output()),
            ]);
        }
    }
}
