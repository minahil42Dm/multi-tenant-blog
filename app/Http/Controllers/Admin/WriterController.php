<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TenantUserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWriterRequest;
use App\Models\Tenant;
use App\Models\Writer;
use App\Support\CentralAbility;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WriterController extends Controller
{
    public const SUSPENDED_TENANT_MESSAGE = 'Writers cannot be added to a suspended tenant.';

    public function index(Request $request, string $centralDomain, Tenant $tenant): Response
    {
        $actor = $request->user();
        $canEnterTenant = $tenant->isActive() && $tenant->primaryDomainName() !== null;

        $writers = $tenant->run(fn (): array => Writer::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Writer $writer) => [
                'id' => $writer->id,
                'name' => $writer->name,
                'email' => $writer->email,
                'role_label' => ($writer->role ?? TenantUserRole::Writer)->label(),
                'created_at' => $writer->created_at?->toDateString(),
                'can_impersonate' => $canEnterTenant && CentralAbility::canImpersonate($actor, $writer),
            ])
            ->all());

        return Inertia::render('Admin/Tenants/Writers/Index', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'domain' => $tenant->primaryDomainName(),
                'is_suspended' => $tenant->isSuspended(),
            ],
            'writers' => $writers,
            'csrfToken' => csrf_token(),
        ]);
    }

    public function create(string $centralDomain, Tenant $tenant): Response
    {
        abort_if($tenant->isSuspended(), 403, self::SUSPENDED_TENANT_MESSAGE);

        return Inertia::render('Admin/Tenants/Writers/Create', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'domain' => $tenant->primaryDomainName(),
            ],
            'roles' => array_map(fn (TenantUserRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
            ], TenantUserRole::cases()),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(StoreWriterRequest $request, string $centralDomain, Tenant $tenant): RedirectResponse
    {
        $attributes = $request->safe()->only(['name', 'email', 'password']);
        $role = TenantUserRole::from($request->validated('role'));

        $created = $tenant->run(function () use ($attributes, $role): bool {
            try {
                $writer = Writer::create($attributes);
                $writer->forceFill(['role' => $role])->save();
            } catch (UniqueConstraintViolationException) {
                return false;
            }

            return true;
        });

        if (! $created) {
            throw ValidationException::withMessages([
                'email' => trans('validation.unique', ['attribute' => 'email']),
            ]);
        }

        return redirect()
            ->route('admin.tenants.writers.index', $tenant)
            ->with('success', 'Writer created for '.$tenant->name.'.');
    }
}
