<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTenantRequest;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class TenantController extends Controller
{
    public function dashboard(): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'tenantCount' => Tenant::query()->count(),
        ]);
    }

    public function index(): Response
    {
        $tenants = Tenant::query()
            ->with('domains')
            ->latest()
            ->get()
            ->map(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'domain' => $tenant->domains->first()?->domain,
                'post_count' => $this->postCount($tenant),
                'brand_color' => $tenant->brand_color,
                'logo_url' => $tenant->logo ? Storage::disk('public')->url($tenant->logo) : null,
            ]);

        return Inertia::render('Admin/Tenants/Index', [
            'tenants' => $tenants,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Tenants/Create');
    }

    public function store(StoreTenantRequest $request): RedirectResponse
    {
        $logoPath = $request->file('logo')->store('tenant-logos', 'public');
        $tenant = null;

        try {
            $tenant = Tenant::create([
                'name' => $request->string('name')->toString(),
                'brand_color' => $request->string('brand_color')->toString(),
                'logo' => $logoPath,
            ]);

            $tenant->createDomain($request->string('domain')->toString());
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($logoPath);

            if ($tenant?->exists) {
                $tenant->delete();
            }

            report($exception);

            return back()
                ->withInput()
                ->withErrors([
                    'domain' => 'The tenant database could not be provisioned.',
                ]);
        }

        return redirect()
            ->route('admin.tenants.index')
            ->with('success', 'Tenant created.');
    }

    private function postCount(Tenant $tenant): int
    {
        try {
            return (int) $tenant->run(fn () => DB::table('posts')->count());
        } catch (Throwable $exception) {
            report($exception);

            return 0;
        }
    }
}
