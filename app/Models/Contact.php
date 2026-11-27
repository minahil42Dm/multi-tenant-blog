<?php

namespace App\Models;

use App\Models\Concerns\RequiresTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasFactory, RequiresTenant;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'company',
        'job_title',
        'website',
        'location',
    ];

    /**
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    /**
     * Tenant admins see every contact. Other users see contacts they own
     * and contacts that were explicitly shared with them.
     *
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeVisibleTo(Builder $query, Writer $writer): Builder
    {
        if ($writer->isTenantAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($writer) {
            $query->where('owner_id', $writer->getKey())
                ->orWhereHas('sharedWith', fn (Builder $query) => $query->whereKey($writer->getKey()));
        });
    }

    /**
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(function (Builder $query) use ($like) {
            $query->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('company', 'like', $like);
        });
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    /**
     * @return BelongsTo<Writer, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Writer::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<Writer, $this>
     */
    public function sharedWith(): BelongsToMany
    {
        return $this->belongsToMany(Writer::class, 'contact_shares', 'contact_id', 'user_id')->withTimestamps();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * @return HasMany<ContactNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(ContactNote::class)->orderBy('created_at')->orderBy('id');
    }
}
