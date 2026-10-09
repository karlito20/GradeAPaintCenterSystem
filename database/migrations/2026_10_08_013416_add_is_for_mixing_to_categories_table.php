<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_for_mixing')->default(false)->after('active')->index();
        });

        // Set existing tinting/mixing categories to is_for_mixing = true
        DB::table('categories')
            ->where('name', 'like', '%Tinting%')
            ->orWhere('name', 'like', '%Colorants%')
            ->orWhere('name', 'like', '%Mixing%')
            ->update(['is_for_mixing' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('is_for_mixing');
        });
    }
};
