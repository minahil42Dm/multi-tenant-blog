<?php

namespace App\Support;

use App\Models\Writer;
use Illuminate\Http\Request;

/**
 * A tenant admin impersonating a writer inside the same tenant.
 * The original admin is stored in the tenant session and is not the
 * authenticated user until impersonation stops.
 */
final class TenantImpersonation
{
    public const SESSION_KEY = 'tenant_impersonation';

    public static function active(?Request $request = null): bool
    {
        return self::details($request) !== null;
    }

    /**
     * @return array{actor_id: int, actor_email: string, actor_name: string, writer_id: int, writer_name: string, writer_email: string, tenant_id: string, started_at: string}|null
     */
    public static function details(?Request $request = null): ?array
    {
        $request ??= request();

        if (! tenancy()->initialized || ! $request->hasSession()) {
            return null;
        }

        $details = $request->session()->get(self::SESSION_KEY);

        if (! is_array($details) || ($details['tenant_id'] ?? null) !== (string) tenant()->getTenantKey()) {
            return null;
        }

        return $details;
    }

    public static function remember(Request $request, Writer $actor, Writer $target): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'actor_id' => (int) $actor->getKey(),
            'actor_email' => $actor->email,
            'actor_name' => $actor->name,
            'writer_id' => (int) $target->getKey(),
            'writer_name' => $target->name,
            'writer_email' => $target->email,
            'tenant_id' => (string) tenant()->getTenantKey(),
            'started_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * The original tenant admin, loaded from this tenant's database only.
     */
    public static function actor(Request $request): ?Writer
    {
        $details = self::details($request);

        if ($details === null) {
            return null;
        }

        $actor = Writer::query()->find($details['actor_id']);

        if (! $actor instanceof Writer || $actor->email !== $details['actor_email']) {
            return null;
        }

        return $actor;
    }
}
