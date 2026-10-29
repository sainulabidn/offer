<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Access\UserStoreAccess;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'phone', 'locale', 'default_radius_km', 'all_stores', 'disabled_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, PasskeyUser
{
    /** The built-in roles (AccessSeeder). Anyone with a role may sign in to the admin; shoppers have none. */
    public const array STAFF_ROLES = ['super_admin', 'data_operator', 'reviewer', 'store_owner', 'store_staff'];

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable;

    /** Spatie roles and permissions are defined on the "web" guard, also for staff signed in to the admin. */
    protected string $guard_name = 'web';

    /** @var array<string, mixed> */
    protected $attributes = ['all_stores' => false, 'disabled_at' => null];

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->disabled_at === null && $this->loadMissing('roles')->roles->isNotEmpty();
    }

    /** What the website and apps get about the signed-in shopper. */
    public function toProfile(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'email' => $this->email];
    }

    /** The retail groups, chains and branches this admin user works on (see App\Domain\Access\StoreScope). */
    public function storeAccess(): HasMany
    {
        return $this->hasMany(UserStoreAccess::class);
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
            'all_stores' => 'boolean',
            'disabled_at' => 'datetime',
        ];
    }
}
