<?php

namespace App\Http\Middleware;

use App\Support\CentralAbility;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdministrator
{
    /**
     * Require an authenticated central user who holds a central role (super admin or support).
     * Individual routes narrow this further with `can:` abilities from CentralAbility.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Gate::forUser($request->user())->denies(CentralAbility::ACCESS_ADMIN)) {
            abort(403);
        }

        return $next($request);
    }
}
