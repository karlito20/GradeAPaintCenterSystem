<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\PackageUnit;
use App\Models\PhysicalInventory;
use App\Models\PhysicalInventoryItem;
use App\Models\Product;
use App\Models\User;
use Livewire\Volt\Volt;

test('navigation displays expandable inventory report with mixing paint usage sub-link', function () {
    $user = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($user)->get(route('inventory.mixing-usage'));
    $response->assertOk();

    Volt::actingAs($user)
        ->test('layout.navigation')
        ->assertSee('Inventory Report')
        ->assertSee('General Inventory')
        ->assertSee('Mixing Paint Usage')
        ->assertSee(route('inventory.mixing-usage'), false);
});

test('mixing paint usage report displays paints flagged from mixing awaiting check', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $brand = Brand::create(['name' => 'Boysen Test', 'active' => true]);
    $unit = PackageUnit::create(['name' => 'Gallon', 'abbreviation' => 'gal', 'active' => true]);
    $category = Category::create(['name' => 'Latex', 'active' => true]);

    $product = Product::create([
        'sku' => 'TEST-MIX-PAINT-01',
        'name' => 'Boysen Tinting Color Hansa Yellow',
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'package_unit_id' => $unit->id,
        'selling_price' => 450.00,
        'active' => true,
        'requires_physical_check' => true,
    ]);

    Inventory::create([
        'product_id' => $product->id,
        'location' => 'storefront',
        'quantity' => 12.00,
    ]);

    Volt::actingAs($user)
        ->test('pages.reports.mixing-usage')
        ->assertSee('Mixing Paint Usage Report')
        ->assertSee('TEST-MIX-PAINT-01')
        ->assertSee('Boysen Tinting Color Hansa Yellow')
        ->assertSee('Needs Count')
        ->assertSee('12.00');
});

test('physical check reduces stored quantity and records estimated quantity used on mixing usage report', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $brand = Brand::create(['name' => 'Davies Test', 'active' => true]);
    $unit = PackageUnit::create(['name' => 'Gallon', 'abbreviation' => 'gal', 'active' => true]);
    $category = Category::create(['name' => 'Enamel', 'active' => true]);

    $product = Product::create([
        'sku' => 'TEST-DAVIES-MIX-02',
        'name' => 'Davies Quick Drying Enamel White',
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'package_unit_id' => $unit->id,
        'selling_price' => 620.00,
        'active' => true,
        'requires_physical_check' => true,
    ]);

    Inventory::create([
        'product_id' => $product->id,
        'location' => 'storefront',
        'quantity' => 10.00,
    ]);

    // Perform physical count: measured quantity is 8.25 (1.75 gals used)
    Volt::actingAs($user)
        ->test('pages.inventory.physical-count')
        ->set("counts.{$product->id}", '8.25')
        ->call('confirmCount');

    $product->refresh();
    expect($product->requires_physical_check)->toBeFalse();
    expect((float) $product->fresh()->inventories->sum('quantity'))->toBe(8.25);

    $auditItem = PhysicalInventoryItem::where('product_id', $product->id)->first();
    expect($auditItem)->not->toBeNull()
        ->and($auditItem->was_mixing_check)->toBeTrue()
        ->and((float) $auditItem->estimated_used_quantity)->toBe(1.75)
        ->and((float) $auditItem->system_quantity)->toBe(10.00)
        ->and((float) $auditItem->physical_quantity)->toBe(8.25);

    // Verify on Mixing Paint Usage page
    Volt::actingAs($user)
        ->test('pages.reports.mixing-usage')
        ->assertSee('TEST-DAVIES-MIX-02')
        ->assertSee('Davies Quick Drying Enamel White')
        ->assertSee('Checked & Deducted')
        ->assertSee('10.00')
        ->assertSee('8.25')
        ->assertSee('1.75');
});

test('mixing paint usage report can filter by status and search', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $brand = Brand::create(['name' => 'Island Paint', 'active' => true]);
    $unit = PackageUnit::create(['name' => 'Liter', 'abbreviation' => 'L', 'active' => true]);
    $category = Category::create(['name' => 'Primer', 'active' => true]);

    // Product 1: Pending check
    $pendingProd = Product::create([
        'sku' => 'ISLAND-PENDING-01',
        'name' => 'Island Primer Red',
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'package_unit_id' => $unit->id,
        'selling_price' => 200.00,
        'active' => true,
        'requires_physical_check' => true,
    ]);

    // Product 2: Completed check
    $checkedProd = Product::create([
        'sku' => 'ISLAND-CHECKED-02',
        'name' => 'Island Finish Gloss',
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'package_unit_id' => $unit->id,
        'selling_price' => 300.00,
        'active' => true,
        'requires_physical_check' => false,
    ]);

    $count = PhysicalInventory::create([
        'user_id' => $user->id,
        'counted_at' => now(),
        'status' => 'completed',
    ]);

    PhysicalInventoryItem::create([
        'physical_inventory_id' => $count->id,
        'product_id' => $checkedProd->id,
        'system_quantity' => 15.00,
        'physical_quantity' => 12.50,
        'variance' => -2.50,
        'was_mixing_check' => true,
        'estimated_used_quantity' => 2.50,
        'reason' => 'Mixing check verified',
    ]);

    // In 'pending' status: see only pendingProd
    Volt::actingAs($user)
        ->test('pages.reports.mixing-usage')
        ->set('status', 'pending')
        ->assertSee('ISLAND-PENDING-01')
        ->assertDontSee('ISLAND-CHECKED-02');

    // In 'checked' status: see only checkedProd
    Volt::actingAs($user)
        ->test('pages.reports.mixing-usage')
        ->set('status', 'checked')
        ->assertSee('ISLAND-CHECKED-02')
        ->assertDontSee('ISLAND-PENDING-01');

    // Search filter
    Volt::actingAs($user)
        ->test('pages.reports.mixing-usage')
        ->set('status', 'all')
        ->set('search', 'Island Primer')
        ->assertSee('ISLAND-PENDING-01')
        ->assertDontSee('ISLAND-CHECKED-02');
});
