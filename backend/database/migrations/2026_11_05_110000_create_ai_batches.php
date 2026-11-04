<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Flyer pages sent through a provider's batch API at half price (App\Domain\Flyers\FlyerBatches). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_batches', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('model', 80);
            $table->string('external_id', 120)->nullable();
            // submitted | ended | failed
            $table->string('status', 12)->default('submitted');
            $table->unsignedInteger('page_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::table('brochure_pages', fn (Blueprint $table) => $table->foreignId('ai_batch_id')->nullable()->constrained()->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('brochure_pages', fn (Blueprint $table) => $table->dropConstrainedForeignId('ai_batch_id'));
        Schema::dropIfExists('ai_batches');
    }
};
