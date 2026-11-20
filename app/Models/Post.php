<?php

namespace App\Models;

use App\Support\HomepageCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use RuntimeException;

class Post extends Model
{
    use HasFactory;
    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'slug',
        'body',
        'status',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => HomepageCache::forget());
        static::deleted(fn () => HomepageCache::forget());
    }

    public function writer(): BelongsTo
    {
        return $this->belongsTo(Writer::class, 'user_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopeVisibleToWriter(Builder $query, Writer $writer): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->where('user_id', $writer->id)
            ->orWhere(fn (Builder $query) => $query->published()));
    }

    public function isPublished(): bool
    {
        return $this->status === 'published'
            && $this->published_at !== null
            && ! $this->published_at->isFuture();
    }

    public function scopeDueForPublishing(Builder $query): Builder
    {
        return $query
            ->where('status', 'scheduled')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function getConnectionName(): ?string
    {
        if (! tenancy()->initialized) {
            throw new RuntimeException('Tenant post queries require an initialized tenant.');
        }

        return parent::getConnectionName();
    }

    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title);
        $base = $base !== '' ? $base : 'post';

        // /posts/{id} and /posts/create belong to the writer panel.
        if (preg_match('/^\d+$/', $base) === 1 || $base === 'create') {
            $base .= '-post';
        }

        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
