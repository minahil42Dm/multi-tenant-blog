<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Writer;
use App\Support\TenantBranding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class TenantBrandingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY_LOGOS = [
        'tenant-logos/branding-test-alpha.png',
        'tenant-logos/branding-test-beta.png',
    ];

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
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        if (Schema::hasTable('tenants')) {
            Tenant::query()->get()->each(function (Tenant $tenant) {
                $storage = storage_path(config('tenancy.filesystem.suffix_base').$tenant->id);
                $tenant->delete();
                File::deleteDirectory($storage);
            });
        }

        Storage::disk('public')->delete(self::LEGACY_LOGOS);
        Storage::disk('public')->deleteDirectory('branding-test-shared');

        parent::tearDown();
    }

    public function test_super_admin_can_view_the_branding_settings(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha', [
            'brand_color' => '#112233',
            'accent_color' => '#445566',
            'support_email' => 'help@alpha.test',
            'sidebar_style' => 'logo',
            'sidebar_tagline' => 'Stories from Alpha',
            'footer_links' => [['label' => 'About', 'url' => 'https://alpha.test/about']],
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.tenants.branding.edit', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tenants/Branding')
                ->where('tenant.id', $tenant->id)
                ->where('tenant.domain', 'alpha.localhost')
                ->where('branding.name', 'Alpha')
                ->where('branding.brand_color', '#112233')
                ->where('branding.accent_color', '#445566')
                ->where('branding.support_email', 'help@alpha.test')
                ->where('branding.sidebar_style', 'logo')
                ->where('branding.sidebar_tagline', 'Stories from Alpha')
                ->where('branding.footer_links', [['label' => 'About', 'url' => 'https://alpha.test/about']])
                ->where('branding.logo_url', null)
                ->where('branding.favicon_url', null)
                ->where('options.max_footer_links', 5)
                ->where('auth.can.manageTenants', true));
    }

    public function test_support_can_view_branding_but_not_change_it(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha', ['brand_color' => '#112233']);
        $support = User::factory()->support()->create();

        $this->actingAs($support)
            ->get(route('admin.tenants.branding.edit', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('branding.name', 'Alpha')
                ->where('auth.can.manageTenants', false));

        $this->actingAs($support)
            ->put(route('admin.tenants.branding.update', $tenant), $this->payload([
                'name' => 'Hijacked',
                'logo' => UploadedFile::fake()->image('logo.png'),
            ]))
            ->assertForbidden();

        $tenant->refresh();
        $this->assertSame('Alpha', $tenant->name);
        $this->assertSame('#112233', $tenant->brand_color);
        $this->assertNull($tenant->logo);
        $this->assertSame([], $tenant->run(fn () => Storage::disk('public')->allFiles()));
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_guests_are_redirected_to_the_central_login(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        $this->get(route('admin.tenants.branding.edit', $tenant))->assertRedirect(route('login'));
        $this->put(route('admin.tenants.branding.update', $tenant), $this->payload())->assertRedirect(route('login'));
        $this->get(route('admin.tenants.branding.asset', [$tenant, 'logo']))->assertRedirect(route('login'));

        $this->assertSame('Alpha', $tenant->refresh()->name);
    }

    public function test_tenant_users_cannot_reach_central_branding_management(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $tenant->run(fn () => Writer::factory()->create(['email' => 'ada@alpha.test']));

        $this->post('http://alpha.localhost/login', ['email' => 'ada@alpha.test', 'password' => 'password']);
        $this->assertAuthenticated('writer');

        $this->get(route('admin.tenants.branding.edit', $tenant))->assertRedirect(route('login'));
        $this->put(route('admin.tenants.branding.update', $tenant), $this->payload(['name' => 'Writer edit']))
            ->assertRedirect(route('login'));
        $this->get('http://alpha.localhost/admin/tenants/'.$tenant->id.'/branding')->assertNotFound();

        $this->assertSame('Alpha', $tenant->refresh()->name);
    }

    public function test_super_admin_can_update_every_branding_setting(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $tenant->suspend();
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->put(route('admin.tenants.branding.update', $tenant), [
                'name' => '  Alpha Journal ',
                'brand_color' => '#ABCDEF',
                'accent_color' => ' #FF0066 ',
                'support_email' => ' Help@Alpha.TEST ',
                'sidebar_style' => 'name',
                'sidebar_tagline' => 'Fresh stories',
                'footer_links' => [
                    ['label' => 'About', 'url' => 'https://alpha.test/about'],
                    ['label' => '', 'url' => ''],
                    ['label' => 'Privacy', 'url' => 'http://alpha.test/privacy'],
                ],
                'logo' => UploadedFile::fake()->image('logo.png', 120, 120),
                'favicon' => UploadedFile::fake()->image('favicon.png', 32, 32),
                'status' => 'active',
                'id' => 'hijacked',
            ])
            ->assertRedirect(route('admin.tenants.branding.edit', $tenant))
            ->assertSessionHas('success', 'Branding for Alpha Journal has been updated.');

        $tenant->refresh();
        $this->assertSame('Alpha Journal', $tenant->name);
        $this->assertSame('#abcdef', $tenant->brand_color);
        $this->assertSame('#ff0066', $tenant->accent_color);
        $this->assertSame('help@alpha.test', $tenant->support_email);
        $this->assertSame('name', $tenant->sidebar_style);
        $this->assertSame('Fresh stories', $tenant->sidebar_tagline);
        $this->assertSame([
            ['label' => 'About', 'url' => 'https://alpha.test/about'],
            ['label' => 'Privacy', 'url' => 'http://alpha.test/privacy'],
        ], $tenant->footer_links);
        $this->assertSame(TenantStatus::Suspended, $tenant->status);
        $this->assertNull(Tenant::find('hijacked'));

        foreach (['logo', 'favicon'] as $asset) {
            $this->assertTrue(TenantBranding::isTenantAsset($tenant->{$asset}, $tenant->id));
            Storage::disk('public')->assertMissing($tenant->{$asset});
            $tenant->run(fn () => Storage::disk('public')->assertExists($tenant->{$asset}));
        }

        $log = AuditLog::query()->where('action', 'tenant.branding_updated')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertEqualsCanonicalizing(
            ['name', 'brand_color', 'accent_color', 'support_email', 'sidebar_style', 'sidebar_tagline', 'footer_links', 'logo', 'favicon'],
            $log->metadata['changed'],
        );
    }

    public function test_replacing_files_deletes_the_old_ones_only_after_the_new_ones_are_saved(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->put(route('admin.tenants.branding.update', $tenant), $this->payload([
            'logo' => UploadedFile::fake()->image('first.png'),
            'favicon' => UploadedFile::fake()->image('first-icon.png', 32, 32),
        ]))->assertSessionHasNoErrors();

        $first = $tenant->refresh()->only(['logo', 'favicon']);

        $this->actingAs($admin)->put(route('admin.tenants.branding.update', $tenant), $this->payload([
            'logo' => UploadedFile::fake()->image('second.png'),
        ]))->assertSessionHasNoErrors();

        $tenant->refresh();
        $this->assertNotSame($first['logo'], $tenant->logo);
        $this->assertSame($first['favicon'], $tenant->favicon);

        $tenant->run(function () use ($first, $tenant) {
            Storage::disk('public')->assertMissing($first['logo']);
            Storage::disk('public')->assertExists($tenant->logo);
            Storage::disk('public')->assertExists($first['favicon']);
        });

        $this->actingAs($admin)->put(route('admin.tenants.branding.update', $tenant), $this->payload([
            'remove_favicon' => '1',
        ]))->assertSessionHasNoErrors();

        $this->assertNull($tenant->refresh()->favicon);
        $tenant->run(fn () => Storage::disk('public')->assertMissing($first['favicon']));
    }

    public function test_replacing_a_legacy_logo_moves_it_into_tenant_storage(): void
    {
        Storage::disk('public')->put(self::LEGACY_LOGOS[0], 'legacy');
        $tenant = $this->createTenant('alpha.localhost', 'Alpha', ['logo' => self::LEGACY_LOGOS[0]]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->put(route('admin.tenants.branding.update', $tenant), $this->payload([
                'logo' => UploadedFile::fake()->image('new.png'),
            ]))
            ->assertSessionHasNoErrors();

        $this->assertStringStartsWith('branding/'.$tenant->id.'/', $tenant->refresh()->logo);
        Storage::disk('public')->assertMissing(self::LEGACY_LOGOS[0]);
    }

    public function test_logos_outside_the_managed_folders_are_never_deleted(): void
    {
        Storage::disk('public')->put('branding-test-shared/brand.png', 'shared');
        $tenant = $this->createTenant('alpha.localhost', 'Alpha', ['logo' => 'branding-test-shared/brand.png']);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->put(route('admin.tenants.branding.update', $tenant), $this->payload([
                'logo' => UploadedFile::fake()->image('new.png'),
            ]))
            ->assertSessionHasNoErrors();

        Storage::disk('public')->assertExists('branding-test-shared/brand.png');
    }

    public function test_saving_without_files_keeps_the_current_logo_and_favicon(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->put(route('admin.tenants.branding.update', $tenant), $this->payload([
            'logo' => UploadedFile::fake()->image('logo.png'),
            'favicon' => UploadedFile::fake()->create('favicon.ico', 4, 'image/x-icon'),
        ]))->assertSessionHasNoErrors();

        $files = $tenant->refresh()->only(['logo', 'favicon']);
        $this->assertStringEndsWith('.ico', $files['favicon']);

        $this->actingAs($admin)
            ->put(route('admin.tenants.branding.update', $tenant), $this->payload(['name' => 'Renamed']))
            ->assertSessionHasNoErrors();

        $this->assertSame($files, $tenant->refresh()->only(['logo', 'favicon']));
        $tenant->run(fn () => Storage::disk('public')->assertExists(array_values($files)));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<int, string>}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'colors' => [['brand_color' => 'blue', 'accent_color' => '#12345'], ['brand_color', 'accent_color']],
            'missing name' => [['name' => ''], ['name']],
            'sidebar style' => [['sidebar_style' => 'floating'], ['sidebar_style']],
            'support email' => [['support_email' => 'not-an-email'], ['support_email']],
            'long tagline' => [['sidebar_tagline' => str_repeat('a', 81)], ['sidebar_tagline']],
            'javascript footer link' => [['footer_links' => [['label' => 'Bad', 'url' => 'javascript:alert(1)']]], ['footer_links.0.url']],
            'footer link without label' => [['footer_links' => [['label' => '', 'url' => 'https://alpha.test']]], ['footer_links.0.label']],
            'too many footer links' => [['footer_links' => array_fill(0, 6, ['label' => 'Link', 'url' => 'https://alpha.test'])], ['footer_links']],
        ];
    }

    /**
     * @dataProvider invalidPayloads
     *
     * @param  array<string, mixed>  $overrides
     * @param  array<int, string>  $errors
     */
    public function test_invalid_settings_are_rejected(array $overrides, array $errors): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha', ['brand_color' => '#112233']);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->from(route('admin.tenants.branding.edit', $tenant))
            ->put(route('admin.tenants.branding.update', $tenant), $this->payload($overrides))
            ->assertRedirect(route('admin.tenants.branding.edit', $tenant))
            ->assertSessionHasErrors($errors);

        $this->assertSame('Alpha', $tenant->refresh()->name);
        $this->assertSame('#112233', $tenant->brand_color);
    }

    public function test_logo_and_favicon_files_are_validated(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = User::factory()->superAdmin()->create();

        $cases = [
            ['logo', UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml')],
            ['logo', UploadedFile::fake()->create('logo.pdf', 4, 'application/pdf')],
            ['logo', UploadedFile::fake()->image('logo.png')->size(2049)],
            ['logo', UploadedFile::fake()->image('wide.png', 4097, 10)],
            ['favicon', UploadedFile::fake()->create('favicon.svg', 2, 'image/svg+xml')],
            ['favicon', UploadedFile::fake()->image('favicon.jpg', 32, 32)],
            ['favicon', UploadedFile::fake()->image('favicon.png', 32, 32)->size(513)],
        ];

        foreach ($cases as [$field, $file]) {
            $this->actingAs($admin)
                ->put(route('admin.tenants.branding.update', $tenant), $this->payload([$field => $file]))
                ->assertSessionHasErrors($field);
        }

        $tenant->refresh();
        $this->assertNull($tenant->logo);
        $this->assertNull($tenant->favicon);
        $this->assertSame([], $tenant->run(fn () => Storage::disk('public')->allFiles()));
    }

    public function test_a_failed_upload_changes_nothing(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $path = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($path, 'partial');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->from(route('admin.tenants.branding.edit', $tenant))
            ->put(route('admin.tenants.branding.update', $tenant), $this->payload([
                'name' => 'Should not save',
                'logo' => new UploadedFile($path, 'logo.png', 'image/png', UPLOAD_ERR_PARTIAL, true),
            ]))
            ->assertRedirect(route('admin.tenants.branding.edit', $tenant))
            ->assertSessionHasErrors(['logo' => 'The logo failed to upload. Please try again.']);

        $this->assertSame('Alpha', $tenant->refresh()->name);
        $this->assertNull($tenant->logo);
        @unlink($path);
    }

    public function test_a_failed_save_removes_the_new_files_and_keeps_the_old_ones(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->put(route('admin.tenants.branding.update', $tenant), $this->payload([
            'logo' => UploadedFile::fake()->image('old.png'),
        ]))->assertSessionHasNoErrors();

        $oldLogo = $tenant->refresh()->logo;

        $failing = true;
        Tenant::saving(function () use (&$failing) {
            if ($failing) {
                throw new RuntimeException('Central database unavailable.');
            }
        });

        $this->actingAs($admin)
            ->from(route('admin.tenants.branding.edit', $tenant))
            ->put(route('admin.tenants.branding.update', $tenant), $this->payload([
                'name' => 'Should not save',
                'logo' => UploadedFile::fake()->image('new.png'),
                'favicon' => UploadedFile::fake()->image('new-icon.png', 32, 32),
            ]))
            ->assertRedirect(route('admin.tenants.branding.edit', $tenant))
            ->assertSessionHasErrors(['branding' => 'The branding could not be saved. Nothing was changed; please try again.']);

        $failing = false;

        $tenant->refresh();
        $this->assertSame('Alpha', $tenant->name);
        $this->assertSame($oldLogo, $tenant->logo);
        $this->assertSame([$oldLogo], $tenant->run(fn () => Storage::disk('public')->allFiles()));
        $this->assertSame(1, AuditLog::query()->where('action', 'tenant.branding_updated')->count());
    }

    public function test_updating_branding_clears_only_that_tenants_cache(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');

        $this->get('http://alpha.localhost/')->assertInertia(fn (Assert $page) => $page->where('branding.name', 'Alpha'));
        $this->flushSession();
        $this->get('http://beta.localhost/')->assertInertia(fn (Assert $page) => $page->where('branding.name', 'Beta'));
        $this->flushSession();

        $this->assertTrue($this->hasBrandingCache($alpha));
        $this->assertTrue($this->hasBrandingCache($beta));

        $alpha->update(['name' => 'Changed underneath']);
        $this->get('http://alpha.localhost/')->assertInertia(fn (Assert $page) => $page->where('branding.name', 'Alpha'));
        $this->flushSession();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->put(route('admin.tenants.branding.update', $alpha), $this->payload(['name' => 'Alpha Rebranded']))
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->hasBrandingCache($alpha));
        $this->assertTrue($this->hasBrandingCache($beta));

        $this->flushSession();
        $this->get('http://alpha.localhost/')->assertInertia(fn (Assert $page) => $page->where('branding.name', 'Alpha Rebranded'));
        $this->flushSession();
        $this->get('http://beta.localhost/')->assertInertia(fn (Assert $page) => $page->where('branding.name', 'Beta'));
    }

    public function test_branding_is_applied_to_blog_login_and_dashboard_pages(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $tenant->run(fn () => Writer::factory()->create(['email' => 'ada@alpha.test']));

        $this->actingAs(User::factory()->superAdmin()->create())
            ->put(route('admin.tenants.branding.update', $tenant), $this->payload([
                'name' => 'Alpha Journal',
                'brand_color' => '#123456',
                'accent_color' => '#654321',
                'support_email' => 'help@alpha.test',
                'sidebar_tagline' => 'Fresh stories',
                'footer_links' => [['label' => 'About', 'url' => 'https://alpha.test/about']],
                'logo' => UploadedFile::fake()->image('logo.png'),
                'favicon' => UploadedFile::fake()->image('favicon.png', 32, 32),
            ]))
            ->assertSessionHasNoErrors();

        $tenant->refresh();
        $logoUrl = '/tenancy/assets/'.$tenant->logo;
        $faviconUrl = '/tenancy/assets/'.$tenant->favicon;
        $expected = fn (Assert $page) => $page
            ->where('branding.name', 'Alpha Journal')
            ->where('branding.color', '#123456')
            ->where('branding.accent_color', '#654321')
            ->where('branding.logo_url', $logoUrl)
            ->where('branding.favicon_url', $faviconUrl)
            ->where('branding.support_email', 'help@alpha.test')
            ->where('branding.sidebar_tagline', 'Fresh stories')
            ->where('branding.footer_links.0.url', 'https://alpha.test/about');

        $home = $this->get('http://alpha.localhost/')->assertOk()->assertInertia($expected);
        $home->assertSee('<link rel="icon" href="'.$faviconUrl.'">', false);
        $home->assertSee('<meta name="application-name" content="Alpha Journal">', false);

        $this->get('http://alpha.localhost/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $expected($page->component('Writer/Auth/Login')));

        $this->post('http://alpha.localhost/login', ['email' => 'ada@alpha.test', 'password' => 'password']);

        $this->get('http://alpha.localhost/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $expected($page->component('Writer/Dashboard')));

        $this->get('http://alpha.localhost'.$logoUrl)->assertOk();
        $this->get('http://alpha.localhost'.$faviconUrl)->assertOk();
    }

    public function test_central_pages_keep_the_platform_branding(): void
    {
        $this->createTenant('alpha.localhost', 'Alpha');

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('branding', null))
            ->assertSee('<link rel="icon" href="/favicon.ico">', false)
            ->assertDontSee('Alpha');
    }

    public function test_tenants_never_see_or_serve_each_others_branding(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $admin = User::factory()->superAdmin()->create();

        foreach ([[$alpha, '#111111'], [$beta, '#222222']] as [$tenant, $color]) {
            $this->actingAs($admin)->put(route('admin.tenants.branding.update', $tenant), $this->payload([
                'name' => $tenant->name.' Brand',
                'brand_color' => $color,
                'support_email' => strtolower($tenant->name).'@help.test',
                'logo' => UploadedFile::fake()->image('logo.png'),
                'favicon' => UploadedFile::fake()->image('favicon.png', 32, 32),
            ]))->assertSessionHasNoErrors();
        }

        $alpha->refresh();
        $beta->refresh();
        $this->assertStringStartsWith('branding/'.$alpha->id.'/', $alpha->logo);
        $this->assertStringStartsWith('branding/'.$beta->id.'/', $beta->logo);
        $this->flushSession();

        $home = $this->get('http://alpha.localhost/')
            ->assertInertia(fn (Assert $page) => $page
                ->where('branding.name', 'Alpha Brand')
                ->where('branding.color', '#111111')
                ->where('branding.logo_url', '/tenancy/assets/'.$alpha->logo));

        foreach (['Beta Brand', '#222222', 'beta@help.test', $beta->logo, $beta->favicon] as $leak) {
            $home->assertDontSee($leak, false);
        }

        $this->flushSession();
        $this->assertNotSame(200, $this->get('http://alpha.localhost/tenancy/assets/'.$beta->logo)->status());
        $this->assertNotSame(200, $this->get('http://alpha.localhost/tenancy/assets/'.$beta->favicon)->status());
        $this->assertNotSame(200, $this->get('http://alpha.localhost/storage/'.$beta->logo)->status());

        $alpha->update(['logo' => $beta->logo]);
        TenantBranding::forgetFor($alpha);

        $this->get('http://alpha.localhost/')
            ->assertInertia(fn (Assert $page) => $page->where('branding.logo_url', null));
    }

    public function test_the_admin_preview_serves_only_existing_branding_files(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->put(route('admin.tenants.branding.update', $tenant), $this->payload([
            'logo' => UploadedFile::fake()->image('logo.png'),
        ]))->assertSessionHasNoErrors();

        $this->actingAs(User::factory()->support()->create())
            ->get(route('admin.tenants.branding.asset', [$tenant, 'logo']))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($admin)
            ->get(route('admin.tenants.branding.edit', $tenant))
            ->assertInertia(fn (Assert $page) => $page
                ->where('branding.logo_url', fn (string $url) => str_contains($url, '/branding/logo?v='))
                ->where('branding.favicon_url', null));

        $this->actingAs($admin)->get(route('admin.tenants.branding.asset', [$tenant, 'favicon']))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.tenants.show', $tenant).'/branding/password')->assertNotFound();
    }

    public function test_existing_tenants_keep_their_branding_and_get_defaults_for_new_settings(): void
    {
        Storage::disk('public')->put(self::LEGACY_LOGOS[0], 'legacy');
        $tenant = $this->createTenant('alpha.localhost', 'Alpha', [
            'brand_color' => '#112233',
            'logo' => self::LEGACY_LOGOS[0],
        ]);
        $before = DB::table('tenants')->where('id', $tenant->id)->value('data');

        $this->get('http://alpha.localhost/')
            ->assertInertia(fn (Assert $page) => $page
                ->where('branding.name', 'Alpha')
                ->where('branding.color', '#112233')
                ->where('branding.logo_url', '/storage/'.self::LEGACY_LOGOS[0])
                ->where('branding.accent_color', TenantBranding::DEFAULT_ACCENT_COLOR)
                ->where('branding.favicon_url', null)
                ->where('branding.support_email', null)
                ->where('branding.sidebar_style', TenantBranding::DEFAULT_SIDEBAR_STYLE)
                ->where('branding.footer_links', []));

        $this->actingAs(User::factory()->support()->create())
            ->get(route('admin.tenants.branding.edit', $tenant))
            ->assertInertia(fn (Assert $page) => $page
                ->where('branding.logo_url', fn (string $url) => str_ends_with($url, '/storage/'.self::LEGACY_LOGOS[0])));

        $this->assertSame($before, DB::table('tenants')->where('id', $tenant->id)->value('data'));
    }

    public function test_the_creation_wizard_stores_the_logo_in_the_new_tenants_storage(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('admin.tenants.store'), [
                'name' => 'Acme',
                'domain' => 'acme.localhost',
                'brand_color' => '#1d4ed8',
                'logo' => UploadedFile::fake()->image('logo.png'),
                'admin_name' => 'Owner',
                'admin_email' => 'owner@acme.test',
                'admin_password' => 'secret-password',
                'admin_password_confirmation' => 'secret-password',
            ])
            ->assertRedirect(route('admin.tenants.index'));

        $tenant = Tenant::query()->sole();

        $this->assertTrue(TenantBranding::isTenantAsset($tenant->logo, $tenant->id));

        $this->get('http://acme.localhost/')
            ->assertInertia(fn (Assert $page) => $page
                ->where('branding.name', 'Acme')
                ->where('branding.logo_url', '/tenancy/assets/'.$tenant->logo));
    }

    public function test_sidebar_links_keep_order_and_reject_unsafe_urls(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->put(route('admin.tenants.branding.update', $tenant), $this->payload([
                'sidebar_links' => [
                    ['label' => 'Later', 'url' => 'https://alpha.test/later', 'icon' => 'book', 'order' => 2],
                    ['label' => 'First', 'url' => 'https://alpha.test/first', 'icon' => 'home', 'order' => 0],
                    ['label' => 'Bad', 'url' => 'javascript:alert(1)', 'icon' => 'link', 'order' => 1],
                ],
            ]))
            ->assertSessionHasErrors('sidebar_links.2.url');

        $this->actingAs($admin)
            ->put(route('admin.tenants.branding.update', $tenant), $this->payload([
                'sidebar_links' => [
                    ['label' => 'Later', 'url' => 'https://alpha.test/later', 'icon' => 'book', 'order' => 2],
                    ['label' => 'First', 'url' => 'https://alpha.test/first', 'icon' => 'home', 'order' => 0],
                ],
            ]))
            ->assertRedirect();

        $this->get('http://alpha.localhost/')
            ->assertInertia(fn (Assert $page) => $page
                ->where('branding.sidebar_links.0.label', 'First')
                ->where('branding.sidebar_links.0.icon', 'home')
                ->where('branding.sidebar_links.1.label', 'Later'));
    }

    private function hasBrandingCache(Tenant $tenant): bool
    {
        return DB::connection(config('tenancy.database.central_connection'))
            ->table('cache')
            ->pluck('key')
            ->contains(fn (string $key) => str_ends_with($key, 'tenant_'.$tenant->id.'_'.TenantBranding::CACHE_KEY));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Alpha',
            'brand_color' => '#112233',
            'accent_color' => '#f59e0b',
            'support_email' => '',
            'sidebar_style' => 'logo_and_name',
            'sidebar_tagline' => '',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createTenant(string $domain, string $name, array $attributes = []): Tenant
    {
        $tenant = Tenant::create(['name' => $name, ...$attributes]);
        $tenant->createDomain($domain);

        return $tenant;
    }
}
