<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Settings a signed-in shopper keeps across devices. Empty radii mean the site defaults.
        Schema::create('shopper_preferences', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('radius_km')->nullable();
            $table->unsignedSmallInteger('item_radius_km')->nullable();
            $table->boolean('item_radius_off')->default(false);
            $table->jsonb('branch_ids')->default('[]');
            $table->boolean('personalize')->default(false);
            // The device's time of the last change; the newest save wins, as with plans.
            $table->timestamp('client_updated_at')->nullable();
            $table->timestamps();
        });

        // Home, Office and other places. The id is made on the device, so a guest's place keeps it at sign-in.
        Schema::create('shopper_places', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40);
            $table->string('kind', 8)->default('other'); // home | work | other
            $table->geography('location', subtype: 'point', srid: 4326);
            $table->string('area_name', 120)->nullable();
            $table->unsignedSmallInteger('radius_km')->nullable();
            $table->boolean('is_default')->default(false);
            // The system chose this default from use; a default the shopper chose is never replaced.
            $table->boolean('is_auto')->default(false);
            $table->float('use_score')->default(0);
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
        DB::statement('CREATE UNIQUE INDEX shopper_places_one_default_idx ON shopper_places (user_id) WHERE is_default');

        // UAE areas, malls, stations and landmarks for the location search. Built from OpenStreetMap
        // (see places:import) and kept apart from store data, because it stays under the ODbL licence.
        Schema::create('place_names', function (Blueprint $table) {
            $table->id();
            $table->string('type', 12); // area | mall | station | landmark
            $table->jsonb('name');
            $table->geography('location', subtype: 'point', srid: 4326);
            $table->timestamps();
        });

        // One fading score per shopper and store or item. Replaces an event log: see App\Domain\Shopper\Affinities.
        Schema::create('shopper_affinities', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // chain | branch | product | group | category | brand
            $table->unsignedBigInteger('target_id');
            $table->float('score');
            $table->unsignedInteger('events')->default(1);
            $table->timestamp('touched_at');

            $table->primary(['user_id', 'kind', 'target_id']);
            $table->index(['user_id', 'kind', 'score']);
        });

        // Public daily counts for "most viewed". No user, device or IP: nothing here identifies a person.
        Schema::create('engagement_daily', function (Blueprint $table) {
            $table->date('day');
            $table->string('kind', 10); // product | group | chain | branch
            $table->unsignedBigInteger('target_id');
            $table->unsignedBigInteger('region_id')->default(0); // 0 = area unknown
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('plans')->default(0);
            $table->unsignedInteger('directions')->default(0);

            $table->primary(['day', 'kind', 'target_id', 'region_id']);
            $table->index(['kind', 'target_id', 'day']);
        });

        // Computed by search:sync-rank. Kept apart from products so updates don't touch its activity log
        // or updated_at, which the sitemap reports.
        Schema::create('product_stats', function (Blueprint $table) {
            $table->foreignId('product_id')->primary()->constrained()->cascadeOnDelete();
            $table->float('popularity')->default(0); // 0–1 percentile among products with live offers
            $table->unsignedInteger('views_14d')->default(0);
            $table->unsignedInteger('rank')->default(0);
            $table->unsignedTinyInteger('grade')->nullable(); // the grade last pushed to search
            $table->timestamp('updated_at')->nullable();
        });

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('default_radius_km'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->unsignedSmallInteger('default_radius_km')->default(10));
        Schema::dropIfExists('product_stats');
        Schema::dropIfExists('engagement_daily');
        Schema::dropIfExists('shopper_affinities');
        Schema::dropIfExists('place_names');
        Schema::dropIfExists('shopper_places');
        Schema::dropIfExists('shopper_preferences');
    }
};
