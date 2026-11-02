<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            // Where an imported branch came from, e.g. "osm:n4879030822", so a fresh import updates it instead of adding it twice.
            $table->string('source_ref', 40)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('source_ref'));
    }
};
