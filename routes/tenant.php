<?php

declare(strict_types=1);

use App\Http\Controllers\BlogController;
use App\Http\Controllers\Writer\AuthenticatedSessionController;
use App\Http\Controllers\Writer\DashboardController;
use App\Http\Controllers\Writer\ImpersonationController;
use App\Http\Controllers\Writer\PostController;
use App\Http\Middleware\EnsureTenantIsActive;
use App\Http\Middleware\UseWriterGuard;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use Stancl\Tenancy\Middleware\ScopeSessions;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the TenantRouteServiceProvider.
|
| Feel free to customize them however you want. Good luck!
|
*/

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
    ScopeSessions::class,
    EnsureTenantIsActive::class,
    UseWriterGuard::class,
])->group(function () {
    Route::get('/', [BlogController::class, 'index'])->name('blog.home');

    Route::post('/impersonate', [ImpersonationController::class, 'start'])->name('writer.impersonate');
    Route::post('/impersonation/stop', [ImpersonationController::class, 'stop'])
        ->middleware('auth:writer')
        ->name('writer.impersonation.stop');

    Route::middleware('guest:writer')->group(function () {
        Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('writer.login');
        Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    });

    Route::middleware('auth:writer')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('writer.dashboard');
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('writer.logout');

        Route::get('/posts', [PostController::class, 'index'])->name('writer.posts.index');
        Route::get('/posts/create', [PostController::class, 'create'])->name('writer.posts.create');
        Route::post('/posts', [PostController::class, 'store'])->name('writer.posts.store');
        Route::get('/posts/{post}', [PostController::class, 'show'])->whereNumber('post')->name('writer.posts.show');
        Route::get('/posts/{post}/edit', [PostController::class, 'edit'])->whereNumber('post')->name('writer.posts.edit');
        Route::put('/posts/{post}', [PostController::class, 'update'])->whereNumber('post')->name('writer.posts.update');
        Route::delete('/posts/{post}', [PostController::class, 'destroy'])->whereNumber('post')->name('writer.posts.destroy');
    });

    Route::get('/posts/{slug}', [BlogController::class, 'show'])->name('blog.show');
});
