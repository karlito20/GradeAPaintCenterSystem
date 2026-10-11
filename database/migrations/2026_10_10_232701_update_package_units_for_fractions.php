<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_units', function (Blueprint $table) {
            $table->boolean('is_for_paint')->default(true)->after('abbreviation');
            $table->decimal('gallon_fraction', 8, 4)->nullable()->after('is_for_paint');
        });

        // Setup initial default fractions
        DB::table('package_units')->where('name', '4-gallon pail')->update(['gallon_fraction' => 4.0]);
        DB::table('package_units')->where('name', 'gallon')->update(['gallon_fraction' => 1.0]);
        DB::table('package_units')->where('name', 'liter')->update(['gallon_fraction' => 0.25]); // 1/4 gallon as per business rule
        DB::table('package_units')->where('name', '1/2 liter (pint)')->update(['gallon_fraction' => 0.125]);
        DB::table('package_units')->where('name', '1/4 liter')->update(['gallon_fraction' => 0.0625]);
        DB::table('package_units')->where('name', '1/8 liter')->update(['gallon_fraction' => 0.03125]);
    }

    public function down(): void
    {
        Schema::table('package_units', function (Blueprint $table) {
            $table->dropColumn(['is_for_paint', 'gallon_fraction']);
        });
    }
};
