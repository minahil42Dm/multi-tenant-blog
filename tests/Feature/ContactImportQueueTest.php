<?php

namespace Tests\Feature;

use App\Enums\TenantUserRole;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\Tenant;
use App\Models\Writer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ContactImportQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['queue.default' => 'database']);
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

    public function test_a_database_queue_worker_imports_inside_the_tenant_that_uploaded_the_file(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $admin = $this->admin($alpha, 'admin@alpha.test');

        $csv = implode("\n", [
            'name,email,company',
            'Queue Person,queue@example.com,Acme',
            'Duplicate,queue@example.com,Acme',
            ',not-an-email,Acme',
        ]);

        $this->actingAs($admin, 'writer')
            ->post('http://alpha.localhost/contacts/import', [
                'file' => UploadedFile::fake()->createWithContent('people.csv', $csv),
            ])
            ->assertRedirect();

        $alpha->run(function () {
            $import = ContactImport::query()->first();

            $this->assertSame('pending', $import->status);
            $this->assertSame(0, Contact::query()->count());
            $this->assertTrue(str_starts_with($import->path, 'contact-imports/'));
        });

        $this->assertSame(1, DB::table('jobs')->count());

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->artisan('queue:work', [
            '--once' => true,
            '--stop-when-empty' => true,
        ])->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count());

        $alpha->run(function () use ($admin) {
            $import = ContactImport::query()->first();

            $this->assertSame('completed', $import->status);
            $this->assertSame(1, $import->summary['created']);
            $this->assertSame(1, $import->summary['skipped']);
            $this->assertSame(1, $import->summary['failed']);
            $this->assertSame($admin->id, (int) Contact::query()->where('email', 'queue@example.com')->value('owner_id'));
        });

        $beta->run(fn () => $this->assertSame(0, Contact::query()->count()));
    }

    public function test_a_database_queue_worker_does_not_import_for_a_suspended_tenant(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = $this->admin($tenant, 'admin@alpha.test');

        $this->actingAs($admin, 'writer')
            ->post('http://alpha.localhost/contacts/import', [
                'file' => UploadedFile::fake()->createWithContent(
                    'people.csv',
                    "name,email\nLater Person,later@example.com\n",
                ),
            ])
            ->assertRedirect();

        $this->assertTrue($tenant->suspend());

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->artisan('queue:work', [
            '--once' => true,
            '--stop-when-empty' => true,
        ])->assertSuccessful();

        $tenant->run(function () {
            $import = ContactImport::query()->first();

            $this->assertSame('failed', $import->status);
            $this->assertSame(0, Contact::query()->count());
            $this->assertFalse($import->summary['accepted']);
        });
    }

    private function createTenant(string $domain, string $name): Tenant
    {
        $tenant = Tenant::create(['name' => $name]);
        $tenant->createDomain($domain);

        return $tenant;
    }

    private function admin(Tenant $tenant, string $email): Writer
    {
        return $tenant->run(function () use ($email) {
            $writer = new Writer;
            $writer->forceFill([
                'name' => 'Admin',
                'email' => $email,
                'password' => 'password',
                'role' => TenantUserRole::Admin,
            ])->save();

            return $writer;
        });
    }
}
