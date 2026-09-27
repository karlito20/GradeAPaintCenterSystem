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
        Schema::table('mixing_transactions', function (Blueprint $table): void {
            $table->decimal('resulting_quantity', 12, 3)->nullable()->after('price_basis_product_id');
            $table->string('resulting_unit')->nullable()->after('resulting_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mixing_transactions', function (Blueprint $table): void {
            $table->dropColumn(['resulting_quantity', 'resulting_unit']);
        });
    }
};
