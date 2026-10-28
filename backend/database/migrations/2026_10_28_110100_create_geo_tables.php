<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->char('code', 2)->unique();
            $table->jsonb('name');
            $table->char('currency', 3);
            $table->string('timezone');
            $table->string('default_locale', 5)->default('en');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Emirate › city › area, used by the area picker and branch addresses.
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained();
            $table->foreignId('parent_id')->nullable()->constrained('regions')->nullOnDelete();
            $table->string('type', 20);
            $table->jsonb('name');
            $table->string('slug');
            $table->geography('centroid', subtype: 'point', srid: 4326)->nullable();
            $table->geography('boundary', subtype: 'multipolygon', srid: 4326)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['country_id', 'slug']);
            $table->index(['parent_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
        Schema::dropIfExists('countries');
    }
};
