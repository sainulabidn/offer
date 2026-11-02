<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Favourite stores, which always come first.
        Schema::table('shopper_preferences', fn (Blueprint $table) => $table->jsonb('pinned_branch_ids')->default('[]'));
    }

    public function down(): void
    {
        Schema::table('shopper_preferences', fn (Blueprint $table) => $table->dropColumn('pinned_branch_ids'));
    }
};
