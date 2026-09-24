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
        Schema::create('package_units', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('abbreviation')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::table('package_units')->insert([
            ['name' => '4-gallon pail', 'abbreviation' => '4 gal', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'gallon', 'abbreviation' => 'gal', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'liter', 'abbreviation' => 'L', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => '1/2 liter (pint)', 'abbreviation' => '1/2 L', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => '1/4 liter', 'abbreviation' => '1/4 L', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => '1/8 liter', 'abbreviation' => '1/8 L', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('package_units');
    }
};
