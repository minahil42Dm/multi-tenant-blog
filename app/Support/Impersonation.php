<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Stancl\Tenancy\Features\UserImpersonation;

/**
 * Tenant-side impersonation state. It lives only in the tenant domain's session,
 * so the central admin session on the central domain is never touched.
 */
final class Impersonation
{
    public const SESSION_KEY = 'central_impersonation';

    public static function ttl(): int
    {
        return UserImpersonation::$ttl;
    }

    public static function active(?Request $request = null): bool
    {
        return self::details($request) !== null;
    }

    /**
     * @return array{impersonator_id: int, impersonator_email: string, writer_id: int, writer_name: string, writer_email: string, tenant_id: string, started_at: string, return_url: string}|null
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

    /**
     * Records the stop event and returns the central URL to go back to.
     */
    public static function stop(Request $request): ?string
    {
        $details = self::details($request);

        if ($details === null) {
            return null;
        }

        AuditLog::record('impersonation.stopped', User::query()->find($details['impersonator_id']), $details['tenant_id'], [
            'writer_id' => $details['writer_id'],
            'writer_email' => $details['writer_email'],
            'domain' => $request->getHost(),
            'started_at' => $details['started_at'],
            'duration_seconds' => max(0, now()->getTimestamp() - strtotime($details['started_at'])),
        ]);

        $request->session()->forget(self::SESSION_KEY);

        return self::safeReturnUrl($details['return_url']);
    }

    /**
     * Only central-domain URLs are accepted as a destination, which rules out open redirects.
     */
    public static function safeReturnUrl(?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');

        return in_array($host, config('tenancy.central_domains', []), true) ? $url : null;
    }
}
