<?php

namespace Tests\Feature;

use App\Enums\CentralRole;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
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
        $user = User::factory()->create();

        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->role);

        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.tenants.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.tenants.create'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.tenants.store'))->assertForbidden();
    }

    public function test_unverified_admins_cannot_access_admin_routes(): void
    {
        $admin = User::factory()->admin()->unverified()->create();

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertRedirect(route('verification.notice'));
        $this->actingAs($admin)->get(route('admin.tenants.index'))->assertRedirect(route('verification.notice'));
        $this->actingAs($admin)->get(route('admin.tenants.create'))->assertRedirect(route('verification.notice'));
        $this->actingAs($admin)->post(route('admin.tenants.store'))->assertRedirect(route('verification.notice'));
    }

    public function test_admin_gains_access_after_verifying_their_email(): void
    {
        $admin = User::factory()->admin()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $admin->id, 'hash' => sha1($admin->email)]
        );

        $this->actingAs($admin)->get($verificationUrl);

        $this->assertTrue($admin->fresh()->hasVerifiedEmail());
        $this->actingAs($admin->fresh())->get(route('admin.dashboard'))->assertOk();
    }

    public function test_registration_sends_an_email_verification_notification(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::query()->where('email', 'new@example.com')->firstOrFail();

        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);
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
            'role' => 'super_admin',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));

        $user = User::query()->where('email', 'test@example.com')->first();

        $this->assertNotNull($user);
        $this->assertNull($user->role);
        $this->assertSame(0, User::query()->whereNotNull('role')->count());
    }

    public function test_profile_updates_cannot_change_the_central_role(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Updated Name',
                'email' => $user->email,
                'is_admin' => true,
                'role' => 'super_admin',
            ])
            ->assertRedirect(route('profile.edit'));

        $this->assertNull($user->refresh()->role);
        $this->assertSame('Updated Name', $user->name);
    }

    public function test_promote_command_promotes_only_the_named_user(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $member = User::factory()->create(['email' => 'member@example.com']);

        $this->artisan('admin:promote', ['email' => 'owner@example.com'])
            ->assertSuccessful();

        $this->assertSame(CentralRole::SuperAdmin, $owner->refresh()->role);
        $this->assertNull($member->refresh()->role);
    }

    public function test_promote_command_does_not_promote_another_user_without_force(): void
    {
        $owner = User::factory()->admin()->create(['email' => 'owner@example.com']);
        $member = User::factory()->create(['email' => 'member@example.com']);

        $this->artisan('admin:promote', ['email' => 'member@example.com'])
            ->assertFailed();

        $this->assertTrue($owner->refresh()->isSuperAdmin());
        $this->assertNull($member->refresh()->role);
    }

    public function test_promote_command_grants_the_support_role_with_force(): void
    {
        User::factory()->superAdmin()->create(['email' => 'owner@example.com']);
        $agent = User::factory()->create(['email' => 'agent@example.com']);

        $this->artisan('admin:promote', ['email' => 'agent@example.com', '--role' => 'support', '--force' => true])
            ->assertSuccessful();

        $this->assertSame(CentralRole::Support, $agent->refresh()->role);
    }

    public function test_promote_command_rejects_an_unknown_role(): void
    {
        $user = User::factory()->create(['email' => 'member@example.com']);

        $this->artisan('admin:promote', ['email' => 'member@example.com', '--role' => 'owner'])
            ->assertFailed();

        $this->assertNull($user->refresh()->role);
    }

    public function test_promote_command_does_not_create_a_missing_user(): void
    {
        $this->artisan('admin:promote', ['email' => 'missing@example.com'])
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }
}
