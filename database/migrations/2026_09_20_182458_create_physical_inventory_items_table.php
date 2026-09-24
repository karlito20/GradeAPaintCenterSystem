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
        Schema::create('physical_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('physical_inventory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('system_quantity', 12, 3);
            $table->decimal('physical_quantity', 12, 3);
            $table->decimal('variance', 12, 3);
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['physical_inventory_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('physical_inventory_items');
    }
};
