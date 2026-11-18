<?php

namespace Tests\Feature;

use App\Jobs\PublishScheduledPost;
use App\Models\Post;
use App\Models\Tenant;
use App\Models\Writer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ScheduledPostTest extends TestCase
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

    public function test_writer_can_schedule_a_post_for_a_future_date(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant);

        $this->actingAs($writer, 'writer')
            ->post('http://alpha.localhost/posts', [
                'title' => 'Later story',
                'body' => 'Not yet.',
                'status' => 'scheduled',
                'published_at' => '2026-11-19 09:30:00',
                'force_publish' => true,
            ])
            ->assertRedirect();

        $tenant->run(function () {
            $post = Post::query()->first();

            $this->assertSame('scheduled', $post->status);
            $this->assertSame('2026-11-19 09:30:00', $post->published_at->toDateTimeString());
        });

        $this->get('http://alpha.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('posts', 0));

        $this->get('http://alpha.localhost/posts/later-story')->assertNotFound();

        $this->actingAs($writer, 'writer')
            ->get('http://alpha.localhost/posts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('posts.0.status', 'scheduled')
                ->where('posts.0.published_at', '2026-11-19 09:30:00'));
    }

    public function test_writer_can_change_a_scheduled_time(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant);
        $post = $tenant->run(fn () => $this->makePost($writer, 'Move me', 'move-me', 'scheduled', '2026-11-19 09:00:00'));

        $this->actingAs($writer, 'writer')
            ->put('http://alpha.localhost/posts/'.$post->id, [
                'title' => 'Move me',
                'body' => 'Not yet.',
                'status' => 'scheduled',
                'published_at' => '2026-11-20 15:00:00',
            ])
            ->assertRedirect();

        $tenant->run(function () use ($post) {
            $post->refresh();
            $this->assertSame('scheduled', $post->status);
            $this->assertSame('2026-11-20 15:00:00', $post->published_at->toDateTimeString());
        });
    }

    public function test_due_posts_are_published_and_future_posts_are_not(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant);

        $tenant->run(function () use ($writer) {
            $this->makePost($writer, 'Due story', 'due-story', 'scheduled', '2026-11-18 11:00:00');
            $this->makePost($writer, 'Future story', 'future-story', 'scheduled', '2026-11-19 11:00:00');
        });

        $this->artisan('posts:publish-due')->assertSuccessful();

        $tenant->run(function () {
            $this->assertSame('published', Post::query()->where('slug', 'due-story')->value('status'));
            $this->assertSame('scheduled', Post::query()->where('slug', 'future-story')->value('status'));
        });

        $this->get('http://alpha.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('posts', 1)
                ->where('posts.0.title', 'Due story'));

        $this->get('http://alpha.localhost/posts/due-story')->assertOk();
        $this->get('http://alpha.localhost/posts/future-story')->assertNotFound();
        $this->assertFalse(Schema::hasTable('posts'));
    }

    public function test_a_tenant_job_does_not_publish_another_tenants_post(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $alphaWriter = $this->writer($alpha, 'ada@alpha.test');
        $betaWriter = $this->writer($beta, 'bea@beta.test', 'Bea Writer');

        $alphaPost = $alpha->run(fn () => $this->makePost($alphaWriter, 'Alpha due', 'alpha-due', 'scheduled', '2026-11-18 11:00:00'));
        $betaPost = $beta->run(fn () => $this->makePost($betaWriter, 'Beta due', 'beta-due', 'scheduled', '2026-11-18 11:00:00'));

        $this->assertSame($alphaPost->id, $betaPost->id);

        $alpha->run(fn () => PublishScheduledPost::dispatchSync($alphaPost->id));

        $alpha->run(fn () => $this->assertSame('published', $alphaPost->fresh()->status));
        $beta->run(fn () => $this->assertSame('scheduled', $betaPost->fresh()->status));
    }

    public function test_deleted_and_already_published_posts_are_handled_safely(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant);
        $post = $tenant->run(fn () => $this->makePost($writer, 'Once', 'once', 'published', '2026-11-18 08:00:00'));

        $tenant->run(function () use ($post) {
            PublishScheduledPost::dispatchSync($post->id);
            PublishScheduledPost::dispatchSync($post->id);
            PublishScheduledPost::dispatchSync(999);

            $post->refresh();
            $this->assertSame('published', $post->status);
            $this->assertSame('2026-11-18 08:00:00', $post->published_at->toDateTimeString());
            $this->assertSame(1, Post::query()->count());
        });

        $deletedId = $post->id;
        $tenant->run(fn () => $post->delete());
        $tenant->run(fn () => PublishScheduledPost::dispatchSync($deletedId));

        $tenant->run(fn () => $this->assertSame(0, Post::query()->count()));
    }

    public function test_invalid_or_missing_scheduled_dates_are_rejected(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant);

        $this->actingAs($writer, 'writer')
            ->post('http://alpha.localhost/posts', [
                'title' => 'Missing time',
                'body' => 'Body',
                'status' => 'scheduled',
            ])
            ->assertSessionHasErrors('published_at');

        $this->actingAs($writer, 'writer')
            ->post('http://alpha.localhost/posts', [
                'title' => 'Past time',
                'body' => 'Body',
                'status' => 'scheduled',
                'published_at' => '2026-11-18 11:00:00',
            ])
            ->assertSessionHasErrors('published_at');

        $this->actingAs($writer, 'writer')
            ->post('http://alpha.localhost/posts', [
                'title' => 'Draft',
                'body' => 'Body',
                'status' => 'draft',
                'published_at' => '2026-11-18 11:00:00',
            ])
            ->assertRedirect();

        $tenant->run(function () {
            $this->assertSame(1, Post::query()->count());
            $draft = Post::query()->first();
            $this->assertSame('draft', $draft->status);
            $this->assertNull($draft->published_at);
        });
    }

    private function createTenant(string $domain, string $name): Tenant
    {
        $tenant = Tenant::create([
            'name' => $name,
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

    private function makePost(Writer $writer, string $title, string $slug, string $status, string $publishedAt): Post
    {
        return Post::forceCreate([
            'user_id' => $writer->id,
            'title' => $title,
            'slug' => $slug,
            'body' => 'Body',
            'status' => $status,
            'published_at' => $publishedAt,
        ]);
    }
}
