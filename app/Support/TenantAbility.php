<?php

namespace App\Support;

use App\Models\Writer;
use Illuminate\Support\Facades\Gate;

/**
 * Tenant abilities. Checked against the writer guard only, and only while tenancy
 * is initialized. A writer loaded from another tenant never matches the current database.
 */
final class TenantAbility
{
    public const MANAGE_USERS = 'tenant.manage-users';

    public const VIEW_ANY_POST = 'tenant.view-any-post';

    public const MANAGE_ANY_POST = 'tenant.manage-any-post';

    public const CREATE_POSTS = 'tenant.create-posts';

    public const MANAGE_CONTACTS = 'tenant.manage-contacts';

    /**
     * @return array<string, bool>
     */
    public static function definitions(): array
    {
        return [
            self::MANAGE_USERS => true,
            self::VIEW_ANY_POST => true,
            self::MANAGE_ANY_POST => true,
            self::CREATE_POSTS => false,
            self::MANAGE_CONTACTS => false,
        ];
    }

    public static function register(): void
    {
        foreach (self::definitions() as $ability => $adminsOnly) {
            Gate::define($ability, fn (mixed $user = null) => self::permits($user, $adminsOnly));
        }
    }

    /**
     * @return array<string, bool>
     */
    public static function for(mixed $user): array
    {
        return [
            'manageUsers' => self::allows($user, self::MANAGE_USERS),
            'viewAnyPost' => self::allows($user, self::VIEW_ANY_POST),
            'manageAnyPost' => self::allows($user, self::MANAGE_ANY_POST),
            'createPosts' => self::allows($user, self::CREATE_POSTS),
            'manageContacts' => self::allows($user, self::MANAGE_CONTACTS),
        ];
    }

    public static function allows(mixed $user, string $ability): bool
    {
        return Gate::forUser($user)->allows($ability);
    }

    public static function belongsToCurrentTenant(mixed $user): bool
    {
        return self::resolve($user) instanceof Writer;
    }

    private static function permits(mixed $user, bool $adminsOnly): bool
    {
        $writer = self::resolve($user);

        if (! $writer instanceof Writer) {
            return false;
        }

        return $adminsOnly ? $writer->isTenantAdmin() : true;
    }

    /**
     * The signed-in writer, re-read from the current tenant database.
     * The in-memory model is not trusted: its id can collide with another tenant.
     */
    private static function resolve(mixed $user): ?Writer
    {
        if (! tenancy()->initialized || ! $user instanceof Writer) {
            return null;
        }

        $authenticated = auth('writer')->user();

        if (! $authenticated instanceof Writer
            || (int) $authenticated->getAuthIdentifier() !== (int) $user->getAuthIdentifier()
            || $authenticated->email !== $user->email) {
            return null;
        }

        $record = Writer::query()->find($user->getAuthIdentifier());

        if (! $record instanceof Writer
            || $record->email !== $authenticated->email
            || $record->password !== $authenticated->password) {
            return null;
        }

        return $record;
    }
}
