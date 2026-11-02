<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_groups', function (Blueprint $table) {
            $table->string('compare_as', 8)->default('product');
        });

        // Unbranded groups (tomatoes, bananas) are bought by type; staff switch branded staples such as eggs by hand.
        DB::statement(<<<'SQL'
            UPDATE product_groups SET compare_as = 'group'
            WHERE NOT EXISTS (SELECT 1 FROM products p WHERE p.product_group_id = product_groups.id AND p.brand_id IS NOT NULL)
            SQL);

        Schema::table('product_stats', function (Blueprint $table) {
            // Equal on every offer that shares a home page card, like rank on a product's offers.
            $table->unsignedInteger('compare_rank')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('product_stats', fn (Blueprint $table) => $table->dropColumn('compare_rank'));
        Schema::table('product_groups', fn (Blueprint $table) => $table->dropColumn('compare_as'));
    }
};
