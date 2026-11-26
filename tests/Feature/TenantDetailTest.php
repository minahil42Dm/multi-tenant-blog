<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Http\Controllers\Admin\TenantController;
use App\Models\Post;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Writer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantDetailTest extends TestCase
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

    public function test_super_admin_can_view_tenant_details(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha', [
            'brand_color' => '#112233',
            'logo' => 'tenant-logos/alpha.png',
        ]);
        $writer = $this->writer($alpha, 'ada@alpha.test');
        $alpha->run(fn () => Post::factory()->published()->count(2)->for($writer, 'writer')->create());

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.tenants.show', $alpha))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tenants/Show')
                ->where('tenant.id', $alpha->id)
                ->where('tenant.name', 'Alpha')
                ->where('tenant.domain', 'alpha.localhost')
                ->where('tenant.status_label', 'Active')
                ->where('tenant.is_suspended', false)
                ->where('tenant.suspended_at', null)
                ->where('tenant.created_at', now()->toDayDateTimeString())
                ->where('tenant.brand_color', '#112233')
                ->where('tenant.logo_url', fn (string $url) => str_ends_with($url, '/storage/tenant-logos/alpha.png'))
                ->where('tenant.post_count', 2)
                ->where('tenant.writer_count', 1)
                ->where('auth.can.manageTenants', true)
                ->missing('writers')
                ->missing('posts'));
    }

    public function test_support_can_view_tenant_details_without_management_actions(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $alpha->suspend();

        $this->actingAs(User::factory()->support()->create())
            ->get(route('admin.tenants.show', $alpha))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tenants/Show')
                ->where('tenant.name', 'Alpha')
                ->where('tenant.status_label', 'Suspended')
                ->where('tenant.is_suspended', true)
                ->where('tenant.suspended_at', now()->toDayDateTimeString())
                ->where('auth.can.viewTenants', true)
                ->where('auth.can.viewTenantUsers', true)
                ->where('auth.can.manageTenants', false)
                ->where('auth.can.manageTenantUsers', false));
    }

    public function test_unauthorized_users_cannot_view_tenant_details(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($alpha, 'ada@alpha.test');

        $this->get(route('admin.tenants.show', $alpha))
            ->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('admin.tenants.show', $alpha))
            ->assertForbidden();

        $this->actingAs(User::factory()->support()->unverified()->create())
            ->get(route('admin.tenants.show', $alpha))
            ->assertRedirect(route('verification.notice'));

        $this->actingAs($writer, 'writer')
            ->get('http://alpha.localhost/admin/tenants/'.$alpha->id)
            ->assertNotFound();
    }

    public function test_unknown_tenants_return_not_found(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.tenants.show', 'missing-tenant'))
            ->assertNotFound();
    }

    public function test_detail_page_never_shows_another_tenants_data(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha', ['brand_color' => '#111111']);
        $beta = $this->createTenant('beta.localhost', 'Beta', ['brand_color' => '#222222']);
        $alphaWriter = $this->writer($alpha, 'ada@alpha.test');
        $alpha->run(function () use ($alphaWriter): void {
            Post::factory()->published()->for($alphaWriter, 'writer')->create(['title' => 'Alpha secret story']);
            Post::factory()->published()->for($alphaWriter, 'writer')->create(['title' => 'Alpha second story']);
        });
        foreach (['grace@beta.test', 'linus@beta.test', 'barbara@beta.test'] as $email) {
            $this->writer($beta, $email);
        }

        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->get(route('admin.tenants.show', $alpha))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tenant.name', 'Alpha')
                ->where('tenant.domain', 'alpha.localhost')
                ->where('tenant.brand_color', '#111111')
                ->where('tenant.post_count', 2)
                ->where('tenant.writer_count', 1))
            ->assertDontSee('beta.localhost')
            ->assertDontSee('grace@beta.test')
            ->assertDontSee('ada@alpha.test')
            ->assertDontSee('Alpha secret story');

        $this->actingAs($admin)
            ->get(route('admin.tenants.show', $beta))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tenant.name', 'Beta')
                ->where('tenant.domain', 'beta.localhost')
                ->where('tenant.brand_color', '#222222')
                ->where('tenant.post_count', 0)
                ->where('tenant.writer_count', 3))
            ->assertDontSee('alpha.localhost')
            ->assertDontSee('Alpha secret story');
    }

    public function test_counting_restores_the_central_context(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $this->writer($alpha, 'ada@alpha.test');
        $centralConnection = DB::getDefaultConnection();

        app(TenantController::class)->show('localhost', $alpha);

        $this->assertFalse(tenancy()->initialized);
        $this->assertSame($centralConnection, DB::getDefaultConnection());
        $this->assertSame(1, DB::table('tenants')->count());
    }

    public function test_a_broken_tenant_database_shows_zero_and_restores_the_central_context(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $alpha->run(fn () => Schema::drop('posts'));
        $centralConnection = DB::getDefaultConnection();

        $this->withoutExceptionHandling();
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.tenants.show', $alpha))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tenant.name', 'Alpha')
                ->where('tenant.post_count', 0));

        app(TenantController::class)->show('localhost', $alpha);

        $this->assertFalse(tenancy()->initialized);
        $this->assertSame($centralConnection, DB::getDefaultConnection());
    }

    public function test_status_actions_from_the_detail_page_return_to_it(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->post(route('admin.tenants.suspend', $alpha), ['redirect_to' => 'show'])
            ->assertRedirect(route('admin.tenants.show', $alpha))
            ->assertSessionHas('success', 'Alpha has been suspended.');

        $this->actingAs($admin)
            ->get(route('admin.tenants.show', $alpha))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tenant.status_label', 'Suspended')
                ->where('flash.success', 'Alpha has been suspended.'));

        $this->actingAs($admin)
            ->post(route('admin.tenants.reactivate', $alpha), ['redirect_to' => 'show'])
            ->assertRedirect(route('admin.tenants.show', $alpha))
            ->assertSessionHas('success', 'Alpha has been reactivated.');

        $this->actingAs($admin)
            ->post(route('admin.tenants.reactivate', $alpha), ['redirect_to' => 'show'])
            ->assertRedirect(route('admin.tenants.show', $alpha))
            ->assertSessionHas('error', 'Alpha is already active.');

        $this->assertSame(TenantStatus::Active, $alpha->fresh()->status);
    }

    public function test_unknown_redirect_targets_fall_back_to_the_tenant_list(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('admin.tenants.suspend', $alpha), ['redirect_to' => 'https://evil.example'])
            ->assertRedirect(route('admin.tenants.index'));
    }

    public function test_support_still_cannot_change_status_from_the_detail_page(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');

        $this->actingAs(User::factory()->support()->create())
            ->post(route('admin.tenants.suspend', $alpha), ['redirect_to' => 'show'])
            ->assertForbidden();

        $this->assertSame(TenantStatus::Active, $alpha->fresh()->status);
    }

    public function test_the_create_page_is_not_captured_by_the_detail_route(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.tenants.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Tenants/Create'));

        $this->actingAs(User::factory()->support()->create())
            ->get(route('admin.tenants.create'))
            ->assertForbidden();
    }

    public function test_tenant_list_and_writer_pages_still_work(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $this->writer($alpha, 'ada@alpha.test');
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->get(route('admin.tenants.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tenants/Index')
                ->has('tenants', 1)
                ->where('tenants.0.id', $alpha->id)
                ->where('tenants.0.status_label', 'Active')
                ->where('tenants.0.writer_count', 1)
                ->where('tenants.0.created_at', now()->toDayDateTimeString()));

        $this->actingAs($admin)
            ->get(route('admin.tenants.writers.index', $alpha))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('writers', 1)
                ->where('writers.0.email', 'ada@alpha.test'));
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function createTenant(string $domain, string $name, array $attributes = []): Tenant
    {
        $tenant = Tenant::create([
            'name' => $name,
            ...$attributes,
        ]);

        $tenant->createDomain($domain);

        return $tenant;
    }

    private function writer(Tenant $tenant, string $email): Writer
    {
        return $tenant->run(fn () => Writer::create([
            'name' => 'Writer '.$email,
            'email' => $email,
            'password' => 'password',
        ]));
    }
}
