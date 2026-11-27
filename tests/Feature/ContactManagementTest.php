<?php

namespace Tests\Feature;

use App\Enums\TenantUserRole;
use App\Jobs\ImportContacts;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\ContactNote;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\Writer;
use App\Support\ContactImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class ContactManagementTest extends TestCase
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

    public function test_provisioning_a_tenant_creates_the_crm_tables(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');

        $tenant->run(function () {
            foreach (['contacts', 'contact_shares', 'tags', 'contact_tag', 'contact_notes', 'contact_imports'] as $table) {
                $this->assertTrue(Schema::hasTable($table), $table);
            }
        });
    }

    public function test_contact_records_cannot_be_queried_before_tenancy_starts(): void
    {
        $this->expectException(RuntimeException::class);

        Contact::query()->count();
    }

    public function test_a_tenant_user_can_manage_contacts_tags_and_notes(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = $this->admin($tenant, 'admin@alpha.test');
        $writer = $this->writer($tenant, 'ada@alpha.test');

        $this->actingAs($writer, 'writer')
            ->post('http://alpha.localhost/contact-tags', ['name' => 'Customer'])
            ->assertRedirect();

        $tagId = $tenant->run(fn () => Tag::query()->where('name', 'Customer')->value('id'));

        $this->actingAs($writer, 'writer')
            ->put('http://alpha.localhost/contact-tags/'.$tagId, ['name' => 'Client'])
            ->assertRedirect();

        $tenant->run(fn () => $this->assertSame('Client', Tag::query()->find($tagId)->name));

        $this->actingAs($writer, 'writer')
            ->post('http://alpha.localhost/contacts', [
                'name' => 'Mina Ellis',
                'email' => 'mina@example.com',
                'phone' => '555-0100',
                'company' => 'Northwind',
                'job_title' => 'Editor',
                'website' => 'https://northwind.test',
                'location' => 'Lahore',
                'tags' => [$tagId],
            ])
            ->assertRedirect();

        $contact = $tenant->run(fn () => Contact::query()->where('email', 'mina@example.com')->first());
        $this->assertNotNull($contact);

        $this->actingAs($writer, 'writer')
            ->get('http://alpha.localhost/contacts?search=Northwind&tag='.$tagId.'&sort=company&direction=desc')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Writer/Contacts/Index')
                ->has('contacts', 1)
                ->where('contacts.0.email', 'mina@example.com')
                ->where('filters.tag', (string) $tagId));

        $this->actingAs($writer, 'writer')
            ->patch('http://alpha.localhost/contacts/'.$contact->id, [
                'name' => 'Mina Ellis',
                'email' => 'mina@example.com',
                'phone' => '555-0199',
                'company' => 'Northwind',
                'job_title' => 'Editor',
                'tags' => [],
            ])
            ->assertRedirect();

        $this->actingAs($writer, 'writer')
            ->post('http://alpha.localhost/contacts/'.$contact->id.'/notes', [
                'body' => 'Met at the market.',
            ])
            ->assertRedirect();

        $note = $tenant->run(fn () => ContactNote::query()->first());
        $this->assertSame($writer->id, $note->user_id);

        $this->actingAs($admin, 'writer')
            ->put('http://alpha.localhost/contacts/'.$contact->id.'/notes/'.$note->id, [
                'body' => 'Updated by the admin.',
            ])
            ->assertRedirect();

        $this->actingAs($writer, 'writer')
            ->put('http://alpha.localhost/contacts/'.$contact->id.'/notes/'.$note->id, [
                'body' => 'The writer cannot edit an admin-owned rewrite of their own note.',
            ])
            ->assertRedirect();

        $this->actingAs($writer, 'writer')
            ->post('http://alpha.localhost/contacts/'.$contact->id.'/notes', [
                'body' => 'Admin note.',
            ]);

        $adminNote = $tenant->run(function () use ($admin, $contact) {
            $note = new ContactNote(['body' => 'Private admin note.']);
            $note->contact()->associate($contact);
            $note->author()->associate($admin);
            $note->save();

            return $note;
        });

        $this->actingAs($writer, 'writer')
            ->put('http://alpha.localhost/contacts/'.$contact->id.'/notes/'.$adminNote->id, [
                'body' => 'Hijacked',
            ])
            ->assertForbidden();

        $this->actingAs($writer, 'writer')
            ->delete('http://alpha.localhost/contacts/'.$contact->id.'/notes/'.$adminNote->id)
            ->assertForbidden();

        $tenant->run(fn () => $this->assertSame('Private admin note.', $adminNote->fresh()->body));

        $this->actingAs($admin, 'writer')
            ->delete('http://alpha.localhost/contacts/'.$contact->id)
            ->assertRedirect();

        $tenant->run(function () use ($contact) {
            $this->assertNull($contact->fresh());
            $this->assertSame(0, ContactNote::query()->count());
        });
    }

    public function test_contact_validation_search_and_pagination(): void
    {
        $tenant = $this->createTenant('alpha.localhost', 'Alpha');
        $admin = $this->admin($tenant, 'admin@alpha.test');

        $this->actingAs($admin, 'writer')
            ->post('http://alpha.localhost/contacts', [
                'name' => '',
                'email' => 'not-an-email',
            ])
            ->assertSessionHasErrors(['name', 'email']);

        $this->actingAs($admin, 'writer')
            ->post('http://alpha.localhost/contacts', [
                'name' => 'First',
                'email' => 'first@example.com',
            ])
            ->assertRedirect();

        $this->actingAs($admin, 'writer')
            ->post('http://alpha.localhost/contacts', [
                'name' => 'Duplicate',
                'email' => 'first@example.com',
            ])
            ->assertSessionHasErrors('email');

        $this->actingAs($admin, 'writer')
            ->post('http://alpha.localhost/contacts', ['name' => 'No Email'])
            ->assertRedirect();

        $this->actingAs($admin, 'writer')
            ->post('http://alpha.localhost/contacts', ['name' => 'Also No Email'])
            ->assertRedirect();

        $tenant->run(function () use ($admin) {
            foreach (range(1, 14) as $index) {
                $contact = new Contact([
                    'name' => sprintf('Person %02d', $index),
                    'email' => "person{$index}@example.com",
                    'company' => $index % 2 === 0 ? 'Even Co' : 'Odd Co',
                ]);
                $contact->owner_id = $admin->id;
                $contact->save();
            }
        });

        $this->actingAs($admin, 'writer')
            ->get('http://alpha.localhost/contacts?sort=name&direction=asc')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 17)
                ->where('pagination.last_page', 2)
                ->has('contacts', 15));

        $this->actingAs($admin, 'writer')
            ->get('http://alpha.localhost/contacts?search=Even&sort=name&direction=asc')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('pagination.total', 7));

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->get('http://alpha.localhost/contacts')
            ->assertRedirect(route('writer.login', absolute: false));
    }

    public function test_contacts_are_isolated_from_other_tenants(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $admin = $this->admin($alpha, 'admin@alpha.test');
        $betaAdmin = $this->admin($beta, 'admin@beta.test');
        $contact = $alpha->run(function () use ($admin) {
            $contact = new Contact([
                'name' => 'Alpha Contact',
                'email' => 'alpha@example.com',
            ]);
            $contact->owner_id = $admin->id;
            $contact->save();

            return $contact;
        });

        $this->actingAs($betaAdmin, 'writer')
            ->get('http://beta.localhost/contacts/'.$contact->id)
            ->assertNotFound();

        $this->actingAs($betaAdmin, 'writer')
            ->put('http://beta.localhost/contacts/'.$contact->id, [
                'name' => 'Hijacked',
                'email' => 'alpha@example.com',
            ])
            ->assertNotFound();

        $this->actingAs($betaAdmin, 'writer')
            ->delete('http://beta.localhost/contacts/'.$contact->id)
            ->assertNotFound();

        $alpha->run(fn () => $this->assertSame('Alpha Contact', $contact->fresh()->name));

        $beta->run(fn () => $this->assertSame(0, Contact::query()->count()));

        $this->assertTrue($alpha->suspend());

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->actingAs($admin, 'writer')
            ->get('http://alpha.localhost/contacts')
            ->assertStatus(503);
    }

    public function test_csv_import_reports_created_skipped_and_failed_rows_inside_the_tenant(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $admin = $this->admin($alpha, 'admin@alpha.test');

        $alpha->run(function () use ($admin) {
            $contact = new Contact([
                'name' => 'Existing',
                'email' => 'existing@example.com',
            ]);
            $contact->owner_id = $admin->id;
            $contact->save();
        });

        $csv = implode("\n", [
            'name,email,phone,company,job_title,tags',
            'New Person,new@example.com,555,Acme,Editor,Press;Local',
            'Existing,existing@example.com,555,Acme,Editor,',
            ',bad-email,555,Acme,Editor,',
            'No Email Person,,555,Beta Co,Writer,Press',
        ]);

        $this->actingAs($admin, 'writer')
            ->post('http://alpha.localhost/contacts/import', [
                'file' => UploadedFile::fake()->createWithContent('people.csv', $csv),
            ])
            ->assertRedirect();

        $alpha->run(function () use ($admin) {
            $import = ContactImport::query()->first();

            $this->assertSame('completed', $import->status);
            $this->assertSame(2, $import->summary['created']);
            $this->assertSame(1, $import->summary['skipped']);
            $this->assertSame(1, $import->summary['failed']);
            $this->assertSame($admin->id, (int) Contact::query()->where('email', 'new@example.com')->value('owner_id'));
            $this->assertSame('new@example.com', Contact::query()->where('email', 'new@example.com')->value('email'));
            $this->assertTrue(Contact::query()->where('email', 'new@example.com')->first()->tags->contains('name', 'Press'));
            $this->assertSame('Existing', Contact::query()->where('email', 'existing@example.com')->value('name'));
        });

        $beta->run(fn () => $this->assertSame(0, Contact::query()->count()));

        $this->actingAs($admin, 'writer')
            ->post('http://alpha.localhost/contacts/import', [
                'file' => UploadedFile::fake()->createWithContent('people.csv', "title,body\nHello,World\n"),
            ])
            ->assertRedirect();

        $alpha->run(function () {
            $import = ContactImport::query()->latest('id')->first();

            $this->assertSame('failed', $import->status);
            $this->assertSame(0, $import->summary['created']);
            $this->assertNotEmpty($import->summary['errors']);
        });

        $this->actingAs($admin, 'writer')
            ->post('http://alpha.localhost/contacts/import', [
                'file' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'),
            ])
            ->assertSessionHasErrors('file');
    }

    public function test_a_queued_import_does_not_write_into_another_tenant(): void
    {
        $alpha = $this->createTenant('alpha.localhost', 'Alpha');
        $beta = $this->createTenant('beta.localhost', 'Beta');
        $admin = $this->admin($alpha, 'admin@alpha.test');

        $import = $alpha->run(function () use ($admin) {
            $record = new ContactImport([
                'status' => 'pending',
                'original_filename' => 'people.csv',
                'path' => 'contact-imports/missing.csv',
            ]);
            $record->author()->associate($admin);
            $record->save();

            return $record;
        });

        tenancy()->initialize($beta);

        (new ImportContacts($import->id, (string) $beta->id))->handle(app(ContactImporter::class));

        $alpha->run(function () use ($import) {
            $this->assertSame('pending', $import->fresh()->status);
            $this->assertSame(0, Contact::query()->count());
        });

        $beta->run(fn () => $this->assertSame(0, Contact::query()->count()));
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

    private function writer(Tenant $tenant, string $email): Writer
    {
        return $tenant->run(fn () => Writer::create([
            'name' => 'Ada',
            'email' => $email,
            'password' => 'password',
        ]));
    }
}
