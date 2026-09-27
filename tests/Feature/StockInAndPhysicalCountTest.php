<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\PackageUnit;
use App\Models\PhysicalInventory;
use App\Models\Product;
use App\Models\StockIn;
use App\Models\User;
use Livewire\Volt\Volt;

test('stock-in receiving updates inventory and logs movements and audits', function () {
    $user = User::factory()->create(['role' => 'manager']);
    $brand = Brand::create(['name' => 'Davies', 'active' => true]);
    $category = Category::create(['name' => 'Megacryl', 'active' => true]);
    $unit = PackageUnit::create(['name' => 'Gallon', 'abbreviation' => 'gal', 'active' => true]);

    $product = Product::create([
        'sku' => 'DAV-WHITE-GAL',
        'name' => 'Davies Megacryl White',
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'package_unit_id' => $unit->id,
        'package_size' => 4.0,
        'selling_price' => 620.00,
        'low_stock_threshold' => 5,
        'active' => true,
    ]);

    Inventory::create(['product_id' => $product->id, 'quantity' => 4.0]);

    Volt::actingAs($user)
        ->test('pages.inventory.stock-in')
        ->set('receivedAt', now()->toDateString())
        ->set('notes', 'Supplier Delivery Batch #9921')
        ->set('productId', (string) $product->id)
        ->set('quantity', '12.0')
        ->set('unitCost', '480.00')
        ->call('addItem')
        ->call('saveStockIn')
        ->assertHasNoErrors();

    // Verify inventory incremented from 4.0 to 16.0
    expect((float) $product->fresh()->inventory->quantity)->toBe(16.0);

    // Verify StockIn record
    $stockIn = StockIn::latest('id')->first();
    expect($stockIn)->not->toBeNull()
        ->and($stockIn->notes)->toBe('Supplier Delivery Batch #9921')
        ->and($stockIn->items->count())->toBe(1)
        ->and((float) $stockIn->items->first()->quantity)->toBe(12.0)
        ->and((float) $stockIn->items->first()->unit_cost)->toBe(480.00);

    // Verify inventory movement
    $movement = InventoryMovement::where('product_id', $product->id)->where('type', 'stock_in')->latest('id')->first();
    expect($movement)->not->toBeNull()
        ->and((float) $movement->quantity_change)->toBe(12.0)
        ->and((float) $movement->quantity_before)->toBe(4.0)
        ->and((float) $movement->quantity_after)->toBe(16.0);

    // Verify audit log
    $audit = AuditLog::where('auditable_type', StockIn::class)->where('auditable_id', $stockIn->id)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->event)->toBe('stock_in_created');
});

test('weekly physical inventory reconciles stock with dipstick variance and creates adjustment movements', function () {
    $user = User::factory()->create(['role' => 'manager']);
    $brand = Brand::create(['name' => 'Boysen', 'active' => true]);
    $category = Category::create(['name' => 'Permacoat Latex', 'active' => true]);
    $unit = PackageUnit::create(['name' => 'Gallon', 'abbreviation' => 'gal', 'active' => true]);

    $product = Product::create([
        'sku' => 'BOY-FLAT-WHITE-GAL',
        'name' => 'Boysen Permacoat Flat White',
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'package_unit_id' => $unit->id,
        'package_size' => 4.0,
        'selling_price' => 580.00,
        'low_stock_threshold' => 3,
        'active' => true,
    ]);

    // Initial system stock is 10.0
    Inventory::create(['product_id' => $product->id, 'quantity' => 10.0]);

    // Staff performs weekly physical count and measures 8.5 gal (shortage of 1.5 due to partial dipstick mixing)
    Volt::actingAs($user)
        ->test('pages.inventory.physical-count')
        ->set('countedAt', now()->toDateString())
        ->set('notes', 'Saturday physical inventory dipstick check')
        ->set("counts.{$product->id}", '8.500')
        ->set("lineReasons.{$product->id}", '1.5 gal partial dipstick loss from unrecorded store testing')
        ->call('confirmCount')
        ->assertHasNoErrors();

    // Verify official inventory replaced with physical measurement
    expect((float) $product->fresh()->inventory->quantity)->toBe(8.5);

    // Verify PhysicalInventory record
    $audit = PhysicalInventory::latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->items->count())->toBe(1);

    $item = $audit->items->first();
    expect((float) $item->system_quantity)->toBe(10.0)
        ->and((float) $item->physical_quantity)->toBe(8.5)
        ->and((float) $item->variance)->toBe(-1.5);

    // Verify physical_adjustment movement logged
    $movement = InventoryMovement::where('product_id', $product->id)->where('type', 'physical_adjustment')->first();
    expect($movement)->not->toBeNull()
        ->and((float) $movement->quantity_change)->toBe(-1.5)
        ->and((float) $movement->quantity_before)->toBe(10.0)
        ->and((float) $movement->quantity_after)->toBe(8.5);

    // Verify audit log
    $auditLog = AuditLog::where('auditable_type', PhysicalInventory::class)->where('auditable_id', $audit->id)->first();
    expect($auditLog)->not->toBeNull()
        ->and($auditLog->event)->toBe('physical_inventory_confirmed');
});
