<?php

namespace Tests\Feature;

use App\Enums\TenantUserRole;
use App\Models\AuditLog;
use App\Models\Post;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Writer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Stancl\Tenancy\Database\Models\ImpersonationToken;
use Tests\TestCase;

class TenantWriterImpersonationTest extends TestCase
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
            Tenant::query()->get()->each(function (Tenant $tenant) {
                $id = $tenant->id;
                $tenant->delete();
                File::deleteDirectory(storage_path('tenant'.$id));
            });
        }

        parent::tearDown();
    }

    public function test_a_tenant_admin_can_impersonate_a_writer_and_then_return(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = $this->admin($tenant, 'admin@alpha.test');
        $writer = $this->writer($tenant, 'ada@alpha.test');

        $this->actingAs($admin, 'writer')
            ->post('http://alpha.localhost/writers/'.$writer->id.'/impersonate')
            ->assertRedirect(route('writer.dashboard', absolute: false));

        $this->app['auth']->forgetGuards();

        $this->get('http://alpha.localhost/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.email', 'ada@alpha.test')
                ->where('auth.can.manageUsers', false)
                ->where('impersonation.writer_email', 'ada@alpha.test')
                ->where('impersonation.impersonator_email', 'admin@alpha.test'));

        $this->get('http://alpha.localhost/writers')->assertForbidden();

        $this->post('http://alpha.localhost/posts', [
            'title' => 'Written while impersonating',
            'body' => 'A note from the writer session.',
            'status' => 'draft',
        ])->assertRedirect();

        $tenant->run(function () use ($writer, $admin) {
            $post = Post::query()->where('title', 'Written while impersonating')->first();

            $this->assertSame($writer->id, $post->user_id);
            $this->assertNotSame($admin->id, $post->user_id);
        });

        $started = AuditLog::query()->where('action', 'tenant_impersonation.started')->first();
        $this->assertNotNull($started);
        $this->assertNull($started->actor_id);
        $this->assertSame((string) $tenant->id, $started->tenant_id);
        $this->assertSame($admin->id, $started->metadata['actor_id']);
        $this->assertSame('ada@alpha.test', $started->metadata['target_email']);
        $this->assertNotNull($started->created_at);

        $this->post('http://alpha.localhost/impersonation/stop')
            ->assertRedirect(route('writer.users.index', absolute: false));

        $this->app['auth']->forgetGuards();

        $this->get('http://alpha.localhost/writers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.email', 'admin@alpha.test')
                ->where('auth.can.manageUsers', true)
                ->where('impersonation', null));

        $stopped = AuditLog::query()->where('action', 'tenant_impersonation.stopped')->first();
        $this->assertNotNull($stopped);
        $this->assertSame('admin@alpha.test', $stopped->metadata['actor_email']);
        $this->assertSame($writer->id, $stopped->metadata['target_id']);
        $this->assertSame((string) $tenant->id, $stopped->tenant_id);
    }

    public function test_impersonation_cannot_be_started_by_a_writer_or_against_the_wrong_user(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $admin = $this->admin($alpha, 'admin@alpha.test');
        $secondAdmin = $this->admin($alpha, 'second@alpha.test', 'Second');
        $writer = $this->writer($alpha, 'ada@alpha.test');
        $other = $this->writer($alpha, 'bea@alpha.test', 'Bea');
        $betaWriter = $this->writer($beta, 'ada@beta.test', 'Beta Ada');
        $central = User::factory()->create(['email' => 'central@example.com']);

        $this->actingAs($writer, 'writer')
            ->post('http://alpha.localhost/writers/'.$other->id.'/impersonate')
            ->assertForbidden();

        $this->actingAs($admin, 'writer')
            ->post('http://alpha.localhost/writers/'.$admin->id.'/impersonate')
            ->assertForbidden();

        $this->actingAs($admin, 'writer')
            ->post('http://alpha.localhost/writers/'.$secondAdmin->id.'/impersonate')
            ->assertForbidden();

        $unknownId = $alpha->run(fn () => (int) Writer::query()->max('id')) + 1000;

        $this->actingAs($admin, 'writer')
            ->post('http://alpha.localhost/writers/'.$unknownId.'/impersonate')
            ->assertNotFound();

        $this->assertSame(0, AuditLog::query()->where('action', 'tenant_impersonation.started')->count());
        $this->assertSame('central@example.com', $central->fresh()->email);
        $beta->run(fn () => $this->assertSame('Beta Ada', $betaWriter->fresh()->name));
    }

    public function test_an_impersonated_session_cannot_be_reused_on_another_tenant(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $admin = $this->admin($alpha, 'admin@alpha.test');
        $writer = $this->writer($alpha, 'ada@alpha.test');
        $betaWriter = $this->writer($beta, 'ada@beta.test', 'Beta Ada');

        $response = $this->actingAs($admin, 'writer')
            ->post('http://alpha.localhost/writers/'.$writer->id.'/impersonate');

        $cookie = collect($response->headers->getCookies())->first(
            fn ($cookie) => $cookie->getName() === config('session.cookie')
        );

        $this->assertNotNull($cookie);
        $this->app['auth']->forgetGuards();

        $this->disableCookieEncryption()
            ->withCookie($cookie->getName(), $cookie->getValue())
            ->get('http://beta.localhost/dashboard')
            ->assertForbidden();

        $this->disableCookieEncryption()
            ->withCookie($cookie->getName(), $cookie->getValue())
            ->post('http://beta.localhost/impersonation/stop')
            ->assertForbidden();

        $this->disableCookieEncryption()
            ->withCookie($cookie->getName(), $cookie->getValue())
            ->put('http://beta.localhost/posts/1', [
                'title' => 'Hijacked',
                'body' => 'No',
                'status' => 'draft',
            ])
            ->assertForbidden();

        $beta->run(fn () => $this->assertSame('Beta Ada', $betaWriter->fresh()->name));
        $this->assertSame(0, AuditLog::query()->where('action', 'tenant_impersonation.stopped')->count());
    }

    public function test_email_and_password_changes_are_rejected_during_tenant_impersonation(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = $this->admin($tenant, 'admin@alpha.test');
        $writer = $this->writer($tenant, 'ada@alpha.test');

        $this->actingAs($admin, 'writer')
            ->post('http://alpha.localhost/writers/'.$writer->id.'/impersonate')
            ->assertRedirect();

        $this->app['auth']->forgetGuards();

        $this->get('http://alpha.localhost/dashboard')->assertOk();

        $this->put('http://alpha.localhost/writers/'.$writer->id, [
            'name' => 'Ada',
            'email' => 'stolen@alpha.test',
            'role' => TenantUserRole::Admin->value,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertForbidden();

        tenancy()->initialize($tenant);

        $impersonated = Writer::query()->findOrFail($writer->id);
        $impersonated->update(['name' => 'Renamed Writer']);

        foreach ([['email' => 'evil@alpha.test'], ['password' => 'new-password']] as $change) {
            try {
                $impersonated->fresh()->update($change);
                $this->fail('The change was allowed during impersonation.');
            } catch (AuthorizationException $exception) {
                $this->assertSame('Email and password changes are not allowed while impersonating.', $exception->getMessage());
            }
        }

        $fresh = $impersonated->fresh();
        $this->assertSame('ada@alpha.test', $fresh->email);
        $this->assertSame('Renamed Writer', $fresh->name);
        $this->assertTrue(Hash::check('password', $fresh->password));
        $this->assertSame(TenantUserRole::Writer, $fresh->role);
        tenancy()->end();
    }

    public function test_central_and_tenant_impersonation_cannot_run_together(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = $this->admin($tenant, 'admin@alpha.test');
        $writer = $this->writer($tenant, 'ada@alpha.test');
        $central = User::factory()->superAdmin()->create();

        $this->actingAs($central)
            ->post(route('admin.tenants.writers.impersonate', [$tenant, $admin->id]))
            ->assertOk();

        $token = ImpersonationToken::query()->latest('created_at')->firstOrFail()->token;

        $this->post('http://alpha.localhost/impersonate', ['token' => $token])->assertRedirect();
        $this->app['auth']->forgetGuards();

        $this->post('http://alpha.localhost/writers/'.$writer->id.'/impersonate')
            ->assertForbidden();

        $this->get('http://alpha.localhost/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.email', 'admin@alpha.test')
                ->where('impersonation.impersonator_email', $central->email));
    }

    private function createTenant(string $domain, string $name): Tenant
    {
        $tenant = Tenant::create(['name' => $name]);
        $tenant->createDomain($domain);

        return $tenant;
    }

    private function admin(Tenant $tenant, string $email, string $name = 'Admin'): Writer
    {
        return $tenant->run(function () use ($email, $name) {
            $writer = new Writer;
            $writer->forceFill([
                'name' => $name,
                'email' => $email,
                'password' => 'password',
                'role' => TenantUserRole::Admin,
            ])->save();

            return $writer;
        });
    }

    private function writer(Tenant $tenant, string $email, string $name = 'Ada'): Writer
    {
        return $tenant->run(fn () => Writer::create([
            'name' => $name,
            'email' => $email,
            'password' => 'password',
        ]));
    }
}
