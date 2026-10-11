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
        if (Schema::hasColumn('categories', 'is_for_mixing')) {
            try {
                Schema::table('categories', function (Blueprint $table) {
                    $table->dropIndex(['is_for_mixing']);
                });
            } catch (Throwable $e) {
                // index might not exist or already dropped
            }

            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('is_for_mixing');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_for_mixing')->default(false)->after('active');
        });
    }
};
