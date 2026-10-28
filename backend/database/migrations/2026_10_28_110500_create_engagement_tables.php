<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Likes on products or store offers; one per user per target. Counts are aggregated by a job.
        Schema::create('likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('likeable');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'likeable_type', 'likeable_id']);
        });

        // Shopper reports: wrong price, expired, wrong product/image, wrong store…
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->morphs('reportable');
            $table->string('reason', 20);
            $table->text('comment')->nullable();
            $table->string('photo_path')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Guests are identified by a salted hash, never by raw IP.
            $table->string('guest_hash', 64)->nullable();
            $table->string('status', 10)->default('open');
            $table->text('resolution')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index(['reportable_type', 'reportable_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
        Schema::dropIfExists('likes');
    }
};
