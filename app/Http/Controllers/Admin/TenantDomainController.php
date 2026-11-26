<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTenantDomainRequest;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TenantDomainController extends Controller
{
    /**
     * @throws ValidationException
     */
    public function store(StoreTenantDomainRequest $request, string $centralDomain, Tenant $tenant): RedirectResponse
    {
        try {
            $domain = $tenant->domains()->create(['domain' => $request->validated('domain')]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'domain' => trans('validation.unique', ['attribute' => 'domain']),
            ]);
        }

        AuditLog::record('domain.added', $request->user(), (string) $tenant->getTenantKey(), [
            'domain' => $domain->domain,
            'is_primary' => (bool) $domain->is_primary,
        ]);

        return redirect()
            ->route('admin.tenants.edit', $tenant)
            ->with('success', $domain->domain.' was added.');
    }

    public function destroy(Request $request, string $centralDomain, Tenant $tenant, int $domain): RedirectResponse
    {
        $result = DB::connection($this->centralConnection())->transaction(function () use ($tenant, $domain): string|Domain {
            $domains = $this->lockTenantDomains($tenant);
            $target = $domains->firstWhere('id', $domain);

            abort_if($target === null, 404);

            if ($domains->count() <= 1) {
                return 'A tenant must keep at least one domain.';
            }

            if ($target->is_primary) {
                return 'Make another domain primary before removing '.$target->domain.'.';
            }

            $target->delete();

            return $target;
        });

        if (is_string($result)) {
            return redirect()->route('admin.tenants.edit', $tenant)->with('error', $result);
        }

        AuditLog::record('domain.removed', $request->user(), (string) $tenant->getTenantKey(), [
            'domain' => $result->domain,
        ]);

        return redirect()
            ->route('admin.tenants.edit', $tenant)
            ->with('success', $result->domain.' was removed.');
    }

    public function makePrimary(Request $request, string $centralDomain, Tenant $tenant, int $domain): RedirectResponse
    {
        [$target, $previous] = DB::connection($this->centralConnection())->transaction(function () use ($tenant, $domain): array {
            $domains = $this->lockTenantDomains($tenant);
            $target = $domains->firstWhere('id', $domain);

            abort_if($target === null, 404);

            $previous = $domains->firstWhere('is_primary', true);

            Domain::query()
                ->where('tenant_id', $tenant->getTenantKey())
                ->whereKeyNot($target->getKey())
                ->update(['is_primary' => false]);

            Domain::query()->whereKey($target->getKey())->update(['is_primary' => true]);

            return [$target, $previous];
        });

        if ($previous?->is($target)) {
            return redirect()
                ->route('admin.tenants.edit', $tenant)
                ->with('error', $target->domain.' is already the primary domain.');
        }

        AuditLog::record('domain.primary_changed', $request->user(), (string) $tenant->getTenantKey(), [
            'domain' => $target->domain,
            'previous' => $previous?->domain,
        ]);

        return redirect()
            ->route('admin.tenants.edit', $tenant)
            ->with('success', $target->domain.' is now the primary domain.');
    }

    /**
     * @return Collection<int, Domain>
     */
    private function lockTenantDomains(Tenant $tenant)
    {
        return Domain::query()
            ->where('tenant_id', $tenant->getTenantKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function centralConnection(): string
    {
        return config('tenancy.database.central_connection');
    }
}
