<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        if (Schema::hasTable('tenants')) {
            Tenant::query()->get()->each(function (Tenant $tenant) {
                if ($tenant->logo) {
                    Storage::disk('public')->delete($tenant->logo);
                }

                $tenant->delete();
            });
        }

        parent::tearDown();
    }

    public function test_guests_cannot_view_the_central_admin_dashboard(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_central_administrators_can_view_the_dashboard_and_tenant_list(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dashboard')
                ->where('tenantCount', 0));

        $this->actingAs($admin)
            ->get(route('admin.tenants.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tenants/Index')
                ->has('tenants', 0));
    }

    public function test_tenant_creation_requires_valid_branding_and_domain(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.tenants.store'), [
                'name' => '',
                'domain' => 'localhost',
                'brand_color' => 'blue',
                'logo' => UploadedFile::fake()->create('logo.txt', 10, 'text/plain'),
            ])
            ->assertSessionHasErrors(['name', 'domain', 'brand_color', 'logo']);

        $this->assertSame(0, Tenant::query()->count());
    }

    public function test_creating_a_tenant_provisions_an_isolated_database_and_counts_its_posts(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.tenants.store'), [
            'name' => 'Acme Blog',
            'domain' => 'acme.localhost',
            'brand_color' => '#1D4ED8',
            'logo' => UploadedFile::fake()->image('logo.png'),
        ]);

        $response->assertRedirect(route('admin.tenants.index'));

        $tenant = Tenant::query()->first();

        $this->assertNotNull($tenant);
        $this->assertSame('Acme Blog', $tenant->name);
        $this->assertSame('#1d4ed8', $tenant->brand_color);
        $this->assertNotNull($tenant->logo);
        Storage::disk('public')->assertExists($tenant->logo);
        $this->assertDatabaseHas('domains', [
            'domain' => 'acme.localhost',
            'tenant_id' => $tenant->id,
        ]);
        $this->assertFalse(Schema::hasTable('posts'));

        $centralDatabase = DB::connection()->getDatabaseName();

        $tenant->run(function () use ($centralDatabase) {
            $this->assertTrue(Schema::hasTable('users'));
            $this->assertTrue(Schema::hasTable('posts'));
            $this->assertNotSame($centralDatabase, DB::connection()->getDatabaseName());

            $userId = DB::table('users')->insertGetId([
                'name' => 'Writer',
                'email' => 'writer@acme.test',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('posts')->insert([
                'user_id' => $userId,
                'title' => 'Hello',
                'slug' => 'hello',
                'body' => 'Tenant post',
                'status' => 'published',
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertFalse(Schema::hasTable('posts'));

        $this->actingAs($admin)
            ->get(route('admin.tenants.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tenants/Index')
                ->has('tenants', 1)
                ->where('tenants.0.name', 'Acme Blog')
                ->where('tenants.0.domain', 'acme.localhost')
                ->where('tenants.0.post_count', 1));
    }
}
