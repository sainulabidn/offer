<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            // When the price, "was" price or promotion was last set: store users wait before changing it again.
            $table->timestamp('price_set_at')->nullable();
            $table->index(['chain_id', 'product_variant_id', 'price_set_at']);
        });
        DB::statement('UPDATE offers SET price_set_at = updated_at');

        // Existing office roles keep working without the wait; new installs get it from AccessSeeder.
        $permission = Permission::findOrCreate('brochures.skip_wait.all', 'web');
        Role::whereIn('name', ['data_operator', 'reviewer'])->where('guard_name', 'web')->with('permissions')->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'brochures.skip_wait.all')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Schema::table('offers', fn (Blueprint $table) => $table->dropColumn('price_set_at'));
    }
};
