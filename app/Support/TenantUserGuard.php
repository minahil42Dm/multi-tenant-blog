<?php

namespace App\Support;

use App\Enums\TenantUserRole;
use App\Models\Writer;

/**
 * Limits that keep a tenant from losing its last admin or the signed-in admin's own account.
 */
final class TenantUserGuard
{
    public static function demotionBlocked(Writer $actor, Writer $target, TenantUserRole $role): ?string
    {
        if (! $target->isTenantAdmin() || $role === TenantUserRole::Admin) {
            return null;
        }

        if (self::lockedAdminCount() <= 1) {
            return 'The last tenant admin cannot be demoted.';
        }

        if ((int) $actor->getKey() === (int) $target->getKey()) {
            return 'You cannot change your own role.';
        }

        return null;
    }

    public static function deletionBlocked(Writer $actor, Writer $target): ?string
    {
        if ($target->isTenantAdmin() && self::lockedAdminCount() <= 1) {
            return 'The last tenant admin cannot be deleted.';
        }

        if ((int) $actor->getKey() === (int) $target->getKey()) {
            return 'You cannot delete your own account.';
        }

        return null;
    }

    public static function adminCount(): int
    {
        return Writer::query()->where('role', TenantUserRole::Admin)->count();
    }

    /**
     * Locks every tenant-admin row for the surrounding transaction so two
     * requests cannot both observe more than one admin and then remove one.
     */
    public static function lockedAdminCount(): int
    {
        return Writer::query()
            ->where('role', TenantUserRole::Admin)
            ->orderBy('id')
            ->lockForUpdate()
            ->count();
    }
}
