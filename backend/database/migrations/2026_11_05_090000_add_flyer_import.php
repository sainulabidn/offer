<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Importing offers from flyer PDFs and photos with AI (App\Domain\Flyers): page renders and AI answers,
 * the items read from each page before anyone accepts them, learned product matches and the AI bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        // CDN links to flyers are often longer than 255 characters.
        Schema::table('brochures', function (Blueprint $table) {
            $table->text('source_url')->nullable()->change();
            $table->foreignId('ai_requested_by')->nullable()->constrained('users')->nullOnDelete();
            // fast: one AI call per page now; economy: the providers' half-price batch
            $table->string('ai_mode', 10)->nullable();
            // What the AI read off the pages: {valid_from, valid_to, locations[]}, for the reviewer to use
            $table->jsonb('detected')->nullable();
        });
        Schema::table('chains', fn (Blueprint $table) => $table->text('brochure_pdf_url')->nullable()->change());

        Schema::table('brochure_pages', function (Blueprint $table) {
            // sha256 of the rendered page: the same page in a later or regional edition reuses the AI answer
            $table->char('content_hash', 64)->nullable()->index();
            $table->string('thumb_path')->nullable();
            // The AI's answer, kept so matching can run again without paying for another call
            $table->jsonb('ai_output')->nullable();
            $table->text('ai_error')->nullable();
            $table->unsignedSmallInteger('ai_attempts')->default(0);
            $table->foreignId('reused_from_page_id')->nullable()->constrained('brochure_pages')->nullOnDelete();
            $table->timestamp('extracted_at')->nullable();
        });

        // Offers read off a page, before anyone accepts them; an accepted item becomes a draft offer.
        Schema::create('brochure_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brochure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brochure_page_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->jsonb('raw');
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('brand_text', 120)->nullable();
            $table->string('size_text', 80)->nullable();
            $table->unsignedSmallInteger('pack_count')->nullable();
            $table->decimal('unit_qty', 10, 3)->nullable();
            $table->string('unit_code', 10)->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('regular_price', 10, 2)->nullable();
            $table->string('promo_type', 12)->default('price');
            $table->jsonb('promo_params')->default('{}');
            $table->jsonb('promo_label')->nullable();
            $table->jsonb('conditions')->default('{}');
            $table->jsonb('condition_label')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            // Where on the page, normalised 0–1: {x, y, w, h}
            $table->jsonb('bbox')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            // barcode | alias | name | manual
            $table->string('match_method', 12)->nullable();
            $table->decimal('match_score', 4, 3)->nullable();
            // Other likely packs: [{id, score}]
            $table->jsonb('candidates')->default('[]');
            // new | matched | accepted | ignored
            $table->string('status', 12)->default('new');
            // What a reviewer should check: [{code, message}]
            $table->jsonb('issues')->default('[]');
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['brochure_id', 'status']);
        });

        // "This flyer name and size is this pack", learned from reviewers, per store or for all (chain_id null).
        Schema::create('flyer_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chain_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 160);
            $table->string('size_key', 40)->default('');
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('hits')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX flyer_aliases_key_unique ON flyer_aliases (chain_id, key, size_key) NULLS NOT DISTINCT');

        // Every AI call and what it cost, for the budget and the usage figures.
        Schema::create('ai_calls', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('model', 80);
            // sync | batch
            $table->string('mode', 10)->default('sync');
            $table->string('purpose', 20)->default('flyer_page');
            $table->foreignId('brochure_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brochure_page_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 5)->default(0);
            // ok | refusal | truncated | invalid | error
            $table->string('status', 12)->default('ok');
            $table->text('error')->nullable();
            $table->string('request_id', 120)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        // Fuzzy product-name matching for flyer items (pg_trgm is enabled by 2026_10_28_110000).
        DB::statement("CREATE INDEX products_name_en_trgm ON products USING gin ((lower(name->>'en')) gin_trgm_ops)");
        DB::statement("CREATE INDEX products_name_ar_trgm ON products USING gin ((name->>'ar') gin_trgm_ops)");
        DB::statement("CREATE INDEX brands_name_en_trgm ON brands USING gin ((lower(name->>'en')) gin_trgm_ops)");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS brands_name_en_trgm');
        DB::statement('DROP INDEX IF EXISTS products_name_ar_trgm');
        DB::statement('DROP INDEX IF EXISTS products_name_en_trgm');
        Schema::dropIfExists('ai_calls');
        Schema::dropIfExists('flyer_aliases');
        Schema::dropIfExists('brochure_items');
        Schema::table('brochure_pages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reused_from_page_id');
            $table->dropColumn(['content_hash', 'thumb_path', 'ai_output', 'ai_error', 'ai_attempts', 'extracted_at']);
        });
        Schema::table('brochures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_requested_by');
            $table->dropColumn(['ai_mode', 'detected']);
        });
    }
};
