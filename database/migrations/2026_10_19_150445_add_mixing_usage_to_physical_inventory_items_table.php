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
        Schema::table('physical_inventory_items', function (Blueprint $table) {
            $table->boolean('was_mixing_check')->default(false)->after('variance');
            $table->decimal('estimated_used_quantity', 12, 3)->nullable()->after('was_mixing_check');
        });

        // Backfill any existing physical inventory items where the product was used in mixing components
        $mixingProductIds = DB::table('mixing_components')->pluck('product_id')->unique();
        if ($mixingProductIds->isNotEmpty()) {
            DB::table('physical_inventory_items')
                ->whereIn('product_id', $mixingProductIds)
                ->where('variance', '<', 0)
                ->update([
                    'was_mixing_check' => true,
                    'estimated_used_quantity' => DB::raw('ABS(variance)'),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('physical_inventory_items', function (Blueprint $table) {
            $table->dropColumn(['was_mixing_check', 'estimated_used_quantity']);
        });
    }
};
