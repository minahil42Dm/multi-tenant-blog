<?php

use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\TenantBrandingController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\TenantDomainController;
use App\Http\Controllers\Admin\WriterController;
use App\Http\Controllers\ProfileController;
use App\Support\CentralAbility;
use App\Support\TenantBranding;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

$centralDomainPattern = collect(config('tenancy.central_domains'))
    ->map(fn (string $domain) => preg_quote($domain))
    ->implode('|');

Route::domain('{centralDomain}')
    ->where(['centralDomain' => $centralDomainPattern])
    ->group(function () {
        Route::get('/', function () {
            return Inertia::render('Welcome', [
                'canLogin' => Route::has('login'),
                'canRegister' => Route::has('register'),
                'laravelVersion' => Application::VERSION,
                'phpVersion' => PHP_VERSION,
            ]);
        });

        Route::get('/dashboard', function () {
            return Inertia::render('Dashboard');
        })->middleware(['auth', 'verified'])->name('dashboard');

        Route::middleware('auth')->group(function () {
            Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
            Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
            Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
        });

        Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
            Route::get('/', [TenantController::class, 'dashboard'])->name('dashboard');

            Route::middleware('can:'.CentralAbility::VIEW_TENANTS)->group(function () {
                Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
            });

            Route::middleware('can:'.CentralAbility::MANAGE_TENANTS)->group(function () {
                Route::get('/tenants/create', [TenantController::class, 'create'])->name('tenants.create');
                Route::post('/tenants/create/step/{step}', [TenantController::class, 'validateStep'])
                    ->whereIn('step', ['1', '2', '3', '4'])
                    ->name('tenants.create.validate');
                Route::post('/tenants', [TenantController::class, 'store'])->name('tenants.store');
                Route::get('/tenants/{tenant}/edit', [TenantController::class, 'edit'])->name('tenants.edit');
                Route::put('/tenants/{tenant}/branding', [TenantBrandingController::class, 'update'])->name('tenants.branding.update');
                Route::delete('/tenants/{tenant}', [TenantController::class, 'destroy'])->name('tenants.destroy');
                Route::post('/tenants/{tenant}/suspend', [TenantController::class, 'suspend'])->name('tenants.suspend');
                Route::post('/tenants/{tenant}/reactivate', [TenantController::class, 'reactivate'])->name('tenants.reactivate');

                Route::post('/tenants/{tenant}/domains', [TenantDomainController::class, 'store'])->name('tenants.domains.store');
                Route::delete('/tenants/{tenant}/domains/{domain}', [TenantDomainController::class, 'destroy'])
                    ->whereNumber('domain')
                    ->name('tenants.domains.destroy');
                Route::post('/tenants/{tenant}/domains/{domain}/primary', [TenantDomainController::class, 'makePrimary'])
                    ->whereNumber('domain')
                    ->name('tenants.domains.primary');
            });

            Route::middleware('can:'.CentralAbility::VIEW_TENANTS)->group(function () {
                Route::get('/tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
                Route::get('/tenants/{tenant}/branding', [TenantBrandingController::class, 'edit'])->name('tenants.branding.edit');
                Route::get('/tenants/{tenant}/branding/{asset}', [TenantBrandingController::class, 'asset'])
                    ->whereIn('asset', TenantBranding::ASSETS)
                    ->name('tenants.branding.asset');
            });

            Route::middleware('can:'.CentralAbility::VIEW_TENANT_USERS)->group(function () {
                Route::get('/tenants/{tenant}/writers', [WriterController::class, 'index'])->name('tenants.writers.index');
            });

            Route::middleware('can:'.CentralAbility::IMPERSONATE_TENANT_USERS)->group(function () {
                Route::post('/tenants/{tenant}/writers/{writer}/impersonate', [ImpersonationController::class, 'store'])
                    ->whereNumber('writer')
                    ->name('tenants.writers.impersonate');
            });

            Route::middleware('can:'.CentralAbility::MANAGE_TENANT_USERS)->group(function () {
                Route::get('/tenants/{tenant}/writers/create', [WriterController::class, 'create'])->name('tenants.writers.create');
                Route::post('/tenants/{tenant}/writers', [WriterController::class, 'store'])->name('tenants.writers.store');
            });
        });

        require __DIR__.'/auth.php';
    });
