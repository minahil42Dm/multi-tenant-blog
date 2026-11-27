<?php

namespace App\Models;

use App\Models\Concerns\RequiresTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactImport extends Model
{
    use RequiresTenant;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'status',
        'original_filename',
        'path',
        'summary',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'summary' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Writer, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Writer::class, 'user_id');
    }
}
