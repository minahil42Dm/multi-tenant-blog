<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Writer;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoTenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\TestCase;

class DemoTenantSeederTest extends TestCase
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

    public function test_demo_seeder_provisions_two_isolated_tenants(): void
    {
        $this->seed(DemoTenantSeeder::class);

        $this->assertSame(2, Tenant::query()->count());
        $this->assertSame(2, Domain::query()->count());

        $harbor = $this->tenant('harbor.blogs.test');
        $pine = $this->tenant('pine.blogs.test');

        $this->assertSame('Harbor Journal', $harbor->name);
        $this->assertSame('#0f766e', $harbor->brand_color);
        $this->assertNull($harbor->logo);
        $this->assertSame('Pine and Paper', $pine->name);
        $this->assertSame('#9a3412', $pine->brand_color);
        $this->assertNull($pine->logo);
        $this->assertNotSame($harbor->id, $pine->id);

        $harborDatabase = $harbor->run(fn () => DB::connection()->getDatabaseName());
        $pineDatabase = $pine->run(fn () => DB::connection()->getDatabaseName());

        $this->assertNotSame($harborDatabase, $pineDatabase);
        $this->assertNotSame($harborDatabase, DB::connection(config('tenancy.database.central_connection'))->getDatabaseName());

        $this->assertTenantPosts($harbor, 'writer@harbor.blogs.test', [
            'welcome-ashore' => 'published',
            'harbor-market' => 'published',
            'harbor-field-notes' => 'draft',
            'next-departure' => 'scheduled',
        ], [
            'first-edition',
            'press-room',
            'pine-draft',
            'spring-issue',
        ]);

        $this->assertTenantPosts($pine, 'writer@pine.blogs.test', [
            'first-edition' => 'published',
            'press-room' => 'published',
            'pine-draft' => 'draft',
            'spring-issue' => 'scheduled',
        ], [
            'welcome-ashore',
            'harbor-market',
            'harbor-field-notes',
            'next-departure',
        ]);

        $this->assertFalse(Schema::hasTable('posts'));
        $this->assertNull(User::query()->where('email', 'writer@harbor.blogs.test')->first());
        $this->assertNull(User::query()->where('email', 'writer@pine.blogs.test')->first());
    }

    public function test_demo_seeder_can_run_twice_without_duplicates(): void
    {
        $this->seed(DemoTenantSeeder::class);

        $scheduledAt = $this->tenant('harbor.blogs.test')->run(
            fn () => Post::query()->where('slug', 'next-departure')->firstOrFail()->published_at->toDateTimeString()
        );

        $this->seed(DemoTenantSeeder::class);

        $this->assertSame(2, Tenant::query()->count());
        $this->assertSame(2, Domain::query()->count());

        $this->tenant('harbor.blogs.test')->run(function () use ($scheduledAt) {
            $this->assertSame(1, Writer::query()->count());
            $this->assertSame(4, Post::query()->count());
            $this->assertSame(1, Post::query()->where('slug', 'welcome-ashore')->count());
            $this->assertSame(
                $scheduledAt,
                Post::query()->where('slug', 'next-departure')->firstOrFail()->published_at->toDateTimeString()
            );
        });

        $this->tenant('pine.blogs.test')->run(function () {
            $this->assertSame(1, Writer::query()->count());
            $this->assertSame(4, Post::query()->count());
            $this->assertSame(1, Post::query()->where('slug', 'first-edition')->count());
        });
    }

    public function test_database_seeder_runs_the_demo_tenant_seeder_idempotently(): void
    {
        $this->seed();
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::query()->where('email', 'test@example.com')->count());
        $this->assertSame(2, Tenant::query()->count());
        $this->assertSame(2, Domain::query()->count());

        foreach (['harbor.blogs.test', 'pine.blogs.test'] as $domain) {
            $this->tenant($domain)->run(function () {
                $this->assertSame(1, Writer::query()->count());
                $this->assertSame(4, Post::query()->count());
            });
        }
    }

    public function test_demo_seeder_preserves_existing_tenants_and_central_admins(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'kept-admin@example.com',
        ]);

        $existing = Tenant::create([
            'name' => 'Existing Blog',
            'brand_color' => '#112233',
        ]);
        $existing->createDomain('existing.localhost');
        $existing->run(function () {
            $writer = Writer::factory()->create([
                'email' => 'kept@existing.localhost',
            ]);

            Post::factory()->published()->create([
                'user_id' => $writer->id,
                'slug' => 'kept-post',
            ]);
        });

        $this->seed(DemoTenantSeeder::class);

        $this->assertTrue($admin->fresh()->isSuperAdmin());
        $this->assertSame(1, User::query()->count());
        $this->assertNotNull(Tenant::query()->find($existing->id));
        $this->assertSame(3, Tenant::query()->count());
        $this->assertNotNull(Domain::query()->where('domain', 'existing.localhost')->first());

        $existing->run(function () {
            $this->assertSame(1, Writer::query()->where('email', 'kept@existing.localhost')->count());
            $this->assertSame(1, Post::query()->where('slug', 'kept-post')->count());
            $this->assertSame(0, Writer::query()->where('email', 'writer@harbor.blogs.test')->count());
        });
    }

    public function test_writer_and_post_factories_honor_status_dates_inside_a_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Factory Blog',
            'brand_color' => '#1d4ed8',
        ]);
        $tenant->createDomain('factory.localhost');

        $tenant->run(function () {
            $writer = Writer::factory()->create();
            $draft = Post::factory()->draft()->create(['user_id' => $writer->id]);
            $published = Post::factory()->published()->create(['user_id' => $writer->id]);
            $scheduled = Post::factory()->scheduled()->create(['user_id' => $writer->id]);
            $owned = Post::factory()->published()->create();

            $this->assertSame('draft', $draft->status);
            $this->assertNull($draft->published_at);
            $this->assertNull($draft->cover_image);
            $this->assertSame('published', $published->status);
            $this->assertNotNull($published->published_at);
            $this->assertTrue($published->published_at->lessThanOrEqualTo(now()));
            $this->assertSame('scheduled', $scheduled->status);
            $this->assertTrue($scheduled->published_at->isFuture());
            $this->assertNull($scheduled->cover_image);
            $this->assertSame($writer->id, $draft->user_id);
            $this->assertNotNull(Writer::query()->find($owned->user_id));
            $this->assertSame(
                Post::query()->count(),
                Post::query()->distinct()->count('slug')
            );
        });
    }

    /**
     * @param  array<string, string>  $expected
     * @param  array<int, string>  $foreignSlugs
     */
    private function assertTenantPosts(Tenant $tenant, string $email, array $expected, array $foreignSlugs): void
    {
        $tenant->run(function () use ($email, $expected, $foreignSlugs) {
            $this->assertTrue(Schema::hasTable('users'));
            $this->assertTrue(Schema::hasTable('posts'));

            $writer = Writer::query()->where('email', $email)->firstOrFail();

            $this->assertSame(1, Writer::query()->count());
            $this->assertTrue(Hash::check(DemoTenantSeeder::PASSWORD, $writer->password));
            $this->assertSame(count($expected), Post::query()->count());
            $this->assertSame(0, Post::query()->whereIn('slug', $foreignSlugs)->count());
            $this->assertSame(0, Writer::query()->where('email', '!=', $email)->count());

            foreach ($expected as $slug => $status) {
                $post = Post::query()->where('slug', $slug)->firstOrFail();

                $this->assertSame($status, $post->status);
                $this->assertSame($writer->id, $post->user_id);
                $this->assertNull($post->cover_image);

                if ($status === 'draft') {
                    $this->assertNull($post->published_at);
                }

                if ($status === 'published') {
                    $this->assertNotNull($post->published_at);
                    $this->assertTrue($post->published_at->lessThanOrEqualTo(now()));
                }

                if ($status === 'scheduled') {
                    $this->assertNotNull($post->published_at);
                    $this->assertTrue($post->published_at->isFuture());
                }
            }
        });
    }

    private function tenant(string $domain): Tenant
    {
        $match = Domain::query()->where('domain', $domain)->firstOrFail();

        return Tenant::query()->findOrFail($match->tenant_id);
    }
}
