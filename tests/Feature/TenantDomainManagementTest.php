<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantDomainManagementTest extends TestCase
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

    public function test_the_first_domain_becomes_primary_and_later_domains_do_not(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $tenant->createDomain('blog.alpha.test');

        $this->assertSame('alpha.localhost', $tenant->primaryDomainName());
        $this->assertSame(1, $tenant->domains()->where('is_primary', true)->count());
    }

    public function test_super_admin_can_add_a_normalized_domain(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->post(route('admin.tenants.domains.store', $tenant), ['domain' => '  Blog.Alpha.TEST. '])
            ->assertRedirect(route('admin.tenants.edit', $tenant))
            ->assertSessionHas('success', 'blog.alpha.test was added.');

        $this->assertDatabaseHas('domains', [
            'tenant_id' => $tenant->id,
            'domain' => 'blog.alpha.test',
            'is_primary' => false,
        ]);
        $this->assertTrue(AuditLog::query()->where('action', 'domain.added')->where('actor_id', $admin->id)->exists());

        $this->get('http://blog.alpha.test/')->assertOk();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidDomains(): array
    {
        return [
            'central domain' => ['localhost'],
            'central platform domain' => ['platform.test'],
            'ip address' => ['192.168.1.10'],
            'no dot' => ['intranet'],
            'scheme' => ['https://blog.alpha.test'],
            'path' => ['blog.alpha.test/admin'],
            'underscore' => ['blog_alpha.test'],
            'leading hyphen' => ['-blog.alpha.test'],
            'space' => ['blog alpha.test'],
            'empty' => [''],
        ];
    }

    /**
     * @dataProvider invalidDomains
     */
    public function test_invalid_domains_are_rejected(string $domain): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->from(route('admin.tenants.edit', $tenant))
            ->post(route('admin.tenants.domains.store', $tenant), ['domain' => $domain])
            ->assertRedirect(route('admin.tenants.edit', $tenant))
            ->assertSessionHasErrors('domain');

        $this->assertSame(1, $tenant->domains()->count());
    }

    public function test_domains_must_be_unique_across_all_tenants(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->post(route('admin.tenants.domains.store', $alpha), ['domain' => 'BETA.localhost'])
            ->assertSessionHasErrors('domain');

        $this->actingAs($admin)
            ->post(route('admin.tenants.domains.store', $alpha), ['domain' => 'alpha.localhost'])
            ->assertSessionHasErrors('domain');

        $this->assertSame(1, $alpha->domains()->count());
        $this->assertSame(1, $beta->domains()->count());
    }

    public function test_super_admin_can_change_the_primary_domain_atomically(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $secondary = $tenant->createDomain('blog.alpha.test');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('admin.tenants.domains.primary', [$tenant, $secondary->id]))
            ->assertRedirect(route('admin.tenants.edit', $tenant))
            ->assertSessionHas('success', 'blog.alpha.test is now the primary domain.');

        $this->assertSame(['blog.alpha.test'], $tenant->domains()->where('is_primary', true)->pluck('domain')->all());
        $this->assertSame('blog.alpha.test', $tenant->fresh()->primaryDomainName());

        $log = AuditLog::query()->where('action', 'domain.primary_changed')->sole();
        $this->assertSame(['domain' => 'blog.alpha.test', 'previous' => 'alpha.localhost'], $log->metadata);
    }

    public function test_making_the_current_primary_primary_again_reports_an_error(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $primary = $tenant->domains()->first();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('admin.tenants.domains.primary', [$tenant, $primary->id]))
            ->assertSessionHas('error', 'alpha.localhost is already the primary domain.');

        $this->assertSame(1, $tenant->domains()->where('is_primary', true)->count());
    }

    public function test_super_admin_can_remove_an_additional_domain(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $secondary = $tenant->createDomain('blog.alpha.test');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->delete(route('admin.tenants.domains.destroy', [$tenant, $secondary->id]))
            ->assertRedirect(route('admin.tenants.edit', $tenant))
            ->assertSessionHas('success', 'blog.alpha.test was removed.');

        $this->assertDatabaseMissing('domains', ['domain' => 'blog.alpha.test']);
        $this->assertTrue(AuditLog::query()->where('action', 'domain.removed')->exists());
    }

    public function test_the_primary_domain_cannot_be_removed(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $tenant->createDomain('blog.alpha.test');
        $primary = $tenant->domains()->where('is_primary', true)->first();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->delete(route('admin.tenants.domains.destroy', [$tenant, $primary->id]))
            ->assertSessionHas('error', 'Make another domain primary before removing alpha.localhost.');

        $this->assertSame(2, $tenant->domains()->count());
    }

    public function test_the_last_domain_cannot_be_removed(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $only = $tenant->domains()->first();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->delete(route('admin.tenants.domains.destroy', [$tenant, $only->id]))
            ->assertSessionHas('error', 'A tenant must keep at least one domain.');

        $this->assertSame(1, $tenant->domains()->count());
    }

    public function test_domains_of_another_tenant_cannot_be_changed_through_the_wrong_tenant(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $betaSecondary = $beta->createDomain('blog.beta.test');
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->delete(route('admin.tenants.domains.destroy', [$alpha, $betaSecondary->id]))
            ->assertNotFound();

        $this->actingAs($admin)
            ->post(route('admin.tenants.domains.primary', [$alpha, $betaSecondary->id]))
            ->assertNotFound();

        $this->assertSame(2, $beta->domains()->count());
        $this->assertSame('beta.localhost', $beta->primaryDomainName());
        $this->assertSame('alpha.localhost', $alpha->primaryDomainName());
    }

    public function test_support_cannot_change_domains(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $secondary = $tenant->createDomain('blog.alpha.test');
        $support = User::factory()->support()->create();

        $this->actingAs($support)
            ->post(route('admin.tenants.domains.store', $tenant), ['domain' => 'new.alpha.test'])
            ->assertForbidden();
        $this->actingAs($support)
            ->post(route('admin.tenants.domains.primary', [$tenant, $secondary->id]))
            ->assertForbidden();
        $this->actingAs($support)
            ->delete(route('admin.tenants.domains.destroy', [$tenant, $secondary->id]))
            ->assertForbidden();

        $this->assertSame(2, $tenant->domains()->count());
        $this->assertSame('alpha.localhost', $tenant->primaryDomainName());
    }

    public function test_the_migration_backfills_the_oldest_domain_of_each_tenant_as_primary(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $alpha->createDomain('blog.alpha.test');
        $beta = $this->createTenant('beta.localhost', 'Beta');

        $migration = require database_path('migrations/2026_11_26_000000_add_is_primary_to_domains_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('domains', 'is_primary'));

        $migration->up();

        $this->assertSame(
            ['alpha.localhost', 'beta.localhost'],
            DB::table('domains')->where('is_primary', true)->orderBy('domain')->pluck('domain')->all(),
        );
        $this->assertSame(0, Domain::query()->where('domain', 'blog.alpha.test')->where('is_primary', true)->count());
        $this->assertSame('beta.localhost', $beta->primaryDomainName());
    }

    private function createTenant(string $domain, string $name): Tenant
    {
        $tenant = Tenant::create(['name' => $name]);
        $tenant->createDomain($domain);

        return $tenant;
    }
}
