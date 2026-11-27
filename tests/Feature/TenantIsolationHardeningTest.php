<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\Writer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantIsolationHardeningTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_cache_storage_and_contact_rows_stay_inside_the_tenant_that_wrote_them(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');

        $prefixes = [];

        $alpha->run(function () use (&$prefixes, $alpha) {
            $prefixes['alpha'] = (string) config('cache.prefix');
            Cache::put('secret', 'alpha-value', 60);
            Storage::disk('local')->put('private.txt', 'alpha-file');
            $writer = Writer::factory()->create();
            $contact = new Contact([
                'name' => 'Alpha Contact',
                'email' => 'alpha@example.com',
            ]);
            $contact->owner_id = $writer->id;
            $contact->save();

            $this->assertStringContainsString((string) $alpha->id, $prefixes['alpha']);
        });

        $beta->run(function () use (&$prefixes, $beta) {
            $prefixes['beta'] = (string) config('cache.prefix');
            $this->assertStringContainsString((string) $beta->id, $prefixes['beta']);
            $this->assertNull(Cache::get('secret'));
            $this->assertFalse(Storage::disk('local')->exists('private.txt'));
            $this->assertSame(0, Contact::query()->count());

            Storage::disk('local')->put('private.txt', 'beta-file');
        });

        $this->assertNotSame($prefixes['alpha'], $prefixes['beta']);

        $alpha->run(function () {
            $this->assertSame('alpha-file', Storage::disk('local')->get('private.txt'));
            $this->assertSame(1, Contact::query()->where('email', 'alpha@example.com')->count());
        });

        $beta->run(function () {
            $this->assertSame('beta-file', Storage::disk('local')->get('private.txt'));
            $this->assertSame(0, Contact::query()->count());
        });
    }

    public function test_database_cache_values_survive_switching_tenants(): void
    {
        config(['cache.default' => 'database']);

        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');

        $alpha->run(fn () => Cache::put('secret', 'alpha-value', 60));

        $beta->run(function () {
            $this->assertNull(Cache::get('secret'));
            Cache::put('secret', 'beta-value', 60);
            $this->assertSame('beta-value', Cache::get('secret'));
        });

        $alpha->run(fn () => $this->assertSame('alpha-value', Cache::get('secret')));
    }

    private function createTenant(string $domain, string $name): Tenant
    {
        $tenant = Tenant::create(['name' => $name]);
        $tenant->createDomain($domain);

        return $tenant;
    }
}
