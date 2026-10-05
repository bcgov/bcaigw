<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'guid',
        'name',
        'first_name',
        'last_name',
        'disabled',
        'email',
        'password',
        'idir_user_guid',
        'idir_username',
        'last_touch_by_user_guid',
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
            'disabled' => 'boolean',
        ];
    }

    /**
     * The roles that belong to the user.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    /**
     * The applications the user is a member of.
     */
    public function applications(): BelongsToMany
    {
        return $this->belongsToMany(Application::class, 'application_user')
            ->withPivot(['role', 'created_by'])
            ->withTimestamps();
    }

    /**
     * Determine whether the user has the given role.
     */
    public function hasRole(string $role): bool
    {
        return $this->roles->contains('name', $role);
    }

    /**
     * Determine whether the user holds an administrative role.
     */
    public function isAdministrator(): bool
    {
        return $this->hasRole(Role::SUPER_ADMIN) || $this->hasRole(Role::Ministry_ADMIN);
    }

    /**
     * Scope a query to only active (not disabled) users.
     */
    public function scopeIsActive(Builder $query): Builder
    {
        return $query->where('disabled', false);
    }
}
