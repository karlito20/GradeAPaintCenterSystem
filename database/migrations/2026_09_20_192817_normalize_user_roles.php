<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('users')->whereIn('role', ['staff', 'cashier'])->update(['role' => 'mixer']);
        DB::table('users')->whereNotIn('role', ['dev', 'admin', 'manager', 'mixer'])->update(['role' => 'mixer']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('users')->whereIn('role', ['dev', 'admin', 'manager', 'mixer'])->update(['role' => 'mixer']);
    }
};
