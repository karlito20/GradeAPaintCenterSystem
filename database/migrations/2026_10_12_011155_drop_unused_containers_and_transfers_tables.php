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
        Schema::dropIfExists('mixing_container_usages');

        if (Schema::hasColumn('mixing_components', 'container_id')) {
            Schema::table('mixing_components', function (Blueprint $table) {
                $table->dropForeign(['container_id']);
                $table->dropColumn('container_id');
            });
        }

        if (Schema::hasColumn('inventory_movements', 'container_id')) {
            Schema::table('inventory_movements', function (Blueprint $table) {
                $table->dropForeign(['container_id']);
                $table->dropColumn('container_id');
            });
        }

        Schema::dropIfExists('inventory_transfers');
        Schema::dropIfExists('containers');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('containers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('location')->default('storage');
            $table->string('identifier')->unique();
            $table->decimal('capacity', 12, 3);
            $table->decimal('current_quantity', 12, 3);
            $table->foreignId('package_unit_id')->nullable()->constrained('package_units')->nullOnDelete();
            $table->boolean('is_sealed')->default(true);
            $table->boolean('requires_physical_check')->default(false);
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('container_id')->nullable()->after('location')->constrained('containers')->nullOnDelete();
        });

        Schema::table('mixing_components', function (Blueprint $table) {
            $table->foreignId('container_id')->nullable()->after('product_id')->constrained('containers')->nullOnDelete();
        });

        Schema::create('mixing_container_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mixing_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('container_id')->constrained('containers')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('container_id')->nullable()->constrained('containers')->cascadeOnDelete();
            $table->string('from_location');
            $table->string('to_location');
            $table->decimal('quantity', 12, 3);
            $table->timestamp('transferred_at')->useCurrent();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }
};
