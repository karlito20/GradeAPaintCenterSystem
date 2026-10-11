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
            $table->decimal('downpayment_amount', 12, 2)->default(0)->after('payment_amount');
            $table->decimal('balance_due', 12, 2)->default(0)->after('downpayment_amount');
            $table->string('payment_status')->default('paid')->after('balance_due');
        });

        Schema::table('mixing_transactions', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('sale_id')->constrained('brands')->nullOnDelete();
            $table->string('custom_color')->nullable()->after('brand_id');
            $table->decimal('price_basis', 12, 2)->nullable()->after('price_basis_product_id');
            $table->string('status')->default('pending_mix')->after('notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['downpayment_amount', 'balance_due', 'payment_status']);
        });

        Schema::table('mixing_transactions', function (Blueprint $table) {
            $table->dropForeign(['brand_id']);
            $table->dropColumn(['brand_id', 'custom_color', 'price_basis', 'status']);
        });
    }
};
