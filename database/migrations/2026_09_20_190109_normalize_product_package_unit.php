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
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('package_unit_id')->nullable()->after('package_size')->constrained('package_units')->nullOnDelete();
        });

        DB::table('products')->whereNotNull('package_unit')->orderBy('id')->each(function (object $product): void {
            $unit = DB::table('package_units')->where('name', $product->package_unit)->orWhere('abbreviation', $product->package_unit)->first();
            if ($unit) {
                DB::table('products')->where('id', $product->id)->update(['package_unit_id' => $unit->id]);
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('package_unit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('package_unit')->nullable();
            $table->dropConstrainedForeignId('package_unit_id');
        });
    }
};
