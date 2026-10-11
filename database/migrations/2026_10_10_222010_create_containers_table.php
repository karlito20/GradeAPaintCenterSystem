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
        Schema::create('containers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('location')->default('storage');
            $table->string('identifier')->unique(); // e.g. CAN-0001
            $table->decimal('capacity', 12, 3);
            $table->decimal('current_quantity', 12, 3);
            $table->foreignId('package_unit_id')->nullable()->constrained('package_units')->nullOnDelete();
            $table->boolean('is_sealed')->default(true);
            $table->boolean('requires_physical_check')->default(false);
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('containers');
    }
};
