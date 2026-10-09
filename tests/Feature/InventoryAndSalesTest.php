<?php

use App\Models\Brand;
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
        'sku' => 'PAINT-'.uniqid(),
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

test('inventory report calculates balances and exports PDF with date filters', function () {
    $user = User::factory()->create();
    $product = inventoryProduct(['sku' => 'REPORT-SKU-1', 'name' => 'Report Paint Item']);
    $product->inventory->update(['quantity' => 15]);

    // Record movements
    InventoryMovement::create([
        'product_id' => $product->id,
        'user_id' => $user->id,
        'type' => 'stock_in',
        'quantity_change' => 20,
        'quantity_before' => 0,
        'quantity_after' => 20,
        'created_at' => now(),
    ]);

    InventoryMovement::create([
        'product_id' => $product->id,
        'user_id' => $user->id,
        'type' => 'sale',
        'quantity_change' => -5,
        'quantity_before' => 20,
        'quantity_after' => 15,
        'created_at' => now(),
    ]);

    $brandA = Brand::create(['name' => 'Brand Alpha', 'active' => true]);
    $brandB = Brand::create(['name' => 'Brand Beta', 'active' => true]);
    $product->update(['brand_id' => $brandA->id]);

    $productB = inventoryProduct(['sku' => 'REPORT-SKU-2', 'name' => 'Beta Paint Item', 'brand_id' => $brandB->id]);
    $productB->inventory->update(['quantity' => 10]);

    // Test livewire inventory report component
    Volt::actingAs($user)
        ->test('pages.reports.inventory')
        ->set('dateFrom', now()->toDateString())
        ->set('dateTo', now()->toDateString())
        ->assertSee('REPORT-SKU-1')
        ->assertSee('Report Paint Item')
        ->assertSee('Brand Alpha')
        ->assertSee('Report produced by')
        ->assertSee($user->name)
        ->assertSee('20.00')
        ->assertSee('5.00')
        ->assertSee('15.00')
        ->assertDontSee('Retail Price')
        ->set('brandId', (string) $brandB->id)
        ->assertSee('REPORT-SKU-2')
        ->assertDontSee('REPORT-SKU-1');

    // Test PDF export route with brand_id
    $this->actingAs($user)
        ->get(route('inventory.pdf', ['from' => now()->toDateString(), 'to' => now()->toDateString(), 'brand_id' => $brandA->id]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('inventory pages do not display navigation breadcrumbs in header', function () {
    $user = User::factory()->create();

    Volt::actingAs($user)
        ->test('pages.inventory.movements')
        ->assertDontSee('Inventory / Movements');

    Volt::actingAs($user)
        ->test('pages.inventory.physical-count')
        ->assertDontSee('Inventory / Physical Count');

    Volt::actingAs($user)
        ->test('pages.reports.inventory')
        ->assertDontSee('Inventory / Stock Report');
});
