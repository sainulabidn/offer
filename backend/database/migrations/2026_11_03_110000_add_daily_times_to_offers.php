<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            // A daily window in market time, e.g. 08:00–11:00 on every day from valid_from to valid_to.
            // Both empty = the whole day.
            $table->time('start_time')->nullable()->after('valid_to');
            $table->time('end_time')->nullable()->after('start_time');
        });
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_daily_window_check CHECK ((start_time IS NULL AND end_time IS NULL) OR (start_time IS NOT NULL AND end_time IS NOT NULL AND end_time > start_time))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE offers DROP CONSTRAINT IF EXISTS offers_daily_window_check');
        Schema::table('offers', fn (Blueprint $table) => $table->dropColumn(['start_time', 'end_time']));
    }
};
