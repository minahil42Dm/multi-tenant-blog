<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Tenant;
use App\Models\Writer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CoverImageTest extends TestCase
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

    public function test_writer_can_upload_a_cover_image_into_the_tenant_directory(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $writer = $this->writer($alpha, 'ada@alpha.test');

        $this->actingAs($writer, 'writer')
            ->post('http://alpha.localhost/posts', [
                'title' => 'With cover',
                'body' => 'A public story.',
                'status' => 'published',
                'published_at' => '2026-11-18 09:00:00',
                'cover_image' => UploadedFile::fake()->image('cover.jpg', 40, 40),
                'user_id' => 999,
            ])
            ->assertRedirect();

        $path = $alpha->run(function () use ($alpha, $writer) {
            $post = Post::query()->first();

            $this->assertNotNull($post);
            $this->assertSame($writer->id, $post->user_id);
            $this->assertStringStartsWith('covers/'.$alpha->id.'/', $post->cover_image);
            $this->assertStringNotContainsString('cover.jpg', $post->cover_image);
            $this->assertTrue(Storage::disk('public')->exists($post->cover_image));

            return $post->cover_image;
        });

        $this->assertFileExists(storage_path('tenant'.$alpha->id.'/app/public/'.$path));
        $this->assertFileDoesNotExist(storage_path('app/public/'.$path));

        $beta->run(fn () => $this->assertFalse(Storage::disk('public')->exists($path)));
        $beta->run(fn () => $this->assertSame(0, Post::query()->count()));

        $this->get('http://alpha.localhost/tenancy/assets/'.$path)
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        $other = $this->get('http://beta.localhost/tenancy/assets/'.$path);
        $this->assertNotSame(200, $other->status());

        $this->get('http://alpha.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Blog/Home')
                ->where('posts.0.cover_url', fn ($url) => str_contains((string) $url, '/tenancy/assets/'.$path)));

        $this->get('http://alpha.localhost/posts/with-cover')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Blog/Show')
                ->where('post.cover_url', fn ($url) => str_contains((string) $url, '/tenancy/assets/'.$path)));
    }

    public function test_invalid_and_oversized_images_are_rejected(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant, 'ada@alpha.test');

        $this->actingAs($writer, 'writer')
            ->post('http://alpha.localhost/posts', [
                'title' => 'Bad file',
                'body' => 'Body',
                'status' => 'draft',
                'cover_image' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
            ])
            ->assertSessionHasErrors('cover_image');

        $this->actingAs($writer, 'writer')
            ->post('http://alpha.localhost/posts', [
                'title' => 'Too large',
                'body' => 'Body',
                'status' => 'draft',
                'cover_image' => UploadedFile::fake()->image('big.jpg')->size(2049),
            ])
            ->assertSessionHasErrors('cover_image');

        $tenant->run(fn () => $this->assertSame(0, Post::query()->count()));
        $this->assertDirectoryDoesNotExist(storage_path('tenant'.$tenant->id.'/app/public/covers'));
    }

    public function test_writer_can_replace_and_remove_their_cover_image(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant, 'ada@alpha.test');

        $this->actingAs($writer, 'writer')
            ->post('http://alpha.localhost/posts', [
                'title' => 'Replace me',
                'body' => 'Body',
                'status' => 'draft',
                'cover_image' => UploadedFile::fake()->image('first.jpg'),
            ])
            ->assertRedirect();

        $original = $tenant->run(fn () => Post::query()->value('cover_image'));
        $postId = $tenant->run(fn () => Post::query()->value('id'));

        $this->actingAs($writer, 'writer')
            ->put('http://alpha.localhost/posts/'.$postId, [
                'title' => 'Replace me',
                'body' => 'Body',
                'status' => 'draft',
                'cover_image' => UploadedFile::fake()->image('second.png'),
            ])
            ->assertRedirect();

        $replacement = $tenant->run(function () use ($original) {
            $post = Post::query()->first();

            $this->assertNotSame($original, $post->cover_image);
            $this->assertStringEndsWith('.png', $post->cover_image);
            $this->assertFalse(Storage::disk('public')->exists($original));
            $this->assertTrue(Storage::disk('public')->exists($post->cover_image));

            return $post->cover_image;
        });

        $this->actingAs($writer, 'writer')
            ->put('http://alpha.localhost/posts/'.$postId, [
                'title' => 'Replace me',
                'body' => 'Body',
                'status' => 'draft',
                'remove_cover_image' => '1',
            ])
            ->assertRedirect();

        $tenant->run(function () use ($replacement) {
            $this->assertNull(Post::query()->value('cover_image'));
            $this->assertFalse(Storage::disk('public')->exists($replacement));
        });
    }

    public function test_another_writer_cannot_change_a_cover_image(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $owner = $this->writer($tenant, 'owner@alpha.test');
        $other = $this->writer($tenant, 'other@alpha.test', 'Other');

        $this->actingAs($owner, 'writer')
            ->post('http://alpha.localhost/posts', [
                'title' => 'Owned',
                'body' => 'Mine',
                'status' => 'draft',
                'cover_image' => UploadedFile::fake()->image('owned.jpg'),
            ])
            ->assertRedirect();

        $original = $tenant->run(fn () => Post::query()->value('cover_image'));
        $postId = $tenant->run(fn () => Post::query()->value('id'));

        $this->actingAs($other, 'writer')
            ->put('http://alpha.localhost/posts/'.$postId, [
                'title' => 'Stolen',
                'body' => 'Stolen',
                'status' => 'draft',
                'cover_image' => UploadedFile::fake()->image('stolen.jpg'),
                'remove_cover_image' => '1',
            ])
            ->assertForbidden();

        $tenant->run(function () use ($original) {
            $this->assertSame($original, Post::query()->value('cover_image'));
            $this->assertTrue(Storage::disk('public')->exists($original));
        });
    }

    public function test_posts_without_images_still_work(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($tenant, 'ada@alpha.test');

        $this->actingAs($writer, 'writer')
            ->post('http://alpha.localhost/posts', [
                'title' => 'Plain post',
                'body' => 'No picture.',
                'status' => 'published',
                'published_at' => '2026-11-18 09:00:00',
                'cover_image' => 'covers/'.$tenant->id.'/supplied.jpg',
            ])
            ->assertRedirect();

        $tenant->run(fn () => $this->assertNull(Post::query()->value('cover_image')));

        $this->get('http://alpha.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('posts.0.title', 'Plain post')
                ->where('posts.0.cover_url', null));

        $this->get('http://alpha.localhost/posts/plain-post')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('post.cover_url', null));
    }

    public function test_guests_cannot_upload_cover_images(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        $this->post('http://alpha.localhost/posts', [
            'title' => 'Nope',
            'body' => 'Nope',
            'status' => 'draft',
            'cover_image' => UploadedFile::fake()->image('cover.jpg'),
        ])->assertRedirect(route('writer.login', absolute: false));

        $this->assertDirectoryDoesNotExist(storage_path('tenant'.$tenant->id.'/app/public/covers'));
    }

    private function createTenant(string $domain, string $name): Tenant
    {
        $tenant = Tenant::create([
            'name' => $name,
        ]);

        $tenant->createDomain($domain);

        return $tenant;
    }

    private function writer(Tenant $tenant, string $email, string $name = 'Ada Writer'): Writer
    {
        return $tenant->run(fn () => Writer::create([
            'name' => $name,
            'email' => $email,
            'password' => 'password',
        ]));
    }
}
