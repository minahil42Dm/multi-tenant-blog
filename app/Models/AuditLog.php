<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class AuditLog extends Model
{
    use CentralConnection;

    public const UPDATED_AT = null;

    /**
     * @var array<int, string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function record(string $action, ?User $actor, ?string $tenantId, array $metadata = []): self
    {
        $request = request();

        $log = new self;
        $log->forceFill([
            'action' => $action,
            'actor_id' => $actor?->getKey(),
            'actor_email' => $actor?->email,
            'tenant_id' => $tenantId,
            'metadata' => $metadata === [] ? null : $metadata,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ])->save();

        return $log;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
