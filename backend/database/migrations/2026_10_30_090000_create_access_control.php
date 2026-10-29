<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tables whose records remember who created them ("own" permissions). Brochures and offers already do. */
    private const array OWNED = ['retail_groups', 'chains', 'branches', 'categories', 'brands', 'product_groups', 'products', 'search_synonyms'];

    /** Shared catalog: items added by store owners wait for staff review before the website shows them. */
    private const array REVIEWED = ['categories', 'brands', 'product_groups', 'products'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('all_stores')->default(false); // staff who work on every store
            $table->timestamp('disabled_at')->nullable(); // can't sign in to the admin
        });

        // Which stores an admin user works on: whole retail groups, chains, or single branches.
        Schema::create('user_store_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type', 20); // retail_group | chain | branch
            $table->unsignedBigInteger('scope_id');
            $table->timestamps();
            $table->unique(['user_id', 'scope_type', 'scope_id']);
            $table->index(['scope_type', 'scope_id']);
        });

        foreach (self::OWNED as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete());
        }

        foreach (self::REVIEWED as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('review_status', 20)->default('approved')->index(); // approved | pending | rejected
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_note')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::REVIEWED as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('reviewed_by'));
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['review_status', 'reviewed_at', 'review_note']));
        }
        foreach (self::OWNED as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('created_by'));
        }
        Schema::dropIfExists('user_store_access');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['all_stores', 'disabled_at']));
    }
};
