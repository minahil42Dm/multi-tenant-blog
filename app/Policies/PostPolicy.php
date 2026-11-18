<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\Writer;

class PostPolicy
{
    public function view(Writer $writer, Post $post): bool
    {
        return true;
    }

    public function update(Writer $writer, Post $post): bool
    {
        return (int) $post->user_id === (int) $writer->id;
    }

    public function delete(Writer $writer, Post $post): bool
    {
        return (int) $post->user_id === (int) $writer->id;
    }
}
