<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\PackageUnit;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;

test('authenticated user can stream or download purchase order pdf', function () {
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

    $po = PurchaseOrder::create([
        'po_number' => 'PO-PDF-001',
        'user_id' => $user->id,
        'supplier_name' => 'Pacific Paint Phils',
        'order_date' => '2026-10-11',
        'status' => 'pending',
        'total_amount' => 1500.00,
    ]);

    $po->items()->create([
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_cost' => 750.00,
        'subtotal' => 1500.00,
    ]);

    // Stream test
    $response = $this->actingAs($user)
        ->get(route('products.purchase-orders.pdf', $po->id));

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    // Download test
    $downloadResponse = $this->actingAs($user)
        ->get(route('products.purchase-orders.pdf', ['purchaseOrder' => $po->id, 'download' => 1]));

    $downloadResponse->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition', 'attachment; filename=grade-a-paint-purchase-order-PO-PDF-001.pdf');
});

test('mixer role is forbidden from viewing or downloading purchase order pdf', function () {
    $mixer = User::factory()->create(['role' => 'mixer']);
    $user = User::factory()->create(['role' => 'manager']);
    $brand = Brand::create(['name' => 'Boysen', 'active' => true]);
    $category = Category::create(['name' => 'Topcoats', 'active' => true]);
    $unit = PackageUnit::create(['name' => 'Gallon', 'abbreviation' => 'gal', 'active' => true]);

    $product = Product::create([
        'sku' => 'BOY-PERM-4L-TEST',
        'name' => 'Boysen Permacoat Semi-Gloss',
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'package_unit_id' => $unit->id,
        'selling_price' => 750.00,
        'low_stock_threshold' => 5,
        'active' => true,
    ]);

    $po = PurchaseOrder::create([
        'po_number' => 'PO-PDF-002',
        'user_id' => $user->id,
        'supplier_name' => 'Pacific Paint Phils',
        'order_date' => '2026-10-11',
        'status' => 'pending',
        'total_amount' => 1500.00,
    ]);

    $this->actingAs($mixer)
        ->get(route('products.purchase-orders.pdf', $po->id))
        ->assertForbidden();

    $this->actingAs($mixer)
        ->get(route('products.purchase-orders.pdf', ['purchaseOrder' => $po->id, 'download' => 1]))
        ->assertForbidden();
});
