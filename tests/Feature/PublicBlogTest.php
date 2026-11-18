<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Tenant;
use App\Models\Writer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicBlogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
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

    public function test_homepage_lists_only_published_posts_for_the_current_tenant(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');

        $writer = $alpha->run(fn () => Writer::create([
            'name' => 'Ada Writer',
            'email' => 'ada@alpha.test',
            'password' => 'password',
        ]));

        $alpha->run(function () use ($writer) {
            $this->makePost($writer, 'Published story', 'published-story', 'This is the public body.', 'published', '2026-11-18 09:00:00');
            $this->makePost($writer, 'Hidden draft', 'hidden-draft', 'Draft body that must stay private.', 'draft', null);
            $this->makePost($writer, 'Future scheduled', 'future-scheduled', 'Scheduled body that must stay private.', 'scheduled', '2026-12-01 09:00:00');
        });

        $beta->run(function () {
            $writer = Writer::create([
                'name' => 'Bea Writer',
                'email' => 'bea@beta.test',
                'password' => 'password',
            ]);
            $this->makePost($writer, 'Beta published', 'beta-published', 'Beta only.', 'published', '2026-11-18 10:00:00');
        });

        $this->get('http://alpha.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Blog/Home')
                ->where('tenantName', 'Alpha')
                ->has('posts', 1)
                ->where('posts.0.title', 'Published story')
                ->where('posts.0.slug', 'published-story')
                ->where('posts.0.excerpt', 'This is the public body.'));

        $this->assertGuest('writer');

        $this->flushSession();

        $this->get('http://beta.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Blog/Home')
                ->where('tenantName', 'Beta')
                ->has('posts', 1)
                ->where('posts.0.title', 'Beta published'));

        $this->assertFalse(Schema::hasTable('posts'));
    }

    public function test_visitors_can_open_published_posts_by_slug(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $tenant->run(fn () => Writer::create([
            'name' => 'Ada Writer',
            'email' => 'ada@alpha.test',
            'password' => 'password',
        ]));

        $tenant->run(fn () => $this->makePost(
            $writer,
            'My first post',
            'my-first-post',
            'The full public article.',
            'published',
            '2026-11-18 09:00:00',
        ));

        $this->get('http://alpha.localhost/posts/my-first-post')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Blog/Show')
                ->where('post.title', 'My first post')
                ->where('post.body', 'The full public article.')
                ->where('post.slug', 'my-first-post'));

        $this->assertGuest('writer');
    }

    public function test_unpublished_and_missing_posts_return_not_found(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $tenant->run(fn () => Writer::create([
            'name' => 'Ada Writer',
            'email' => 'ada@alpha.test',
            'password' => 'password',
        ]));

        $tenant->run(function () use ($writer) {
            $this->makePost($writer, 'Hidden draft', 'hidden-draft', 'Draft body.', 'draft', null);
            $this->makePost($writer, 'Future scheduled', 'future-scheduled', 'Scheduled body.', 'scheduled', '2026-12-01 09:00:00');
        });

        $this->get('http://alpha.localhost/posts/hidden-draft')->assertNotFound();
        $this->get('http://alpha.localhost/posts/future-scheduled')->assertNotFound();
        $this->get('http://alpha.localhost/posts/missing-post')->assertNotFound();
    }

    public function test_a_tenant_cannot_display_another_tenants_post(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $this->createTenant('beta.localhost', 'Beta');

        $writer = $alpha->run(fn () => Writer::create([
            'name' => 'Ada Writer',
            'email' => 'ada@alpha.test',
            'password' => 'password',
        ]));

        $alpha->run(fn () => $this->makePost(
            $writer,
            'Alpha only',
            'alpha-only',
            'Secret to Alpha.',
            'published',
            '2026-11-18 09:00:00',
        ));

        $this->get('http://beta.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('posts', 0));

        $this->get('http://beta.localhost/posts/alpha-only')->assertNotFound();
    }

    private function createTenant(string $domain, string $name): Tenant
    {
        $tenant = Tenant::create([
            'name' => $name,
        ]);

        $tenant->createDomain($domain);

        return $tenant;
    }

    private function makePost(Writer $writer, string $title, string $slug, string $body, string $status, ?string $publishedAt): Post
    {
        return Post::forceCreate([
            'user_id' => $writer->id,
            'title' => $title,
            'slug' => $slug,
            'body' => $body,
            'status' => $status,
            'published_at' => $publishedAt,
        ]);
    }
}
