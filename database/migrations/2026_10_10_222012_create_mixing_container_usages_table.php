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
        Schema::create('mixing_container_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mixing_transaction_id')->constrained('mixing_transactions')->cascadeOnDelete();
            $table->foreignId('container_id')->constrained('containers')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mixing_container_usages');
    }
};
