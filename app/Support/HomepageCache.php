<?php

namespace App\Support;

use App\Models\Post;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class HomepageCache
{
    public const KEY = 'blog.homepage';

    public const TTL = 3600;

    /**
     * @return array<int, array{title: string, slug: string, excerpt: string, published_at: ?string, cover_url: ?string}>
     */
    public static function posts(): array
    {
        $cached = Cache::get(self::KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $posts = Post::query()
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

        Cache::put(self::KEY, $posts, self::ttl());

        return $posts;
    }

    /**
     * Expire no later than the next future-dated published post, so it appears on time.
     */
    private static function ttl(): int
    {
        $next = Post::query()
            ->where('status', 'published')
            ->where('published_at', '>', now())
            ->min('published_at');

        if ($next === null) {
            return self::TTL;
        }

        $seconds = (int) ceil(now()->diffInSeconds(Carbon::parse($next), absolute: true));

        return max(1, min(self::TTL, $seconds));
    }

    public static function forget(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        Cache::forget(self::KEY);
    }
}
