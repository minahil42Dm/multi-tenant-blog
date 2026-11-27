<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * White-label branding. Settings live in the central tenant record's `data` JSON; uploaded
 * logo and favicon files live in the tenant's own public disk under `branding/{tenantId}/`
 * and are served only through the tenant-scoped `/tenancy/assets/` route.
 */
class TenantBranding
{
    public const DEFAULT_COLOR = '#1d4ed8';

    public const DEFAULT_ACCENT_COLOR = '#f59e0b';

    public const DEFAULT_NAME = 'Blog';

    public const SIDEBAR_STYLES = ['logo_and_name', 'logo', 'name'];

    public const DEFAULT_SIDEBAR_STYLE = 'logo_and_name';

    public const MAX_FOOTER_LINKS = 5;

    public const MAX_SIDEBAR_LINKS = 5;

    public const LINK_ICONS = ['link', 'home', 'mail', 'phone', 'book', 'users', 'star'];

    public const ASSETS = ['logo', 'favicon'];

    public const CACHE_KEY = 'tenant.branding';

    public const CACHE_TTL = 3600;

    private const LEGACY_LOGO_PATTERN = '/^tenant-logos\/[A-Za-z0-9][A-Za-z0-9._-]*$/';

    private const ASSET_FILE_PATTERN = '/^[A-Za-z0-9]{1,64}\.[a-z0-9]{2,5}$/';

    /**
     * Branding for the current tenant, cached under the tenant-prefixed cache key.
     *
     * @return array{name: string, color: string, accent_color: string, logo_url: ?string, favicon_url: ?string, support_email: ?string, sidebar_style: string, sidebar_tagline: ?string, sidebar_links: array<int, array{label: string, url: string, icon: string, order: int}>, footer_links: array<int, array{label: string, url: string}>}
     */
    public static function current(): array
    {
        if (! tenancy()->initialized) {
            return self::present(self::settings(null));
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $branding = self::present(self::settings(tenant()));

        Cache::put(self::CACHE_KEY, $branding, self::CACHE_TTL);

        return $branding;
    }

    public static function forget(): void
    {
        if (tenancy()->initialized) {
            Cache::forget(self::CACHE_KEY);
        }
    }

    /**
     * Clears only the given tenant's branding cache, from any context.
     */
    public static function forgetFor(Tenant $tenant): void
    {
        try {
            $tenant->run(fn () => self::forget());
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Normalized stored settings, with file paths rather than URLs.
     *
     * @return array{name: string, brand_color: string, accent_color: string, logo: ?string, favicon: ?string, support_email: ?string, sidebar_style: string, sidebar_tagline: ?string, sidebar_links: array<int, array{label: string, url: string, icon: string, order: int}>, footer_links: array<int, array{label: string, url: string}>}
     */
    public static function settings(?Tenant $tenant): array
    {
        $value = fn (string $key): mixed => $tenant?->getAttribute($key);
        $string = fn (string $key): ?string => is_string($value($key)) && trim($value($key)) !== '' ? trim($value($key)) : null;

        $supportEmail = $string('support_email');
        $sidebarStyle = $string('sidebar_style');

        return [
            'name' => self::name($string('name')),
            'brand_color' => self::color($string('brand_color')),
            'accent_color' => self::color($string('accent_color'), self::DEFAULT_ACCENT_COLOR),
            'logo' => $string('logo'),
            'favicon' => $string('favicon'),
            'support_email' => $supportEmail !== null && filter_var($supportEmail, FILTER_VALIDATE_EMAIL) ? $supportEmail : null,
            'sidebar_style' => in_array($sidebarStyle, self::SIDEBAR_STYLES, true) ? $sidebarStyle : self::DEFAULT_SIDEBAR_STYLE,
            'sidebar_tagline' => $string('sidebar_tagline'),
            'footer_links' => self::footerLinks($value('footer_links')),
            'sidebar_links' => self::sidebarLinks($value('sidebar_links')),
        ];
    }

    public static function name(?string $name): string
    {
        $name = trim((string) $name);

        return $name !== '' ? $name : self::DEFAULT_NAME;
    }

    public static function color(?string $color, string $default = self::DEFAULT_COLOR): string
    {
        $color = strtolower(trim((string) $color));

        return preg_match('/^#[0-9a-f]{6}$/', $color) === 1
            ? $color
            : $default;
    }

    /**
     * Only http(s) links with a label are kept, so stored data can never produce a javascript: link.
     *
     * @return array<int, array{label: string, url: string}>
     */
    public static function footerLinks(mixed $links): array
    {
        if (! is_array($links)) {
            return [];
        }

        return collect($links)
            ->filter(fn (mixed $link) => is_array($link)
                && is_string($link['label'] ?? null) && trim($link['label']) !== ''
                && is_string($link['url'] ?? null)
                && in_array(strtolower((string) parse_url($link['url'], PHP_URL_SCHEME)), ['http', 'https'], true))
            ->map(fn (array $link) => ['label' => trim($link['label']), 'url' => trim($link['url'])])
            ->take(self::MAX_FOOTER_LINKS)
            ->values()
            ->all();
    }

    /**
     * Custom navigation links. Order is the display order. Icons are limited to a
     * fixed set so the stored value cannot become markup.
     *
     * @return array<int, array{label: string, url: string, icon: string, order: int}>
     */
    public static function sidebarLinks(mixed $links): array
    {
        if (! is_array($links)) {
            return [];
        }

        return collect($links)
            ->filter(fn (mixed $link) => is_array($link)
                && is_string($link['label'] ?? null) && trim($link['label']) !== ''
                && is_string($link['url'] ?? null)
                && in_array(strtolower((string) parse_url($link['url'], PHP_URL_SCHEME)), ['http', 'https'], true))
            ->values()
            ->map(function (array $link, int $index): array {
                $icon = is_string($link['icon'] ?? null) ? $link['icon'] : 'link';

                return [
                    'label' => trim($link['label']),
                    'url' => trim($link['url']),
                    'icon' => in_array($icon, self::LINK_ICONS, true) ? $icon : 'link',
                    'order' => is_numeric($link['order'] ?? null) ? (int) $link['order'] : $index,
                ];
            })
            ->sortBy('order')
            ->take(self::MAX_SIDEBAR_LINKS)
            ->values()
            ->all();
    }

    public static function logoUrl(?string $path): ?string
    {
        $path = str_replace('\\', '/', trim((string) $path));

        if (self::isLegacyLogo($path)) {
            return is_file(base_path('storage/app/public/'.$path)) ? '/storage/'.$path : null;
        }

        return self::tenantAssetUrl($path);
    }

    public static function faviconUrl(?string $path): ?string
    {
        return self::tenantAssetUrl(str_replace('\\', '/', trim((string) $path)));
    }

    public static function isLegacyLogo(?string $path): bool
    {
        return is_string($path) && preg_match(self::LEGACY_LOGO_PATTERN, $path) === 1;
    }

    /**
     * True only for a file directly inside `branding/{tenantId}/` of the given tenant.
     */
    public static function isTenantAsset(?string $path, string $tenantId): bool
    {
        if (! is_string($path) || ! str_starts_with($path, 'branding/'.$tenantId.'/')) {
            return false;
        }

        return preg_match(self::ASSET_FILE_PATTERN, substr($path, strlen('branding/'.$tenantId.'/'))) === 1;
    }

    /**
     * Stores an uploaded logo or favicon in the current tenant's disk.
     */
    public static function storeAsset(UploadedFile $file): string
    {
        $path = $file->store(self::assetDirectory(), 'public');

        if (! is_string($path) || ! self::isTenantAsset($path, (string) tenant()->getTenantKey())) {
            throw new RuntimeException('The branding file could not be stored.');
        }

        return $path;
    }

    /**
     * Deletes a branding file of the given tenant. Legacy logos live in the central public
     * disk; newer files live in the tenant's own disk. Anything else is left alone.
     */
    public static function deleteAsset(Tenant $tenant, ?string $path): void
    {
        if (self::isLegacyLogo($path)) {
            TenantLifecycle::deleteLogo($path);

            return;
        }

        if (! self::isTenantAsset($path, (string) $tenant->getTenantKey())) {
            return;
        }

        try {
            $tenant->run(fn () => Storage::disk('public')->delete($path));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Absolute path of a tenant's logo or favicon, for the central admin preview.
     */
    public static function assetPath(Tenant $tenant, string $asset): ?string
    {
        $path = $tenant->getAttribute($asset);

        if ($asset === 'logo' && self::isLegacyLogo($path)) {
            $absolute = base_path('storage/app/public/'.$path);

            return is_file($absolute) ? $absolute : null;
        }

        if (! self::isTenantAsset($path, (string) $tenant->getTenantKey())) {
            return null;
        }

        return $tenant->run(fn () => Storage::disk('public')->exists($path)
            ? Storage::disk('public')->path($path)
            : null);
    }

    /**
     * URL the central admin uses to preview a tenant's logo or favicon.
     */
    public static function adminAssetUrl(Tenant $tenant, string $asset): ?string
    {
        $path = $tenant->getAttribute($asset);

        if ($asset === 'logo' && self::isLegacyLogo($path)) {
            return Storage::disk('public')->url($path);
        }

        if (! self::isTenantAsset($path, (string) $tenant->getTenantKey())) {
            return null;
        }

        return route('admin.tenants.branding.asset', [
            'tenant' => $tenant,
            'asset' => $asset,
            'v' => substr(sha1($path), 0, 12),
        ]);
    }

    /**
     * @param  array{name: string, brand_color: string, accent_color: string, logo: ?string, favicon: ?string, support_email: ?string, sidebar_style: string, sidebar_tagline: ?string, footer_links: array<int, array{label: string, url: string}>}  $settings
     * @return array{name: string, color: string, accent_color: string, logo_url: ?string, favicon_url: ?string, support_email: ?string, sidebar_style: string, sidebar_tagline: ?string, footer_links: array<int, array{label: string, url: string}>}
     */
    private static function present(array $settings): array
    {
        return [
            'name' => $settings['name'],
            'color' => $settings['brand_color'],
            'accent_color' => $settings['accent_color'],
            'logo_url' => self::logoUrl($settings['logo']),
            'favicon_url' => self::faviconUrl($settings['favicon']),
            'support_email' => $settings['support_email'],
            'sidebar_style' => $settings['sidebar_style'],
            'sidebar_tagline' => $settings['sidebar_tagline'],
            'sidebar_links' => $settings['sidebar_links'],
            'footer_links' => $settings['footer_links'],
        ];
    }

    /**
     * Relative URL so it works on every domain of the tenant. The tenant assets route reads
     * only from the current tenant's storage, so another tenant's file can never be served.
     */
    private static function tenantAssetUrl(string $path): ?string
    {
        if (! tenancy()->initialized || ! self::isTenantAsset($path, (string) tenant()->getTenantKey())) {
            return null;
        }

        return Storage::disk('public')->exists($path) ? '/tenancy/assets/'.$path : null;
    }

    private static function assetDirectory(): string
    {
        if (! tenancy()->initialized) {
            throw new RuntimeException('Branding files require an initialized tenant.');
        }

        return 'branding/'.tenant()->getTenantKey();
    }
}
