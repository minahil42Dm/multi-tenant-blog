<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantEditTest extends TestCase
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

    public function test_super_admin_can_view_the_edit_page(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.tenants.edit', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tenants/Edit')
                ->where('tenant.name', 'Alpha')
                ->where('tenant.domains.0.domain', 'alpha.localhost')
                ->where('tenant.domains.0.is_primary', true)
                ->missing('tenant.post_count'));
    }

    public function test_support_cannot_open_the_edit_page(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        $this->actingAs(User::factory()->support()->create())
            ->get(route('admin.tenants.edit', $tenant))
            ->assertForbidden();
    }

    public function test_the_old_tenant_update_endpoint_no_longer_exists(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->put(route('admin.tenants.show', $tenant), ['name' => 'Renamed', 'brand_color' => '#000000'])
            ->assertStatus(405);

        $this->assertSame('Alpha', $tenant->refresh()->name);
    }

    private function createTenant(string $domain, string $name): Tenant
    {
        $tenant = Tenant::create(['name' => $name, 'brand_color' => '#000000']);
        $tenant->createDomain($domain);

        return $tenant;
    }
}
