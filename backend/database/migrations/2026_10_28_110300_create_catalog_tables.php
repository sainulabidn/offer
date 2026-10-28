<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->jsonb('name');
            $table->string('slug')->unique();
            // Materialized ancestor path such as "/1/5/" so a parent category can match all of its children.
            $table->string('path')->default('/');
            $table->unsignedTinyInteger('depth')->default(0);
            $table->string('icon')->nullable();
            $table->string('image_path')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['parent_id', 'sort']);
        });
        DB::statement('CREATE INDEX categories_path_prefix_idx ON categories (path text_pattern_ops)');

        // g, kg, ml, l, pc, sheet… factor_to_base converts to the dimension's base unit (g, ml, pc…).
        Schema::create('units', function (Blueprint $table) {
            $table->string('code', 12)->primary();
            $table->string('dimension', 12);
            $table->decimal('factor_to_base', 18, 6);
            $table->jsonb('name');
            $table->jsonb('aliases')->default('[]');
        });

        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->jsonb('name');
            $table->string('data_type', 20);
            $table->string('unit_code', 12)->nullable();
            $table->boolean('is_filterable')->default(true);
            $table->boolean('is_searchable')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->foreign('unit_code')->references('code')->on('units');
        });

        Schema::create('attribute_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->jsonb('name');
            $table->jsonb('synonyms')->default('[]');
            $table->unsignedSmallInteger('sort')->default(0);

            $table->unique(['attribute_id', 'code']);
        });

        Schema::create('category_attribute', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            // variant: makes a different variant (fat % for milk); descriptive: a filter only (organic)
            $table->string('role', 12)->default('descriptive');
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);

            $table->primary(['category_id', 'attribute_id']);
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->jsonb('name');
            $table->string('slug')->unique();
            $table->string('manufacturer')->nullable();
            $table->boolean('is_private_label')->default(false);
            $table->foreignId('owner_chain_id')->nullable()->constrained('chains')->nullOnDelete();
            $table->string('logo_path')->nullable();
            $table->jsonb('synonyms')->default('[]');
            $table->timestamps();
        });

        // Generic comparable product ("Basmati Rice"): compares across brands per comparison unit.
        Schema::create('product_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained();
            $table->jsonb('name');
            $table->string('slug')->unique();
            // Prices compare per comparison_quantity × comparison_unit_code, e.g. per 1 kg or per 100 sheet.
            $table->string('comparison_unit_code', 12);
            $table->decimal('comparison_quantity', 10, 3)->default(1);
            $table->jsonb('synonyms')->default('[]');
            $table->string('image_path')->nullable();
            $table->timestamps();

            $table->foreign('comparison_unit_code')->references('code')->on('units');
        });

        // One product page, like an online store ("India Gate Classic Basmati Rice").
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_group_id')->constrained();
            $table->foreignId('category_id')->constrained();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('name');
            $table->string('slug')->unique();
            $table->jsonb('description')->nullable();
            // Descriptive attribute values (organic, gluten-free…), keyed by attribute code.
            $table->jsonb('attributes')->default('{}');
            $table->boolean('is_variable_weight')->default(false);
            $table->string('status', 12)->default('draft');
            $table->unsignedInteger('like_count')->default(0);
            $table->timestamps();

            $table->index(['category_id', 'status']);
            $table->index('brand_id');
        });
        DB::statement('CREATE INDEX products_attributes_gin_idx ON products USING gin (attributes)');

        // Variation dimensions of one product: Size, Type, Flavour, Origin…
        Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('name');
            $table->unsignedSmallInteger('sort')->default(0);
        });

        Schema::create('product_option_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_option_id')->constrained()->cascadeOnDelete();
            $table->jsonb('label');
            $table->string('code', 60);
            $table->unsignedSmallInteger('sort')->default(0);

            $table->unique(['product_option_id', 'code']);
        });

        // One purchasable pack ("5 kg bag, India"). Offers point here.
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('gtin', 14)->nullable()->unique();
            $table->jsonb('name')->nullable();
            $table->string('slug');
            // Sorted option value IDs ("3-7"); unique per product, so each combination exists once.
            $table->string('option_signature')->default('');
            $table->unsignedSmallInteger('pack_count')->default(1);
            $table->decimal('unit_qty', 12, 3);
            $table->string('unit_code', 12);
            $table->decimal('qty_max', 12, 3)->nullable();
            // pack_count × unit_qty × unit factor, in the dimension's base unit (g, ml, pc…).
            $table->decimal('net_base_qty', 14, 3);
            $table->jsonb('attributes')->default('{}');
            $table->boolean('is_default')->default(false);
            $table->string('status', 12)->default('active');
            $table->foreignId('merged_into_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->foreign('unit_code')->references('code')->on('units');
            $table->unique(['product_id', 'slug']);
            $table->unique(['product_id', 'option_signature']);
        });

        Schema::create('product_variant_option_value', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_option_value_id')->constrained()->cascadeOnDelete();

            $table->primary(['product_variant_id', 'product_option_value_id']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            // Base path; responsive AVIF/WebP sizes are stored next to it.
            $table->string('path');
            $table->jsonb('alt')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('thumbhash', 64)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_variant_option_value');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_option_values');
        Schema::dropIfExists('product_options');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_groups');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('category_attribute');
        Schema::dropIfExists('attribute_options');
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('units');
        Schema::dropIfExists('categories');
    }
};
