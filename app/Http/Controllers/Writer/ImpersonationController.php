<?php

namespace App\Http\Controllers\Writer;

use App\Enums\TenantUserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Writer;
use App\Support\CentralAbility;
use App\Support\Impersonation;
use App\Support\TenantImpersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Stancl\Tenancy\Database\Models\ImpersonationToken;
use Stancl\Tenancy\Middleware\ScopeSessions;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ImpersonationController extends Controller
{
    /**
     * Consumes a token issued on the central domain. The token is deleted before it is
     * trusted, so concurrent requests cannot both use it.
     */
    public function start(Request $request): RedirectResponse
    {
        abort_if(Impersonation::active($request) || TenantImpersonation::active($request), 403, 'Stop the current impersonation first.');

        $value = $request->input('token');

        abort_unless(is_string($value) && strlen($value) === 128, 403);

        $token = ImpersonationToken::query()->find($value);

        abort_if($token === null, 403);
        abort_unless(ImpersonationToken::query()->whereKey($value)->delete() === 1, 403);

        abort_unless((string) $token->tenant_id === (string) tenant()->getTenantKey(), 403);
        abort_unless($token->auth_guard === 'writer', 403);
        abort_if($token->created_at->lt(now()->subSeconds(Impersonation::ttl())) || $token->created_at->gt(now()), 403);

        $impersonator = User::query()->find($token->impersonator_id);
        $target = Writer::query()->find((int) $token->user_id);

        abort_if($target === null, 403);
        abort_unless(CentralAbility::canImpersonate($impersonator, $target), 403);

        $returnUrl = Impersonation::safeReturnUrl($token->return_url);

        abort_if($returnUrl === null, 403);

        Auth::guard('writer')->logout();
        $request->session()->invalidate();
        $request->session()->put(ScopeSessions::$tenantIdKey, tenant()->getTenantKey());
        Auth::guard('writer')->login($target);
        $request->session()->regenerate();

        $startedAt = now()->toIso8601String();

        $request->session()->put(Impersonation::SESSION_KEY, [
            'impersonator_id' => $impersonator->getKey(),
            'impersonator_email' => $impersonator->email,
            'writer_id' => $target->getKey(),
            'writer_name' => $target->name,
            'writer_email' => $target->email,
            'tenant_id' => (string) tenant()->getTenantKey(),
            'started_at' => $startedAt,
            'return_url' => $returnUrl,
        ]);

        AuditLog::record('impersonation.started', $impersonator, (string) tenant()->getTenantKey(), [
            'writer_id' => $target->getKey(),
            'writer_email' => $target->email,
            'writer_role' => $target->role?->value,
            'impersonator_role' => $impersonator->role?->value,
            'domain' => $request->getHost(),
        ]);

        return redirect(str_starts_with($token->redirect_url, '/') && ! str_starts_with($token->redirect_url, '//')
            ? $token->redirect_url
            : '/dashboard');
    }

    /**
     * Switches the tenant session to a writer while remembering the tenant admin
     * who started it. The admin's abilities are not kept on the session.
     */
    public function impersonate(Request $request, Writer $writer): RedirectResponse
    {
        $actor = $request->user('writer');

        abort_unless($actor instanceof Writer && $actor->isTenantAdmin(), 403);
        abort_if(Impersonation::active($request) || TenantImpersonation::active($request), 403, 'Stop the current impersonation first.');
        abort_if((int) $actor->getKey() === (int) $writer->getKey(), 403, 'You cannot impersonate yourself.');
        abort_unless($writer->role === TenantUserRole::Writer, 403, 'Only a writer can be impersonated.');

        $tenantId = (string) tenant()->getTenantKey();

        Auth::guard('writer')->logout();
        $request->session()->invalidate();
        $request->session()->put(ScopeSessions::$tenantIdKey, tenant()->getTenantKey());
        Auth::guard('writer')->login($writer);
        $request->session()->regenerate();
        TenantImpersonation::remember($request, $actor, $writer);

        AuditLog::record('tenant_impersonation.started', null, $tenantId, [
            'actor_id' => (int) $actor->getKey(),
            'actor_email' => $actor->email,
            'target_id' => (int) $writer->getKey(),
            'target_email' => $writer->email,
            'domain' => $request->getHost(),
        ]);

        return redirect()->route('writer.dashboard');
    }

    public function stop(Request $request): SymfonyResponse|RedirectResponse
    {
        if (TenantImpersonation::active($request)) {
            return $this->stopTenantImpersonation($request);
        }

        $returnUrl = Impersonation::stop($request);

        abort_if($returnUrl === null, 403);

        Auth::guard('writer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Inertia::location($returnUrl);
    }

    private function stopTenantImpersonation(Request $request): RedirectResponse
    {
        $details = TenantImpersonation::details($request);
        $actor = TenantImpersonation::actor($request);

        abort_if($details === null || $actor === null, 403);

        AuditLog::record('tenant_impersonation.stopped', null, $details['tenant_id'], [
            'actor_id' => $details['actor_id'],
            'actor_email' => $details['actor_email'],
            'target_id' => $details['writer_id'],
            'target_email' => $details['writer_email'],
            'domain' => $request->getHost(),
            'started_at' => $details['started_at'],
            'duration_seconds' => max(0, now()->getTimestamp() - strtotime($details['started_at'])),
        ]);

        Auth::guard('writer')->logout();
        $request->session()->invalidate();
        $request->session()->put(ScopeSessions::$tenantIdKey, tenant()->getTenantKey());
        Auth::guard('writer')->login($actor);
        $request->session()->regenerate();

        return redirect()->route('writer.users.index');
    }
}
