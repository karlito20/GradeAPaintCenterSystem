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
        if (! DB::table('users')->whereIn('role', ['dev', 'admin'])->exists()) {
            DB::table('users')->orderBy('id')->limit(1)->update(['role' => 'admin']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The promotion is intentionally not reversed to avoid removing an existing admin role.
    }
};
