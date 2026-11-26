<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantIsActive
{
    /**
     * Show the suspended page instead of any tenant content while the tenant is suspended.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! tenancy()->initialized) {
            abort(403);
        }

        $tenant = tenant();

        if (! $tenant instanceof Tenant || $tenant->isActive()) {
            return $next($request);
        }

        if ($request->hasSession()) {
            if (Auth::guard('writer')->check()) {
                Auth::guard('writer')->logout();
            }

            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        Inertia::share('auth.user', null);

        return Inertia::render('Blog/Suspended', [
            'tenantName' => $tenant->name,
        ])->toResponse($request)->setStatusCode(Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
