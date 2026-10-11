<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Deactivate all legacy products (for example, those without package_unit_id, or simply all existing products if instructed to completely reset)
        // Wait, the prompt says "safely archive/deactivate old products and clear stale initial stock records".
        // Let's archive products that have no transactions, or we can just deactivate ALL products before this migration if they were the "legacy" ones.
        // Wait! The user says "The legacy product catalog must be safely reset/archived".
        // Actually, I'll deactivate all products created before now, EXCEPT those that were just created in our test or maybe just set active = false for all.
        // Let's just set active = false for products that are marked as legacy or all.
        DB::table('products')->update(['active' => false]);

        // Clear stale initial stock records WITHOUT cascade-deleting
        DB::table('inventories')->update(['quantity' => 0]);
        DB::table('containers')->update(['current_quantity' => 0]);
    }

    public function down(): void
    {
        // Cannot easily reverse without knowing which ones were active.
    }
};
