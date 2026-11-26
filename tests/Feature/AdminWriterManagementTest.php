<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Writer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class AdminWriterManagementTest extends TestCase
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

    public function test_guests_cannot_create_tenant_writers(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        $this->get(route('admin.tenants.writers.index', $tenant))
            ->assertRedirect(route('login'));

        $this->get(route('admin.tenants.writers.create', $tenant))
            ->assertRedirect(route('login'));

        $this->post(route('admin.tenants.writers.store', $tenant), $this->writerData())
            ->assertRedirect(route('login'));

        $this->assertTenantWriterCount($tenant, 0);
    }

    public function test_non_admin_users_cannot_create_tenant_writers(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.tenants.writers.index', $tenant))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('admin.tenants.writers.create', $tenant))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.tenants.writers.store', $tenant), $this->writerData())
            ->assertForbidden();

        $this->assertTenantWriterCount($tenant, 0);
    }

    public function test_unverified_admins_cannot_create_tenant_writers(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = User::factory()->admin()->unverified()->create();

        $this->actingAs($admin)
            ->get(route('admin.tenants.writers.index', $tenant))
            ->assertRedirect(route('verification.notice'));

        $this->actingAs($admin)
            ->get(route('admin.tenants.writers.create', $tenant))
            ->assertRedirect(route('verification.notice'));

        $this->actingAs($admin)
            ->post(route('admin.tenants.writers.store', $tenant), $this->writerData())
            ->assertRedirect(route('verification.notice'));

        $this->assertTenantWriterCount($tenant, 0);
    }

    public function test_tenant_writers_cannot_reach_writer_management(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        $tenant->run(function () {
            Writer::factory()->create(['email' => 'ada@alpha.test']);
        });

        $this->post('http://alpha.localhost/login', [
            'email' => 'ada@alpha.test',
            'password' => 'password',
        ])->assertRedirect(route('writer.dashboard', absolute: false));

        $this->post("http://alpha.localhost/admin/tenants/{$tenant->id}/writers", $this->writerData())
            ->assertNotFound();

        $this->get("http://alpha.localhost/admin/tenants/{$tenant->id}/writers")
            ->assertNotFound();

        $this->get(route('admin.tenants.writers.index', $tenant))
            ->assertRedirect(route('login'));

        $this->post(route('admin.tenants.writers.store', $tenant), $this->writerData())
            ->assertRedirect(route('login'));

        $this->assertTenantWriterCount($tenant, 1);
    }

    public function test_admin_sees_only_the_selected_tenants_writers(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $other = $this->createTenant('beta.localhost', 'Beta');

        $tenant->run(function () {
            Writer::factory()->create(['name' => 'Zed Writer', 'email' => 'zed@alpha.test']);
            Writer::factory()->create(['name' => 'Ada Writer', 'email' => 'ada@alpha.test']);
        });

        $other->run(function () {
            Writer::factory()->create(['name' => 'Beta Writer', 'email' => 'writer@beta.test']);
        });

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.tenants.writers.index', $tenant))
            ->assertOk()
            ->assertDontSee('writer@beta.test')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tenants/Writers/Index')
                ->where('tenant.id', $tenant->id)
                ->where('tenant.name', 'Alpha')
                ->where('tenant.domain', 'alpha.localhost')
                ->has('writers', 2)
                ->has('writers.0', fn (Assert $writer) => $writer
                    ->where('name', 'Ada Writer')
                    ->where('email', 'ada@alpha.test')
                    ->hasAll(['id', 'created_at', 'role_label', 'can_impersonate'])
                    ->missing('password')
                    ->missing('remember_token'))
                ->where('writers.1.name', 'Zed Writer')
                ->where('writers.1.email', 'zed@alpha.test'));

        $this->assertFalse(tenancy()->initialized);
    }

    public function test_writer_list_shows_an_empty_state_for_tenants_without_writers(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.tenants.writers.index', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tenants/Writers/Index')
                ->has('writers', 0));
    }

    public function test_unexpected_failure_while_listing_writers_restores_the_central_context(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $centralConnection = config('database.default');

        DB::listen(function (QueryExecuted $query) {
            if (str_contains($query->sql, 'from "users"') && tenancy()->initialized) {
                throw new RuntimeException('writer list failed');
            }
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs(User::factory()->admin()->create())
                ->get(route('admin.tenants.writers.index', $tenant));

            $this->fail('The unexpected exception was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('writer list failed', $exception->getMessage());
        }

        $this->assertFalse(tenancy()->initialized);
        $this->assertSame($centralConnection, config('database.default'));
    }

    public function test_tenant_list_shows_each_tenants_writer_count(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $this->createTenant('beta.localhost', 'Beta');

        $tenant->run(fn () => Writer::factory()->count(2)->create());

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.tenants.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tenants/Index')
                ->has('tenants', 2)
                ->where('tenants', fn ($tenants) => collect($tenants)->pluck('writer_count', 'name')->sortKeys()->all() === [
                    'Alpha' => 2,
                    'Beta' => 0,
                ]));
    }

    public function test_admin_can_view_the_create_writer_page(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.tenants.writers.create', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tenants/Writers/Create')
                ->where('tenant.id', $tenant->id)
                ->where('tenant.name', 'Alpha')
                ->where('tenant.domain', 'alpha.localhost'));
    }

    public function test_verified_admin_creates_a_writer_only_in_the_selected_tenant_database(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $other = $this->createTenant('beta.localhost', 'Beta');
        $admin = User::factory()->admin()->create();

        $this->assertTrue($admin->hasVerifiedEmail());

        $this->actingAs($admin)
            ->post(route('admin.tenants.writers.store', $tenant), $this->writerData())
            ->assertRedirect(route('admin.tenants.writers.index', $tenant))
            ->assertSessionHas('success', 'Writer created for Alpha.');

        $tenant->run(function () {
            $writer = Writer::query()->where('email', 'ada@alpha.test')->first();

            $this->assertNotNull($writer);
            $this->assertSame('Ada Writer', $writer->name);
            $this->assertNotSame('secret-password', $writer->password);
            $this->assertTrue(Hash::check('secret-password', $writer->password));
        });

        $this->assertTenantWriterCount($other, 0);
        $this->assertSame(0, User::query()->where('email', 'ada@alpha.test')->count());

        $this->flushSession();

        $this->post('http://alpha.localhost/login', [
            'email' => 'ada@alpha.test',
            'password' => 'secret-password',
        ])->assertRedirect(route('writer.dashboard', absolute: false));

        $this->assertAuthenticated('writer');
    }

    public function test_same_email_can_exist_in_separate_tenants(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $other = $this->createTenant('beta.localhost', 'Beta');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.tenants.writers.store', $tenant), $this->writerData())
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->post(route('admin.tenants.writers.store', $other), $this->writerData())
            ->assertSessionHasNoErrors();

        $this->assertTenantWriterCount($tenant, 1);
        $this->assertTenantWriterCount($other, 1);
    }

    public function test_duplicate_email_within_one_tenant_is_rejected(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.tenants.writers.store', $tenant), $this->writerData());

        $this->actingAs($admin)
            ->post(route('admin.tenants.writers.store', $tenant), $this->writerData(['name' => 'Second']))
            ->assertSessionHasErrors('email');

        $this->assertFalse(tenancy()->initialized);
        $this->assertTenantWriterCount($tenant, 1);
    }

    public function test_duplicate_email_inserted_after_validation_is_rejected_cleanly(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        Writer::creating(function (Writer $writer) {
            DB::table('users')->insert([
                'name' => 'Concurrent Writer',
                'email' => $writer->email,
                'password' => 'not-a-real-hash',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.tenants.writers.store', $tenant), $this->writerData())
            ->assertSessionHasErrors('email');

        $this->assertFalse(tenancy()->initialized);
        $this->assertTenantWriterCount($tenant, 1);
    }

    public function test_unexpected_failure_while_creating_a_writer_restores_the_central_context(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $centralConnection = config('database.default');

        Writer::creating(fn () => throw new RuntimeException('writer insert failed'));

        $this->assertRequestFailsAndRestoresCentralContext(
            $tenant,
            'writer insert failed',
            $centralConnection,
        );
    }

    public function test_unexpected_failure_while_validating_the_email_restores_the_central_context(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $centralConnection = config('database.default');

        DB::listen(function (QueryExecuted $query) {
            if (str_contains($query->sql, 'exists') && str_contains($query->sql, 'users')) {
                throw new RuntimeException('tenant lookup failed');
            }
        });

        $this->assertRequestFailsAndRestoresCentralContext(
            $tenant,
            'tenant lookup failed',
            $centralConnection,
        );
    }

    public function test_writer_data_is_validated_without_flashing_passwords(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.tenants.writers.store', $tenant), [
                'name' => '',
                'email' => 'not-an-email',
                'password' => 'secret-password',
                'password_confirmation' => 'different-password',
            ])
            ->assertSessionHasErrors(['name', 'email', 'password']);

        $oldInput = session()->getOldInput();

        $this->assertArrayNotHasKey('password', $oldInput);
        $this->assertArrayNotHasKey('password_confirmation', $oldInput);
        $this->assertTenantWriterCount($tenant, 0);
    }

    public function test_unknown_tenant_returns_not_found(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.tenants.writers.store', 'missing-tenant'), $this->writerData())
            ->assertNotFound();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.tenants.writers.index', 'missing-tenant'))
            ->assertNotFound();
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function writerData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Writer',
            'email' => 'ada@alpha.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
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

    private function assertRequestFailsAndRestoresCentralContext(
        Tenant $tenant,
        string $message,
        string $centralConnection,
    ): void {
        $this->withoutExceptionHandling();

        try {
            $this->actingAs(User::factory()->admin()->create())
                ->post(route('admin.tenants.writers.store', $tenant), $this->writerData());

            $this->fail('The unexpected exception was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }

        $this->assertFalse(tenancy()->initialized);
        $this->assertSame($centralConnection, config('database.default'));
        $this->assertStringNotContainsString('tenant_'.$tenant->getTenantKey(), (string) config('cache.prefix'));
    }

    private function assertTenantWriterCount(Tenant $tenant, int $expected): void
    {
        $tenant->run(function () use ($expected) {
            $this->assertSame($expected, DB::table('users')->count());
        });
    }
}
