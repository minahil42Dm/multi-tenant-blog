<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Support\HomepageCache;
use App\Support\TenantBranding;
use App\Support\TenantCoverImage;
use Inertia\Inertia;
use Inertia\Response;

class BlogController extends Controller
{
    public function index(): Response
    {
        $posts = HomepageCache::posts();

        $branding = TenantBranding::current();

        return Inertia::render('Blog/Home', [
            'tenantName' => $branding['name'],
            'branding' => $branding,
            'posts' => $posts,
        ]);
    }

    public function show(string $slug): Response
    {
        $post = Post::query()
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        $branding = TenantBranding::current();

        return Inertia::render('Blog/Show', [
            'tenantName' => $branding['name'],
            'branding' => $branding,
            'post' => [
                'title' => $post->title,
                'slug' => $post->slug,
                'body' => $post->body,
                'published_at' => $post->published_at?->toFormattedDateString(),
                'cover_url' => TenantCoverImage::url($post->cover_image),
            ],
        ]);
    }
}
