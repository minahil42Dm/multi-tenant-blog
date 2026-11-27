<?php

declare(strict_types=1);

use App\Http\Controllers\BlogController;
use App\Http\Controllers\Writer\AuthenticatedSessionController;
use App\Http\Controllers\Writer\ContactController;
use App\Http\Controllers\Writer\ContactImportController;
use App\Http\Controllers\Writer\ContactNoteController;
use App\Http\Controllers\Writer\DashboardController;
use App\Http\Controllers\Writer\ImpersonationController;
use App\Http\Controllers\Writer\PostController;
use App\Http\Controllers\Writer\TagController;
use App\Http\Controllers\Writer\UserController;
use App\Http\Middleware\EnsureTenantIsActive;
use App\Http\Middleware\UseWriterGuard;
use App\Support\TenantAbility;
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

        Route::middleware('can:'.TenantAbility::MANAGE_USERS)->group(function () {
            Route::get('/writers', [UserController::class, 'index'])->name('writer.users.index');
            Route::get('/writers/create', [UserController::class, 'create'])->name('writer.users.create');
            Route::post('/writers', [UserController::class, 'store'])->name('writer.users.store');
            Route::get('/writers/{writer}/edit', [UserController::class, 'edit'])->whereNumber('writer')->name('writer.users.edit');
            Route::match(['put', 'patch'], '/writers/{writer}', [UserController::class, 'update'])->whereNumber('writer')->name('writer.users.update');
            Route::delete('/writers/{writer}', [UserController::class, 'destroy'])->whereNumber('writer')->name('writer.users.destroy');
            Route::post('/writers/{writer}/impersonate', [ImpersonationController::class, 'impersonate'])->whereNumber('writer')->name('writer.users.impersonate');
        });

        Route::middleware('can:'.TenantAbility::MANAGE_CONTACTS)->group(function () {
            Route::get('/contacts', [ContactController::class, 'index'])->name('writer.contacts.index');
            Route::get('/contacts/create', [ContactController::class, 'create'])->name('writer.contacts.create');
            Route::post('/contacts', [ContactController::class, 'store'])->name('writer.contacts.store');
            Route::get('/contacts/import', [ContactImportController::class, 'create'])->name('writer.contacts.import');
            Route::post('/contacts/import', [ContactImportController::class, 'store'])->name('writer.contacts.import.store');
            Route::get('/contacts/imports/{import}', [ContactImportController::class, 'show'])->whereNumber('import')->name('writer.contacts.imports.show');
            Route::get('/contacts/{contact}', [ContactController::class, 'show'])->whereNumber('contact')->name('writer.contacts.show');
            Route::get('/contacts/{contact}/edit', [ContactController::class, 'edit'])->whereNumber('contact')->name('writer.contacts.edit');
            Route::match(['put', 'patch'], '/contacts/{contact}', [ContactController::class, 'update'])->whereNumber('contact')->name('writer.contacts.update');
            Route::delete('/contacts/{contact}', [ContactController::class, 'destroy'])->whereNumber('contact')->name('writer.contacts.destroy');
            Route::put('/contacts/{contact}/shares', [ContactController::class, 'updateShares'])->whereNumber('contact')->name('writer.contacts.shares.update');
            Route::post('/contacts/{contact}/notes', [ContactNoteController::class, 'store'])->whereNumber('contact')->name('writer.contacts.notes.store');
            Route::match(['put', 'patch'], '/contacts/{contact}/notes/{note}', [ContactNoteController::class, 'update'])->whereNumber(['contact', 'note'])->name('writer.contacts.notes.update');
            Route::delete('/contacts/{contact}/notes/{note}', [ContactNoteController::class, 'destroy'])->whereNumber(['contact', 'note'])->name('writer.contacts.notes.destroy');
            Route::post('/contact-tags', [TagController::class, 'store'])->name('writer.contact-tags.store');
            Route::match(['put', 'patch'], '/contact-tags/{tag}', [TagController::class, 'update'])->whereNumber('tag')->name('writer.contact-tags.update');
            Route::delete('/contact-tags/{tag}', [TagController::class, 'destroy'])->whereNumber('tag')->name('writer.contact-tags.destroy');
        });

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
