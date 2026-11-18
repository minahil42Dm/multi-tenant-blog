<?php

namespace Database\Factories;

use App\Models\Post;
use App\Models\Writer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    protected $model = Post::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => Writer::factory(),
            'title' => fake()->sentence(6),
            'slug' => fn (array $attributes) => Post::uniqueSlug($attributes['title']),
            'body' => fake()->paragraphs(3, true),
            'cover_image' => null,
            'status' => 'draft',
            'published_at' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => 'draft',
            'published_at' => null,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn () => [
            'status' => 'scheduled',
            'published_at' => now()->addWeek(),
        ]);
    }
}
