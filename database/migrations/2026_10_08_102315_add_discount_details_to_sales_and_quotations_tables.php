<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('discount_type', 50)->nullable()->after('discount_amount');
            $table->string('discount_reason', 255)->nullable()->after('discount_type');
            $table->foreignId('discount_authorized_by')->nullable()->after('discount_reason')->constrained('users')->nullOnDelete();
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->string('discount_type', 50)->nullable()->after('discount_amount');
            $table->string('discount_reason', 255)->nullable()->after('discount_type');
            $table->foreignId('discount_authorized_by')->nullable()->after('discount_reason')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['discount_authorized_by']);
            $table->dropColumn(['discount_type', 'discount_reason', 'discount_authorized_by']);
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropForeign(['discount_authorized_by']);
            $table->dropColumn(['discount_type', 'discount_reason', 'discount_authorized_by']);
        });
    }
};
