<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\PackageUnit;
use App\Models\Product;
use App\Models\User;
use Livewire\Volt\Volt;

test('authenticated users can view the product catalog', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/products')
        ->assertOk()
        ->assertSeeVolt('pages.products.index');
});

test('staff can create a product and its inventory record', function () {
    $user = User::factory()->create();

    Volt::actingAs($user)
        ->test('pages.products.index')
        ->set('sku', 'GUILDER-THALO-1L')
        ->set('name', 'Guilder Thalo Green')
        ->set('newCategory', 'Tinting Color')
        ->set('packageSize', '1')
        ->set('packageUnit', 'liter')
        ->set('sellingPrice', '125.00')
        ->set('lowStockThreshold', '2')
        ->call('createProduct')
        ->assertHasNoErrors();

    $product = Product::query()->where('sku', 'GUILDER-THALO-1L')->firstOrFail();

    expect($product->name)->toBe('Guilder Thalo Green')
        ->and($product->inventories)->toHaveCount(1)
        ->and((float) $product->inventories->first()->quantity)->toBe(0.0);
});

test('user can open deactivated modal and restore deactivated product', function () {
    $user = User::factory()->create();
    $cat = Category::create(['name' => 'Primers', 'active' => true]);
    $unit = PackageUnit::create(['name' => 'Can', 'abbreviation' => 'can', 'active' => true]);

    $product = Product::create([
        'sku' => 'DEACT-001',
        'name' => 'Deactivated Primer',
        'category_id' => $cat->id,
        'package_unit_id' => $unit->id,
        'selling_price' => 200.00,
        'low_stock_threshold' => 5,
        'active' => false,
    ]);

    Volt::actingAs($user)
        ->test('pages.products.index')
        ->assertSee('Deactivated Products')
        ->call('openDeactivatedModal')
        ->assertSet('showDeactivatedModal', true)
        ->assertSee('Deactivated Primer')
        ->call('restoreProduct', $product->id)
        ->assertHasNoErrors();

    expect($product->refresh()->active)->toBeTrue();
});

test('user can permanently delete deactivated product with dependent records', function () {
    $user = User::factory()->create();
    $cat = Category::create(['name' => 'Solvents', 'active' => true]);
    $unit = PackageUnit::create(['name' => 'Bottle', 'abbreviation' => 'btl', 'active' => true]);

    $product = Product::create([
        'sku' => 'DEACT-DEL-001',
        'name' => 'Deactivated Thinner',
        'category_id' => $cat->id,
        'package_unit_id' => $unit->id,
        'selling_price' => 150.00,
        'low_stock_threshold' => 5,
        'active' => false,
    ]);

    Inventory::create([
        'product_id' => $product->id,
        'location' => 'storage',
        'quantity' => 10,
    ]);

    InventoryMovement::create([
        'product_id' => $product->id,
        'location' => 'storage',
        'user_id' => $user->id,
        'type' => 'stock_in',
        'quantity_change' => 10,
        'quantity_before' => 0,
        'quantity_after' => 10,
        'reference_text' => 'Initial stock',
    ]);

    Volt::actingAs($user)
        ->test('pages.products.index')
        ->call('permanentlyDeleteProduct', $product->id)
        ->assertHasNoErrors();

    expect(Product::find($product->id))->toBeNull()
        ->and(Inventory::where('product_id', $product->id)->count())->toBe(0)
        ->and(InventoryMovement::where('product_id', $product->id)->count())->toBe(0);
});
