<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use Stancl\Tenancy\Middleware\ScopeSessions;
use Tests\TestCase;

class TenantInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'session.driver' => 'database',
            'session.connection' => config('tenancy.database.central_connection'),
            'cache.default' => 'database',
            'cache.stores.database.connection' => config('tenancy.database.central_connection'),
            'queue.default' => 'database',
            'queue.connections.database.connection' => config('tenancy.database.central_connection'),
        ]);

        Route::middleware([
            'web',
            InitializeTenancyByDomain::class,
            PreventAccessFromCentralDomains::class,
            ScopeSessions::class,
        ])->get('/session-check', function () {
            $previous = session('marker');

            session(['marker' => 'stored-for-'.tenant('id')]);

            return response()->json([
                'previous' => $previous,
                'tenant' => tenant('id'),
            ]);
        });
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

    public function test_tenant_requests_read_and_write_sessions_on_the_central_connection(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $central = config('tenancy.database.central_connection');

        $this->disableCookieEncryption();

        $first = $this->get('http://alpha.localhost/session-check');

        $first->assertOk();
        $first->assertJson([
            'previous' => null,
            'tenant' => $tenant->id,
        ]);

        $this->assertGreaterThan(0, DB::connection($central)->table('sessions')->count());

        $tenant->run(function () {
            $this->assertFalse(Schema::hasTable('sessions'));
            $this->assertNotSame(
                config('tenancy.database.central_connection'),
                DB::getDefaultConnection()
            );
        });

        $cookie = collect($first->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

        $this->assertNotNull($cookie);

        $second = $this->withCookie($cookie->getName(), $cookie->getValue())
            ->get('http://alpha.localhost/session-check');

        $second->assertOk();
        $second->assertJson([
            'previous' => 'stored-for-'.$tenant->id,
            'tenant' => $tenant->id,
        ]);
    }

    public function test_tenant_a_cannot_access_tenant_b_data(): void
    {
        $tenantA = $this->createTenant('alpha.localhost', 'Alpha');
        $tenantB = $this->createTenant('beta.localhost', 'Beta');
        $central = config('tenancy.database.central_connection');

        $tenantA->run(function () {
            $userId = DB::table('users')->insertGetId([
                'name' => 'Alpha Writer',
                'email' => 'writer@alpha.test',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('posts')->insert([
                'user_id' => $userId,
                'title' => 'Alpha Post',
                'slug' => 'alpha-post',
                'body' => 'Only alpha can read this',
                'status' => 'published',
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Cache::put('shared-key', 'alpha-secret', 60);

            dispatch(function () {
                // Stored on the central jobs table and tagged with this tenant.
            });
        });

        $tenantB->run(function () {
            $this->assertSame(0, DB::table('users')->count());
            $this->assertSame(0, DB::table('posts')->count());
            $this->assertNull(Cache::get('shared-key'));
            $this->assertFalse(Schema::hasTable('sessions'));
            $this->assertFalse(Schema::hasTable('cache'));
            $this->assertFalse(Schema::hasTable('jobs'));
        });

        $tenantA->run(function () {
            $this->assertSame(1, DB::table('posts')->count());
            $this->assertSame('alpha-secret', Cache::get('shared-key'));
        });

        $this->assertFalse(Schema::connection($central)->hasTable('posts'));
        $this->assertGreaterThan(0, DB::connection($central)->table('cache')->count());
        $this->assertGreaterThan(0, DB::connection($central)->table('jobs')->count());
        $this->assertStringContainsString(
            (string) $tenantA->getTenantKey(),
            (string) DB::connection($central)->table('jobs')->value('payload')
        );

        $this->disableCookieEncryption();

        $response = $this->get('http://alpha.localhost/session-check');
        $response->assertOk();

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

        $this->withCookie($cookie->getName(), $cookie->getValue())
            ->get('http://beta.localhost/session-check')
            ->assertForbidden();
    }

    private function createTenant(string $domain, string $name): Tenant
    {
        $tenant = Tenant::create([
            'name' => $name,
        ]);

        $tenant->createDomain($domain);

        return $tenant;
    }
}
