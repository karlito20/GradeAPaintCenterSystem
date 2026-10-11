<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use Livewire\Volt\Volt;

test('a user can repack a large product into smaller units', function () {
    $user = User::factory()->create();
    $category = Category::create(['name' => 'Paint']);

    // Create source product (4L can)
    $sourceProduct = Product::create([
        'category_id' => $category->id,
        'sku' => 'SRC-1',
        'name' => 'Source Paint 4L',
        'selling_price' => 1000,
        'active' => true,
        'package_size' => 4.0,
    ]);
    Inventory::create(['product_id' => $sourceProduct->id, 'location' => 'storefront', 'quantity' => 2]);

    // Create target product (1L can)
    $targetProduct = Product::create([
        'category_id' => $category->id,
        'sku' => 'TGT-1',
        'name' => 'Target Paint 1L',
        'selling_price' => 300,
        'active' => true,
        'package_size' => 1.0,
    ]);
    Inventory::create(['product_id' => $targetProduct->id, 'location' => 'storefront', 'quantity' => 0]);

    Volt::actingAs($user)
        ->test('pages.inventory.repack')
        ->call('selectSourceProduct', $sourceProduct->id)
        ->call('selectTargetProduct', $targetProduct->id)
        ->set('location', 'storefront')
        ->set('quantityToCreate', 4) // We want 4x 1L cans (takes 1x 4L can)
        ->call('repack')
        ->assertHasNoErrors();

    // Source product should have 1 can left (from 2 down by 1)
    expect((float) $sourceProduct->fresh()->getInventory('storefront')->quantity)->toBe(1.0);

    // Target product should have 4 cans created
    expect((float) $targetProduct->fresh()->getInventory('storefront')->quantity)->toBe(4.0);
});
