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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku')->unique();
            $table->string('name');
            $table->decimal('package_size', 10, 3)->nullable();
            $table->string('package_unit')->nullable();
            $table->decimal('selling_price', 12, 2)->default(0);
            $table->decimal('low_stock_threshold', 12, 3)->default(0);
            $table->string('manufacturer_code')->nullable();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->index(['category_id', 'active']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
