<?php

namespace App\Support;

use App\Enums\CentralRole;
use App\Models\User;
use App\Models\Writer;
use Illuminate\Support\Facades\Gate;

/**
 * Central platform abilities. Checked against the `web` guard only; tenant writers never hold them.
 */
final class CentralAbility
{
    public const ACCESS_ADMIN = 'central.access-admin';

    public const VIEW_TENANTS = 'central.view-tenants';

    public const MANAGE_TENANTS = 'central.manage-tenants';

    public const VIEW_TENANT_USERS = 'central.view-tenant-users';

    public const MANAGE_TENANT_USERS = 'central.manage-tenant-users';

    public const IMPERSONATE_TENANT_USERS = 'central.impersonate-tenant-users';

    /**
     * @return array<string, array<int, CentralRole>>
     */
    public static function roles(): array
    {
        $everyCentralRole = [CentralRole::SuperAdmin, CentralRole::Support];

        return [
            self::ACCESS_ADMIN => $everyCentralRole,
            self::VIEW_TENANTS => $everyCentralRole,
            self::VIEW_TENANT_USERS => $everyCentralRole,
            self::IMPERSONATE_TENANT_USERS => $everyCentralRole,
            self::MANAGE_TENANTS => [CentralRole::SuperAdmin],
            self::MANAGE_TENANT_USERS => [CentralRole::SuperAdmin],
        ];
    }

    public static function register(): void
    {
        foreach (self::roles() as $ability => $roles) {
            Gate::define($ability, fn (mixed $user = null) => $user instanceof User
                && $user->hasVerifiedEmail()
                && $user->hasCentralRole(...$roles));
        }
    }

    /**
     * @return array<string, bool>
     */
    public static function for(?User $user): array
    {
        return [
            'accessAdmin' => self::allows($user, self::ACCESS_ADMIN),
            'viewTenants' => self::allows($user, self::VIEW_TENANTS),
            'manageTenants' => self::allows($user, self::MANAGE_TENANTS),
            'viewTenantUsers' => self::allows($user, self::VIEW_TENANT_USERS),
            'manageTenantUsers' => self::allows($user, self::MANAGE_TENANT_USERS),
            'impersonateTenantUsers' => self::allows($user, self::IMPERSONATE_TENANT_USERS),
        ];
    }

    /**
     * Support may impersonate regular writers only; tenant admins hold more privilege
     * inside their tenant, so only a super admin may impersonate them.
     */
    public static function canImpersonate(?User $user, Writer $target): bool
    {
        if (! self::allows($user, self::IMPERSONATE_TENANT_USERS)) {
            return false;
        }

        return ! $target->isTenantAdmin() || $user->isSuperAdmin();
    }

    private static function allows(?User $user, string $ability): bool
    {
        return $user !== null && Gate::forUser($user)->allows($ability);
    }
}
