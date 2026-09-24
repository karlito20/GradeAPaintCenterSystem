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
        Schema::table('brands', function (Blueprint $table): void {
            $table->boolean('active')->default(true);
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->boolean('active')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn('active');
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('active');
        });
    }
};
