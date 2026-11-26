<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Writer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantListingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Carbon::setTestNow('2026-11-20 10:00:00');
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

    public function test_the_list_shows_every_domain_status_user_count_and_created_date(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $alpha->createDomain('blog.alpha.test');
        $alpha->run(fn () => Writer::factory()->count(2)->create());

        $this->actingAs(User::factory()->support()->create())
            ->get(route('admin.tenants.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tenants/Index')
                ->where('tenants.0.name', 'Alpha')
                ->where('tenants.0.domains', [
                    ['id' => $alpha->domains()->where('domain', 'alpha.localhost')->value('id'), 'domain' => 'alpha.localhost', 'is_primary' => true],
                    ['id' => $alpha->domains()->where('domain', 'blog.alpha.test')->value('id'), 'domain' => 'blog.alpha.test', 'is_primary' => false],
                ])
                ->where('tenants.0.status', 'active')
                ->where('tenants.0.writer_count', 2)
                ->where('tenants.0.created_at', 'Fri, Nov 20, 2026 10:00 AM')
                ->where('totalTenants', 1)
                ->where('filters', ['search' => '', 'status' => '']));
    }

    public function test_search_matches_names_and_domains_case_insensitively(): void
    {
        $this->createTenant('alpha.localhost', 'Alpha Kitchen');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $beta->createDomain('recipes.example.test');
        $this->createTenant('gamma.localhost', 'Gamma');

        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->get(route('admin.tenants.index', ['search' => 'KITCHEN']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('tenants', 1)
                ->where('tenants.0.name', 'Alpha Kitchen')
                ->where('filters.search', 'KITCHEN'));

        $this->actingAs($admin)
            ->get(route('admin.tenants.index', ['search' => 'Recipes.EXAMPLE']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('tenants', 1)
                ->where('tenants.0.name', 'Beta'));
    }

    public function test_search_treats_like_wildcards_literally(): void
    {
        $this->createTenant('alpha.localhost', 'Alpha');
        $this->createTenant('beta.localhost', '100% Beta');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.tenants.index', ['search' => '%']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('tenants', 1)
                ->where('tenants.0.name', '100% Beta'));
    }

    public function test_status_filter_shows_only_matching_tenants_and_ignores_unknown_values(): void
    {
        $this->createTenant('alpha.localhost', 'Alpha');
        $this->createTenant('beta.localhost', 'Beta')->suspend();
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->get(route('admin.tenants.index', ['status' => 'suspended']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('tenants', 1)
                ->where('tenants.0.name', 'Beta')
                ->where('filters.status', 'suspended'));

        $this->actingAs($admin)
            ->get(route('admin.tenants.index', ['status' => 'active']))
            ->assertInertia(fn (Assert $page) => $page->has('tenants', 1)->where('tenants.0.name', 'Alpha'));

        $this->actingAs($admin)
            ->get(route('admin.tenants.index', ['status' => 'deleted']))
            ->assertInertia(fn (Assert $page) => $page->has('tenants', 2)->where('filters.status', ''));
    }

    public function test_pagination_keeps_search_and_filter_parameters(): void
    {
        foreach (range(1, 12) as $number) {
            $this->createTenant("shop{$number}.localhost", "Shop {$number}");
            $this->travel(1)->minute();
        }

        $this->createTenant('other.localhost', 'Other');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.tenants.index', ['search' => 'shop', 'status' => 'active', 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tenants', 2)
                ->where('tenants.0.name', 'Shop 2')
                ->where('tenants.1.name', 'Shop 1')
                ->where('pagination.current_page', 2)
                ->where('pagination.last_page', 2)
                ->where('pagination.total', 12)
                ->where('pagination.from', 11)
                ->where('pagination.to', 12)
                ->where('pagination.links.1.url', fn (string $url) => str_contains($url, 'search=shop')
                    && str_contains($url, 'status=active')
                    && str_contains($url, 'page=1'))
                ->where('totalTenants', 13));
    }

    public function test_empty_states_distinguish_no_tenants_from_no_matches(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->get(route('admin.tenants.index'))
            ->assertInertia(fn (Assert $page) => $page->has('tenants', 0)->where('totalTenants', 0));

        $this->createTenant('alpha.localhost', 'Alpha');

        $this->actingAs($admin)
            ->get(route('admin.tenants.index', ['search' => 'nothing-matches']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('tenants', 0)
                ->where('totalTenants', 1)
                ->where('pagination.total', 0));
    }

    private function createTenant(string $domain, string $name): Tenant
    {
        $tenant = Tenant::create(['name' => $name]);
        $tenant->createDomain($domain);

        return $tenant;
    }
}
