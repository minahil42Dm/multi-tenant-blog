<?php

namespace App\Models;

use App\Enums\TenantUserRole;
use App\Support\Impersonation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use RuntimeException;

class Writer extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'users';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => TenantUserRole::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Writer $writer): void {
            if (Impersonation::active() && $writer->isDirty(['email', 'password'])) {
                throw new AuthorizationException('Email and password changes are not allowed while impersonating.');
            }
        });
    }

    public function isTenantAdmin(): bool
    {
        return $this->role === TenantUserRole::Admin;
    }

    public function getConnectionName(): ?string
    {
        if (! tenancy()->initialized) {
            throw new RuntimeException('Tenant writer queries require an initialized tenant.');
        }

        return parent::getConnectionName();
    }
}
