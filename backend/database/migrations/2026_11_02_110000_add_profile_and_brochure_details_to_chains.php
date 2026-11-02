<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chains', function (Blueprint $table) {
            // For shoppers.
            $table->string('online_shop_url')->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->string('brochure_page_url')->nullable();
            $table->string('brochure_frequency', 12)->nullable();

            // For staff collecting brochures.
            $table->string('brochure_pdf_url')->nullable();
            $table->string('brochure_contact')->nullable();
            $table->string('content_permission', 12)->default('unknown');
            $table->text('staff_notes')->nullable();

            // Staff checked these details, including the parent group, on this date.
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chains', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn([
                'online_shop_url', 'customer_phone', 'brochure_page_url', 'brochure_frequency',
                'brochure_pdf_url', 'brochure_contact', 'content_permission', 'staff_notes', 'is_verified', 'verified_at',
            ]);
        });
    }
};
