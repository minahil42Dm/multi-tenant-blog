<?php

namespace App\Http\Middleware;

use App\Support\CentralAbility;
use App\Support\Impersonation;
use App\Support\TenantBranding;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => tenancy()->initialized
                    ? $request->user('writer')
                    : $request->user(),
                'can' => fn () => tenancy()->initialized
                    ? []
                    : CentralAbility::for($request->user('web')),
            ],
            'tenant' => fn () => tenancy()->initialized ? [
                'name' => tenant('name'),
            ] : null,
            'branding' => fn () => tenancy()->initialized ? TenantBranding::current() : null,
            'impersonation' => function () use ($request) {
                $details = Impersonation::details($request);

                return $details === null ? null : [
                    'writer_name' => $details['writer_name'],
                    'writer_email' => $details['writer_email'],
                    'impersonator_email' => $details['impersonator_email'],
                ];
            },
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
