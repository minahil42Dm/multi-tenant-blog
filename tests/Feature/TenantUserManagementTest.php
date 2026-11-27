<?php

namespace Tests\Feature;

use App\Enums\TenantUserRole;
use App\Models\Post;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Writer;
use App\Support\TenantLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Stancl\Tenancy\Database\Models\ImpersonationToken;
use Tests\TestCase;

class TenantUserManagementTest extends TestCase
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

    public function test_the_first_user_is_a_tenant_admin_and_later_users_default_to_writer(): void
    {
        $tenant = TenantLifecycle::provision(
            ['name' => 'Alpha', 'brand_color' => '#112233'],
            ['alpha.localhost'],
            ['name' => 'Owner', 'email' => 'owner@alpha.test', 'password' => 'password'],
        );

        $tenant->run(function () {
            $owner = Writer::query()->where('email', 'owner@alpha.test')->first();

            $this->assertInstanceOf(Writer::class, $owner);
            $this->assertSame(TenantUserRole::Admin, $owner->role);

            $writer = Writer::create([
                'name' => 'Ada',
                'email' => 'ada@alpha.test',
                'password' => 'password',
                'role' => TenantUserRole::Admin->value,
            ]);

            $this->assertSame(TenantUserRole::Writer, $writer->fresh()->role);
        });
    }

    public function test_super_admin_can_assign_a_tenant_admin_role_when_creating_a_user(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('admin.tenants.writers.store', $tenant), [
                'name' => 'Ada Writer',
                'email' => 'ada@alpha.test',
                'password' => 'secret-password',
                'password_confirmation' => 'secret-password',
                'role' => TenantUserRole::Admin->value,
            ])
            ->assertRedirect();

        $tenant->run(function () {
            $this->assertSame(TenantUserRole::Admin, Writer::query()->first()->role);
        });

        $this->actingAs(User::factory()->support()->create())
            ->post(route('admin.tenants.writers.store', $tenant), [
                'name' => 'Blair',
                'email' => 'blair@alpha.test',
                'password' => 'secret-password',
                'password_confirmation' => 'secret-password',
                'role' => TenantUserRole::Admin->value,
            ])
            ->assertForbidden();

        $tenant->run(fn () => $this->assertSame(1, Writer::query()->count()));
    }

    public function test_tenant_admin_can_list_create_edit_and_delete_users(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = $this->admin($tenant, 'admin@alpha.test');
        $central = User::factory()->create(['email' => 'central@example.com']);

        $this->actingAs($admin, 'writer')
            ->get('http://alpha.localhost/writers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Writer/Users/Index')
                ->has('users', 1)
                ->where('users.0.email', 'admin@alpha.test')
                ->where('users.0.can_delete', false)
                ->where('auth.can.manageUsers', true)
                ->missing('auth.can.manageTenants'));

        $this->actingAs($admin, 'writer')
            ->post('http://alpha.localhost/writers', $this->userData())
            ->assertRedirect();

        $writer = $tenant->run(fn () => Writer::query()->where('email', 'ada@alpha.test')->first());

        $this->assertInstanceOf(Writer::class, $writer);
        $this->assertSame(TenantUserRole::Writer, $writer->role);

        $this->actingAs($admin, 'writer')
            ->put('http://alpha.localhost/writers/'.$writer->id, [
                'name' => 'Ada Edited',
                'email' => 'ada@alpha.test',
                'role' => TenantUserRole::Admin->value,
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertRedirect();

        $tenant->run(function () use ($writer) {
            $fresh = $writer->fresh();

            $this->assertSame('Ada Edited', $fresh->name);
            $this->assertSame(TenantUserRole::Admin, $fresh->role);
        });

        $this->post('http://alpha.localhost/logout');

        $this->post('http://alpha.localhost/login', [
            'email' => 'ada@alpha.test',
            'password' => 'new-password',
        ])->assertRedirect(route('writer.dashboard', absolute: false));

        $this->post('http://alpha.localhost/logout');

        $this->post('http://alpha.localhost/login', [
            'email' => 'ada@alpha.test',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->actingAs($admin, 'writer')
            ->delete('http://alpha.localhost/writers/'.$writer->id)
            ->assertRedirect();

        $tenant->run(fn () => $this->assertNull($writer->fresh()));
        $this->assertSame('central@example.com', $central->fresh()->email);
    }

    public function test_writers_cannot_manage_users_or_change_roles(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = $this->admin($tenant, 'admin@alpha.test');
        $writer = $this->writer($tenant, 'ada@alpha.test');

        $this->actingAs($writer, 'writer')
            ->get('http://alpha.localhost/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.can.manageUsers', false)
                ->where('auth.can.createPosts', true)
                ->missing('auth.can.viewTenants'));

        foreach (['get', 'post'] as $method) {
            $this->actingAs($writer, 'writer')
                ->{$method}('http://alpha.localhost/writers', $this->userData([
                    'role' => TenantUserRole::Admin->value,
                ]))
                ->assertForbidden();
        }

        $this->actingAs($writer, 'writer')
            ->get('http://alpha.localhost/writers/create')
            ->assertForbidden();

        $this->actingAs($writer, 'writer')
            ->get('http://alpha.localhost/writers/'.$admin->id.'/edit')
            ->assertForbidden();

        $this->actingAs($writer, 'writer')
            ->put('http://alpha.localhost/writers/'.$admin->id, [
                'name' => 'Taken Over',
                'email' => 'admin@alpha.test',
                'role' => TenantUserRole::Writer->value,
            ])
            ->assertForbidden();

        $this->actingAs($writer, 'writer')
            ->delete('http://alpha.localhost/writers/'.$admin->id)
            ->assertForbidden();

        $tenant->run(function () use ($admin) {
            $this->assertSame(TenantUserRole::Admin, $admin->fresh()->role);
            $this->assertSame('Admin', $admin->fresh()->name);
        });
    }

    public function test_an_admin_cannot_demote_or_delete_themselves_or_the_last_admin(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = $this->admin($tenant, 'admin@alpha.test');
        $second = $this->admin($tenant, 'second@alpha.test', 'Second');

        $this->actingAs($admin, 'writer')
            ->put('http://alpha.localhost/writers/'.$admin->id, [
                'name' => 'Admin',
                'email' => 'admin@alpha.test',
                'role' => TenantUserRole::Writer->value,
            ])
            ->assertForbidden();

        $this->actingAs($admin, 'writer')
            ->delete('http://alpha.localhost/writers/'.$admin->id)
            ->assertForbidden();

        $tenant->run(function () use ($admin) {
            $this->assertSame(TenantUserRole::Admin, $admin->fresh()->role);
            $this->assertNotNull($admin->fresh());
        });

        $this->actingAs($admin, 'writer')
            ->put('http://alpha.localhost/writers/'.$second->id, [
                'name' => 'Second',
                'email' => 'second@alpha.test',
                'role' => TenantUserRole::Writer->value,
            ])
            ->assertRedirect();

        $tenant->run(fn () => $this->assertSame(TenantUserRole::Writer, $second->fresh()->role));

        $this->actingAs($admin, 'writer')
            ->put('http://alpha.localhost/writers/'.$admin->id, [
                'name' => 'Admin',
                'email' => 'admin@alpha.test',
                'role' => TenantUserRole::Writer->value,
            ])
            ->assertForbidden();

        $this->actingAs($admin, 'writer')
            ->delete('http://alpha.localhost/writers/'.$admin->id)
            ->assertForbidden();

        $tenant->run(fn () => $this->assertSame(TenantUserRole::Admin, $admin->fresh()->role));
    }

    public function test_duplicate_emails_are_rejected_inside_one_tenant_and_allowed_across_tenants(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $admin = $this->admin($alpha, 'admin@alpha.test');
        $this->writer($alpha, 'ada@alpha.test');
        $betaAdmin = $this->admin($beta, 'admin@beta.test');

        $this->actingAs($admin, 'writer')
            ->post('http://alpha.localhost/writers', $this->userData())
            ->assertSessionHasErrors('email');

        $alpha->run(fn () => $this->assertSame(1, Writer::query()->where('email', 'ada@alpha.test')->count()));

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->actingAs($betaAdmin, 'writer')
            ->post('http://beta.localhost/writers', $this->userData())
            ->assertRedirect();

        $beta->run(fn () => $this->assertSame(1, Writer::query()->where('email', 'ada@alpha.test')->count()));
    }

    public function test_cross_tenant_user_ids_cannot_be_read_or_changed(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $admin = $this->admin($alpha, 'admin@alpha.test');
        $this->writer($beta, 'first@beta.test', 'First');
        $betaUser = $this->writer($beta, 'ada@beta.test', 'Beta Ada');

        $alpha->run(fn () => $this->assertNull(Writer::query()->find($betaUser->id)));

        $this->actingAs($admin, 'writer')
            ->get('http://alpha.localhost/writers/'.$betaUser->id.'/edit')
            ->assertNotFound();

        $this->actingAs($admin, 'writer')
            ->put('http://alpha.localhost/writers/'.$betaUser->id, [
                'name' => 'Hijacked',
                'email' => 'ada@beta.test',
                'role' => TenantUserRole::Admin->value,
            ])
            ->assertNotFound();

        $beta->run(function () use ($betaUser) {
            $fresh = $betaUser->fresh();

            $this->assertSame('Beta Ada', $fresh->name);
            $this->assertSame(TenantUserRole::Writer, $fresh->role);
        });

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $login = $this->post('http://alpha.localhost/login', [
            'email' => 'admin@alpha.test',
            'password' => 'password',
        ]);

        $cookie = collect($login->headers->getCookies())->first(
            fn ($cookie) => $cookie->getName() === config('session.cookie')
        );

        $this->assertNotNull($cookie);

        $this->disableCookieEncryption()
            ->withCookie($cookie->getName(), $cookie->getValue())
            ->get('http://beta.localhost/writers/'.$betaUser->id.'/edit')
            ->assertForbidden();

        $beta->run(fn () => $this->assertSame('Beta Ada', $betaUser->fresh()->name));
    }

    public function test_a_user_with_posts_cannot_be_deleted(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = $this->admin($tenant, 'admin@alpha.test');
        $writer = $this->writer($tenant, 'ada@alpha.test');

        $post = $tenant->run(fn () => Post::forceCreate([
            'user_id' => $writer->id,
            'title' => 'Keep me',
            'slug' => 'keep-me',
            'body' => 'Body',
            'status' => 'draft',
        ]));

        $this->actingAs($admin, 'writer')
            ->from('http://alpha.localhost/writers')
            ->delete('http://alpha.localhost/writers/'.$writer->id)
            ->assertRedirect('http://alpha.localhost/writers')
            ->assertSessionHasErrors('user');

        $tenant->run(function () use ($writer, $post) {
            $this->assertNotNull($writer->fresh());
            $this->assertNotNull($post->fresh());
        });
    }

    public function test_an_invalid_role_is_rejected(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = $this->admin($tenant, 'admin@alpha.test');

        $this->actingAs($admin, 'writer')
            ->post('http://alpha.localhost/writers', $this->userData([
                'role' => 'owner',
            ]))
            ->assertSessionHasErrors('role');

        $tenant->run(fn () => $this->assertSame(1, Writer::query()->count()));
    }

    public function test_guests_and_suspended_tenants_cannot_open_user_management(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = $this->admin($tenant, 'admin@alpha.test');

        $this->get('http://alpha.localhost/writers')
            ->assertRedirect(route('writer.login', absolute: false));

        $this->assertTrue($tenant->suspend());

        $this->actingAs($admin, 'writer')
            ->get('http://alpha.localhost/writers')
            ->assertStatus(503);
    }

    public function test_updating_a_user_rejects_an_email_another_user_already_has(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = $this->admin($tenant, 'admin@alpha.test');
        $writer = $this->writer($tenant, 'ada@alpha.test');

        $this->actingAs($admin, 'writer')
            ->put('http://alpha.localhost/writers/'.$writer->id, [
                'name' => 'Ada',
                'email' => 'admin@alpha.test',
                'role' => TenantUserRole::Writer->value,
            ])
            ->assertSessionHasErrors('email');

        $tenant->run(fn () => $this->assertSame('ada@alpha.test', $writer->fresh()->email));
    }

    public function test_a_blank_password_keeps_the_existing_password(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = $this->admin($tenant, 'admin@alpha.test');
        $writer = $this->writer($tenant, 'ada@alpha.test');
        $hash = $tenant->run(fn () => $writer->fresh()->password);

        $this->actingAs($admin, 'writer')
            ->put('http://alpha.localhost/writers/'.$writer->id, [
                'name' => 'Ada Renamed',
                'email' => 'ada@alpha.test',
                'role' => TenantUserRole::Writer->value,
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $tenant->run(function () use ($writer, $hash) {
            $fresh = $writer->fresh();

            $this->assertSame('Ada Renamed', $fresh->name);
            $this->assertTrue(Hash::check('password', $fresh->password));
            $this->assertSame($hash, $fresh->password);
        });
    }

    public function test_a_password_confirmation_mismatch_is_rejected(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = $this->admin($tenant, 'admin@alpha.test');
        $writer = $this->writer($tenant, 'ada@alpha.test');

        $this->actingAs($admin, 'writer')
            ->put('http://alpha.localhost/writers/'.$writer->id, [
                'name' => 'Ada',
                'email' => 'ada@alpha.test',
                'role' => TenantUserRole::Writer->value,
                'password' => 'new-password',
                'password_confirmation' => 'different-password',
            ])
            ->assertSessionHasErrors('password');

        $tenant->run(fn () => $this->assertTrue(Hash::check('password', $writer->fresh()->password)));
    }

    public function test_an_admin_can_create_another_tenant_admin_directly(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = $this->admin($tenant, 'admin@alpha.test');

        $this->actingAs($admin, 'writer')
            ->post('http://alpha.localhost/writers', $this->userData([
                'name' => 'Second Admin',
                'email' => 'second@alpha.test',
                'role' => TenantUserRole::Admin->value,
            ]))
            ->assertRedirect();

        $tenant->run(function () {
            $second = Writer::query()->where('email', 'second@alpha.test')->first();

            $this->assertSame(TenantUserRole::Admin, $second->role);
        });
    }

    public function test_user_updates_accept_patch_requests(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = $this->admin($tenant, 'admin@alpha.test');
        $writer = $this->writer($tenant, 'ada@alpha.test');

        $this->actingAs($admin, 'writer')
            ->patch('http://alpha.localhost/writers/'.$writer->id, [
                'name' => 'Patched Ada',
                'email' => 'ada@alpha.test',
                'role' => TenantUserRole::Writer->value,
            ])
            ->assertRedirect();

        $tenant->run(fn () => $this->assertSame('Patched Ada', $writer->fresh()->name));
    }

    public function test_an_admin_session_cannot_be_reused_on_another_tenant(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $this->admin($alpha, 'admin@alpha.test');
        $betaWriter = $this->writer($beta, 'ada@beta.test', 'Beta Ada');

        $login = $this->post('http://alpha.localhost/login', [
            'email' => 'admin@alpha.test',
            'password' => 'password',
        ]);

        $cookie = collect($login->headers->getCookies())->first(
            fn ($cookie) => $cookie->getName() === config('session.cookie')
        );

        $this->assertNotNull($cookie);

        $this->disableCookieEncryption()
            ->withCookie($cookie->getName(), $cookie->getValue())
            ->get('http://beta.localhost/writers')
            ->assertForbidden();

        $this->disableCookieEncryption()
            ->withCookie($cookie->getName(), $cookie->getValue())
            ->get('http://beta.localhost/posts')
            ->assertForbidden();

        $this->disableCookieEncryption()
            ->withCookie($cookie->getName(), $cookie->getValue())
            ->put('http://beta.localhost/writers/'.$betaWriter->id, [
                'name' => 'Hijacked',
                'email' => 'ada@beta.test',
                'role' => TenantUserRole::Admin->value,
            ])
            ->assertForbidden();

        $beta->run(fn () => $this->assertSame('Beta Ada', $betaWriter->fresh()->name));
    }

    public function test_changing_your_own_password_requires_the_current_password(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = $this->admin($tenant, 'admin@alpha.test');
        $writer = $this->writer($tenant, 'ada@alpha.test');

        $this->actingAs($admin, 'writer')
            ->put('http://alpha.localhost/writers/'.$admin->id, [
                'name' => 'Admin',
                'email' => 'admin@alpha.test',
                'role' => TenantUserRole::Admin->value,
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertSessionHasErrors('current_password');

        $this->actingAs($admin, 'writer')
            ->put('http://alpha.localhost/writers/'.$admin->id, [
                'name' => 'Admin',
                'email' => 'admin@alpha.test',
                'role' => TenantUserRole::Admin->value,
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
                'current_password' => 'wrong-password',
            ])
            ->assertSessionHasErrors('current_password');

        $this->actingAs($admin, 'writer')
            ->put('http://alpha.localhost/writers/'.$writer->id, [
                'name' => 'Ada',
                'email' => 'ada@alpha.test',
                'role' => TenantUserRole::Writer->value,
                'password' => 'writer-password',
                'password_confirmation' => 'writer-password',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors('current_password');

        $this->actingAs($admin, 'writer')
            ->put('http://alpha.localhost/writers/'.$admin->id, [
                'name' => 'Admin',
                'email' => 'admin@alpha.test',
                'role' => TenantUserRole::Admin->value,
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
                'current_password' => 'password',
            ])
            ->assertRedirect();

        $tenant->run(function () use ($admin, $writer) {
            $this->assertTrue(Hash::check('new-password', $admin->fresh()->password));
            $this->assertTrue(Hash::check('writer-password', $writer->fresh()->password));
        });
    }

    public function test_credential_changes_during_impersonation_are_validation_errors(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = $this->admin($tenant, 'admin@alpha.test');
        $writer = $this->writer($tenant, 'ada@alpha.test');
        $central = User::factory()->superAdmin()->create();

        $this->actingAs($central)
            ->post(route('admin.tenants.writers.impersonate', [$tenant, $admin->id]))
            ->assertOk();

        $token = ImpersonationToken::query()->latest('created_at')->firstOrFail()->token;

        $this->post('http://alpha.localhost/impersonate', ['token' => $token])
            ->assertRedirect();

        $this->put('http://alpha.localhost/writers/'.$writer->id, [
            'name' => 'Ada',
            'email' => 'ada@alpha.test',
            'role' => TenantUserRole::Writer->value,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasErrors('password');

        $this->put('http://alpha.localhost/writers/'.$writer->id, [
            'name' => 'Ada',
            'email' => 'renamed@alpha.test',
            'role' => TenantUserRole::Writer->value,
        ])->assertSessionHasErrors('email');

        $tenant->run(function () use ($writer) {
            $fresh = $writer->fresh();

            $this->assertSame('ada@alpha.test', $fresh->email);
            $this->assertTrue(Hash::check('password', $fresh->password));
        });
    }

    public function test_a_duplicate_email_that_appears_during_the_save_is_a_validation_error(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = $this->admin($tenant, 'admin@alpha.test');
        $writer = $this->writer($tenant, 'ada@alpha.test');

        Writer::updating(function (Writer $model): void {
            if (! $model->isDirty('email') || $model->email !== 'race@alpha.test') {
                return;
            }

            DB::table('users')->insert([
                'name' => 'Race',
                'email' => 'race@alpha.test',
                'password' => 'secret',
                'role' => TenantUserRole::Writer->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->actingAs($admin, 'writer')
            ->put('http://alpha.localhost/writers/'.$writer->id, [
                'name' => 'Ada',
                'email' => 'race@alpha.test',
                'role' => TenantUserRole::Writer->value,
            ])
            ->assertSessionHasErrors('email');

        $tenant->run(fn () => $this->assertSame('ada@alpha.test', $writer->fresh()->email));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function userData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Writer',
            'email' => 'ada@alpha.test',
            'role' => TenantUserRole::Writer->value,
            'password' => 'password',
            'password_confirmation' => 'password',
        ], $overrides);
    }

    private function createTenant(string $domain, string $name): Tenant
    {
        $tenant = Tenant::create([
            'name' => $name,
        ]);

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
