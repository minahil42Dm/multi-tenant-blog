<?php

namespace Tests\Feature;

use App\Jobs\PublishScheduledPost;
use App\Models\Post;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Writer;
use App\Support\TenantCoverImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class TenantSecurityAuditTest extends TestCase
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
            Tenant::query()->get()->each(function (Tenant $tenant) {
                File::deleteDirectory(storage_path('tenant'.$tenant->id));
                $tenant->delete();
            });
        }

        parent::tearDown();
    }

    public function test_central_users_are_not_read_or_written_through_a_tenant_connection(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@central.test',
        ]);

        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        $tenant->run(function () use ($admin) {
            Writer::create([
                'name' => 'Ada Writer',
                'email' => 'writer@alpha.test',
                'password' => 'password',
            ]);

            User::factory()->create([
                'email' => 'created-during-tenancy@central.test',
            ]);

            $this->assertSame(
                config('tenancy.database.central_connection'),
                $admin->getConnectionName()
            );
            $this->assertTrue(User::query()->where('email', 'admin@central.test')->exists());
            $this->assertTrue(User::query()->where('email', 'created-during-tenancy@central.test')->exists());
            $this->assertFalse(User::query()->where('email', 'writer@alpha.test')->exists());
            $this->assertSame(0, Writer::query()->where('email', 'admin@central.test')->count());
            $this->assertSame(0, Writer::query()->where('email', 'created-during-tenancy@central.test')->count());
            $this->assertTrue($admin->fresh()->isSuperAdmin());
        });

        $this->assertNotNull(User::query()->where('email', 'created-during-tenancy@central.test')->first());
        $this->assertSame(2, User::query()->count());

        try {
            Writer::query()->count();
            $this->fail('Writer queries must require tenancy.');
        } catch (RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        try {
            Post::query()->count();
            $this->fail('Post queries must require tenancy.');
        } catch (RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    public function test_numeric_and_reserved_slugs_stay_on_the_public_route(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $tenant->run(fn () => Writer::create([
            'name' => 'Ada Writer',
            'email' => 'ada@alpha.test',
            'password' => 'password',
        ]));

        $draft = $tenant->run(fn () => Post::forceCreate([
            'user_id' => $writer->id,
            'title' => 'Secret draft',
            'slug' => 'secret-draft',
            'body' => 'Draft body must stay private.',
            'status' => 'draft',
        ]));

        $this->actingAs($writer, 'writer')
            ->post('http://alpha.localhost/posts', [
                'title' => '2026',
                'body' => 'Numeric title body.',
                'status' => 'published',
                'published_at' => '2026-11-18 09:00:00',
                'user_id' => 999,
                'cover_image' => 'covers/'.$tenant->id.'/supplied.jpg',
            ])
            ->assertRedirect();

        $this->actingAs($writer, 'writer')
            ->post('http://alpha.localhost/posts', [
                'title' => 'Create',
                'body' => 'Reserved title body.',
                'status' => 'published',
                'published_at' => '2026-11-17 09:00:00',
            ])
            ->assertRedirect();

        $posts = $tenant->run(fn () => Post::query()->whereIn('title', ['2026', 'Create'])->get()->keyBy('title'));

        $this->assertDoesNotMatchRegularExpression('/^\d+$/', $posts['2026']->slug);
        $this->assertNotSame('create', $posts['Create']->slug);
        $this->assertSame($writer->id, (int) $posts['2026']->user_id);
        $this->assertNull($posts['2026']->cover_image);

        auth('writer')->logout();
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->get('http://alpha.localhost/posts/'.$posts['2026']->slug)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Blog/Show')
                ->where('post.body', 'Numeric title body.')
                ->where('post.slug', $posts['2026']->slug));

        $this->get('http://alpha.localhost/posts/'.$posts['Create']->slug)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('post.body', 'Reserved title body.'));

        $this->get('http://alpha.localhost/posts/'.$draft->id)
            ->assertRedirect(route('writer.login', absolute: false));

        $this->get('http://alpha.localhost/posts/create')
            ->assertRedirect(route('writer.login', absolute: false));

        $this->get('http://alpha.localhost/posts/secret-draft')
            ->assertNotFound();
    }

    public function test_an_owner_cannot_reassign_a_post_or_replace_it_with_a_foreign_cover_path(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $owner = $alpha->run(fn () => Writer::create([
            'name' => 'Owner',
            'email' => 'owner@alpha.test',
            'password' => 'password',
        ]));
        $other = $alpha->run(fn () => Writer::create([
            'name' => 'Other',
            'email' => 'other@alpha.test',
            'password' => 'password',
        ]));

        $post = $alpha->run(fn () => Post::forceCreate([
            'user_id' => $owner->id,
            'title' => 'Owned',
            'slug' => 'owned',
            'body' => 'Mine',
            'status' => 'draft',
        ]));

        $this->actingAs($owner, 'writer')
            ->put('http://alpha.localhost/posts/'.$post->id, [
                'title' => 'Still mine',
                'body' => 'Still mine',
                'status' => 'draft',
                'user_id' => $other->id,
                'cover_image' => 'covers/'.$beta->id.'/foreign.jpg',
            ])
            ->assertRedirect();

        $alpha->run(function () use ($owner, $post, $beta) {
            $post->refresh();

            $this->assertSame($owner->id, (int) $post->user_id);
            $this->assertNull($post->cover_image);
            $this->assertNull(TenantCoverImage::url('covers/'.$beta->id.'/foreign.jpg'));
            $this->assertNull(TenantCoverImage::url('covers/'.$beta->id.'/../../.env'));
        });
    }

    public function test_asset_requests_cannot_read_another_tenants_files(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $marker = 'beta-marker-secret';

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        File::ensureDirectoryExists(storage_path('tenant'.$beta->id.'/app/public'));
        File::put(storage_path('tenant'.$beta->id.'/app/public/secret.txt'), $marker);

        $this->withoutExceptionHandling();

        foreach ([
            '../../../tenant'.$beta->id.'/app/public/secret.txt',
            'secret.txt',
        ] as $relative) {
            try {
                $response = $this->get('http://alpha.localhost/tenancy/assets/'.$relative);
                $this->assertNotSame($marker, $response->getContent());
                $this->fail('The asset route returned a file outside the tenant storage root.');
            } catch (AssertionFailedError $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $this->assertStringNotContainsString($marker, $exception->getMessage());
            }
        }
    }

    public function test_publish_jobs_keep_the_dispatching_tenant_and_do_nothing_without_tenancy(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => config('tenancy.database.central_connection'),
        ]);

        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $alphaWriter = $alpha->run(fn () => Writer::create([
            'name' => 'Ada',
            'email' => 'ada@alpha.test',
            'password' => 'password',
        ]));
        $betaWriter = $beta->run(fn () => Writer::create([
            'name' => 'Bea',
            'email' => 'bea@beta.test',
            'password' => 'password',
        ]));

        $alphaPost = $alpha->run(fn () => Post::forceCreate([
            'user_id' => $alphaWriter->id,
            'title' => 'Alpha due',
            'slug' => 'alpha-due',
            'body' => 'Alpha body',
            'status' => 'scheduled',
            'published_at' => now()->subHour(),
        ]));
        $betaPost = $beta->run(fn () => Post::forceCreate([
            'user_id' => $betaWriter->id,
            'title' => 'Beta due',
            'slug' => 'beta-due',
            'body' => 'Beta body',
            'status' => 'scheduled',
            'published_at' => now()->subHour(),
        ]));

        $this->assertSame($alphaPost->id, $betaPost->id);

        $alpha->run(function () use ($alphaPost) {
            PublishScheduledPost::dispatch($alphaPost->id);
        });

        $central = config('tenancy.database.central_connection');
        $payload = json_decode((string) DB::connection($central)->table('jobs')->value('payload'), true);

        $this->assertSame((string) $alpha->id, $payload['tenant_id'] ?? null);
        $this->assertSame((string) $alpha->id, unserialize($payload['data']['command'])->tenantId);
        $beta->run(fn () => $this->assertFalse(Schema::hasTable('jobs')));

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        (new PublishScheduledPost($alphaPost->id))->handle();

        $alpha->run(fn () => $this->assertSame('scheduled', $alphaPost->fresh()->status));
        $beta->run(fn () => $this->assertSame('scheduled', $betaPost->fresh()->status));

        $queuedJob = unserialize($payload['data']['command']);

        $beta->run(fn () => $queuedJob->handle());

        $alpha->run(fn () => $this->assertSame('scheduled', $alphaPost->fresh()->status));
        $beta->run(fn () => $this->assertSame('scheduled', $betaPost->fresh()->status));

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $queuedJob->handle();

        $alpha->run(fn () => $this->assertSame('published', $alphaPost->fresh()->status));
        $beta->run(fn () => $this->assertSame('scheduled', $betaPost->fresh()->status));
    }

    private function createTenant(string $domain, string $name): Tenant
    {
        $tenant = Tenant::create([
            'name' => $name,
            'brand_color' => '#1d4ed8',
        ]);

        $tenant->createDomain($domain);

        return $tenant;
    }
}
