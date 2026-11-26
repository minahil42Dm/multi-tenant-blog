<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HerdSiteSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.herd.link_tenant_domains' => true,
            'services.herd.binary' => 'herd',
            'services.herd.tld' => 'test',
        ]);

        Process::fake();
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        Tenant::query()->get()->each(function (Tenant $tenant) {
            if ($tenant->logo) {
                Storage::disk('public')->delete($tenant->logo);
            }

            $tenant->delete();
            File::deleteDirectory(storage_path('tenant'.$tenant->id));
        });

        parent::tearDown();
    }

    public function test_creating_a_tenant_from_the_admin_panel_links_its_domain_in_herd(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.tenants.store'), [
            'name' => 'Probe Blog',
            'domain' => 'probe.blogs.test',
            'brand_color' => '#1d4ed8',
            'logo' => UploadedFile::fake()->image('logo.png'),
            'admin_name' => 'Probe Admin',
            'admin_email' => 'admin@probe.test',
            'admin_password' => 'secret-password',
            'admin_password_confirmation' => 'secret-password',
        ])->assertRedirect(route('admin.tenants.index'));

        Process::assertRan(fn (PendingProcess $process) => $process->command === ['herd', 'link', 'probe.blogs']
            && $process->path === base_path());
    }

    public function test_deleting_a_tenant_unlinks_its_domains(): void
    {
        $tenant = Tenant::create(['name' => 'Probe Blog']);
        $tenant->createDomain('probe.blogs.test');

        $tenant->delete();

        Process::assertRan(fn (PendingProcess $process) => $process->command === ['herd', 'unlink', 'probe.blogs']);
    }

    public function test_deleting_a_domain_unlinks_it(): void
    {
        $tenant = Tenant::create(['name' => 'Probe Blog']);
        $domain = $tenant->createDomain('probe.blogs.test');

        $domain->delete();

        Process::assertRan(fn (PendingProcess $process) => $process->command === ['herd', 'unlink', 'probe.blogs']);
    }

    public function test_domains_outside_the_herd_tld_are_not_linked(): void
    {
        $tenant = Tenant::create(['name' => 'Alpha']);
        $tenant->createDomain('alpha.localhost');

        Process::assertNothingRan();
    }

    public function test_nothing_is_linked_when_the_integration_is_disabled(): void
    {
        config(['services.herd.link_tenant_domains' => false]);

        $tenant = Tenant::create(['name' => 'Probe Blog']);
        $tenant->createDomain('probe.blogs.test');

        Process::assertNothingRan();
    }

    public function test_a_failing_herd_command_does_not_block_tenant_creation(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'herd: command not found', exitCode: 1)]);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.tenants.store'), [
            'name' => 'Probe Blog',
            'domain' => 'probe.blogs.test',
            'brand_color' => '#1d4ed8',
            'logo' => UploadedFile::fake()->image('logo.png'),
            'admin_name' => 'Probe Admin',
            'admin_email' => 'admin@probe.test',
            'admin_password' => 'secret-password',
            'admin_password_confirmation' => 'secret-password',
        ])->assertRedirect(route('admin.tenants.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('domains', ['domain' => 'probe.blogs.test']);
    }
}
