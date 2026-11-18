<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guests_cannot_access_admin_routes(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->get(route('admin.tenants.index'))->assertRedirect(route('login'));
        $this->get(route('admin.tenants.create'))->assertRedirect(route('login'));
        $this->post(route('admin.tenants.store'))->assertRedirect(route('login'));
    }

    public function test_verified_non_admin_users_cannot_access_admin_routes(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
        ]);

        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->is_admin);

        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.tenants.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.tenants.create'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.tenants.store'))->assertForbidden();
    }

    public function test_admin_users_can_access_admin_routes(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard'));

        $this->actingAs($admin)
            ->get(route('admin.tenants.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Tenants/Index'));

        $this->actingAs($admin)
            ->get(route('admin.tenants.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Tenants/Create'));
    }

    public function test_public_registration_cannot_create_an_administrator(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'is_admin' => true,
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));

        $user = User::query()->where('email', 'test@example.com')->first();

        $this->assertNotNull($user);
        $this->assertFalse($user->is_admin);
        $this->assertSame(0, User::query()->where('is_admin', true)->count());
    }

    public function test_profile_updates_cannot_change_is_admin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Updated Name',
                'email' => $user->email,
                'is_admin' => true,
            ])
            ->assertRedirect(route('profile.edit'));

        $this->assertFalse($user->refresh()->is_admin);
        $this->assertSame('Updated Name', $user->name);
    }

    public function test_promote_command_promotes_only_the_named_user(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $member = User::factory()->create(['email' => 'member@example.com']);

        $this->artisan('admin:promote', ['email' => 'owner@example.com'])
            ->assertSuccessful();

        $this->assertTrue($owner->refresh()->is_admin);
        $this->assertFalse($member->refresh()->is_admin);
    }

    public function test_promote_command_does_not_promote_another_user_without_force(): void
    {
        $owner = User::factory()->admin()->create(['email' => 'owner@example.com']);
        $member = User::factory()->create(['email' => 'member@example.com']);

        $this->artisan('admin:promote', ['email' => 'member@example.com'])
            ->assertFailed();

        $this->assertTrue($owner->refresh()->is_admin);
        $this->assertFalse($member->refresh()->is_admin);
    }

    public function test_promote_command_does_not_create_a_missing_user(): void
    {
        $this->artisan('admin:promote', ['email' => 'missing@example.com'])
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }
}
