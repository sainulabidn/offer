<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            // Limit as the brochure words it, e.g. "Max 3 kg per customer". Translatable: {"en": …, "ar": …}
            $table->jsonb('condition_label')->nullable()->after('promo_label');
        });
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $table) => $table->dropColumn('condition_label'));
    }
};
