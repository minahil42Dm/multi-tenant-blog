<?php

namespace App\Support;

class TenantBranding
{
    public const DEFAULT_COLOR = '#1d4ed8';

    public const DEFAULT_NAME = 'Blog';

    /**
     * @return array{name: string, color: string, logo_url: ?string}
     */
    public static function current(): array
    {
        $tenant = tenant();

        return [
            'name' => self::name(is_string($tenant?->name ?? null) ? $tenant->name : null),
            'color' => self::color(is_string($tenant?->brand_color ?? null) ? $tenant->brand_color : null),
            'logo_url' => self::logoUrl(is_string($tenant?->logo ?? null) ? $tenant->logo : null),
        ];
    }

    public static function name(?string $name): string
    {
        $name = trim((string) $name);

        return $name !== '' ? $name : self::DEFAULT_NAME;
    }

    public static function color(?string $color): string
    {
        $color = strtolower(trim((string) $color));

        return preg_match('/^#[0-9a-f]{6}$/', $color) === 1
            ? $color
            : self::DEFAULT_COLOR;
    }

    public static function logoUrl(?string $path): ?string
    {
        $path = str_replace('\\', '/', trim((string) $path));

        if (preg_match('/^tenant-logos\/[A-Za-z0-9][A-Za-z0-9._-]*$/', $path) !== 1) {
            return null;
        }

        $absolute = base_path('storage/app/public/'.$path);

        if (! is_file($absolute)) {
            return null;
        }

        return '/storage/'.$path;
    }
}
