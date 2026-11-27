<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateTenantBrandingRequest;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Support\TenantBranding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class TenantBrandingController extends Controller
{
    private const EDITABLE = ['name', 'brand_color', 'accent_color', 'support_email', 'sidebar_style', 'sidebar_tagline', 'sidebar_links', 'footer_links'];

    public function edit(string $centralDomain, Tenant $tenant): Response
    {
        $settings = TenantBranding::settings($tenant);

        return Inertia::render('Admin/Tenants/Branding', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'domain' => $tenant->primaryDomainName(),
                'is_suspended' => $tenant->isSuspended(),
            ],
            'branding' => [
                ...collect($settings)->only(self::EDITABLE)->all(),
                'logo_url' => TenantBranding::adminAssetUrl($tenant, 'logo'),
                'favicon_url' => TenantBranding::adminAssetUrl($tenant, 'favicon'),
            ],
            'options' => [
                'sidebar_styles' => [
                    ['value' => 'logo_and_name', 'label' => 'Logo and name'],
                    ['value' => 'logo', 'label' => 'Logo only'],
                    ['value' => 'name', 'label' => 'Name only'],
                ],
                'max_footer_links' => TenantBranding::MAX_FOOTER_LINKS,
                'max_sidebar_links' => TenantBranding::MAX_SIDEBAR_LINKS,
                'link_icons' => TenantBranding::LINK_ICONS,
            ],
        ]);
    }

    public function update(UpdateTenantBrandingRequest $request, string $centralDomain, Tenant $tenant): RedirectResponse
    {
        $before = $this->snapshot($tenant);
        $uploads = array_filter(['logo' => $request->file('logo'), 'favicon' => $request->file('favicon')]);

        try {
            $stored = $tenant->run(function () use ($uploads): array {
                $stored = [];

                try {
                    foreach ($uploads as $asset => $file) {
                        $stored[$asset] = TenantBranding::storeAsset($file);
                    }
                } catch (Throwable $exception) {
                    Storage::disk('public')->delete(array_values($stored));

                    throw $exception;
                }

                return $stored;
            });
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                array_key_first($uploads) ?? 'branding' => 'The file could not be uploaded. Nothing was changed; please try again.',
            ]);
        }

        $attributes = [
            'name' => $request->validated('name'),
            'brand_color' => $request->validated('brand_color'),
            'accent_color' => $request->validated('accent_color'),
            'support_email' => $request->validated('support_email'),
            'sidebar_style' => $request->validated('sidebar_style'),
            'sidebar_tagline' => $request->validated('sidebar_tagline'),
            'footer_links' => TenantBranding::footerLinks($request->validated('footer_links') ?? []),
            ...$stored,
        ];

        if ($request->exists('sidebar_links')) {
            $attributes['sidebar_links'] = TenantBranding::sidebarLinks($request->validated('sidebar_links') ?? []);
        }

        if (! isset($stored['favicon']) && $request->boolean('remove_favicon')) {
            $attributes['favicon'] = null;
        }

        try {
            $tenant->update($attributes);
        } catch (Throwable $exception) {
            report($exception);

            foreach ($stored as $path) {
                TenantBranding::deleteAsset($tenant, $path);
            }

            return back()->withErrors(['branding' => 'The branding could not be saved. Nothing was changed; please try again.']);
        }

        foreach (TenantBranding::ASSETS as $asset) {
            if (array_key_exists($asset, $attributes) && $before[$asset] !== $attributes[$asset]) {
                TenantBranding::deleteAsset($tenant, $before[$asset]);
            }
        }

        TenantBranding::forgetFor($tenant);

        $after = $this->snapshot($tenant);

        AuditLog::record('tenant.branding_updated', $request->user(), (string) $tenant->getTenantKey(), [
            'changed' => array_keys(array_filter($after, fn (mixed $value, string $key) => $value !== $before[$key], ARRAY_FILTER_USE_BOTH)),
        ]);

        return redirect()
            ->route('admin.tenants.branding.edit', $tenant)
            ->with('success', 'Branding for '.$tenant->name.' has been updated.');
    }

    public function asset(string $centralDomain, Tenant $tenant, string $asset): BinaryFileResponse
    {
        $path = TenantBranding::assetPath($tenant, $asset);

        abort_if($path === null, 404);

        return response()->file($path, [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Tenant $tenant): array
    {
        return collect([...self::EDITABLE, ...TenantBranding::ASSETS])
            ->mapWithKeys(fn (string $key) => [$key => match ($key) {
                'sidebar_links' => TenantBranding::sidebarLinks($tenant->getAttribute($key)),
                'footer_links' => TenantBranding::footerLinks($tenant->getAttribute($key)),
                default => $tenant->getAttribute($key),
            }])
            ->all();
    }
}
