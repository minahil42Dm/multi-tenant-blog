<?php

namespace Tests\Feature;

use App\Enums\TenantUserRole;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\Writer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContactSharingTest extends TestCase
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

    public function test_a_tenant_user_sees_only_owned_and_shared_contacts(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = $this->admin($tenant, 'admin@alpha.test');
        $owner = $this->writer($tenant, 'ada@alpha.test', 'Ada');
        $peer = $this->writer($tenant, 'bea@alpha.test', 'Bea');
        $owned = $this->contact($tenant, $owner, 'Owned', 'owned@example.com');
        $private = $this->contact($tenant, $peer, 'Private', 'private@example.com');
        $shared = $this->contact($tenant, $peer, 'Shared', 'shared@example.com');

        $tenant->run(fn () => $shared->sharedWith()->sync([$owner->id]));

        $this->actingAs($owner, 'writer')
            ->get('http://alpha.localhost/contacts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('contacts', 2)
                ->where('contacts', function ($contacts) {
                    $emails = collect($contacts)->pluck('email')->sort()->values()->all();

                    return $emails === ['owned@example.com', 'shared@example.com'];
                }));

        $this->actingAs($owner, 'writer')
            ->get('http://alpha.localhost/contacts/'.$private->id)
            ->assertForbidden();

        $this->actingAs($owner, 'writer')
            ->put('http://alpha.localhost/contacts/'.$private->id, [
                'name' => 'Hijacked',
            ])
            ->assertForbidden();

        $this->actingAs($owner, 'writer')
            ->delete('http://alpha.localhost/contacts/'.$private->id)
            ->assertForbidden();

        $this->actingAs($owner, 'writer')
            ->post('http://alpha.localhost/contacts/'.$private->id.'/notes', [
                'body' => 'Should not be saved.',
            ])
            ->assertForbidden();

        $this->actingAs($admin, 'writer')
            ->get('http://alpha.localhost/contacts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('contacts', 3));

        $this->actingAs($admin, 'writer')
            ->get('http://alpha.localhost/contacts/'.$private->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('contact.email', 'private@example.com'));

        $tenant->run(fn () => $this->assertSame('Private', $private->fresh()->name));
    }

    public function test_sharing_can_be_granted_and_removed_by_the_owner(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $owner = $this->writer($tenant, 'ada@alpha.test', 'Ada');
        $peer = $this->writer($tenant, 'bea@alpha.test', 'Bea');
        $contact = $this->contact($tenant, $owner, 'Mina', 'mina@example.com');

        $this->actingAs($peer, 'writer')
            ->put('http://alpha.localhost/contacts/'.$contact->id.'/shares', [
                'users' => [$owner->id],
            ])
            ->assertForbidden();

        $this->actingAs($owner, 'writer')
            ->put('http://alpha.localhost/contacts/'.$contact->id.'/shares', [
                'users' => [$peer->id, $owner->id, 999999],
            ])
            ->assertSessionHasErrors('users.2');

        $this->actingAs($owner, 'writer')
            ->put('http://alpha.localhost/contacts/'.$contact->id.'/shares', [
                'users' => [$peer->id, $owner->id],
            ])
            ->assertRedirect();

        $tenant->run(function () use ($contact, $peer, $owner) {
            $ids = $contact->sharedWith()->pluck('users.id')->map(fn ($id) => (int) $id)->all();

            $this->assertSame([$peer->id], $ids);
            $this->assertNotContains($owner->id, $ids);
        });

        $this->actingAs($peer, 'writer')
            ->get('http://alpha.localhost/contacts/'.$contact->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('contact.can_update', false)
                ->where('contact.can_delete', false)
                ->where('contact.can_share', false));

        $this->actingAs($peer, 'writer')
            ->patch('http://alpha.localhost/contacts/'.$contact->id, [
                'name' => 'Changed by peer',
                'email' => 'mina@example.com',
            ])
            ->assertForbidden();

        $this->actingAs($peer, 'writer')
            ->delete('http://alpha.localhost/contacts/'.$contact->id)
            ->assertForbidden();

        $this->actingAs($peer, 'writer')
            ->post('http://alpha.localhost/contacts/'.$contact->id.'/notes', [
                'body' => 'A note from the shared user.',
            ])
            ->assertRedirect();

        $this->actingAs($owner, 'writer')
            ->put('http://alpha.localhost/contacts/'.$contact->id.'/shares', [
                'users' => [],
            ])
            ->assertRedirect();

        $this->actingAs($peer, 'writer')
            ->get('http://alpha.localhost/contacts/'.$contact->id)
            ->assertForbidden();

        $tenant->run(fn () => $this->assertSame('Mina', $contact->fresh()->name));
    }

    public function test_a_tenant_admin_can_share_any_contact_and_an_owner_cannot_be_deleted(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = $this->admin($tenant, 'admin@alpha.test');
        $owner = $this->writer($tenant, 'ada@alpha.test', 'Ada');
        $peer = $this->writer($tenant, 'bea@alpha.test', 'Bea');
        $contact = $this->contact($tenant, $owner, 'Mina', 'mina@example.com');

        $this->actingAs($admin, 'writer')
            ->put('http://alpha.localhost/contacts/'.$contact->id.'/shares', [
                'users' => [$peer->id],
            ])
            ->assertRedirect();

        $this->actingAs($admin, 'writer')
            ->delete('http://alpha.localhost/writers/'.$owner->id)
            ->assertSessionHasErrors('user');

        $tenant->run(function () use ($owner, $contact, $peer) {
            $this->assertNotNull($owner->fresh());
            $this->assertSame([$peer->id], $contact->sharedWith()->pluck('users.id')->map(fn ($id) => (int) $id)->all());
        });
    }

    public function test_sharing_cannot_reach_another_tenant(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $owner = $this->writer($alpha, 'ada@alpha.test', 'Ada');
        $betaUser = $this->writer($beta, 'bea@beta.test', 'Bea');
        $contact = $this->contact($alpha, $owner, 'Mina', 'mina@example.com');

        $this->actingAs($betaUser, 'writer')
            ->put('http://beta.localhost/contacts/'.$contact->id.'/shares', [
                'users' => [$betaUser->id],
            ])
            ->assertNotFound();

        $this->actingAs($betaUser, 'writer')
            ->get('http://beta.localhost/contacts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('contacts', 0));

        $alpha->run(fn () => $this->assertSame(0, $contact->sharedWith()->count()));
    }

    private function createTenant(string $domain, string $name): Tenant
    {
        $tenant = Tenant::create(['name' => $name]);
        $tenant->createDomain($domain);

        return $tenant;
    }

    private function admin(Tenant $tenant, string $email): Writer
    {
        return $tenant->run(function () use ($email) {
            $writer = new Writer;
            $writer->forceFill([
                'name' => 'Admin',
                'email' => $email,
                'password' => 'password',
                'role' => TenantUserRole::Admin,
            ])->save();

            return $writer;
        });
    }

    private function writer(Tenant $tenant, string $email, string $name): Writer
    {
        return $tenant->run(fn () => Writer::create([
            'name' => $name,
            'email' => $email,
            'password' => 'password',
        ]));
    }

    private function contact(Tenant $tenant, Writer $owner, string $name, string $email): Contact
    {
        return $tenant->run(function () use ($owner, $name, $email) {
            $contact = new Contact([
                'name' => $name,
                'email' => $email,
            ]);
            $contact->owner_id = $owner->id;
            $contact->save();

            return $contact;
        });
    }
}
