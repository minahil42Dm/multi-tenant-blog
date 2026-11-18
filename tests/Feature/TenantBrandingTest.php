<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Tenant;
use App\Models\Writer;
use App\Support\TenantBranding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantBrandingTest extends TestCase
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
                if (is_string($tenant->logo) && preg_match('/^tenant-logos\/[A-Za-z0-9][A-Za-z0-9._-]*$/', $tenant->logo) === 1) {
                    Storage::disk('public')->delete($tenant->logo);
                }

                $tenant->delete();
            });
        }

        parent::tearDown();
    }

    public function test_homepage_and_post_page_use_the_current_tenants_branding(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha Blog', '#112233', 'alpha.png');
        $writer = $tenant->run(fn () => Writer::create([
            'name' => 'Ada Writer',
            'email' => 'ada@alpha.test',
            'password' => 'password',
        ]));
        $tenant->run(fn () => $this->makePost($writer));

        $this->get('http://alpha.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Blog/Home')
                ->where('tenantName', 'Alpha Blog')
                ->where('branding.name', 'Alpha Blog')
                ->where('branding.color', '#112233')
                ->where('branding.logo_url', '/storage/tenant-logos/alpha.png')
                ->where('posts.0.title', 'Public story'));

        $this->get('http://alpha.localhost/posts/public-story')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Blog/Show')
                ->where('branding.name', 'Alpha Blog')
                ->where('branding.color', '#112233')
                ->where('branding.logo_url', '/storage/tenant-logos/alpha.png')
                ->where('post.title', 'Public story'));
    }

    public function test_each_tenant_displays_only_its_own_branding(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha Blog', '#112233', 'alpha.png');
        $beta = $this->createTenant('beta.localhost', 'Beta Blog', '#abcdef', 'beta.png');

        $alpha->run(fn () => $this->makePost(Writer::create([
            'name' => 'Ada Writer',
            'email' => 'ada@alpha.test',
            'password' => 'password',
        ])));

        $home = $this->get('http://alpha.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('branding.name', 'Alpha Blog')
                ->where('branding.color', '#112233')
                ->where('branding.logo_url', '/storage/tenant-logos/alpha.png'));

        $home->assertDontSee('Beta Blog', false);
        $home->assertDontSee('#abcdef', false);
        $home->assertDontSee('/storage/tenant-logos/beta.png', false);

        $this->flushSession();

        $this->get('http://beta.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('branding.name', 'Beta Blog')
                ->where('branding.color', '#abcdef')
                ->where('branding.logo_url', '/storage/tenant-logos/beta.png')
                ->has('posts', 0));
    }

    public function test_missing_or_unsafe_branding_uses_defaults(): void
    {
        $plain = Tenant::create([
            'name' => 'Plain Blog',
        ]);
        $plain->createDomain('plain.localhost');

        $unsafe = Tenant::create([
            'name' => '   ',
            'brand_color' => 'red;background:url(https://evil.test)',
            'logo' => '../.env',
        ]);
        $unsafe->createDomain('unsafe.localhost');

        $this->get('http://plain.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('branding.name', 'Plain Blog')
                ->where('branding.color', TenantBranding::DEFAULT_COLOR)
                ->where('branding.logo_url', null));

        $this->flushSession();

        $response = $this->get('http://unsafe.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('branding.name', TenantBranding::DEFAULT_NAME)
                ->where('branding.color', TenantBranding::DEFAULT_COLOR)
                ->where('branding.logo_url', null));

        $response->assertDontSee('evil.test', false);
        $response->assertDontSee('.env', false);
    }

    private function createTenant(string $domain, string $name, string $color, string $logo): Tenant
    {
        Storage::disk('public')->put('tenant-logos/'.$logo, 'logo');

        $tenant = Tenant::create([
            'name' => $name,
            'brand_color' => $color,
            'logo' => 'tenant-logos/'.$logo,
        ]);

        $tenant->createDomain($domain);

        return $tenant;
    }

    private function makePost(Writer $writer): Post
    {
        return Post::forceCreate([
            'user_id' => $writer->id,
            'title' => 'Public story',
            'slug' => 'public-story',
            'body' => 'Visible body.',
            'status' => 'published',
            'published_at' => '2026-11-18 09:00:00',
        ]);
    }
}
