<?php

namespace Database\Seeders;

use App\Models\Post;
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
                $writer = Writer::factory()->create([
                    'name' => $definition['writer']['name'],
                    'email' => $definition['writer']['email'],
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
        });
    }
}
