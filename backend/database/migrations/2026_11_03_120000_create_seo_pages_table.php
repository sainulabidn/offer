<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Search engine and social texts for one website path. A path under /deals/ is a page of its own,
        // built from the saved search filters; any other path ("/dubai/c/dairy") overrides what that page writes.
        Schema::create('seo_pages', function (Blueprint $table) {
            $table->id();
            $table->string('path')->unique();
            $table->jsonb('title')->nullable();
            $table->jsonb('description')->nullable();
            $table->jsonb('heading')->nullable();
            $table->jsonb('intro')->nullable();
            $table->string('image_path')->nullable();
            // q, category_id, brand_ids, chain_ids, region_id, product_group_id, min_discount, card_only, sort
            $table->jsonb('filters')->default('{}');
            $table->boolean('noindex')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_pages');
    }
};
