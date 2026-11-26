<?php

namespace App\Http\Controllers\Writer;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Writer;
use App\Support\CentralAbility;
use App\Support\Impersonation;
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
        abort_if(Impersonation::active($request), 403, 'Stop the current impersonation first.');

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

    public function stop(Request $request): SymfonyResponse
    {
        $returnUrl = Impersonation::stop($request);

        abort_if($returnUrl === null, 403);

        Auth::guard('writer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Inertia::location($returnUrl);
    }
}
