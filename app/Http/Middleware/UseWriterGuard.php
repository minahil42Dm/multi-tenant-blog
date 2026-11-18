<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class UseWriterGuard
{
    /**
     * Authenticate tenant requests with the writer guard after tenancy is initialized.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! tenancy()->initialized) {
            abort(403);
        }

        Auth::shouldUse('writer');

        return $next($request);
    }
}
