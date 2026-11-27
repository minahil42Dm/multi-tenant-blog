<?php

namespace Database\Seeders;

use App\Enums\TenantUserRole;
use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\Post;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\Writer;
use Illuminate\Database\Seeder;
use Stancl\Tenancy\Database\Models\Domain;

class DemoTenantSeeder extends Seeder
{
    public const PASSWORD = 'password';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'Harbor Journal',
                'domain' => 'harbor.blogs.test',
                'brand_color' => '#0f766e',
                'writer' => [
                    'name' => 'Harbor Writer',
                    'email' => 'writer@harbor.blogs.test',
                ],
                'staff' => [
                    'name' => 'Harbor Staff',
                    'email' => 'staff@harbor.blogs.test',
                ],
                'contacts' => [
                    [
                        'name' => 'Asha Khan',
                        'email' => 'asha@harbor.example',
                        'phone' => '555-0142',
                        'company' => 'Harbor Press',
                        'job_title' => 'Editor',
                        'tags' => ['Sponsor', 'Local'],
                        'notes' => ['Asked about a spring feature.'],
                    ],
                    [
                        'name' => 'Jonah Hale',
                        'email' => 'jonah@harbor.example',
                        'phone' => '555-0177',
                        'company' => 'North Pier',
                        'job_title' => 'Bookseller',
                        'tags' => ['Sponsor'],
                        'notes' => ['Wants the market notes early.'],
                    ],
                ],
                'posts' => [
                    [
                        'title' => 'Welcome ashore',
                        'slug' => 'welcome-ashore',
                        'body' => 'A published note from the Harbor Journal.',
                        'status' => 'published',
                        'published_at' => now()->subDays(3),
                    ],
                    [
                        'title' => 'Harbor market',
                        'slug' => 'harbor-market',
                        'body' => 'Another published note that stays inside Harbor Journal.',
                        'status' => 'published',
                        'published_at' => now()->subDay(),
                    ],
                    [
                        'title' => 'Harbor field notes',
                        'slug' => 'harbor-field-notes',
                        'body' => 'A draft that is not visible on the public site.',
                        'status' => 'draft',
                        'published_at' => null,
                    ],
                    [
                        'title' => 'Next departure',
                        'slug' => 'next-departure',
                        'body' => 'A scheduled post that publishes in the future.',
                        'status' => 'scheduled',
                        'published_at' => now()->addDays(7),
                    ],
                ],
            ],
            [
                'name' => 'Pine and Paper',
                'domain' => 'pine.blogs.test',
                'brand_color' => '#9a3412',
                'writer' => [
                    'name' => 'Pine Writer',
                    'email' => 'writer@pine.blogs.test',
                ],
                'staff' => [
                    'name' => 'Pine Staff',
                    'email' => 'staff@pine.blogs.test',
                ],
                'contacts' => [
                    [
                        'name' => 'Leah Ortiz',
                        'email' => 'leah@pine.example',
                        'phone' => '555-0133',
                        'company' => 'Pine Press',
                        'job_title' => 'Publisher',
                        'tags' => ['Subscriber'],
                        'notes' => ['Requested the spring issue draft.'],
                    ],
                    [
                        'name' => 'Chris Adeyemi',
                        'email' => 'chris@pine.example',
                        'phone' => '555-0188',
                        'company' => 'Paper Mill',
                        'job_title' => 'Buyer',
                        'tags' => ['Subscriber', 'Wholesale'],
                        'notes' => ['Asked for a printed sample.'],
                    ],
                ],
                'posts' => [
                    [
                        'title' => 'First edition',
                        'slug' => 'first-edition',
                        'body' => 'A published note from Pine and Paper.',
                        'status' => 'published',
                        'published_at' => now()->subDays(2),
                    ],
                    [
                        'title' => 'Press room',
                        'slug' => 'press-room',
                        'body' => 'Another published note that stays inside Pine and Paper.',
                        'status' => 'published',
                        'published_at' => now()->subDay(),
                    ],
                    [
                        'title' => 'Pine draft',
                        'slug' => 'pine-draft',
                        'body' => 'A draft kept inside the Pine and Paper tenant.',
                        'status' => 'draft',
                        'published_at' => null,
                    ],
                    [
                        'title' => 'Spring issue',
                        'slug' => 'spring-issue',
                        'body' => 'A scheduled issue that is not public yet.',
                        'status' => 'scheduled',
                        'published_at' => now()->addDays(14),
                    ],
                ],
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::definitions() as $definition) {
            $this->seedTenant($definition);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function seedTenant(array $definition): void
    {
        $domain = Domain::query()->where('domain', $definition['domain'])->first();

        if ($domain) {
            $tenant = Tenant::query()->findOrFail($domain->tenant_id);
        } else {
            $tenant = Tenant::create([
                'name' => $definition['name'],
                'brand_color' => $definition['brand_color'],
            ]);

            $tenant->createDomain($definition['domain']);
        }

        $tenant->run(function () use ($definition) {
            $writer = Writer::query()->where('email', $definition['writer']['email'])->first();

            if (! $writer) {
                $writer = new Writer;
                $writer->forceFill([
                    'name' => $definition['writer']['name'],
                    'email' => $definition['writer']['email'],
                    'password' => self::PASSWORD,
                    'role' => TenantUserRole::Admin,
                ])->save();
            }

            if (! Writer::query()->where('email', $definition['staff']['email'])->exists()) {
                Writer::factory()->create([
                    'name' => $definition['staff']['name'],
                    'email' => $definition['staff']['email'],
                    'password' => self::PASSWORD,
                ]);
            }

            foreach ($definition['posts'] as $post) {
                if (Post::query()->where('slug', $post['slug'])->exists()) {
                    continue;
                }

                $factory = match ($post['status']) {
                    'published' => Post::factory()->published(),
                    'scheduled' => Post::factory()->scheduled(),
                    default => Post::factory()->draft(),
                };

                $factory->create([
                    'user_id' => $writer->id,
                    'title' => $post['title'],
                    'slug' => $post['slug'],
                    'body' => $post['body'],
                    'cover_image' => null,
                    'status' => $post['status'],
                    'published_at' => $post['published_at'],
                ]);
            }

            $staff = Writer::query()->where('email', $definition['staff']['email'])->first();
            $shareNextContact = true;

            foreach ($definition['contacts'] as $contactData) {
                if (Contact::query()->where('email', $contactData['email'])->exists()) {
                    $shareNextContact = false;

                    continue;
                }

                $contact = Contact::factory()->make([
                    'name' => $contactData['name'],
                    'email' => $contactData['email'],
                    'phone' => $contactData['phone'],
                    'company' => $contactData['company'],
                    'job_title' => $contactData['job_title'],
                    'website' => null,
                    'location' => null,
                ]);
                $contact->owner_id = $writer->id;
                $contact->save();

                if ($shareNextContact && $staff) {
                    $contact->sharedWith()->sync([$staff->id]);
                    $shareNextContact = false;
                }

                $tagIds = [];

                foreach ($contactData['tags'] as $name) {
                    $tagIds[] = Tag::query()->firstOrCreate(['name' => $name])->getKey();
                }

                $contact->tags()->sync($tagIds);

                foreach ($contactData['notes'] as $body) {
                    $note = new ContactNote(['body' => $body]);
                    $note->contact()->associate($contact);
                    $note->author()->associate($writer);
                    $note->save();
                }
            }
        });
    }
}
