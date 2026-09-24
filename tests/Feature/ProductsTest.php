<?php

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
        ->and($product->inventory)->not->toBeNull()
        ->and((float) $product->inventory->quantity)->toBe(0.0);
});
