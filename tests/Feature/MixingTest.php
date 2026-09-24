<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Livewire\Volt\Volt;

test('custom mixing records estimated components without changing official stock', function () {
    $user = User::factory()->create();
    $category = Category::create(['name' => 'Paint']);
    $base = Product::create(['category_id' => $category->id, 'sku' => 'BASE-1', 'name' => 'Base Paint', 'selling_price' => 200, 'active' => true]);
    $tint = Product::create(['category_id' => $category->id, 'sku' => 'TINT-1', 'name' => 'Tint Color', 'selling_price' => 50, 'active' => true]);
    Inventory::create(['product_id' => $base->id, 'quantity' => 4]);
    Inventory::create(['product_id' => $tint->id, 'quantity' => 3]);

    Volt::actingAs($user)
        ->test('pages.mixing.index')
        ->set('productId', $base->id)
        ->set('estimatedQuantity', '1')
        ->call('addComponent')
        ->set('productId', $tint->id)
        ->set('estimatedQuantity', '0.2')
        ->call('addComponent')
        ->call('finalizeMix')
        ->assertHasNoErrors();

    expect(Sale::query()->where('type', 'custom_mix')->count())->toBe(1)
        ->and((float) $base->fresh()->inventory->quantity)->toBe(4.0)
        ->and((float) $tint->fresh()->inventory->quantity)->toBe(3.0);
});

test('the POS checks out normal and custom mix lines together', function () {
    $user = User::factory()->create();
    $category = Category::create(['name' => 'POS Paint']);
    $normal = Product::create(['category_id' => $category->id, 'sku' => 'POS-PAINT', 'name' => 'Retail Paint', 'selling_price' => 80, 'active' => true]);
    $expensive = Product::create(['category_id' => $category->id, 'sku' => 'POS-BASE', 'name' => 'Premium Base', 'selling_price' => 240, 'active' => true]);
    $tint = Product::create(['category_id' => $category->id, 'sku' => 'POS-TINT', 'name' => 'Tint', 'selling_price' => 20, 'active' => true]);
    Inventory::create(['product_id' => $normal->id, 'quantity' => 3]);
    Inventory::create(['product_id' => $expensive->id, 'quantity' => 3]);
    Inventory::create(['product_id' => $tint->id, 'quantity' => 3]);

    Volt::actingAs($user)
        ->test('pages.sales.index')
        ->call('addProduct', $normal->id)
        ->set('mixProductId', $expensive->id)
        ->set('mixEstimatedQuantity', '1')
        ->call('addMixComponent')
        ->set('mixProductId', $tint->id)
        ->set('mixEstimatedQuantity', '0.2')
        ->call('addMixComponent')
        ->set('mixDescription', 'Customer blue mix')
        ->call('addMixToCart')
        ->call('checkout')
        ->assertHasNoErrors();

    $sale = Sale::query()->where('type', 'mixed')->with('items')->firstOrFail();

    expect($sale->items)->toHaveCount(2)
        ->and((float) $normal->fresh()->inventory->quantity)->toBe(2.0)
        ->and((float) $expensive->fresh()->inventory->quantity)->toBe(3.0)
        ->and(InventoryMovement::where('product_id', $expensive->id)->count())->toBe(0)
        ->and((float) $sale->items->whereNull('product_id')->first()->unit_price)->toBe(240.0);
});
