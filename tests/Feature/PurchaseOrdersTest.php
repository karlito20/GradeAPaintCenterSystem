<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\PackageUnit;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use Livewire\Volt\Volt;

test('authenticated users can view purchase orders page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/products/purchase-orders')
        ->assertOk()
        ->assertSeeVolt('pages.products.purchase-orders');
});

test('user can create a purchase order with items', function () {
    $user = User::factory()->create();
    $brand = Brand::create(['name' => 'Boysen', 'active' => true]);
    $category = Category::create(['name' => 'Topcoats', 'active' => true]);
    $unit = PackageUnit::create(['name' => 'Gallon', 'abbreviation' => 'gal', 'active' => true]);

    $product = Product::create([
        'sku' => 'BOY-PERM-4L',
        'name' => 'Boysen Permacoat Semi-Gloss',
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'package_unit_id' => $unit->id,
        'selling_price' => 750.00,
        'low_stock_threshold' => 5,
        'active' => true,
    ]);

    Volt::actingAs($user)
        ->test('pages.products.purchase-orders-create')
        ->set('supplierName', 'Pacific Paint Phils')
        ->set('orderDate', '2026-10-11')
        ->call('addProductToOrder', $product->id)
        ->call('updateItemQuantity', 0, 10)
        ->call('updateItemCost', 0, 600)
        ->call('savePurchaseOrder')
        ->assertHasNoErrors()
        ->assertRedirect(route('products.purchase-orders'));

    $po = PurchaseOrder::where('supplier_name', 'Pacific Paint Phils')->first();

    expect($po)->not->toBeNull()
        ->and($po->status)->toBe('pending')
        ->and((float) $po->total_amount)->toBe(6000.00)
        ->and($po->items)->toHaveCount(1)
        ->and((float) $po->items->first()->quantity)->toBe(10.00);
});

test('user can receive purchase order into storage inventory', function () {
    $user = User::factory()->create();
    $brand = Brand::create(['name' => 'Davies', 'active' => true]);
    $category = Category::create(['name' => 'Paint Accessories', 'active' => true]);
    $unit = PackageUnit::create(['name' => 'Piece', 'abbreviation' => 'pc', 'active' => true]);

    $product = Product::create([
        'sku' => 'DAV-BRUSH-2IN',
        'name' => 'Davies Paint Brush 2 Inch',
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'package_unit_id' => $unit->id,
        'selling_price' => 120.00,
        'low_stock_threshold' => 10,
        'active' => true,
    ]);

    $po = PurchaseOrder::create([
        'po_number' => 'PO-TEST-001',
        'user_id' => $user->id,
        'supplier_name' => 'Davies Paints',
        'order_date' => '2026-10-11',
        'status' => 'ordered',
        'total_amount' => 800.00,
    ]);

    $po->items()->create([
        'product_id' => $product->id,
        'quantity' => 10,
        'unit_cost' => 80.00,
        'subtotal' => 800.00,
    ]);

    Volt::actingAs($user)
        ->test('pages.products.purchase-orders')
        ->call('receiveOrder', $po->id)
        ->assertHasNoErrors();

    $po->refresh();
    expect($po->status)->toBe('received')
        ->and($po->received_at)->not->toBeNull();

    $storageInventory = Inventory::where('product_id', $product->id)->where('location', 'storage')->first();
    expect($storageInventory)->not->toBeNull()
        ->and((float) $storageInventory->quantity)->toBe(10.00);
});

test('user can view purchase order details in modal', function () {
    $user = User::factory()->create();
    $po = PurchaseOrder::create([
        'po_number' => 'PO-VIEW-001',
        'user_id' => $user->id,
        'supplier_name' => 'Boysen',
        'order_date' => '2026-10-11',
        'status' => 'pending',
        'total_amount' => 500.00,
    ]);

    Volt::actingAs($user)
        ->test('pages.products.purchase-orders')
        ->call('viewDetails', $po->id)
        ->assertSet('showDetailModal', true)
        ->assertSet('viewingPoId', $po->id)
        ->assertSee('PO-VIEW-001')
        ->call('closeDetailModal')
        ->assertSet('showDetailModal', false)
        ->assertSet('viewingPoId', null);
});

test('user can mark pending purchase order as ordered', function () {
    $user = User::factory()->create();
    $po = PurchaseOrder::create([
        'po_number' => 'PO-ORD-001',
        'user_id' => $user->id,
        'supplier_name' => 'Supplier Inc',
        'order_date' => '2026-10-11',
        'status' => 'pending',
        'total_amount' => 1200.00,
    ]);

    Volt::actingAs($user)
        ->test('pages.products.purchase-orders')
        ->call('markAsOrdered', $po->id)
        ->assertHasNoErrors();

    expect($po->refresh()->status)->toBe('ordered');
});

test('user can cancel and delete purchase order', function () {
    $user = User::factory()->create();
    $po = PurchaseOrder::create([
        'po_number' => 'PO-CANCEL-001',
        'user_id' => $user->id,
        'supplier_name' => 'Supplier Inc',
        'order_date' => '2026-10-11',
        'status' => 'ordered',
        'total_amount' => 1200.00,
    ]);

    Volt::actingAs($user)
        ->test('pages.products.purchase-orders')
        ->call('cancelOrder', $po->id)
        ->assertHasNoErrors();

    expect($po->refresh()->status)->toBe('cancelled');

    Volt::actingAs($user)
        ->test('pages.products.purchase-orders')
        ->call('deleteOrder', $po->id)
        ->assertHasNoErrors();

    expect(PurchaseOrder::find($po->id))->toBeNull();
});
