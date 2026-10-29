<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A shopping plan synced from the browser. The id is a UUID made on the device, so a plan
        // created offline keeps its id when it reaches the server. Items and settings are stored as
        // the browser's JSON; the newest save wins (client_updated_at).
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80)->default('My plan');
            $table->jsonb('items')->default('[]');
            $table->jsonb('settings')->default('{}');
            $table->string('share_token', 40)->nullable()->unique();
            $table->timestamp('client_updated_at');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('user_id');
        });

        // Family members who joined a shared plan and can edit it.
        Schema::create('plan_members', function (Blueprint $table) {
            $table->uuid('plan_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['plan_id', 'user_id']);
            $table->foreign('plan_id')->references('id')->on('plans')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_members');
        Schema::dropIfExists('plans');
    }
};
