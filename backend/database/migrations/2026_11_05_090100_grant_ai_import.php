<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** "Import flyers with AI" for the existing staff roles; new installs get it from AccessSeeder. */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::findOrCreate('brochures.ai_import.all', 'web');
        Role::whereIn('name', ['data_operator', 'reviewer', 'store_owner', 'store_staff'])->where('guard_name', 'web')->with('permissions')->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'brochures.ai_import.all')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
