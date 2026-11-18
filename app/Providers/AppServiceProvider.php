<?php

namespace App\Providers;

use App\Models\Post;
use App\Policies\PostPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Post::class, PostPolicy::class);

        Vite::prefetch(concurrency: 3);

        Vite::createAssetPathsUsing(function (string $path) {
            return global_asset($path);
        });

        $centralDomains = config('tenancy.central_domains', []);

        if ($centralDomains === []) {
            return;
        }

        $host = request()->getHost();

        URL::defaults([
            'centralDomain' => in_array($host, $centralDomains, true)
                ? $host
                : $centralDomains[0],
        ]);
    }
}
