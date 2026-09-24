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
        Schema::table('mixing_components', function (Blueprint $table): void {
            $table->string('estimated_quantity_unit')->after('estimated_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mixing_components', function (Blueprint $table): void {
            $table->dropColumn('estimated_quantity_unit');
        });
    }
};
