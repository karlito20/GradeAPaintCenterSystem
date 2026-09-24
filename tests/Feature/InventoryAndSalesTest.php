<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Livewire\Volt\Volt;

function inventoryProduct(array $attributes = []): Product
{
    $category = Category::firstOrCreate(['name' => 'Paint']);

    $product = Product::create(array_merge([
        'category_id' => $category->id,
        'sku' => 'PAINT-' . uniqid(),
        'name' => 'Interior Paint',
        'selling_price' => 100,
        'low_stock_threshold' => 1,
        'active' => true,
    ], $attributes));

    Inventory::create(['product_id' => $product->id, 'quantity' => 0]);

    return $product;
}

test('stock in increases inventory and records a movement', function () {
    $user = User::factory()->create();
    $product = inventoryProduct();

    Volt::actingAs($user)
        ->test('pages.inventory.stock-in')
        ->set('productId', $product->id)
        ->set('quantity', '5')
        ->call('addItem')
        ->call('saveStockIn')
        ->assertHasNoErrors();

    expect((float) $product->fresh()->inventory->quantity)->toBe(5.0)
        ->and(InventoryMovement::where('product_id', $product->id)->where('type', 'stock_in')->count())->toBe(1);
});

test('a sale cannot exceed available inventory and reduces stock when valid', function () {
    $user = User::factory()->create();
    $product = inventoryProduct();
    $product->inventory->update(['quantity' => 2]);

    Volt::actingAs($user)
        ->test('pages.sales.index')
        ->set('productId', $product->id)
        ->set('quantity', '3')
        ->call('finalizeSale')
        ->assertHasErrors('productId');

    expect((float) $product->fresh()->inventory->quantity)->toBe(2.0);

    Volt::actingAs($user)
        ->test('pages.sales.index')
        ->set('productId', $product->id)
        ->set('quantity', '1')
        ->call('finalizeSale')
        ->assertHasNoErrors();

    expect((float) $product->fresh()->inventory->quantity)->toBe(1.0)
        ->and(InventoryMovement::where('product_id', $product->id)->where('type', 'sale')->count())->toBe(1);
});
