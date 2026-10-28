<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/** Staff roles plus the first admin. Set ADMIN_EMAIL / ADMIN_PASSWORD, or a random password is printed once. */
class StaffSeeder extends Seeder
{
    public function run(): void
    {
        foreach (User::STAFF_ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        $email = env('ADMIN_EMAIL', 'admin@offer.test');
        if (User::where('email', $email)->exists()) {
            return;
        }

        $password = env('ADMIN_PASSWORD') ?: Str::password(16, symbols: false);
        $admin = User::create(['name' => 'Admin', 'email' => $email, 'password' => $password]);
        $admin->assignRole('super_admin');

        if (! env('ADMIN_PASSWORD')) {
            $this->command?->warn("Admin created: {$email} / {$password} (shown once; change it after signing in)");
        }
    }
}
