<?php

use App\Http\Middleware\EnsureUserIsAdministrator;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'admin' => EnsureUserIsAdministrator::class,
        ]);

        // Tenant-domain endpoint that receives a cross-domain POST from the central admin.
        // It authenticates with a single-use, 60-second impersonation token instead of a CSRF token.
        $middleware->validateCsrfTokens(except: ['impersonate']);

        $middleware->redirectGuestsTo(function (Request $request) {
            if (tenancy()->initialized) {
                return route('writer.login');
            }

            return route('login');
        });

        $middleware->redirectUsersTo(function (Request $request) {
            if (tenancy()->initialized) {
                return route('writer.dashboard');
            }

            return route('dashboard');
        });

        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
