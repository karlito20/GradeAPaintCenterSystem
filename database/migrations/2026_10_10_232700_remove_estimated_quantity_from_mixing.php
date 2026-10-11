<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mixing_components', function (Blueprint $table) {
            $table->dropColumn(['estimated_quantity', 'estimated_quantity_unit']);
            // The can resolution logic needs to store the container id here.
            $table->foreignId('container_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mixing_components', function (Blueprint $table) {
            $table->decimal('estimated_quantity', 12, 3)->default(0);
            $table->string('estimated_quantity_unit')->default('L');
            $table->dropForeign(['container_id']);
            $table->dropColumn('container_id');
        });
    }
};
