<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Writer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class TenantDeletionTest extends TestCase
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

        Storage::disk('public')->delete('tenant-logos/delete-test-alpha.png');

        parent::tearDown();
    }

    public function test_super_admin_can_delete_a_suspended_tenant_after_typing_its_domain(): void
    {
        Storage::disk('public')->put('tenant-logos/delete-test-alpha.png', 'logo');
        $tenant = $this->createTenant('alpha.localhost', 'Alpha', ['logo' => 'tenant-logos/delete-test-alpha.png']);
        $tenant->createDomain('blog.alpha.test');
        $tenant->run(fn () => Writer::factory()->create());
        $tenant->suspend();

        $databaseName = $tenant->database()->getName();
        $manager = $tenant->database()->manager();
        $storage = storage_path(config('tenancy.filesystem.suffix_base').$tenant->id);
        File::ensureDirectoryExists($storage);
        $admin = User::factory()->superAdmin()->create();

        $this->assertTrue($manager->databaseExists($databaseName));

        $this->actingAs($admin)
            ->delete(route('admin.tenants.destroy', $tenant), ['confirm_domain' => ' ALPHA.localhost '])
            ->assertRedirect(route('admin.tenants.index'))
            ->assertSessionHas('success', 'Alpha and its database have been deleted.');

        $this->assertNull(Tenant::find($tenant->id));
        $this->assertDatabaseMissing('domains', ['tenant_id' => $tenant->id]);
        $this->assertFalse($manager->databaseExists($databaseName));
        $this->assertDirectoryDoesNotExist($storage);
        Storage::disk('public')->assertMissing('tenant-logos/delete-test-alpha.png');

        $log = AuditLog::query()->where('action', 'tenant.deleted')->sole();
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertEqualsCanonicalizing(['alpha.localhost', 'blog.alpha.test'], $log->metadata['domains']);
    }

    public function test_deletion_requires_the_exact_primary_domain(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $tenant->suspend();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->from(route('admin.tenants.show', $tenant))
            ->delete(route('admin.tenants.destroy', $tenant), ['confirm_domain' => 'beta.localhost'])
            ->assertRedirect(route('admin.tenants.show', $tenant))
            ->assertSessionHasErrors('confirm_domain');

        $this->assertNotNull(Tenant::find($tenant->id));
    }

    public function test_active_tenants_must_be_suspended_before_deletion(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->delete(route('admin.tenants.destroy', $tenant), ['confirm_domain' => 'alpha.localhost'])
            ->assertRedirect(route('admin.tenants.show', $tenant))
            ->assertSessionHas('error', 'Suspend Alpha before deleting it.');

        $this->assertNotNull(Tenant::find($tenant->id));
        $this->assertTrue($tenant->database()->manager()->databaseExists($tenant->database()->getName()));
    }

    public function test_support_cannot_delete_a_tenant(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $tenant->suspend();

        $this->actingAs(User::factory()->support()->create())
            ->delete(route('admin.tenants.destroy', $tenant), ['confirm_domain' => 'alpha.localhost'])
            ->assertForbidden();

        $this->assertNotNull(Tenant::find($tenant->id));
    }

    public function test_tenants_cannot_be_deleted_with_a_get_request(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $tenant->suspend();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.tenants.show', $tenant).'/delete?confirm_domain=alpha.localhost')
            ->assertNotFound();

        $this->assertNotNull(Tenant::find($tenant->id));
    }

    public function test_a_failed_deletion_keeps_the_tenant_and_is_audited(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $tenant->suspend();
        $failing = true;
        Tenant::deleting(function () use (&$failing) {
            if ($failing) {
                throw new RuntimeException('Database server unavailable.');
            }
        });

        $this->actingAs(User::factory()->superAdmin()->create())
            ->delete(route('admin.tenants.destroy', $tenant), ['confirm_domain' => 'alpha.localhost'])
            ->assertRedirect(route('admin.tenants.show', $tenant->id))
            ->assertSessionHas('error', 'Alpha could not be deleted. Nothing was removed; please try again.');

        $this->assertNotNull(Tenant::find($tenant->id));
        $this->assertDatabaseHas('domains', ['tenant_id' => $tenant->id, 'domain' => 'alpha.localhost']);
        $this->assertTrue($tenant->database()->manager()->databaseExists($tenant->database()->getName()));
        $this->assertTrue(AuditLog::query()->where('action', 'tenant.delete_failed')->exists());

        $failing = false;
    }

    public function test_the_detail_page_offers_the_delete_confirmation_value(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.tenants.show', $tenant))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('deleteConfirmation', 'alpha.localhost'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createTenant(string $domain, string $name, array $attributes = []): Tenant
    {
        $tenant = Tenant::create(['name' => $name, ...$attributes]);
        $tenant->createDomain($domain);

        return $tenant;
    }
}
