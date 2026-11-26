<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\Writer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantStatusTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_new_tenants_are_active_and_store_status_in_real_columns(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        $this->assertSame(TenantStatus::Active, $tenant->status);
        $this->assertTrue($tenant->isActive());
        $this->assertFalse($tenant->isSuspended());
        $this->assertNull($tenant->suspended_at);

        $row = DB::table('tenants')->where('id', $tenant->id)->first();

        $this->assertSame('active', $row->status);
        $this->assertNull($row->suspended_at);
        $this->assertArrayNotHasKey('status', json_decode($row->data, true));
        $this->assertSame('Alpha', json_decode($row->data, true)['name']);
        $this->assertSame(TenantStatus::Active, $tenant->fresh()->status);
    }

    public function test_suspend_and_reactivate_change_only_the_lifecycle_columns(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $tenant->run(fn () => Writer::factory()->create(['email' => 'ada@alpha.test']));

        $this->travelTo(now()->startOfMinute());
        $tenant->suspend();

        $suspended = $tenant->fresh();
        $this->assertSame(TenantStatus::Suspended, $suspended->status);
        $this->assertTrue($suspended->isSuspended());
        $this->assertTrue($suspended->suspended_at->equalTo(now()));
        $this->assertSame('Alpha', $suspended->name);
        $this->assertSame('alpha.localhost', $suspended->domains()->value('domain'));
        $tenant->run(fn () => $this->assertSame(1, Writer::query()->count()));

        $tenant->reactivate();

        $reactivated = $tenant->fresh();
        $this->assertSame(TenantStatus::Active, $reactivated->status);
        $this->assertNull($reactivated->suspended_at);
        $tenant->run(fn () => $this->assertSame(1, Writer::query()->count()));
    }

    public function test_suspending_twice_keeps_the_original_suspension_time(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        $this->travelTo(now()->startOfMinute());
        $tenant->suspend();
        $firstSuspension = $tenant->fresh()->suspended_at;

        $this->travel(10)->minutes();
        $tenant->fresh()->suspend();

        $this->assertTrue($tenant->fresh()->suspended_at->equalTo($firstSuspension));
    }

    public function test_status_cannot_be_mass_assigned(): void
    {
        $tenant = Tenant::create([
            'name' => 'Alpha',
            'status' => 'suspended',
            'suspended_at' => now(),
        ]);

        $this->assertSame(TenantStatus::Active, $tenant->fresh()->status);
        $this->assertNull($tenant->fresh()->suspended_at);

        $tenant->update(['name' => 'Alpha Renamed', 'status' => 'suspended']);

        $this->assertSame('Alpha Renamed', $tenant->fresh()->name);
        $this->assertSame(TenantStatus::Active, $tenant->fresh()->status);
    }

    public function test_tenants_can_be_filtered_by_status(): void
    {
        $active = $this->createTenant('alpha.localhost', 'Alpha');
        $suspended = $this->createTenant('beta.localhost', 'Beta');
        $suspended->suspend();

        $this->assertSame([$active->id], Tenant::query()->withStatus(TenantStatus::Active)->pluck('id')->all());
        $this->assertSame([$suspended->id], Tenant::query()->withStatus(TenantStatus::Suspended)->pluck('id')->all());
    }

    public function test_the_migration_marks_existing_tenants_active(): void
    {
        $migration = require database_path('migrations/2026_11_25_000000_add_status_to_tenants_table.php');

        $migration->down();

        $this->assertFalse(Schema::hasColumn('tenants', 'status'));
        $this->assertFalse(Schema::hasColumn('tenants', 'suspended_at'));

        DB::table('tenants')->insert([
            'id' => 'legacy-tenant',
            'data' => json_encode(['name' => 'Legacy']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertSame('active', DB::table('tenants')->where('id', 'legacy-tenant')->value('status'));
        $this->assertNull(DB::table('tenants')->where('id', 'legacy-tenant')->value('suspended_at'));

        DB::table('tenants')->where('id', 'legacy-tenant')->delete();
    }

    private function createTenant(string $domain, string $name): Tenant
    {
        $tenant = Tenant::create(['name' => $name]);
        $tenant->createDomain($domain);

        return $tenant;
    }
}
