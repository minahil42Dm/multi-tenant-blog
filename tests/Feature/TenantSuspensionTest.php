<?php

namespace Tests\Feature;

use App\Jobs\PublishScheduledPost;
use App\Models\Post;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Writer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantSuspensionTest extends TestCase
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

    public function test_suspended_tenant_pages_show_the_suspended_page(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($alpha);
        $alpha->run(fn () => Post::factory()->published()->for($writer, 'writer')->create(['title' => 'Hello Alpha']));
        $alpha->suspend();

        foreach (['/', '/posts/hello-alpha', '/login', '/dashboard', '/posts'] as $path) {
            $this->get('http://alpha.localhost'.$path)
                ->assertStatus(503)
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Blog/Suspended')
                    ->where('tenantName', 'Alpha')
                    ->where('auth.user', null)
                    ->missing('posts')
                    ->missing('post'));
        }

        $this->post('http://alpha.localhost/login', [
            'email' => 'ada@alpha.test',
            'password' => 'password',
        ])->assertStatus(503);

        $this->assertGuest('writer');
    }

    public function test_suspended_tenant_cover_assets_are_blocked(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $alpha->suspend();

        $this->get('http://alpha.localhost/tenancy/assets/covers/'.$alpha->id.'/cover.jpg')
            ->assertStatus(503);
    }

    public function test_signed_in_writer_is_logged_out_when_tenant_is_suspended(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($alpha);

        $this->actingAs($writer, 'writer')
            ->get('http://alpha.localhost/dashboard')
            ->assertOk();

        $alpha->suspend();

        $this->get('http://alpha.localhost/dashboard')
            ->assertStatus(503)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Blog/Suspended')
                ->where('auth.user', null));

        $this->assertGuest('writer');

        $alpha->reactivate();

        $this->get('http://alpha.localhost/dashboard')
            ->assertRedirect('http://alpha.localhost/login');
    }

    public function test_other_active_tenants_keep_working(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $betaWriter = $this->writer($beta, 'grace@beta.test', 'Grace Writer');
        $beta->run(fn () => Post::factory()->published()->for($betaWriter, 'writer')->create(['title' => 'Hello Beta']));
        $alpha->suspend();

        $this->get('http://beta.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Blog/Home')
                ->has('posts', 1));

        $this->get('http://beta.localhost/posts/hello-beta')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Blog/Show'));

        $this->post('http://beta.localhost/login', [
            'email' => 'grace@beta.test',
            'password' => 'password',
        ])->assertRedirect('http://beta.localhost/dashboard');

        $this->assertAuthenticatedAs($betaWriter, 'writer');
    }

    public function test_reactivation_restores_public_pages_and_writer_login(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $writer = $this->writer($alpha);
        $alpha->run(fn () => Post::factory()->published()->for($writer, 'writer')->create(['title' => 'Hello Alpha']));

        $alpha->suspend();
        $this->get('http://alpha.localhost/')->assertStatus(503);

        $alpha->reactivate();

        $this->get('http://alpha.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Blog/Home')
                ->has('posts', 1));

        $this->get('http://alpha.localhost/posts/hello-alpha')->assertOk();
        $this->get('http://alpha.localhost/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Writer/Auth/Login'));

        $this->post('http://alpha.localhost/login', [
            'email' => 'ada@alpha.test',
            'password' => 'password',
        ])->assertRedirect('http://alpha.localhost/dashboard');

        $this->assertAuthenticatedAs($writer, 'writer');
    }

    public function test_publish_due_command_skips_suspended_tenants(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $alphaPost = $this->duePost($alpha, 'ada@alpha.test');
        $betaPost = $this->duePost($beta, 'grace@beta.test');
        $alpha->suspend();

        $this->artisan('posts:publish-due')->assertSuccessful();

        $alpha->run(fn () => $this->assertSame('scheduled', $alphaPost->fresh()->status));
        $beta->run(fn () => $this->assertSame('published', $betaPost->fresh()->status));

        $alpha->reactivate();

        $this->artisan('posts:publish-due')->assertSuccessful();

        $alpha->run(fn () => $this->assertSame('published', $alphaPost->fresh()->status));
    }

    public function test_publish_due_command_does_not_dispatch_jobs_for_suspended_tenants(): void
    {
        Queue::fake();

        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $this->duePost($alpha, 'ada@alpha.test');
        $betaPost = $this->duePost($beta, 'grace@beta.test');
        $alpha->suspend();

        $this->artisan('posts:publish-due')->assertSuccessful();

        Queue::assertPushed(PublishScheduledPost::class, 1);
        Queue::assertPushed(
            PublishScheduledPost::class,
            fn (PublishScheduledPost $job) => $job->tenantId === (string) $beta->id && $job->postId === $betaPost->id
        );
    }

    public function test_publish_job_does_not_publish_for_a_suspended_tenant(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $post = $this->duePost($alpha, 'ada@alpha.test');
        $job = new PublishScheduledPost($post->id, (string) $alpha->id);

        $alpha->suspend();

        $job->handle();
        $this->assertFalse(tenancy()->initialized);
        $alpha->run(fn () => $this->assertSame('scheduled', $post->fresh()->status));

        $alpha->run(fn () => PublishScheduledPost::dispatchSync($post->id));
        $alpha->run(fn () => $this->assertSame('scheduled', $post->fresh()->status));

        $alpha->reactivate();

        $job->handle();
        $alpha->run(fn () => $this->assertSame('published', $post->fresh()->status));
    }

    public function test_writers_cannot_be_created_for_a_suspended_tenant(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = User::factory()->superAdmin()->create();
        $alpha->suspend();

        $this->actingAs($admin)
            ->get(route('admin.tenants.writers.create', $alpha))
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.tenants.writers.store', $alpha), $this->writerData())
            ->assertForbidden();

        $alpha->run(fn () => $this->assertSame(0, Writer::query()->count()));

        $this->actingAs($admin)
            ->get(route('admin.tenants.writers.index', $alpha))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tenant.is_suspended', true));

        $alpha->reactivate();

        $this->actingAs($admin)
            ->post(route('admin.tenants.writers.store', $alpha), $this->writerData())
            ->assertRedirect(route('admin.tenants.writers.index', $alpha));

        $alpha->run(fn () => $this->assertSame(1, Writer::query()->count()));
    }

    public function test_writer_creation_for_active_tenants_is_unaffected_by_another_suspension(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $admin = User::factory()->superAdmin()->create();
        $alpha->suspend();

        $this->actingAs($admin)
            ->get(route('admin.tenants.writers.create', $beta))
            ->assertOk();

        $this->actingAs($admin)
            ->post(route('admin.tenants.writers.store', $beta), $this->writerData(['email' => 'grace@beta.test']))
            ->assertRedirect(route('admin.tenants.writers.index', $beta));

        $beta->run(fn () => $this->assertSame(1, Writer::query()->count()));
        $alpha->run(fn () => $this->assertSame(0, Writer::query()->count()));
    }

    public function test_support_users_still_get_the_normal_forbidden_response(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $support = User::factory()->support()->create();
        $alpha->suspend();

        $this->actingAs($support)
            ->post(route('admin.tenants.writers.store', $alpha), $this->writerData())
            ->assertForbidden();

        $alpha->run(fn () => $this->assertSame(0, Writer::query()->count()));
    }

    private function duePost(Tenant $tenant, string $email): Post
    {
        $writer = $this->writer($tenant, $email);

        return $tenant->run(fn () => Post::factory()->for($writer, 'writer')->create([
            'status' => 'scheduled',
            'published_at' => now()->subMinute(),
        ]));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function writerData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Writer',
            'email' => 'ada@alpha.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ], $overrides);
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
}
