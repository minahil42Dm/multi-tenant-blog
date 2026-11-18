<?php

namespace Tests\Feature;

use App\Jobs\PublishScheduledPost;
use App\Models\Post;
use App\Models\Tenant;
use App\Models\Writer;
use App\Support\HomepageCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HomepageCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config([
            'cache.default' => 'database',
            'cache.stores.database.connection' => config('tenancy.database.central_connection'),
            'cache.stores.database.lock_connection' => config('tenancy.database.central_connection'),
        ]);
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

    public function test_homepage_cache_is_populated_and_reused(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant);
        $tenant->run(fn () => $this->makePost($writer, 'Original title', 'original-title', 'published', '2026-11-18 09:00:00'));

        $this->get('http://alpha.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('posts.0.title', 'Original title'));

        $tenant->run(function () {
            DB::table('posts')->update(['title' => 'Changed in the database']);

            $cached = Cache::get(HomepageCache::KEY);

            $this->assertIsArray($cached);
            $this->assertSame('Original title', $cached[0]['title']);
            $this->assertArrayNotHasKey('body', $cached[0]);
            $this->assertArrayNotHasKey('user_id', $cached[0]);
        });

        $this->get('http://alpha.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('posts.0.title', 'Original title'));

        $this->assertTrue($this->cacheKeys()->contains(
            fn (string $key) => str_contains($key, 'tenant_'.$tenant->id.'_'.HomepageCache::KEY)
        ));
    }

    public function test_tenants_have_separate_homepage_caches(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');

        $alpha->run(fn () => $this->makePost(
            $this->writer($alpha, 'ada@alpha.test'),
            'Alpha story',
            'alpha-story',
            'published',
            '2026-11-18 09:00:00',
        ));
        $beta->run(fn () => $this->makePost(
            $this->writer($beta, 'bea@beta.test', 'Bea'),
            'Beta story',
            'beta-story',
            'published',
            '2026-11-18 09:00:00',
        ));

        $this->get('http://alpha.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('posts', 1)
                ->where('posts.0.title', 'Alpha story'));

        $this->flushSession();

        $this->get('http://beta.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('posts', 1)
                ->where('posts.0.title', 'Beta story'));

        $keys = $this->cacheKeys();

        $this->assertTrue($keys->contains(
            fn (string $key) => str_contains($key, 'tenant_'.$alpha->id.'_'.HomepageCache::KEY)
        ));
        $this->assertTrue($keys->contains(
            fn (string $key) => str_contains($key, 'tenant_'.$beta->id.'_'.HomepageCache::KEY)
        ));
        $this->assertSame(1, $keys->filter(
            fn (string $key) => str_contains($key, 'tenant_'.$alpha->id.'_'.HomepageCache::KEY)
        )->count());
    }

    public function test_creating_a_published_post_refreshes_the_homepage(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant);

        $this->get('http://alpha.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('posts', 0));

        $tenant->run(fn () => $this->assertSame([], Cache::get(HomepageCache::KEY)));

        $this->actingAs($writer, 'writer')
            ->post('http://alpha.localhost/posts', [
                'title' => 'Fresh story',
                'body' => 'Now public.',
                'status' => 'published',
                'published_at' => '2026-11-18 10:00:00',
            ])
            ->assertRedirect();

        $this->get('http://alpha.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('posts', 1)
                ->where('posts.0.title', 'Fresh story'));
    }

    public function test_updating_a_published_post_refreshes_the_homepage(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant);
        $post = $tenant->run(fn () => $this->makePost($writer, 'Old title', 'old-title', 'published', '2026-11-18 09:00:00'));

        $this->get('http://alpha.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('posts.0.title', 'Old title'));

        $this->actingAs($writer, 'writer')
            ->put('http://alpha.localhost/posts/'.$post->id, [
                'title' => 'New title',
                'body' => 'Updated body.',
                'status' => 'published',
                'published_at' => '2026-11-18 09:00:00',
            ])
            ->assertRedirect();

        $this->get('http://alpha.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('posts.0.title', 'New title'));
    }

    public function test_deleting_a_published_post_removes_it_from_the_homepage(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant);
        $post = $tenant->run(fn () => $this->makePost($writer, 'Going away', 'going-away', 'published', '2026-11-18 09:00:00'));

        $this->get('http://alpha.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('posts.0.title', 'Going away'));

        $this->actingAs($writer, 'writer')
            ->delete('http://alpha.localhost/posts/'.$post->id)
            ->assertRedirect();

        $this->get('http://alpha.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('posts', 0));
    }

    public function test_scheduled_publishing_refreshes_only_that_tenants_homepage(): void
    {
        Carbon::setTestNow('2026-11-18 12:00:00');

        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $alphaWriter = $this->writer($alpha, 'ada@alpha.test');
        $beta->run(fn () => $this->makePost(
            $this->writer($beta, 'bea@beta.test', 'Bea'),
            'Beta story',
            'beta-story',
            'published',
            '2026-11-18 09:00:00',
        ));

        $this->get('http://beta.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('posts.0.title', 'Beta story'));

        $beta->run(fn () => DB::table('posts')->update(['title' => 'Beta changed underneath']));

        $this->flushSession();

        $due = $alpha->run(fn () => $this->makePost(
            $alphaWriter,
            'Alpha due',
            'alpha-due',
            'scheduled',
            '2026-11-18 11:00:00',
        ));

        $this->get('http://alpha.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('posts', 0));

        $alpha->run(fn () => PublishScheduledPost::dispatchSync($due->id));

        $this->get('http://alpha.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('posts', 1)
                ->where('posts.0.title', 'Alpha due'));

        $this->flushSession();

        $this->get('http://beta.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('posts', 1)
                ->where('posts.0.title', 'Beta story'));
    }

    public function test_draft_and_scheduled_posts_do_not_appear_publicly(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant);

        $tenant->run(function () use ($writer) {
            $this->makePost($writer, 'Hidden draft', 'hidden-draft', 'draft', null);
            $this->makePost($writer, 'Future story', 'future-story', 'scheduled', '2026-12-01 09:00:00');
            $this->makePost($writer, 'Live story', 'live-story', 'published', '2026-11-18 09:00:00');
        });

        $this->get('http://alpha.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('posts', 1)
                ->where('posts.0.title', 'Live story'));

        $tenant->run(function () {
            $titles = collect(Cache::get(HomepageCache::KEY))->pluck('title')->all();

            $this->assertSame(['Live story'], $titles);
        });

        $this->get('http://alpha.localhost/posts/hidden-draft')->assertNotFound();
        $this->get('http://alpha.localhost/posts/future-story')->assertNotFound();
    }

    private function cacheKeys()
    {
        return DB::connection(config('tenancy.database.central_connection'))
            ->table('cache')
            ->pluck('key');
    }

    private function createTenant(string $domain, string $name): Tenant
    {
        $tenant = Tenant::create([
            'name' => $name,
            'brand_color' => '#112233',
        ]);

        $tenant->createDomain($domain);

        return $tenant;
    }

    private function writer(Tenant $tenant, string $email = 'ada@alpha.test', string $name = 'Ada Writer'): Writer
    {
        return $tenant->run(fn () => Writer::create([
            'name' => $name,
            'email' => $email,
            'password' => 'password',
        ]));
    }

    private function makePost(Writer $writer, string $title, string $slug, string $status, ?string $publishedAt): Post
    {
        return Post::forceCreate([
            'user_id' => $writer->id,
            'title' => $title,
            'slug' => $slug,
            'body' => 'Public body for '.$title,
            'status' => $status,
            'published_at' => $publishedAt,
        ]);
    }
}
