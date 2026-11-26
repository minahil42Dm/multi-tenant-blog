<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Jobs\PublishScheduledPost;
use App\Models\Post;
use App\Models\Tenant;
use Illuminate\Console\Command;

class PublishDuePosts extends Command
{
    protected $signature = 'posts:publish-due';

    protected $description = 'Dispatch jobs for scheduled posts that are due';

    public function handle(): int
    {
        Tenant::query()->withStatus(TenantStatus::Active)->orderBy('id')->each(function (Tenant $tenant): void {
            $tenant->run(function (): void {
                Post::query()
                    ->dueForPublishing()
                    ->orderBy('id')
                    ->pluck('id')
                    ->each(fn (int|string $id) => PublishScheduledPost::dispatch((int) $id));
            });
        });

        return self::SUCCESS;
    }
}
