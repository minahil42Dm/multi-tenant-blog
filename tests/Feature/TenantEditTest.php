<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Domain;
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

    public function test_super_admin_can_rename_a_tenant_without_changing_branding_or_domains(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $tenant->update(['brand_color' => '#112233', 'status' => 'active']);
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->put(route('admin.tenants.update', $tenant), ['name' => 'Alpha Journal'])
            ->assertRedirect(route('admin.tenants.show', $tenant))
            ->assertSessionHas('success', 'Tenant details saved.');

        $tenant->refresh();

        $this->assertSame('Alpha Journal', $tenant->name);
        $this->assertSame('#112233', $tenant->brand_color);
        $this->assertSame('active', $tenant->status->value);
        $this->assertSame(['alpha.localhost'], $tenant->domains()->pluck('domain')->all());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tenant.updated',
            'actor_id' => $admin->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_support_cannot_update_tenant_details(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        $this->actingAs(User::factory()->support()->create())
            ->put(route('admin.tenants.update', $tenant), ['name' => 'Renamed'])
            ->assertForbidden();

        $this->assertSame('Alpha', $tenant->refresh()->name);
        $this->assertSame(0, AuditLog::query()->where('action', 'tenant.updated')->count());
    }

    public function test_tenant_details_require_a_name(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->from(route('admin.tenants.edit', $tenant))
            ->put(route('admin.tenants.update', $tenant), ['name' => ''])
            ->assertRedirect(route('admin.tenants.edit', $tenant))
            ->assertSessionHasErrors('name');

        $this->assertSame('Alpha', $tenant->refresh()->name);
        $this->assertSame(1, Domain::query()->where('tenant_id', $tenant->id)->count());
    }

    private function createTenant(string $domain, string $name): Tenant
    {
        $tenant = Tenant::create(['name' => $name, 'brand_color' => '#000000']);
        $tenant->createDomain($domain);

        return $tenant;
    }
}
