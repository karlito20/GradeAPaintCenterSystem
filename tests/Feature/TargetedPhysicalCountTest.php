<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use Livewire\Volt\Volt;

test('a user can conduct physical counts and reconcile inventory', function () {
    $user = User::factory()->create();
    $category = Category::create(['name' => 'Paint']);

    $product = Product::create([
        'category_id' => $category->id,
        'sku' => 'TPC-1',
        'name' => 'Targeted Paint 1L',
        'selling_price' => 500,
        'active' => true,
        'package_size' => 1.0,
    ]);
    Inventory::create(['product_id' => $product->id, 'location' => 'storefront', 'quantity' => 2]);

    Volt::actingAs($user)
        ->test('pages.inventory.physical-count')
        ->set("counts.{$product->id}", '1.0')
        ->call('confirmCount')
        ->assertHasNoErrors();

    // The storefront inventory should be adjusted to 1.0
    $inv = Inventory::where('product_id', $product->id)->where('location', 'storefront')->first();
    expect((float) $inv->quantity)->toBe(1.0);
});

test('mixing materials are marked for physical check and cleared upon audit confirmation', function () {
    $user = User::factory()->create();
    $category = Category::create(['name' => 'Tinting Base']);

    $product = Product::create([
        'category_id' => $category->id,
        'sku' => 'MIX-MAT-01',
        'name' => 'Mixing Material Base',
        'selling_price' => 450,
        'active' => true,
        'package_size' => 4.0,
        'requires_physical_check' => false,
    ]);
    Inventory::create(['product_id' => $product->id, 'location' => 'storefront', 'quantity' => 10]);

    // Use product in custom mixing
    Volt::actingAs($user)
        ->test('pages.mixing.index')
        ->set('productId', $product->id)
        ->set('estimatedQuantity', '1.5')
        ->set('estimatedQuantityUnit', 'gal')
        ->call('addComponent')
        ->call('finalizeMix')
        ->assertHasNoErrors();

    // Verify product is now marked to be physically checked
    expect($product->fresh()->requires_physical_check)->toBeTrue();

    // Check physical count page displays Check Due queue
    Volt::actingAs($user)
        ->test('pages.inventory.physical-count')
        ->assertSee('Check Queue (Mix)')
        ->assertSee('Check Due')
        ->set('discrepancyFilter', 'needs_check')
        ->assertSee($product->sku)
        ->set("counts.{$product->id}", '8.5')
        ->call('confirmCount')
        ->assertHasNoErrors();

    // Verify requires_physical_check is cleared back to false
    expect($product->fresh()->requires_physical_check)->toBeFalse();
    $inv = Inventory::where('product_id', $product->id)->where('location', 'storefront')->first();
    expect((float) $inv->quantity)->toBe(8.5);
});
