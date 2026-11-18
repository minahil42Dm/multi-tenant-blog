<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdministrator::class,
        ]);

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
