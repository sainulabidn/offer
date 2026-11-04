<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a store's flyers can be fetched automatically (App\Domain\Flyers\Sources): a PDF link that stays the
 * same, or a web page that links to the current PDFs. A store may have several, e.g. one per region.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brochure_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chain_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            // pdf_url: the link is the flyer. page: a page whose links (link_pattern) are the flyers.
            $table->string('kind', 10)->default('pdf_url');
            $table->text('url');
            $table->string('link_pattern', 200)->nullable();
            // The branches its flyers are for; empty = every branch of the store.
            $table->jsonb('branch_ids')->default('[]');
            $table->unsignedSmallInteger('check_minutes')->default(60);
            $table->timestamp('next_check_at')->nullable()->index();
            $table->string('etag')->nullable();
            $table->string('last_modified')->nullable();
            $table->char('last_hash', 64)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_new_at')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('failure_alerted_at')->nullable();
            $table->timestamp('stale_alerted_at')->nullable();
            // null: the Flyer AI setting; review or auto for this source only
            $table->string('publish_mode', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Every check of a source: what happened, for the admin and for "seen this link before".
        Schema::create('brochure_fetches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brochure_source_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            // new | unchanged | duplicate | no_links | blocked | failed
            $table->string('outcome', 12);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->foreignId('brochure_id')->nullable()->constrained()->nullOnDelete();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['brochure_source_id', 'created_at']);
        });

        Schema::table('brochures', fn (Blueprint $table) => $table->foreignId('brochure_source_id')->nullable()->constrained()->nullOnDelete());

        // Stores whose PDF link staff already noted become sources (they only run with the store's permission).
        DB::statement(<<<'SQL'
            INSERT INTO brochure_sources (chain_id, name, kind, url, next_check_at, created_at, updated_at)
            SELECT id, 'Flyer PDF', 'pdf_url', brochure_pdf_url, now(), now(), now()
            FROM chains WHERE coalesce(brochure_pdf_url, '') <> ''
            SQL);
    }

    public function down(): void
    {
        Schema::table('brochures', fn (Blueprint $table) => $table->dropConstrainedForeignId('brochure_source_id'));
        Schema::dropIfExists('brochure_fetches');
        Schema::dropIfExists('brochure_sources');
    }
};
