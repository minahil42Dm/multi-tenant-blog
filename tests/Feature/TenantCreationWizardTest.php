<?php

namespace Tests\Feature;

use App\Enums\TenantUserRole;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Writer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class TenantCreationWizardTest extends TestCase
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

    public function test_the_wizard_page_renders_for_super_admins_only(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.tenants.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tenants/Create')
                ->where('maxAdditionalDomains', 5));

        $this->actingAs(User::factory()->support()->create())
            ->get(route('admin.tenants.create'))
            ->assertForbidden();
    }

    public function test_each_step_validates_only_its_own_fields(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin);

        $this->from(route('admin.tenants.create'))
            ->post(route('admin.tenants.create.validate', 1), ['name' => ''])
            ->assertSessionHasErrors('name')
            ->assertSessionDoesntHaveErrors(['domain', 'logo', 'admin_email']);

        $this->post(route('admin.tenants.create.validate', 1), ['name' => 'Acme'])
            ->assertRedirect(route('admin.tenants.create'))
            ->assertSessionHasNoErrors();

        $this->from(route('admin.tenants.create'))
            ->post(route('admin.tenants.create.validate', 2), [
                'domain' => 'localhost',
                'additional_domains' => ['acme.platform.test', 'acme.platform.test', '10.0.0.1'],
            ])
            ->assertSessionHasErrors(['domain', 'additional_domains.0', 'additional_domains.2'])
            ->assertSessionDoesntHaveErrors('name');

        $this->post(route('admin.tenants.create.validate', 3), [
            'brand_color' => 'red',
            'logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors(['brand_color', 'logo']);

        $this->post(route('admin.tenants.create.validate', 4), [
            'admin_name' => 'Owner',
            'admin_email' => 'not-an-email',
            'admin_password' => 'short',
            'admin_password_confirmation' => 'different',
        ])->assertSessionHasErrors(['admin_email', 'admin_password']);

        $this->post(route('admin.tenants.create.validate', 5), [])->assertNotFound();

        $this->assertSame(0, Tenant::query()->count());
    }

    public function test_domains_already_used_by_another_tenant_are_rejected(): void
    {
        $existing = Tenant::create(['name' => 'Existing']);
        $existing->createDomain('taken.platform.test');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->from(route('admin.tenants.create'))
            ->post(route('admin.tenants.create.validate', 2), ['domain' => 'TAKEN.platform.test.'])
            ->assertSessionHasErrors('domain');
    }

    public function test_completing_the_wizard_creates_the_tenant_its_domains_and_a_tenant_admin(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->post(route('admin.tenants.store'), $this->payload([
                'domain' => ' Acme.Platform.Test ',
                'additional_domains' => ['blog.acme.test', ''],
            ]))
            ->assertRedirect(route('admin.tenants.index'))
            ->assertSessionHas('success', 'Tenant created. owner@acme.test can now sign in at acme.platform.test/login.');

        $tenant = Tenant::query()->sole();

        $this->assertSame('Acme', $tenant->name);
        $this->assertTrue($tenant->isActive());
        $this->assertSame('acme.platform.test', $tenant->primaryDomainName());
        $this->assertEqualsCanonicalizing(
            ['acme.platform.test' => true, 'blog.acme.test' => false],
            $tenant->domains()->pluck('is_primary', 'domain')->map(fn ($primary) => (bool) $primary)->all(),
        );
        $this->assertTrue($tenant->database()->manager()->databaseExists($tenant->database()->getName()));

        $tenant->run(function () {
            $admin = Writer::query()->sole();
            $this->assertSame('owner@acme.test', $admin->email);
            $this->assertSame(TenantUserRole::Admin, $admin->role);
        });

        $this->assertFalse(Schema::hasTable('posts'));
        $this->assertTrue(AuditLog::query()->where('action', 'tenant.created')->where('tenant_id', $tenant->id)->exists());
    }

    public function test_the_new_tenant_admin_can_sign_in_on_the_tenant_domain(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('admin.tenants.store'), $this->payload())
            ->assertRedirect(route('admin.tenants.index'));

        $this->post('http://acme.platform.test/login', [
            'email' => 'owner@acme.test',
            'password' => 'secret-password',
        ])->assertRedirect('http://acme.platform.test/dashboard');

        $this->assertAuthenticated('writer');
    }

    public function test_a_failed_provisioning_rolls_everything_back(): void
    {
        $databasesBefore = $this->tenantDatabaseFiles();
        $logosBefore = Storage::disk('public')->files('tenant-logos');
        Writer::creating(fn () => throw new RuntimeException('Tenant database rejected the admin.'));

        $this->actingAs(User::factory()->superAdmin()->create())
            ->from(route('admin.tenants.create'))
            ->post(route('admin.tenants.store'), $this->payload())
            ->assertRedirect(route('admin.tenants.create'))
            ->assertSessionHasErrors('provisioning');

        $this->assertSame(0, Tenant::query()->count());
        $this->assertSame(0, Domain::query()->count());
        $this->assertSame($databasesBefore, $this->tenantDatabaseFiles());
        $this->assertSame($logosBefore, Storage::disk('public')->files('tenant-logos'));
        $this->assertFalse(AuditLog::query()->where('action', 'tenant.created')->exists());
    }

    public function test_support_cannot_create_tenants(): void
    {
        $support = User::factory()->support()->create();

        $this->actingAs($support)
            ->post(route('admin.tenants.create.validate', 1), ['name' => 'Acme'])
            ->assertForbidden();

        $this->actingAs($support)
            ->post(route('admin.tenants.store'), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, Tenant::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Acme',
            'domain' => 'acme.platform.test',
            'brand_color' => '#1d4ed8',
            'logo' => UploadedFile::fake()->image('logo.png'),
            'admin_name' => 'Acme Owner',
            'admin_email' => 'Owner@Acme.test',
            'admin_password' => 'secret-password',
            'admin_password_confirmation' => 'secret-password',
            ...$overrides,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function tenantDatabaseFiles(): array
    {
        $files = glob(database_path('tenant*')) ?: [];
        sort($files);

        return $files;
    }
}
