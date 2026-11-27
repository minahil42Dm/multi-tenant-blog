<?php

namespace Tests\Feature;

use App\Enums\TenantUserRole;
use App\Models\Post;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Writer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
            Tenant::query()->get()->each(function (Tenant $tenant) {
                $id = $tenant->id;
                $tenant->delete();
                File::deleteDirectory(storage_path('tenant'.$id));
            });
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

    public function test_tenant_admin_can_view_edit_and_delete_another_writers_draft(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        [$admin, $post] = $tenant->run(function () {
            $admin = new Writer;
            $admin->forceFill([
                'name' => 'Admin',
                'email' => 'admin@alpha.test',
                'password' => 'password',
                'role' => TenantUserRole::Admin,
            ])->save();

            $owner = Writer::create([
                'name' => 'Owner',
                'email' => 'owner@alpha.test',
                'password' => 'password',
            ]);

            $post = Post::forceCreate([
                'user_id' => $owner->id,
                'title' => 'Private draft',
                'slug' => 'private-draft',
                'body' => 'Draft body',
                'status' => 'draft',
                'created_at' => now()->addMinute(),
            ]);

            Post::forceCreate([
                'user_id' => $owner->id,
                'title' => 'Older note',
                'slug' => 'older-note',
                'body' => 'Older',
                'status' => 'draft',
            ]);

            return [$admin, $post];
        });

        $this->actingAs($admin, 'writer')
            ->get('http://alpha.localhost/posts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Writer/Posts/Index')
                ->has('posts', 2)
                ->where('posts.0.title', 'Private draft')
                ->where('posts.0.can_manage', true)
                ->where('posts.1.can_manage', true));

        $this->actingAs($admin, 'writer')
            ->get('http://alpha.localhost/posts/'.$post->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('post.can_manage', true));

        $this->actingAs($admin, 'writer')
            ->put('http://alpha.localhost/posts/'.$post->id, [
                'title' => 'Edited by admin',
                'body' => 'Updated body',
                'status' => 'draft',
                'cover_image' => UploadedFile::fake()->image('cover.png'),
            ])
            ->assertRedirect();

        $tenant->run(function () use ($tenant, $post) {
            $fresh = $post->fresh();

            $this->assertSame('Edited by admin', $fresh->title);
            $this->assertStringStartsWith('covers/'.$tenant->id.'/', $fresh->cover_image);
            Storage::disk('public')->assertExists($fresh->cover_image);
        });

        $this->actingAs($admin, 'writer')
            ->delete('http://alpha.localhost/posts/'.$post->id)
            ->assertRedirect();

        $tenant->run(fn () => $this->assertNull($post->fresh()));
    }

    public function test_writers_can_only_view_their_own_drafts(): void
    {
        $this->travelTo('2026-11-19 12:00:00');

        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        [$owner, $other, $draft, $published] = $tenant->run(function () {
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
            $draft = Post::forceCreate([
                'user_id' => $owner->id,
                'title' => 'Private draft',
                'slug' => 'private-draft',
                'body' => 'Draft body',
                'status' => 'draft',
            ]);
            $published = Post::forceCreate([
                'user_id' => $owner->id,
                'title' => 'Public story',
                'slug' => 'public-story',
                'body' => 'Published body',
                'status' => 'published',
                'published_at' => '2026-11-18 09:00:00',
            ]);

            return [$owner, $other, $draft, $published];
        });

        $this->actingAs($owner, 'writer')
            ->get('http://alpha.localhost/posts/'.$draft->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Writer/Posts/Show')
                ->where('post.title', 'Private draft'));

        $this->actingAs($owner, 'writer')
            ->get('http://alpha.localhost/posts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('posts', 2));

        $this->actingAs($other, 'writer')
            ->get('http://alpha.localhost/posts/'.$draft->id)
            ->assertForbidden();

        $this->actingAs($other, 'writer')
            ->get('http://alpha.localhost/posts/'.$published->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Writer/Posts/Show')
                ->where('post.title', 'Public story'));

        $this->actingAs($other, 'writer')
            ->get('http://alpha.localhost/posts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('posts', 1)
                ->where('posts.0.title', 'Public story')
                ->where('posts.0.can_manage', false));
    }

    public function test_writers_cannot_view_another_writers_future_dated_post(): void
    {
        $this->travelTo('2026-11-19 12:00:00');

        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        [$other, $post] = $tenant->run(function () {
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
                'title' => 'Embargoed',
                'slug' => 'embargoed',
                'body' => 'Not yet',
                'status' => 'published',
                'published_at' => '2026-11-20 09:00:00',
            ]);

            return [$other, $post];
        });

        $this->actingAs($other, 'writer')
            ->get('http://alpha.localhost/posts/'.$post->id)
            ->assertForbidden();

        $this->travelTo('2026-11-20 09:00:00');

        $this->actingAs($other, 'writer')
            ->get('http://alpha.localhost/posts/'.$post->id)
            ->assertOk();
    }

    public function test_updating_the_title_keeps_the_existing_slug(): void
    {
        $this->travelTo('2026-11-19 12:00:00');

        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $tenant->run(fn () => Writer::create([
            'name' => 'Ada Writer',
            'email' => 'ada@alpha.test',
            'password' => 'password',
        ]));

        $this->actingAs($writer, 'writer')
            ->post('http://alpha.localhost/posts', [
                'title' => 'Original Title',
                'body' => 'Body',
                'status' => 'draft',
            ])
            ->assertRedirect();

        $post = $tenant->run(fn () => Post::query()->firstOrFail());
        $this->assertSame('original-title', $post->slug);

        $this->actingAs($writer, 'writer')
            ->put('http://alpha.localhost/posts/'.$post->id, [
                'title' => 'A Completely New Title',
                'body' => 'Body of the retitled post',
                'status' => 'published',
                'published_at' => '2026-11-18 09:00:00',
            ])
            ->assertRedirect();

        $tenant->run(function () use ($post) {
            $post->refresh();
            $this->assertSame('A Completely New Title', $post->title);
            $this->assertSame('original-title', $post->slug);
        });

        $this->get('http://alpha.localhost/posts/original-title')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Blog/Show')
                ->where('post.slug', 'original-title')
                ->where('post.title', 'A Completely New Title')
                ->where('post.body', 'Body of the retitled post'));

        $this->actingAs($writer, 'writer')
            ->post('http://alpha.localhost/posts', [
                'title' => 'Original Title',
                'body' => 'Another body',
                'status' => 'draft',
            ])
            ->assertRedirect();

        $tenant->run(fn () => $this->assertSame(
            'original-title-2',
            Post::query()->whereKeyNot($post->id)->firstOrFail()->slug
        ));
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
