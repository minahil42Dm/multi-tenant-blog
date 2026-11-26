<?php

namespace App\Models;

use App\Enums\CentralRole;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class User extends Authenticatable implements MustVerifyEmail
{
    use CentralConnection, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => CentralRole::class,
        ];
    }

    public function hasCentralRole(CentralRole ...$roles): bool
    {
        return $this->role !== null && in_array($this->role, $roles, true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasCentralRole(CentralRole::SuperAdmin);
    }

    public function isSupport(): bool
    {
        return $this->hasCentralRole(CentralRole::Support);
    }
}
