<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Writer;
use App\Support\HomepageCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantStatusActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Carbon::setTestNow('2026-11-18 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        if (Schema::hasTable('tenants')) {
            Tenant::query()->get()->each(fn (Tenant $tenant) => $tenant->delete());
        }

        parent::tearDown();
    }

    public function test_super_admin_can_suspend_a_tenant(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->post(route('admin.tenants.suspend', $alpha))
            ->assertRedirect(route('admin.tenants.index'))
            ->assertSessionHas('success', 'Alpha has been suspended.');

        $alpha->refresh();
        $this->assertSame(TenantStatus::Suspended, $alpha->status);
        $this->assertTrue($alpha->suspended_at->equalTo(now()));
        $this->assertTrue($alpha->updated_at->equalTo(now()));

        $this->get('http://alpha.localhost/')->assertStatus(503);
    }

    public function test_super_admin_can_reactivate_a_tenant(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = User::factory()->superAdmin()->create();
        $alpha->suspend();

        $this->travel(5)->minutes();

        $this->actingAs($admin)
            ->post(route('admin.tenants.reactivate', $alpha))
            ->assertRedirect(route('admin.tenants.index'))
            ->assertSessionHas('success', 'Alpha has been reactivated.');

        $alpha->refresh();
        $this->assertSame(TenantStatus::Active, $alpha->status);
        $this->assertNull($alpha->suspended_at);
        $this->assertTrue($alpha->updated_at->equalTo(now()));

        $this->get('http://alpha.localhost/')->assertOk();
    }

    public function test_repeated_suspend_keeps_the_original_timestamp_and_reports_an_error(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->post(route('admin.tenants.suspend', $alpha));
        $firstSuspension = $alpha->fresh()->suspended_at;

        $this->travel(10)->minutes();

        $this->actingAs($admin)
            ->post(route('admin.tenants.suspend', $alpha))
            ->assertRedirect(route('admin.tenants.index'))
            ->assertSessionHas('error', 'Alpha is already suspended.')
            ->assertSessionMissing('success');

        $alpha->refresh();
        $this->assertSame(TenantStatus::Suspended, $alpha->status);
        $this->assertTrue($alpha->suspended_at->equalTo($firstSuspension));
    }

    public function test_repeated_reactivate_reports_an_error_and_changes_nothing(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = User::factory()->superAdmin()->create();
        $updatedAt = $alpha->fresh()->updated_at;

        $this->travel(10)->minutes();

        $this->actingAs($admin)
            ->post(route('admin.tenants.reactivate', $alpha))
            ->assertRedirect(route('admin.tenants.index'))
            ->assertSessionHas('error', 'Alpha is already active.')
            ->assertSessionMissing('success');

        $alpha->refresh();
        $this->assertSame(TenantStatus::Active, $alpha->status);
        $this->assertNull($alpha->suspended_at);
        $this->assertTrue($alpha->updated_at->equalTo($updatedAt));
    }

    public function test_a_stale_model_cannot_overwrite_a_newer_status(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $staleCopy = Tenant::query()->findOrFail($alpha->id);

        $this->assertTrue($alpha->suspend());
        $firstSuspension = $alpha->fresh()->suspended_at;

        $this->travel(10)->minutes();

        $this->assertFalse($staleCopy->suspend());
        $this->assertTrue($staleCopy->isSuspended());
        $this->assertTrue($alpha->fresh()->suspended_at->equalTo($firstSuspension));
    }

    public function test_support_cannot_suspend_or_reactivate(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $beta->suspend();
        $support = User::factory()->support()->create();

        $this->actingAs($support)
            ->post(route('admin.tenants.suspend', $alpha))
            ->assertForbidden();

        $this->actingAs($support)
            ->post(route('admin.tenants.reactivate', $beta))
            ->assertForbidden();

        $this->assertSame(TenantStatus::Active, $alpha->fresh()->status);
        $this->assertSame(TenantStatus::Suspended, $beta->fresh()->status);
    }

    public function test_guests_and_users_without_a_role_cannot_change_status(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');

        $this->post(route('admin.tenants.suspend', $alpha))
            ->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->post(route('admin.tenants.suspend', $alpha))
            ->assertForbidden();

        $this->assertSame(TenantStatus::Active, $alpha->fresh()->status);
    }

    public function test_status_routes_only_accept_post(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->get(route('admin.tenants.suspend', $alpha))
            ->assertMethodNotAllowed();

        $this->assertSame(TenantStatus::Active, $alpha->fresh()->status);
    }

    public function test_unknown_tenants_return_not_found(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->post(route('admin.tenants.suspend', 'missing-tenant'))
            ->assertNotFound();

        $this->actingAs($admin)
            ->post(route('admin.tenants.reactivate', 'missing-tenant'))
            ->assertNotFound();
    }

    public function test_status_changes_clear_only_that_tenants_homepage_cache(): void
    {
        $this->useDatabaseCache();
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $admin = User::factory()->superAdmin()->create();

        $alpha->run(fn () => Cache::put(HomepageCache::KEY, ['stale'], 3600));
        $beta->run(fn () => Cache::put(HomepageCache::KEY, ['beta'], 3600));

        $alpha->run(fn () => $this->assertSame(['stale'], Cache::get(HomepageCache::KEY)));

        $this->actingAs($admin)->post(route('admin.tenants.suspend', $alpha));

        $alpha->run(fn () => $this->assertFalse(Cache::has(HomepageCache::KEY)));
        $beta->run(fn () => $this->assertSame(['beta'], Cache::get(HomepageCache::KEY)));

        $alpha->run(fn () => Cache::put(HomepageCache::KEY, ['stale'], 3600));

        $this->actingAs($admin)->post(route('admin.tenants.reactivate', $alpha));

        $alpha->run(fn () => $this->assertFalse(Cache::has(HomepageCache::KEY)));
        $beta->run(fn () => $this->assertSame(['beta'], Cache::get(HomepageCache::KEY)));
    }

    public function test_repeated_actions_do_not_clear_the_cache(): void
    {
        $this->useDatabaseCache();
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = User::factory()->superAdmin()->create();
        $alpha->run(fn () => Cache::put(HomepageCache::KEY, ['kept'], 3600));

        $this->actingAs($admin)->post(route('admin.tenants.reactivate', $alpha));

        $alpha->run(fn () => $this->assertSame(['kept'], Cache::get(HomepageCache::KEY)));
    }

    public function test_tenant_list_shows_status_for_every_central_role(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $beta->suspend();

        foreach ([User::factory()->superAdmin()->create(), User::factory()->support()->create()] as $user) {
            $this->actingAs($user)
                ->get(route('admin.tenants.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Admin/Tenants/Index')
                    ->has('tenants', 2)
                    ->where('tenants', fn ($tenants) => collect($tenants)->firstWhere('id', $alpha->id)['status_label'] === 'Active'
                        && collect($tenants)->firstWhere('id', $alpha->id)['suspended_at'] === null
                        && collect($tenants)->firstWhere('id', $beta->id)['status_label'] === 'Suspended'
                        && collect($tenants)->firstWhere('id', $beta->id)['is_suspended'] === true
                        && collect($tenants)->firstWhere('id', $beta->id)['suspended_at'] === now()->toDayDateTimeString()));
        }
    }

    public function test_action_permission_flag_matches_the_role(): void
    {
        $this->createTenant('alpha.localhost', 'Alpha');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.tenants.index'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.can.manageTenants', true));

        $this->actingAs(User::factory()->support()->create())
            ->get(route('admin.tenants.index'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.can.manageTenants', false));
    }

    public function test_status_changes_keep_tenant_data_and_counts(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = User::factory()->superAdmin()->create();
        $alpha->run(fn () => Writer::factory()->create(['email' => 'ada@alpha.test']));

        $this->actingAs($admin)->post(route('admin.tenants.suspend', $alpha));
        $this->actingAs($admin)->post(route('admin.tenants.reactivate', $alpha));

        $alpha->refresh();
        $this->assertSame('Alpha', $alpha->name);
        $this->assertSame('alpha.localhost', $alpha->domains()->value('domain'));

        $this->actingAs($admin)
            ->get(route('admin.tenants.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tenants.0.name', 'Alpha')
                ->where('tenants.0.writer_count', 1)
                ->where('tenants.0.status_label', 'Active'));

        $this->actingAs($admin)
            ->get(route('admin.tenants.writers.index', $alpha))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('writers', 1));
    }

    public function test_flash_error_is_shared_with_the_page(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->followingRedirects()
            ->post(route('admin.tenants.reactivate', $alpha))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tenants/Index')
                ->where('flash.error', 'Alpha is already active.')
                ->where('flash.success', null));
    }

    private function useDatabaseCache(): void
    {
        config([
            'cache.default' => 'database',
            'cache.stores.database.connection' => config('tenancy.database.central_connection'),
            'cache.stores.database.lock_connection' => config('tenancy.database.central_connection'),
        ]);
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
