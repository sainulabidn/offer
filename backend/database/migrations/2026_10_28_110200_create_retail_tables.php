<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Operating companies: Lulu Group, MAF, Union Coop, Spinneys…
        Schema::create('retail_groups', function (Blueprint $table) {
            $table->id();
            $table->jsonb('name');
            $table->string('slug')->unique();
            $table->string('logo_path')->nullable();
            $table->string('website')->nullable();
            $table->timestamps();
        });

        Schema::create('loyalty_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retail_group_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        // Banners / store formats: Lulu Hypermarket, Lulu Express, Carrefour Market…
        Schema::create('chains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retail_group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('country_id')->constrained();
            $table->foreignId('loyalty_program_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('name');
            $table->string('slug')->unique();
            $table->string('format', 20);
            $table->string('logo_path')->nullable();
            $table->string('website')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('region_id')->nullable()->constrained()->nullOnDelete();
            // Set when a franchisee/affiliate runs this branch under the chain's banner.
            $table->foreignId('operator_group_id')->nullable()->constrained('retail_groups')->nullOnDelete();
            $table->boolean('is_affiliate')->default(false);
            $table->jsonb('name');
            $table->string('slug');
            $table->jsonb('address')->nullable();
            $table->geography('location', subtype: 'point', srid: 4326);
            $table->string('phone', 30)->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->jsonb('services')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['chain_id', 'slug']);
            $table->spatialIndex('location');
        });

        // Weekly schedule; several rows per weekday allow split shifts. closes_at < opens_at means past midnight.
        Schema::create('branch_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('opens_at');
            $table->time('closes_at');

            $table->index(['branch_id', 'weekday']);
        });

        // Ramadan, Eid and holiday timings.
        Schema::create('branch_hour_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->jsonb('label');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE branch_hour_exceptions ADD COLUMN period daterange NOT NULL');
        // PostgreSQL 18 temporal constraint: one branch can't have overlapping exception periods.
        DB::statement('ALTER TABLE branch_hour_exceptions ADD CONSTRAINT branch_hour_exceptions_no_overlap UNIQUE (branch_id, period WITHOUT OVERLAPS)');
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_hour_exceptions');
        Schema::dropIfExists('branch_hours');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('chains');
        Schema::dropIfExists('loyalty_programs');
        Schema::dropIfExists('retail_groups');
    }
};
