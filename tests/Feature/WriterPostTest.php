<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Writer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WriterPostTest extends TestCase
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

    public function test_writer_can_create_view_update_and_delete_posts(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $tenant->run(fn () => Writer::create([
            'name' => 'Ada Writer',
            'email' => 'ada@alpha.test',
            'password' => 'password',
        ]));

        $this->actingAs($writer, 'writer')
            ->post('http://alpha.localhost/posts', [
                'title' => 'Hello Alpha',
                'body' => 'First post',
                'status' => 'draft',
                'user_id' => 999,
                'cover_image' => 'hack.jpg',
            ])
            ->assertRedirect();

        $post = $tenant->run(fn () => Post::query()->first());

        $this->assertNotNull($post);
        $this->assertSame($writer->id, $post->user_id);
        $this->assertSame('hello-alpha', $post->slug);
        $this->assertSame('draft', $post->status);
        $this->assertNull($post->published_at);
        $this->assertNull($post->cover_image);
        $this->assertFalse(Schema::hasTable('posts'));

        $this->actingAs($writer, 'writer')
            ->get('http://alpha.localhost/posts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Writer/Posts/Index')
                ->has('posts', 1)
                ->where('posts.0.title', 'Hello Alpha'));

        $this->actingAs($writer, 'writer')
            ->get('http://alpha.localhost/posts/'.$post->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Writer/Posts/Show')
                ->where('post.body', 'First post'));

        $this->actingAs($writer, 'writer')
            ->put('http://alpha.localhost/posts/'.$post->id, [
                'title' => 'Hello Alpha',
                'body' => 'Updated body',
                'status' => 'published',
                'published_at' => '2026-11-18 16:00:00',
            ])
            ->assertRedirect();

        $tenant->run(function () use ($post) {
            $post->refresh();
            $this->assertSame('Updated body', $post->body);
            $this->assertSame('published', $post->status);
            $this->assertNotNull($post->published_at);
        });

        $this->actingAs($writer, 'writer')
            ->delete('http://alpha.localhost/posts/'.$post->id)
            ->assertRedirect(route('writer.posts.index', absolute: false));

        $tenant->run(fn () => $this->assertSame(0, Post::query()->count()));
    }

    public function test_guests_cannot_manage_posts(): void
    {
        $this->createTenant('alpha.localhost', 'Alpha');

        $this->get('http://alpha.localhost/posts')
            ->assertRedirect(route('writer.login', absolute: false));
        $this->get('http://alpha.localhost/posts/create')
            ->assertRedirect(route('writer.login', absolute: false));
        $this->post('http://alpha.localhost/posts', [
            'title' => 'Nope',
            'body' => 'Nope',
            'status' => 'draft',
        ])->assertRedirect(route('writer.login', absolute: false));
    }

    public function test_writer_cannot_access_another_tenants_posts(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');

        $writer = $alpha->run(fn () => Writer::create([
            'name' => 'Ada Writer',
            'email' => 'ada@alpha.test',
            'password' => 'password',
        ]));

        $post = $alpha->run(fn () => Post::forceCreate([
            'user_id' => $writer->id,
            'title' => 'Alpha only',
            'slug' => 'alpha-only',
            'body' => 'Secret',
            'status' => 'draft',
        ]));

        $login = $this->post('http://alpha.localhost/login', [
            'email' => 'ada@alpha.test',
            'password' => 'password',
        ]);

        $cookie = collect($login->headers->getCookies())->first(
            fn ($cookie) => $cookie->getName() === config('session.cookie')
        );

        $this->assertNotNull($cookie);

        $this->disableCookieEncryption()
            ->withCookie($cookie->getName(), $cookie->getValue())
            ->get('http://beta.localhost/posts/'.$post->id)
            ->assertForbidden();

        $this->disableCookieEncryption()
            ->withCookie($cookie->getName(), $cookie->getValue())
            ->put('http://beta.localhost/posts/'.$post->id, [
                'title' => 'Stolen',
                'body' => 'Stolen',
                'status' => 'published',
                'published_at' => '2026-11-18 16:00:00',
            ])
            ->assertForbidden();

        $this->disableCookieEncryption()
            ->withCookie($cookie->getName(), $cookie->getValue())
            ->delete('http://beta.localhost/posts/'.$post->id)
            ->assertForbidden();

        $beta->run(fn () => $this->assertSame(0, Post::query()->count()));
        $alpha->run(fn () => $this->assertSame('Alpha only', $post->fresh()->title));
    }

    public function test_writer_cannot_modify_another_writers_post(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        [$owner, $other, $post] = $tenant->run(function () {
            $owner = Writer::create([
                'name' => 'Owner',
                'email' => 'owner@alpha.test',
                'password' => 'password',
            ]);
            $other = Writer::create([
                'name' => 'Other',
                'email' => 'other@alpha.test',
                'password' => 'password',
            ]);
            $post = Post::forceCreate([
                'user_id' => $owner->id,
                'title' => 'Owned',
                'slug' => 'owned',
                'body' => 'Mine',
                'status' => 'draft',
            ]);

            return [$owner, $other, $post];
        });

        $this->actingAs($other, 'writer')
            ->put('http://alpha.localhost/posts/'.$post->id, [
                'title' => 'Changed',
                'body' => 'Changed',
                'status' => 'draft',
            ])
            ->assertForbidden();

        $this->actingAs($other, 'writer')
            ->delete('http://alpha.localhost/posts/'.$post->id)
            ->assertForbidden();

        $tenant->run(fn () => $this->assertSame('Owned', $post->fresh()->title));
    }

    public function test_invalid_post_data_is_rejected(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $tenant->run(fn () => Writer::create([
            'name' => 'Ada Writer',
            'email' => 'ada@alpha.test',
            'password' => 'password',
        ]));

        $this->actingAs($writer, 'writer')
            ->post('http://alpha.localhost/posts', [
                'title' => '',
                'body' => '',
                'status' => 'archived',
            ])
            ->assertSessionHasErrors(['title', 'body', 'status']);

        $this->actingAs($writer, 'writer')
            ->post('http://alpha.localhost/posts', [
                'title' => 'Scheduled',
                'body' => 'Later',
                'status' => 'scheduled',
            ])
            ->assertSessionHasErrors(['published_at']);

        $tenant->run(fn () => $this->assertSame(0, Post::query()->count()));
    }

    public function test_central_admin_authentication_still_works(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@central.test',
        ]);

        $this->post('http://localhost/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->get('http://localhost/admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard'));

        $this->assertFalse(Schema::hasTable('posts'));
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
