<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Writer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WriterAuthenticationTest extends TestCase
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

    public function test_writer_can_register_and_log_in_on_their_tenant_domain(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $other = $this->createTenant('beta.localhost', 'Beta');

        $this->post('http://alpha.localhost/register', [
            'name' => 'Ada Writer',
            'email' => 'ada@alpha.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'is_admin' => true,
        ])->assertRedirect(route('writer.dashboard', absolute: false));

        $this->assertAuthenticated('writer');
        $this->assertGuest('web');

        $tenant->run(function () {
            $writer = Writer::query()->where('email', 'ada@alpha.test')->first();

            $this->assertNotNull($writer);
            $this->assertSame('Ada Writer', $writer->name);
            $this->assertFalse(Schema::hasColumn('users', 'is_admin'));
        });

        $other->run(function () {
            $this->assertSame(0, DB::table('users')->count());
        });

        $this->assertNull(User::query()->where('email', 'ada@alpha.test')->first());

        $this->post('http://alpha.localhost/logout')
            ->assertRedirect(route('writer.login', absolute: false));

        $this->assertGuest('writer');

        $this->post('http://alpha.localhost/login', [
            'email' => 'ada@alpha.test',
            'password' => 'password',
        ])->assertRedirect(route('writer.dashboard', absolute: false));

        $this->get('http://alpha.localhost/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Writer/Dashboard')
                ->where('tenantName', 'Alpha')
                ->where('auth.user.email', 'ada@alpha.test'));
    }

    public function test_guests_cannot_access_the_writer_dashboard(): void
    {
        $this->createTenant('alpha.localhost', 'Alpha');

        $this->get('http://alpha.localhost/dashboard')
            ->assertRedirect(route('writer.login', absolute: false));
    }

    public function test_writer_cannot_authenticate_on_another_tenant_domain(): void
    {
        $this->createTenant('alpha.localhost', 'Alpha');
        $other = $this->createTenant('beta.localhost', 'Beta');

        $this->post('http://alpha.localhost/register', [
            'name' => 'Ada Writer',
            'email' => 'ada@alpha.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('writer.dashboard', absolute: false));

        $this->post('http://alpha.localhost/logout');

        $this->post('http://beta.localhost/login', [
            'email' => 'ada@alpha.test',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('writer');

        $other->run(function () {
            $this->assertSame(0, DB::table('users')->count());
        });
    }

    public function test_writer_cannot_access_another_tenants_dashboard(): void
    {
        $this->createTenant('alpha.localhost', 'Alpha');
        $this->createTenant('beta.localhost', 'Beta');

        $this->post('http://alpha.localhost/register', [
            'name' => 'Ada Writer',
            'email' => 'ada@alpha.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('writer.dashboard', absolute: false));

        $cookie = collect($this->post('http://alpha.localhost/login', [
            'email' => 'ada@alpha.test',
            'password' => 'password',
        ])->headers->getCookies())->first(
            fn ($cookie) => $cookie->getName() === config('session.cookie')
        );

        $this->assertNotNull($cookie);

        $this->disableCookieEncryption()
            ->withCookie($cookie->getName(), $cookie->getValue())
            ->get('http://beta.localhost/dashboard')
            ->assertForbidden();
    }

    public function test_writer_cannot_use_central_admin_login(): void
    {
        $this->createTenant('alpha.localhost', 'Alpha');

        $this->post('http://alpha.localhost/register', [
            'name' => 'Ada Writer',
            'email' => 'ada@alpha.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->post('http://alpha.localhost/logout');

        $this->post('http://localhost/login', [
            'email' => 'ada@alpha.test',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('web');
        $this->assertGuest('writer');

        $admin = $this->get('http://localhost/admin');
        $admin->assertRedirect();
        $this->assertStringEndsWith('/login', (string) $admin->headers->get('Location'));
    }

    public function test_central_admin_login_and_dashboard_still_work(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@central.test',
        ]);

        $this->post('http://localhost/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($admin, 'web');
        $this->assertGuest('writer');

        $this->get('http://localhost/admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard'));
    }

    public function test_public_registration_cannot_create_an_administrator(): void
    {
        $this->post('http://localhost/register', [
            'name' => 'Public User',
            'email' => 'public@central.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'is_admin' => true,
        ])->assertRedirect(route('dashboard', absolute: false));

        $user = User::query()->where('email', 'public@central.test')->first();

        $this->assertNotNull($user);
        $this->assertFalse($user->is_admin);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('writer');
    }

    private function createTenant(string $domain, string $name): Tenant
    {
        $tenant = Tenant::create([
            'name' => $name,
        ]);

        $tenant->createDomain($domain);

        return $tenant;
    }
}
