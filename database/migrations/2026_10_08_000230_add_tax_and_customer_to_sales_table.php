<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('customer_name')->nullable()->after('user_id');
            $table->string('customer_contact')->nullable()->after('customer_name');
            $table->decimal('tax_rate', 5, 2)->default(0)->after('discount_amount');
            $table->decimal('tax_amount', 12, 2)->default(0)->after('tax_rate');
            $table->foreignId('quotation_id')->nullable()->after('payment_amount')
                ->constrained('quotations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['quotation_id']);
            $table->dropColumn(['customer_name', 'customer_contact', 'tax_rate', 'tax_amount', 'quotation_id']);
        });
    }
};
