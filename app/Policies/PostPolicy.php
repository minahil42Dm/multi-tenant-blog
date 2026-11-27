<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\Writer;
use App\Support\TenantAbility;

class PostPolicy
{
    public function view(Writer $writer, Post $post): bool
    {
        if (! TenantAbility::belongsToCurrentTenant($writer)) {
            return false;
        }

        if (TenantAbility::allows($writer, TenantAbility::VIEW_ANY_POST)) {
            return true;
        }

        return (int) $post->user_id === (int) $writer->id || $post->isPublished();
    }

    public function update(Writer $writer, Post $post): bool
    {
        return $this->canManage($writer, $post);
    }

    public function delete(Writer $writer, Post $post): bool
    {
        return $this->canManage($writer, $post);
    }

    private function canManage(Writer $writer, Post $post): bool
    {
        if (! TenantAbility::belongsToCurrentTenant($writer)) {
            return false;
        }

        return TenantAbility::allows($writer, TenantAbility::MANAGE_ANY_POST)
            || (int) $post->user_id === (int) $writer->id;
    }
}
