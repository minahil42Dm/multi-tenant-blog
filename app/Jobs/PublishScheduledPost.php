<?php

namespace App\Jobs;

use App\Enums\TenantStatus;
use App\Models\Post;
use App\Models\Tenant;
use App\Support\HomepageCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PublishScheduledPost implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $postId, public ?string $tenantId = null)
    {
        if ($this->tenantId === null && tenancy()->initialized) {
            $this->tenantId = (string) tenant()->getTenantKey();
        }
    }

    public function handle(): void
    {
        if ($this->tenantId === null) {
            return;
        }

        $startedTenancy = false;

        if (! tenancy()->initialized) {
            $tenant = tenancy()->find($this->tenantId);

            if ($tenant === null) {
                return;
            }

            tenancy()->initialize($tenant);
            $startedTenancy = true;
        }

        if ((string) tenant()->getTenantKey() !== $this->tenantId || ! $this->tenantIsActive()) {
            if ($startedTenancy) {
                tenancy()->end();
            }

            return;
        }

        try {
            $this->publishDuePost();
        } finally {
            if ($startedTenancy && tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    private function tenantIsActive(): bool
    {
        return Tenant::query()
            ->whereKey($this->tenantId)
            ->withStatus(TenantStatus::Active)
            ->exists();
    }

    private function publishDuePost(): void
    {
        $post = Post::query()->find($this->postId);

        if ($post === null || $post->status !== 'scheduled' || $post->published_at === null || $post->published_at->isFuture()) {
            return;
        }

        $updated = Post::query()
            ->whereKey($post->id)
            ->where('status', 'scheduled')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            HomepageCache::forget();
        }
    }
}
