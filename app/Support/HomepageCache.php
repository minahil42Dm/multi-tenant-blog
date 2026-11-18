<?php

namespace App\Support;

use App\Models\Post;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class HomepageCache
{
    public const KEY = 'blog.homepage';

    /**
     * @return array<int, array{title: string, slug: string, excerpt: string, published_at: ?string, cover_url: ?string}>
     */
    public static function posts(): array
    {
        return Cache::remember(self::KEY, 3600, function () {
            return Post::query()
                ->published()
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (Post $post) => [
                    'title' => $post->title,
                    'slug' => $post->slug,
                    'excerpt' => Str::limit(trim($post->body), 160),
                    'published_at' => $post->published_at?->toFormattedDateString(),
                    'cover_url' => TenantCoverImage::url($post->cover_image),
                ])
                ->all();
        });
    }

    public static function forget(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        Cache::forget(self::KEY);
    }
}
