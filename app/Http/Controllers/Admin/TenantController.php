<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TenantStatus;
use App\Exceptions\TenantDeletionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\DestroyTenantRequest;
use App\Http\Requests\StoreTenantRequest;
use App\Http\Requests\ValidateTenantStepRequest;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Tenant;
use App\Support\HomepageCache;
use App\Support\TenantBranding;
use App\Support\TenantLifecycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class TenantController extends Controller
{
    public const PER_PAGE = 10;

    public function dashboard(Request $request): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'tenantCount' => Tenant::query()->count(),
            'roleLabel' => $request->user()->role?->label(),
        ]);
    }

    public function index(Request $request): Response
    {
        $search = mb_substr(trim($request->string('search')->toString()), 0, 100);
        $status = TenantStatus::tryFrom($request->string('status')->toString());

        $paginator = Tenant::query()
            ->with('domains')
            ->when($search !== '', fn (Builder $query) => $this->applySearch($query, $search))
            ->when($status, fn (Builder $query) => $query->withStatus($status))
            ->latest()
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('Admin/Tenants/Index', [
            'tenants' => collect($paginator->items())->map(fn (Tenant $tenant) => $this->summary($tenant))->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
                'links' => $paginator->linkCollection()->all(),
            ],
            'filters' => [
                'search' => $search,
                'status' => $status?->value ?? '',
            ],
            'statuses' => collect(TenantStatus::cases())
                ->map(fn (TenantStatus $case) => ['value' => $case->value, 'label' => $case->label()])
                ->all(),
            'totalTenants' => Tenant::query()->count(),
        ]);
    }

    public function show(string $centralDomain, Tenant $tenant): Response
    {
        $tenant->load('domains');

        return Inertia::render('Admin/Tenants/Show', [
            'tenant' => $this->summary($tenant),
            'deleteConfirmation' => DestroyTenantRequest::confirmationValue($tenant),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Tenants/Create', [
            'maxAdditionalDomains' => StoreTenantRequest::MAX_ADDITIONAL_DOMAINS,
        ]);
    }

    public function validateStep(ValidateTenantStepRequest $request): RedirectResponse
    {
        return redirect()->route('admin.tenants.create');
    }

    public function store(StoreTenantRequest $request): RedirectResponse
    {
        try {
            $tenant = TenantLifecycle::provision(
                [
                    'name' => $request->validated('name'),
                    'brand_color' => $request->validated('brand_color'),
                ],
                $request->domains(),
                [
                    'name' => $request->validated('admin_name'),
                    'email' => $request->validated('admin_email'),
                    'password' => $request->validated('admin_password'),
                ],
                $request->file('logo'),
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'provisioning' => 'The tenant could not be provisioned. Nothing was created; please try again.',
            ]);
        }

        AuditLog::record('tenant.created', $request->user(), (string) $tenant->getTenantKey(), [
            'name' => $tenant->name,
            'domains' => $request->domains(),
            'admin_email' => $request->validated('admin_email'),
        ]);

        return redirect()
            ->route('admin.tenants.index')
            ->with('success', 'Tenant created. '.$request->validated('admin_email').' can now sign in at '.$request->domains()[0].'/login.');
    }

    public function edit(string $centralDomain, Tenant $tenant): Response
    {
        $tenant->load('domains');

        return Inertia::render('Admin/Tenants/Edit', [
            'tenant' => $this->summary($tenant, withCounts: false),
        ]);
    }

    public function destroy(DestroyTenantRequest $request, string $centralDomain, Tenant $tenant): RedirectResponse
    {
        if (! $tenant->isSuspended()) {
            return redirect()
                ->route('admin.tenants.show', $tenant)
                ->with('error', 'Suspend '.$tenant->name.' before deleting it.');
        }

        $tenantId = (string) $tenant->getTenantKey();
        $name = $tenant->name;
        $domains = $tenant->domains()->pluck('domain')->all();

        try {
            TenantLifecycle::delete($tenant);
        } catch (TenantDeletionException $exception) {
            report($exception);

            AuditLog::record('tenant.delete_failed', $request->user(), $tenantId, [
                'name' => $name,
                'domains' => $domains,
                'database' => $exception->databaseName,
                'record_remains' => $exception->tenantRecordRemains,
            ]);

            if ($exception->tenantRecordRemains) {
                return redirect()
                    ->route('admin.tenants.show', $tenantId)
                    ->with('error', $name.' could not be deleted. Nothing was removed; please try again.');
            }

            return redirect()
                ->route('admin.tenants.index')
                ->with('error', $name.' was removed, but its database '.$exception->databaseName.' could not be dropped. It needs manual cleanup.');
        }

        AuditLog::record('tenant.deleted', $request->user(), $tenantId, [
            'name' => $name,
            'domains' => $domains,
        ]);

        return redirect()
            ->route('admin.tenants.index')
            ->with('success', $name.' and its database have been deleted.');
    }

    public function suspend(Request $request, string $centralDomain, Tenant $tenant): RedirectResponse
    {
        if (! $tenant->suspend()) {
            return $this->redirectAfterStatusChange($request, $tenant)
                ->with('error', $tenant->name.' is already suspended.');
        }

        $this->forgetTenantCache($tenant);
        AuditLog::record('tenant.suspended', $request->user(), (string) $tenant->getTenantKey());

        return $this->redirectAfterStatusChange($request, $tenant)
            ->with('success', $tenant->name.' has been suspended.');
    }

    public function reactivate(Request $request, string $centralDomain, Tenant $tenant): RedirectResponse
    {
        if (! $tenant->reactivate()) {
            return $this->redirectAfterStatusChange($request, $tenant)
                ->with('error', $tenant->name.' is already active.');
        }

        $this->forgetTenantCache($tenant);
        AuditLog::record('tenant.reactivated', $request->user(), (string) $tenant->getTenantKey());

        return $this->redirectAfterStatusChange($request, $tenant)
            ->with('success', $tenant->name.' has been reactivated.');
    }

    /**
     * Tenant names live in the `data` JSON column, so the search compiles the JSON path
     * with the active grammar. `!` is the LIKE escape character on both MySQL and SQLite.
     *
     * @param  Builder<Tenant>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
        $name = $query->getQuery()->getGrammar()->wrap('data->name');

        $query->where(function (Builder $query) use ($pattern, $name): void {
            $query->whereRaw('lower('.$name.') like ? escape \'!\'', [$pattern])
                ->orWhereHas('domains', fn (Builder $domains) => $domains->whereRaw('lower(domain) like ? escape \'!\'', [$pattern]));
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Tenant $tenant, bool $withCounts = true): array
    {
        $domains = $tenant->domains
            ->sortBy([['is_primary', 'desc'], ['domain', 'asc']])
            ->values();

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'domain' => $tenant->primaryDomainName(),
            'domains' => $domains->map(fn (Domain $domain) => [
                'id' => $domain->id,
                'domain' => $domain->domain,
                'is_primary' => (bool) $domain->is_primary,
            ])->all(),
            'is_suspended' => $tenant->isSuspended(),
            'status' => $tenant->status->value,
            'status_label' => $tenant->status->label(),
            'suspended_at' => $tenant->suspended_at?->toDayDateTimeString(),
            'created_at' => $tenant->created_at?->toDayDateTimeString(),
            ...($withCounts ? $this->counts($tenant) : []),
            'brand_color' => $tenant->brand_color,
            'accent_color' => TenantBranding::settings($tenant)['accent_color'],
            'logo_url' => TenantBranding::adminAssetUrl($tenant, 'logo'),
        ];
    }

    private function redirectAfterStatusChange(Request $request, Tenant $tenant): RedirectResponse
    {
        return $request->input('redirect_to') === 'show'
            ? redirect()->route('admin.tenants.show', $tenant)
            : redirect()->route('admin.tenants.index');
    }

    private function forgetTenantCache(Tenant $tenant): void
    {
        try {
            $tenant->run(fn () => HomepageCache::forget());
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return array{post_count: int, writer_count: int}
     */
    private function counts(Tenant $tenant): array
    {
        try {
            return $tenant->run(fn () => [
                'post_count' => (int) DB::table('posts')->count(),
                'writer_count' => (int) DB::table('users')->count(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return ['post_count' => 0, 'writer_count' => 0];
        }
    }
}
