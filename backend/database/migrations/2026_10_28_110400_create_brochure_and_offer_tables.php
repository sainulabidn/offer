<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brochures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chain_id')->constrained();
            $table->jsonb('title');
            $table->string('slug')->unique();
            // pdf | images | digital (web-only offers, so every offer has a source)
            $table->string('type', 10)->default('pdf');
            $table->date('valid_from');
            $table->date('valid_to');
            $table->string('status', 20)->default('uploaded');
            $table->string('source', 20)->default('admin_upload');
            $table->string('source_url')->nullable();
            $table->string('original_path')->nullable();
            $table->string('file_hash', 64)->nullable()->index();
            $table->unsignedSmallInteger('page_count')->default(0);
            $table->string('reading_direction', 3)->default('ltr');
            $table->string('channel', 10)->default('in_store');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['chain_id', 'status']);
        });
        DB::statement("ALTER TABLE brochures ADD COLUMN validity daterange GENERATED ALWAYS AS (daterange(valid_from, valid_to, '[]')) STORED");
        DB::statement('CREATE INDEX brochures_validity_idx ON brochures USING gist (validity)');

        // Where a brochure applies: whole chain, an emirate/region, listed branches or a whole group.
        Schema::create('brochure_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brochure_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type', 10);
            $table->unsignedBigInteger('scope_id');
        });

        // Scopes resolved to concrete branches at publish time.
        Schema::create('brochure_branch', function (Blueprint $table) {
            $table->foreignId('brochure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            $table->primary(['brochure_id', 'branch_id']);
            $table->index('branch_id');
        });

        Schema::create('brochure_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brochure_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('page_no');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('image_path')->nullable();
            // Perceptual hash: a page reused from an earlier brochure is detected and its offers copied.
            $table->string('phash', 32)->nullable()->index();
            $table->jsonb('text_layer')->nullable();
            $table->string('page_type', 12)->nullable();
            $table->string('ai_status', 12)->default('pending');
            $table->string('review_status', 12)->default('pending');
            $table->timestamps();

            $table->unique(['brochure_id', 'page_no']);
        });

        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brochure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brochure_page_id')->nullable()->constrained()->nullOnDelete();
            // Hotspot on the page, normalized 0–1: {x, y, w, h}
            $table->jsonb('bbox')->nullable();
            $table->foreignId('product_variant_id')->constrained();
            $table->foreignId('chain_id')->constrained();
            $table->decimal('price', 10, 2);
            $table->decimal('regular_price', 10, 2)->nullable();
            $table->char('currency', 3)->default('AED');
            // price | was_now | multibuy | bogo | percent_off | card_price | bundle | gift | coupon
            $table->string('promo_type', 12)->default('price');
            $table->jsonb('promo_params')->default('{}');
            $table->jsonb('promo_label')->nullable();
            // min_qty, max_per_customer, online_only, selected_branches, day/time windows
            $table->jsonb('conditions')->default('{}');
            $table->decimal('unit_price', 12, 4)->nullable();
            $table->decimal('effective_unit_price', 12, 4)->nullable();
            $table->decimal('discount_pct', 5, 2)->nullable();
            $table->decimal('deal_score', 5, 2)->nullable();
            $table->date('valid_from');
            $table->date('valid_to');
            $table->string('status', 12)->default('draft');
            $table->decimal('confidence', 4, 3)->nullable();
            $table->jsonb('raw_text')->nullable();
            $table->unsignedInteger('like_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['brochure_id', 'status']);
            $table->index(['chain_id', 'status']);
        });
        DB::statement("ALTER TABLE offers ADD COLUMN validity daterange GENERATED ALWAYS AS (daterange(valid_from, valid_to, '[]')) STORED");
        DB::statement('CREATE INDEX offers_validity_idx ON offers USING gist (validity)');
        DB::statement("CREATE INDEX offers_published_variant_idx ON offers (product_variant_id) WHERE status = 'published'");

        // "Assorted" deals that apply to several variants.
        Schema::create('offer_variants', function (Blueprint $table) {
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();

            $table->primary(['offer_id', 'product_variant_id']);
        });

        // Rare "selected branches only" offers override the brochure's branch scope.
        Schema::create('offer_branch', function (Blueprint $table) {
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            $table->primary(['offer_id', 'branch_id']);
        });

        // Non-promo prices: estimate missing plan items and flag inflated "was" prices.
        Schema::create('regular_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chain_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->date('observed_at');
            $table->string('source', 20);
            $table->timestamps();

            $table->index(['product_variant_id', 'chain_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regular_prices');
        Schema::dropIfExists('offer_branch');
        Schema::dropIfExists('offer_variants');
        Schema::dropIfExists('offers');
        Schema::dropIfExists('brochure_pages');
        Schema::dropIfExists('brochure_branch');
        Schema::dropIfExists('brochure_scopes');
        Schema::dropIfExists('brochures');
    }
};
