<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Writer;
use App\Support\CentralAbility;
use App\Support\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stancl\Tenancy\Database\Models\ImpersonationToken;

class ImpersonationController extends Controller
{
    /**
     * Issues a single-use token and hands it to the tenant domain in a POST body,
     * so it never appears in a URL, server log, browser history or client storage.
     */
    public function store(Request $request, string $centralDomain, Tenant $tenant, int $writer): Response
    {
        abort_if($tenant->isSuspended(), 403, 'Suspended tenants cannot be impersonated.');

        $domain = $tenant->primaryDomainName();

        abort_if($domain === null, 409, 'This tenant has no domain to sign in on.');

        $target = $tenant->run(fn () => Writer::query()->find($writer));

        abort_if($target === null, 404);
        abort_unless(CentralAbility::canImpersonate($request->user(), $target), 403);

        ImpersonationToken::query()
            ->where('created_at', '<', now()->subSeconds(Impersonation::ttl()))
            ->delete();

        $token = ImpersonationToken::create([
            'tenant_id' => $tenant->getTenantKey(),
            'user_id' => (string) $target->getKey(),
            'auth_guard' => 'writer',
            'redirect_url' => '/dashboard',
            'impersonator_id' => $request->user()->getKey(),
            'return_url' => route('admin.tenants.writers.index', $tenant),
        ]);

        $port = in_array($request->getPort(), [80, 443], true) ? '' : ':'.$request->getPort();

        return response()
            ->view('impersonation.redirect', [
                'action' => $request->getScheme().'://'.$domain.$port.'/impersonate',
                'token' => $token->token,
                'targetEmail' => $target->email,
            ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
