<?php

namespace App\Support;

use App\Enums\TenantUserRole;
use App\Exceptions\TenantDeletionException;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\Writer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Database\Models\ImpersonationToken;
use Throwable;

/**
 * Creating and deleting tenants. Both rely on stancl's TenantCreated / TenantDeleted
 * pipelines for the tenant database, so no database is ever created or dropped by hand.
 */
final class TenantLifecycle
{
    private const LOGO_PATTERN = '/^tenant-logos\/[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /**
     * Creates the tenant, its domains (the first one primary) and its first tenant admin.
     * Any failure removes everything created so far, including the tenant database.
     *
     * The logo is stored in the new tenant's own disk, so the rollback removes it together
     * with the tenant's storage folder.
     *
     * @param  array{name: string, brand_color: string}  $attributes
     * @param  array<int, string>  $domains
     * @param  array{name: string, email: string, password: string}  $admin
     *
     * @throws Throwable
     */
    public static function provision(array $attributes, array $domains, array $admin, ?UploadedFile $logo = null): Tenant
    {
        $tenant = new Tenant($attributes);

        try {
            $tenant->save();

            foreach (array_values($domains) as $index => $domain) {
                $tenant->domains()->create([
                    'domain' => $domain,
                    'is_primary' => $index === 0,
                ]);
            }

            if ($logo !== null) {
                $tenant->update(['logo' => $tenant->run(fn () => TenantBranding::storeAsset($logo))]);
            }

            $tenant->run(function () use ($admin): void {
                (new Writer)->forceFill([
                    'name' => $admin['name'],
                    'email' => $admin['email'],
                    'password' => $admin['password'],
                    'role' => TenantUserRole::Admin,
                ])->save();
            });
        } catch (Throwable $exception) {
            self::discard($tenant);

            throw $exception;
        }

        return $tenant;
    }

    /**
     * Deletes a tenant through stancl's pipeline, then removes its central files.
     *
     * @throws TenantDeletionException
     */
    public static function delete(Tenant $tenant): void
    {
        $tenantId = (string) $tenant->getTenantKey();
        $logo = $tenant->logo;
        $databaseName = $tenant->database()->getName();

        try {
            $tenant->run(function (): void {
                HomepageCache::forget();
                TenantBranding::forget();
            });
        } catch (Throwable $exception) {
            report($exception);
        }

        ImpersonationToken::query()->where('tenant_id', $tenantId)->delete();

        try {
            $tenant->delete();
        } catch (Throwable $exception) {
            throw new TenantDeletionException(
                Tenant::query()->whereKey($tenantId)->exists(),
                $databaseName,
                $exception,
            );
        }

        Domain::query()->where('tenant_id', $tenantId)->delete();

        self::deleteLogo(is_string($logo) ? $logo : null);
        self::deleteTenantStorage($tenantId);
    }

    public static function isManagedLogo(?string $path): bool
    {
        return is_string($path) && preg_match(self::LOGO_PATTERN, $path) === 1;
    }

    public static function deleteLogo(?string $path): void
    {
        if (self::isManagedLogo($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Removes a half-created tenant. Used only while provisioning.
     */
    private static function discard(Tenant $tenant): void
    {
        if (! $tenant->exists) {
            return;
        }

        $tenantId = (string) $tenant->getTenantKey();

        try {
            $tenant->delete();
        } catch (Throwable $exception) {
            report($exception);
        }

        Domain::query()->where('tenant_id', $tenantId)->delete();
        Tenant::query()->whereKey($tenantId)->delete();
        self::deleteTenantStorage($tenantId);
    }

    private static function deleteTenantStorage(string $tenantId): void
    {
        if (tenancy()->initialized || preg_match('/^[A-Za-z0-9-]+$/', $tenantId) !== 1) {
            return;
        }

        $directory = storage_path(config('tenancy.filesystem.suffix_base').$tenantId);

        if (File::isDirectory($directory)) {
            File::deleteDirectory($directory);
        }
    }
}
