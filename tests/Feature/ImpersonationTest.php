<?php

namespace Tests\Feature;

use App\Enums\TenantUserRole;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Writer;
use App\Support\Impersonation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Stancl\Tenancy\Database\Models\ImpersonationToken;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        if (Schema::hasTable('tenants')) {
            Tenant::query()->get()->each(fn (Tenant $tenant) => $tenant->delete());
        }

        parent::tearDown();
    }

    public function test_support_can_start_impersonating_a_writer_without_exposing_the_token_in_a_url(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant, 'ada@alpha.test');

        $response = $this->actingAs(User::factory()->support()->create())
            ->post(route('admin.tenants.writers.impersonate', [$tenant, $writer->id]))
            ->assertOk()
            ->assertSee('action="http://alpha.localhost/impersonate"', false)
            ->assertSee('Signing in as ada@alpha.test');

        $token = ImpersonationToken::query()->sole();

        $this->assertSame(128, strlen($token->token));
        $this->assertSame((string) $writer->id, (string) $token->user_id);
        $this->assertSame('writer', $token->auth_guard);
        $this->assertStringContainsString('name="token" value="'.$token->token.'"', $response->getContent());
        $this->assertStringNotContainsString('?token', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    public function test_the_token_signs_the_admin_in_on_the_tenant_domain_with_a_banner(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant, 'ada@alpha.test', 'Ada Writer');
        $support = User::factory()->support()->create(['email' => 'support@platform.test']);

        $this->startImpersonation($support, $tenant, $writer)
            ->assertRedirect('http://alpha.localhost/dashboard');

        $this->assertAuthenticatedAs($writer, 'writer');
        $this->assertSame(0, ImpersonationToken::query()->count());

        $this->get('http://alpha.localhost/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('impersonation', [
                'writer_name' => 'Ada Writer',
                'writer_email' => 'ada@alpha.test',
                'impersonator_email' => 'support@platform.test',
            ]));

        $log = AuditLog::query()->where('action', 'impersonation.started')->sole();
        $this->assertSame($support->id, $log->actor_id);
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame('ada@alpha.test', $log->metadata['writer_email']);
        $this->assertArrayNotHasKey('token', $log->metadata);
    }

    public function test_tokens_are_single_use(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant, 'ada@alpha.test');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('admin.tenants.writers.impersonate', [$tenant, $writer->id]));
        $token = ImpersonationToken::query()->sole()->token;

        $this->post('http://alpha.localhost/impersonate', ['token' => $token])->assertRedirect();

        $this->freshBrowser();

        $this->post('http://alpha.localhost/impersonate', ['token' => $token])->assertForbidden();
        $this->assertGuest('writer');
    }

    public function test_expired_tokens_are_rejected(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant, 'ada@alpha.test');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('admin.tenants.writers.impersonate', [$tenant, $writer->id]));
        $token = ImpersonationToken::query()->sole()->token;

        $this->travel(Impersonation::ttl() + 1)->seconds();

        $this->post('http://alpha.localhost/impersonate', ['token' => $token])->assertForbidden();
        $this->assertGuest('writer');
        $this->assertSame(0, ImpersonationToken::query()->count());
    }

    public function test_a_token_cannot_be_used_on_another_tenant(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $this->createTenant('beta.localhost', 'Beta');
        $writer = $this->writer($alpha, 'ada@alpha.test');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('admin.tenants.writers.impersonate', [$alpha, $writer->id]));
        $token = ImpersonationToken::query()->sole()->token;

        $this->post('http://beta.localhost/impersonate', ['token' => $token])->assertForbidden();
        $this->assertGuest('writer');
    }

    public function test_malformed_and_unknown_tokens_are_rejected(): void
    {
        $this->createTenant('alpha.localhost', 'Alpha');

        $this->post('http://alpha.localhost/impersonate', [])->assertForbidden();
        $this->post('http://alpha.localhost/impersonate', ['token' => 'short'])->assertForbidden();
        $this->post('http://alpha.localhost/impersonate', ['token' => str_repeat('a', 128)])->assertForbidden();
        $this->get('http://alpha.localhost/impersonate?token='.str_repeat('a', 128))->assertStatus(405);
        $this->assertGuest('writer');
    }

    public function test_support_cannot_impersonate_a_tenant_admin_but_super_admin_can(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $owner = $this->writer($tenant, 'owner@alpha.test', 'Owner', TenantUserRole::Admin);
        $this->writer($tenant, 'ada@alpha.test', 'Ada');

        $this->actingAs(User::factory()->support()->create())
            ->get(route('admin.tenants.writers.index', $tenant))
            ->assertInertia(fn (Assert $page) => $page
                ->where('writers.0.email', 'ada@alpha.test')
                ->where('writers.0.can_impersonate', true)
                ->where('writers.1.email', 'owner@alpha.test')
                ->where('writers.1.role_label', 'Tenant admin')
                ->where('writers.1.can_impersonate', false));

        $this->post(route('admin.tenants.writers.impersonate', [$tenant, $owner->id]))->assertForbidden();
        $this->assertSame(0, ImpersonationToken::query()->count());

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('admin.tenants.writers.impersonate', [$tenant, $owner->id]))
            ->assertOk();
        $this->assertSame(1, ImpersonationToken::query()->count());
    }

    public function test_impersonation_requires_the_ability_and_a_verified_account(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant, 'ada@alpha.test');

        $this->post(route('admin.tenants.writers.impersonate', [$tenant, $writer->id]))
            ->assertRedirect(route('login'));

        $this->actingAs(User::factory()->support()->unverified()->create())
            ->post(route('admin.tenants.writers.impersonate', [$tenant, $writer->id]))
            ->assertRedirect();

        $this->actingAs(User::factory()->create())
            ->post(route('admin.tenants.writers.impersonate', [$tenant, $writer->id]))
            ->assertForbidden();

        $this->assertSame(0, ImpersonationToken::query()->count());
    }

    public function test_suspended_tenants_and_unknown_writers_cannot_be_impersonated(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant, 'ada@alpha.test');
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->post(route('admin.tenants.writers.impersonate', [$tenant, 999]))
            ->assertNotFound();

        $tenant->suspend();

        $this->actingAs($admin)
            ->post(route('admin.tenants.writers.impersonate', [$tenant, $writer->id]))
            ->assertForbidden();

        $this->assertSame(0, ImpersonationToken::query()->count());
    }

    public function test_a_token_issued_before_suspension_cannot_be_used(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant, 'ada@alpha.test');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('admin.tenants.writers.impersonate', [$tenant, $writer->id]));
        $token = ImpersonationToken::query()->sole()->token;

        $tenant->suspend();

        $this->post('http://alpha.localhost/impersonate', ['token' => $token])->assertStatus(503);
        $this->assertGuest('writer');
    }

    public function test_nested_impersonation_is_blocked(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $ada = $this->writer($tenant, 'ada@alpha.test');
        $bob = $this->writer($tenant, 'bob@alpha.test');
        $admin = User::factory()->superAdmin()->create();

        $this->startImpersonation($admin, $tenant, $ada)->assertRedirect();

        $this->actingAs($admin)->post(route('admin.tenants.writers.impersonate', [$tenant, $bob->id]));
        $token = ImpersonationToken::query()->sole()->token;

        $this->post('http://alpha.localhost/impersonate', ['token' => $token])->assertForbidden();
        $this->assertAuthenticatedAs($ada, 'writer');
    }

    public function test_stopping_restores_the_central_session_and_is_audited(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant, 'ada@alpha.test');
        $support = User::factory()->support()->create();

        $this->startImpersonation($support, $tenant, $writer);
        $this->travel(90)->seconds();

        $this->post('http://alpha.localhost/impersonation/stop')
            ->assertRedirect(route('admin.tenants.writers.index', $tenant));

        $this->assertGuest('writer');
        $this->assertNull(session(Impersonation::SESSION_KEY));
        $this->assertAuthenticatedAs($support, 'web');

        $log = AuditLog::query()->where('action', 'impersonation.stopped')->sole();
        $this->assertSame($support->id, $log->actor_id);
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame(90, $log->metadata['duration_seconds']);

        $this->get(route('admin.tenants.writers.index', $tenant))->assertOk();
    }

    public function test_stopping_from_an_inertia_request_returns_an_external_location(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant, 'ada@alpha.test');

        $this->startImpersonation(User::factory()->superAdmin()->create(), $tenant, $writer);

        $this->withHeaders(['X-Inertia' => 'true'])
            ->post('http://alpha.localhost/impersonation/stop')
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('admin.tenants.writers.index', $tenant));
    }

    public function test_logging_out_while_impersonating_also_stops_and_returns_to_central(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant, 'ada@alpha.test');

        $this->startImpersonation(User::factory()->superAdmin()->create(), $tenant, $writer);

        $this->post('http://alpha.localhost/logout')
            ->assertRedirect(route('admin.tenants.writers.index', $tenant));

        $this->assertTrue(AuditLog::query()->where('action', 'impersonation.stopped')->exists());
    }

    public function test_stop_is_rejected_when_not_impersonating(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant, 'ada@alpha.test');

        $this->post('http://alpha.localhost/login', ['email' => 'ada@alpha.test', 'password' => 'password']);
        $this->assertAuthenticatedAs($writer, 'writer');

        $this->post('http://alpha.localhost/impersonation/stop')->assertForbidden();
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_the_session_cannot_be_reused_on_another_tenant(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $this->createTenant('beta.localhost', 'Beta');
        $writer = $this->writer($alpha, 'ada@alpha.test');

        $cookie = collect($this->startImpersonation(User::factory()->superAdmin()->create(), $alpha, $writer)
            ->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

        $this->assertNotNull($cookie);
        $this->app['auth']->forgetGuards();

        $this->disableCookieEncryption()
            ->withCookie($cookie->getName(), $cookie->getValue())
            ->get('http://beta.localhost/dashboard')
            ->assertForbidden();

        $this->withCookie($cookie->getName(), $cookie->getValue())
            ->post('http://beta.localhost/impersonation/stop')
            ->assertForbidden();

        $this->assertSame(0, AuditLog::query()->where('action', 'impersonation.stopped')->count());
    }

    public function test_email_and_password_changes_are_blocked_while_impersonating(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant, 'ada@alpha.test');

        $this->startImpersonation(User::factory()->superAdmin()->create(), $tenant, $writer);
        $this->get('http://alpha.localhost/dashboard')->assertOk();

        tenancy()->initialize($tenant);

        $impersonated = Writer::query()->findOrFail($writer->id);
        $impersonated->update(['name' => 'Renamed Writer']);
        $this->assertSame('Renamed Writer', $impersonated->fresh()->name);

        foreach ([['email' => 'evil@alpha.test'], ['password' => 'new-password']] as $change) {
            try {
                $impersonated->fresh()->update($change);
                $this->fail('The change was allowed during impersonation.');
            } catch (AuthorizationException $exception) {
                $this->assertSame('Email and password changes are not allowed while impersonating.', $exception->getMessage());
            }
        }

        $this->assertSame('ada@alpha.test', $impersonated->fresh()->email);
        tenancy()->end();
    }

    public function test_writers_cannot_reach_central_admin_routes(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant, 'ada@alpha.test');

        $this->post('http://alpha.localhost/login', ['email' => 'ada@alpha.test', 'password' => 'password']);
        $this->assertAuthenticatedAs($writer, 'writer');

        $this->get(route('admin.tenants.index'))->assertRedirect(route('login'));
        $this->post(route('admin.tenants.writers.impersonate', [$tenant, $writer->id]))->assertRedirect(route('login'));
        $this->assertSame(0, ImpersonationToken::query()->count());
    }

    private function startImpersonation(User $admin, Tenant $tenant, Writer $writer): TestResponse
    {
        $this->actingAs($admin)
            ->post(route('admin.tenants.writers.impersonate', [$tenant, $writer->id]))
            ->assertOk();

        $token = ImpersonationToken::query()->latest('created_at')->firstOrFail()->token;

        return $this->post('http://'.$tenant->primaryDomainName().'/impersonate', ['token' => $token]);
    }

    private function freshBrowser(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
    }

    private function createTenant(string $domain, string $name): Tenant
    {
        $tenant = Tenant::create(['name' => $name]);
        $tenant->createDomain($domain);

        return $tenant;
    }

    private function writer(Tenant $tenant, string $email, string $name = 'Writer', TenantUserRole $role = TenantUserRole::Writer): Writer
    {
        return $tenant->run(fn () => tap((new Writer)->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'role' => $role,
        ]))->save());
    }
}
