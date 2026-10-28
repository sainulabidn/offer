<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // postgis: branch locations; pg_trgm + unaccent: fuzzy name matching; btree_gist: temporal constraints
        foreach (['postgis', 'pg_trgm', 'unaccent', 'btree_gist'] as $extension) {
            DB::statement("CREATE EXTENSION IF NOT EXISTS {$extension}");
        }
    }

    public function down(): void
    {
        // Extensions are shared by the whole database; leave them in place.
    }
};
