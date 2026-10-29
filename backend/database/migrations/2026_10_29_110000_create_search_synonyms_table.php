<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Search synonyms managed in the admin and pushed to the Typesense synonym set (App\Domain\Search\Synonyms).
        Schema::create('search_synonyms', function (Blueprint $table) {
            $table->id();
            $table->jsonb('terms'); // lower-case words or phrases, e.g. ["atta", "wheat flour", "طحين"]
            $table->string('root')->nullable(); // set = one-way: searching root also finds the terms, not the reverse
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_synonyms');
    }
};
