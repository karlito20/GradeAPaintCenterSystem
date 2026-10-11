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
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropUnique('inventories_product_id_unique');
            $table->string('location')->default('storage')->after('product_id');
            $table->unique(['product_id', 'location'], 'inventories_product_location_unique');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropUnique('inventories_product_location_unique');
            $table->dropColumn('location');
            $table->unique('product_id', 'inventories_product_id_unique');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }
};
