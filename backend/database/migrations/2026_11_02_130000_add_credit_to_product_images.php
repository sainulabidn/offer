<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            // Shown under the photo when its licence asks for credit, e.g. "Photo: Jane Doe, CC BY-SA 4.0".
            $table->string('credit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_images', fn (Blueprint $table) => $table->dropColumn('credit'));
    }
};
