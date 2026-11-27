<?php

namespace App\Models;

use App\Models\Concerns\RequiresTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactNote extends Model
{
    use HasFactory, RequiresTenant;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'body',
    ];

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Writer, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Writer::class, 'user_id');
    }
}
