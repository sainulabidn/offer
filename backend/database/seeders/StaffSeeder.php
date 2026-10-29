<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Roles and permissions plus the first admin. Set ADMIN_EMAIL / ADMIN_PASSWORD, or a random password is printed once. */
class StaffSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AccessSeeder::class);

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
