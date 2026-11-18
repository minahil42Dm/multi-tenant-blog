<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class TenantCoverImage
{
    public static function sync(Request $request, ?string $current): ?string
    {
        if ($request->hasFile('cover_image')) {
            $path = self::store($request->file('cover_image'));
            self::delete($current);

            return $path;
        }

        if ($request->boolean('remove_cover_image')) {
            self::delete($current);

            return null;
        }

        return $current;
    }

    public static function store(UploadedFile $file): string
    {
        return $file->store(self::directory(), 'public');
    }

    public static function delete(?string $path): void
    {
        if (! self::belongsToCurrentTenant($path)) {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    public static function url(?string $path): ?string
    {
        if (! self::belongsToCurrentTenant($path)) {
            return null;
        }

        return tenant_asset($path);
    }

    public static function belongsToCurrentTenant(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        $prefix = self::directory().'/';

        if (! str_starts_with($path, $prefix)) {
            return false;
        }

        $name = substr($path, strlen($prefix));

        return $name !== ''
            && ! str_contains($name, '/')
            && ! str_contains($name, '\\')
            && ! str_contains($name, '..');
    }

    public static function directory(): string
    {
        if (! tenancy()->initialized) {
            throw new RuntimeException('Cover images require an initialized tenant.');
        }

        return 'covers/'.tenant('id');
    }
}
