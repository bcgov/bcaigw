<?php

namespace App\Models;

use App\Enums\PortalRole;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'keycloak_subject',
    'idir_user_guid',
    'idir_username',
    'name',
    'first_name',
    'last_name',
    'email',
    'portal_role',
    'last_login_at',
])]
#[Hidden(['password', 'remember_token', 'keycloak_subject', 'idir_user_guid'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

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
            'portal_role' => PortalRole::class,
            'last_login_at' => 'datetime',
        ];
    }

    public function isAdministrator(): bool
    {
        return $this->portal_role === PortalRole::Administrator;
    }

    public function applications(): BelongsToMany
    {
        return $this->belongsToMany(Application::class, 'application_user')
            ->withPivot(['role', 'created_by'])
            ->withTimestamps();
    }
}
