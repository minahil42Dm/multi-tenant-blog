<?php

namespace App\Http\Controllers\Writer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Writer\PostRequest;
use App\Models\Post;
use App\Support\TenantCoverImage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PostController extends Controller
{
    use AuthorizesRequests;

    public function index(): Response
    {
        $writer = auth('writer')->user();
        $writerId = (int) $writer->id;

        $posts = Post::query()
            ->visibleToWriter($writer)
            ->with('writer:id,name')
            ->latest()
            ->get()
            ->map(fn (Post $post) => [
                'id' => $post->id,
                'title' => $post->title,
                'status' => $post->status,
                'published_at' => $post->published_at?->toDateTimeString(),
                'author' => $post->writer?->name,
                'cover_url' => TenantCoverImage::url($post->cover_image),
                'can_manage' => (int) $post->user_id === $writerId,
            ]);

        return Inertia::render('Writer/Posts/Index', [
            'posts' => $posts,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Writer/Posts/Create');
    }

    public function store(PostRequest $request): RedirectResponse
    {
        $post = new Post($this->attributes($request));
        $post->user_id = $request->user('writer')->id;
        $post->cover_image = TenantCoverImage::sync($request, null);
        $post->save();

        return redirect()
            ->route('writer.posts.show', $post)
            ->with('success', 'Post created.');
    }

    public function show(Post $post): Response
    {
        $this->authorize('view', $post);
        $post->load('writer:id,name');

        return Inertia::render('Writer/Posts/Show', [
            'post' => $this->present($post),
        ]);
    }

    public function edit(Post $post): Response
    {
        $this->authorize('update', $post);

        return Inertia::render('Writer/Posts/Edit', [
            'post' => $this->present($post),
        ]);
    }

    public function update(PostRequest $request, Post $post): RedirectResponse
    {
        $this->authorize('update', $post);

        $post->fill($this->attributes($request, $post));
        $post->cover_image = TenantCoverImage::sync($request, $post->cover_image);
        $post->save();

        return redirect()
            ->route('writer.posts.show', $post)
            ->with('success', 'Post updated.');
    }

    public function destroy(Post $post): RedirectResponse
    {
        $this->authorize('delete', $post);

        TenantCoverImage::delete($post->cover_image);
        $post->delete();

        return redirect()
            ->route('writer.posts.index')
            ->with('success', 'Post deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(PostRequest $request, ?Post $post = null): array
    {
        $validated = $request->validated();
        $status = $validated['status'];

        $attributes = [
            'title' => $validated['title'],
            'body' => $validated['body'],
            'status' => $status,
            'published_at' => $status === 'draft' ? null : $validated['published_at'],
        ];

        if ($post === null) {
            $attributes['slug'] = Post::uniqueSlug($validated['title']);
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Post $post): array
    {
        return [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'body' => $post->body,
            'status' => $post->status,
            'published_at' => $post->published_at?->format('Y-m-d\TH:i'),
            'published_at_label' => $post->published_at?->toDateTimeString(),
            'author' => $post->writer?->name,
            'cover_url' => TenantCoverImage::url($post->cover_image),
            'can_manage' => (int) $post->user_id === (int) auth('writer')->id(),
        ];
    }
}
