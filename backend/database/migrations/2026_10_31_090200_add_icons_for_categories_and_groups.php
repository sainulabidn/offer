<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Starter emoji for the seeded categories. Staff can change them; only empty ones are filled. */
    private const array ICONS = [
        'fresh-food' => '🥕', 'fruits-vegetables' => '🍅', 'meat-poultry' => '🍗', 'fish-seafood' => '🐟', 'dairy-eggs' => '🥚', 'bakery' => '🍞',
        'pantry' => '🥫', 'rice-grains' => '🍚', 'oil-ghee' => '🧈', 'flour-baking' => '🌾', 'pulses-lentils' => '🥜', 'spices' => '🌶️',
        'tea-coffee' => '☕', 'canned-food' => '🥫', 'breakfast-cereals' => '🥣', 'snacks-sweets' => '🍫', 'frozen-food' => '🧊',
        'beverages' => '🥤', 'water' => '💧', 'soft-drinks' => '🥤', 'juices' => '🧃',
        'household' => '🧺', 'laundry' => '🧺', 'cleaning' => '🧽', 'paper-tissues' => '🧻', 'personal-care' => '🧴', 'baby' => '🍼',
    ];

    public function up(): void
    {
        // An emoji shown on the card when a product has no photo; falls back to its category's.
        Schema::table('product_groups', fn (Blueprint $table) => $table->string('icon', 16)->nullable());

        foreach (self::ICONS as $slug => $icon) {
            DB::table('categories')->where('slug', $slug)->whereNull('icon')->update(['icon' => $icon]);
        }
    }

    public function down(): void
    {
        Schema::table('product_groups', fn (Blueprint $table) => $table->dropColumn('icon'));
    }
};
