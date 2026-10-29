<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'phone', 'locale', 'default_radius_km'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, PasskeyUser
{
    /** Roles that may sign in to the admin panel; shoppers have none of them. */
    public const array STAFF_ROLES = ['super_admin', 'data_operator', 'reviewer'];

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable;

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasAnyRole(self::STAFF_ROLES);
    }

    /** What the website and apps get about the signed-in shopper. */
    public function toProfile(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'email' => $this->email];
    }

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
        ];
    }
}
