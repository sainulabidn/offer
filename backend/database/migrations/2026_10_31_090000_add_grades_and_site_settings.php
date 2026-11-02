<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1 = the biggest stores … 4 = small local shops. Product cards compare against low numbers first.
        Schema::table('chains', fn (Blueprint $table) => $table->unsignedTinyInteger('grade')->default(3));
        DB::statement("UPDATE chains SET grade = CASE format WHEN 'hypermarket' THEN 1 WHEN 'supermarket' THEN 2 ELSE 3 END");

        // 1 = daily essentials such as eggs and milk. Empty means "not graded yet" (treated as 3);
        // catalog:grade-essentials fills empty groups, and a product can override its group.
        Schema::table('product_groups', fn (Blueprint $table) => $table->unsignedTinyInteger('grade')->nullable());
        Schema::table('products', fn (Blueprint $table) => $table->unsignedTinyInteger('grade')->nullable());

        // Admin settings for the home page and ranking; defaults live in App\Domain\Settings\HomeSettings.
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->jsonb('value');
            $table->timestamps();
        });

        // One price per pack, chain and day, so a brochure republished later doesn't add a second row.
        Schema::table('regular_prices', function (Blueprint $table) {
            $table->dropIndex(['product_variant_id', 'chain_id', 'observed_at']);
            $table->unique(['product_variant_id', 'chain_id', 'observed_at']);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index('updated_at');
        });

        // Offers already published carry "was" prices: start with those.
        DB::statement(<<<'SQL'
            INSERT INTO regular_prices (product_variant_id, chain_id, price, observed_at, source, created_at, updated_at)
            SELECT DISTINCT ON (product_variant_id, chain_id, valid_from)
                   product_variant_id, chain_id, regular_price, valid_from, 'was_price', now(), now()
            FROM offers
            WHERE regular_price IS NOT NULL AND NOT was_price_suspicious AND status IN ('published', 'expired')
            ORDER BY product_variant_id, chain_id, valid_from, id DESC
            SQL);
    }

    public function down(): void
    {
        Schema::table('regular_prices', function (Blueprint $table) {
            $table->dropIndex(['updated_at']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropUnique(['product_variant_id', 'chain_id', 'observed_at']);
            $table->index(['product_variant_id', 'chain_id', 'observed_at']);
        });
        Schema::dropIfExists('site_settings');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('grade'));
        Schema::table('product_groups', fn (Blueprint $table) => $table->dropColumn('grade'));
        Schema::table('chains', fn (Blueprint $table) => $table->dropColumn('grade'));
    }
};
