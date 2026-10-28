<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Tell me when this is on offer": a product, one pack, or any brand of a product group.
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 12); // product | variant | group
            $table->unsignedBigInteger('target_id');
            $table->decimal('max_unit_price', 12, 4)->nullable();
            $table->unsignedTinyInteger('min_discount')->nullable();
            $table->geography('location', subtype: 'point', srid: 4326)->nullable();
            $table->unsignedSmallInteger('radius_km')->nullable();
            $table->string('place')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'target_type', 'target_id']);
            $table->index(['target_type', 'target_id', 'is_active']);
        });

        // Which offers each user was already told about, so nobody hears about the same deal twice.
        Schema::create('alert_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'offer_id']);
        });

        Schema::table('offers', function (Blueprint $table) {
            // Set at publish by DealScorer from the variant's last 90 days of prices.
            $table->boolean('is_lowest_90d')->default(false)->after('deal_score');
            $table->boolean('was_price_suspicious')->default(false)->after('is_lowest_90d');
        });
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $table) => $table->dropColumn(['is_lowest_90d', 'was_price_suspicious']));
        Schema::dropIfExists('alert_deliveries');
        Schema::dropIfExists('alerts');
    }
};
