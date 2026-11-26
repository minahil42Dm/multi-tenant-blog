<?php

namespace Tests\Feature;

use App\Enums\CentralRole;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Writer;
use App\Support\CentralAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CentralRoleAuthorizationTest extends TestCase
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
                if ($tenant->logo) {
                    Storage::disk('public')->delete($tenant->logo);
                }

                $tenant->delete();
                File::deleteDirectory(storage_path('tenant'.$tenant->id));
            });
        }

        parent::tearDown();
    }

    public function test_super_admin_has_full_central_access(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dashboard')
                ->where('roleLabel', 'Super admin')
                ->where('auth.can', [
                    'accessAdmin' => true,
                    'viewTenants' => true,
                    'manageTenants' => true,
                    'viewTenantUsers' => true,
                    'manageTenantUsers' => true,
                    'impersonateTenantUsers' => true,
                ]));

        $this->actingAs($admin)->get(route('admin.tenants.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.tenants.create'))->assertOk();
        $this->actingAs($admin)->get(route('admin.tenants.writers.index', $tenant))->assertOk();
        $this->actingAs($admin)->get(route('admin.tenants.writers.create', $tenant))->assertOk();

        $this->actingAs($admin)
            ->post(route('admin.tenants.store'), $this->tenantData())
            ->assertRedirect(route('admin.tenants.index'));

        $this->actingAs($admin)
            ->post(route('admin.tenants.writers.store', $tenant), $this->writerData())
            ->assertRedirect(route('admin.tenants.writers.index', $tenant));

        $this->assertSame(2, Tenant::query()->count());
        $this->assertTenantWriterCount($tenant, 1);
    }

    public function test_support_can_view_tenants_and_tenant_users_read_only(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $tenant->run(fn () => Writer::factory()->create(['name' => 'Ada', 'email' => 'ada@alpha.test']));
        $support = User::factory()->support()->create();

        $readOnlyAbilities = [
            'accessAdmin' => true,
            'viewTenants' => true,
            'manageTenants' => false,
            'viewTenantUsers' => true,
            'manageTenantUsers' => false,
            'impersonateTenantUsers' => true,
        ];

        $this->actingAs($support)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dashboard')
                ->where('roleLabel', 'Support')
                ->where('auth.can', $readOnlyAbilities));

        $this->actingAs($support)
            ->get(route('admin.tenants.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tenants/Index')
                ->has('tenants', 1)
                ->where('tenants.0.name', 'Alpha')
                ->where('auth.can', $readOnlyAbilities));

        $this->actingAs($support)
            ->get(route('admin.tenants.writers.index', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tenants/Writers/Index')
                ->has('writers', 1)
                ->where('writers.0.email', 'ada@alpha.test'));
    }

    public function test_support_is_blocked_from_tenant_modifications(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $support = User::factory()->support()->create();
        $logosBefore = Storage::disk('public')->allFiles('tenant-logos');

        $this->actingAs($support)->get(route('admin.tenants.create'))->assertForbidden();
        $this->actingAs($support)->post(route('admin.tenants.store'), $this->tenantData())->assertForbidden();
        $this->actingAs($support)->get(route('admin.tenants.writers.create', $tenant))->assertForbidden();
        $this->actingAs($support)
            ->post(route('admin.tenants.writers.store', $tenant), $this->writerData())
            ->assertForbidden();

        $this->assertSame(1, Tenant::query()->count());
        $this->assertSame($logosBefore, Storage::disk('public')->allFiles('tenant-logos'));
        $this->assertTenantWriterCount($tenant, 0);
    }

    public function test_support_gates_allow_viewing_and_impersonation_but_not_management(): void
    {
        $support = User::factory()->support()->create();

        $this->assertFalse($support->can(CentralAbility::MANAGE_TENANTS));
        $this->assertFalse($support->can(CentralAbility::MANAGE_TENANT_USERS));
        $this->assertTrue($support->can(CentralAbility::IMPERSONATE_TENANT_USERS));
    }

    public function test_users_without_a_central_role_are_forbidden_everywhere_in_the_admin(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $user = User::factory()->create();

        foreach ($this->adminGetRoutes($tenant) as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }

        $this->actingAs($user)->post(route('admin.tenants.store'), $this->tenantData())->assertForbidden();
        $this->actingAs($user)
            ->post(route('admin.tenants.writers.store', $tenant), $this->writerData())
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.can.accessAdmin', false));
    }

    public function test_unverified_support_users_must_verify_first(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $support = User::factory()->support()->unverified()->create();

        foreach ($this->adminGetRoutes($tenant) as $url) {
            $this->actingAs($support)->get($url)->assertRedirect(route('verification.notice'));
        }

        $this->assertFalse($support->can(CentralAbility::ACCESS_ADMIN));
    }

    public function test_guests_are_redirected_to_the_central_login(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        foreach ($this->adminGetRoutes($tenant) as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }

        $this->post(route('admin.tenants.store'), $this->tenantData())->assertRedirect(route('login'));
        $this->post(route('admin.tenants.writers.store', $tenant), $this->writerData())->assertRedirect(route('login'));

        $this->assertSame(1, Tenant::query()->count());
    }

    public function test_super_admin_and_support_can_log_in_through_the_central_login(): void
    {
        foreach ([CentralRole::SuperAdmin, CentralRole::Support] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->post(route('login'), [
                'email' => $user->email,
                'password' => 'password',
            ])->assertRedirect(route('dashboard', absolute: false));

            $this->assertAuthenticatedAs($user, 'web');
            $this->assertGuest('writer');
            $this->get(route('admin.dashboard'))->assertOk();

            $this->post(route('logout'));
            $this->assertGuest('web');
        }
    }

    public function test_central_roles_grant_nothing_on_tenant_domains(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $support = User::factory()->support()->create();

        $this->actingAs($support)
            ->get('http://alpha.localhost/dashboard')
            ->assertRedirect(route('writer.login', absolute: false));

        $this->assertGuest('writer');

        $this->actingAs($support)
            ->get("http://alpha.localhost/admin/tenants/{$tenant->id}/writers")
            ->assertNotFound();
    }

    public function test_tenant_writers_hold_no_central_abilities(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        $tenant->run(function () {
            $writer = Writer::factory()->create();

            foreach (array_keys(CentralAbility::roles()) as $ability) {
                $this->assertFalse(Gate::forUser($writer)->allows($ability), $ability);
            }
        });

        $tenant->run(fn () => Writer::factory()->create(['email' => 'ada@alpha.test']));

        $this->post('http://alpha.localhost/login', [
            'email' => 'ada@alpha.test',
            'password' => 'password',
        ])->assertRedirect(route('writer.dashboard', absolute: false));

        $this->assertAuthenticated('writer');
        $this->assertGuest('web');

        $this->get(route('admin.tenants.index'))->assertRedirect(route('login'));
    }

    public function test_the_role_migration_turns_existing_admins_into_super_admins(): void
    {
        $migration = require database_path('migrations/2026_11_24_000000_replace_is_admin_with_role_on_users_table.php');

        $migration->down();

        $this->assertTrue(Schema::hasColumn('users', 'is_admin'));
        $this->assertFalse(Schema::hasColumn('users', 'role'));

        DB::table('users')->insert([
            ['name' => 'Admin', 'email' => 'admin@central.test', 'password' => 'x', 'is_admin' => true],
            ['name' => 'Member', 'email' => 'member@central.test', 'password' => 'x', 'is_admin' => false],
        ]);

        $migration->up();

        $this->assertFalse(Schema::hasColumn('users', 'is_admin'));
        $this->assertSame('super_admin', DB::table('users')->where('email', 'admin@central.test')->value('role'));
        $this->assertNull(DB::table('users')->where('email', 'member@central.test')->value('role'));
    }

    /**
     * @return array<int, string>
     */
    private function adminGetRoutes(Tenant $tenant): array
    {
        return [
            route('admin.dashboard'),
            route('admin.tenants.index'),
            route('admin.tenants.create'),
            route('admin.tenants.writers.index', $tenant),
            route('admin.tenants.writers.create', $tenant),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantData(): array
    {
        return [
            'name' => 'Beta',
            'domain' => 'beta.localhost',
            'brand_color' => '#1d4ed8',
            'logo' => UploadedFile::fake()->image('logo.png'),
            'admin_name' => 'Beta Admin',
            'admin_email' => 'admin@beta.test',
            'admin_password' => 'secret-password',
            'admin_password_confirmation' => 'secret-password',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function writerData(): array
    {
        return [
            'name' => 'Ada Writer',
            'email' => 'ada@alpha.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ];
    }

    private function createTenant(string $domain, string $name): Tenant
    {
        $tenant = Tenant::create(['name' => $name]);
        $tenant->createDomain($domain);

        return $tenant;
    }

    private function assertTenantWriterCount(Tenant $tenant, int $expected): void
    {
        $tenant->run(fn () => $this->assertSame($expected, DB::table('users')->count()));
    }
}
