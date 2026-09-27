<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\PackageUnit;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Livewire\Volt\Volt;

test('checkout processes cash sale with discount, decrements stock, and logs movements and audits', function () {
    $user = User::factory()->create(['role' => 'manager']);
    $brand = Brand::create(['name' => 'Boysen', 'active' => true]);
    $category = Category::create(['name' => 'Permacoat Latex', 'active' => true]);
    $unit = PackageUnit::create(['name' => 'Gallon', 'abbreviation' => 'gal', 'active' => true]);

    $product = Product::create([
        'sku' => 'TEST-BOYSEN-GAL',
        'name' => 'Boysen Permacoat Semi-Gloss',
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'package_unit_id' => $unit->id,
        'package_size' => 4.0,
        'selling_price' => 500.00,
        'low_stock_threshold' => 2,
        'active' => true,
    ]);

    Inventory::create(['product_id' => $product->id, 'quantity' => 10.0]);

    session([
        'pos.cart' => [
            [
                'id' => 'prod_'.$product->id,
                'type' => 'product',
                'product_id' => $product->id,
                'description' => $product->name,
                'unit' => 'gal',
                'unit_price' => 500.00,
                'quantity' => 2.0,
                'subtotal' => 1000.00,
            ],
        ],
    ]);

    // Perform checkout via pages.sales.checkout Volt component
    Volt::actingAs($user)
        ->test('pages.sales.checkout')
        ->set('discountPercentage', '10') // 10% discount on 1000 = 100 discount, net 900
        ->set('tenderedAmount', '1000.00')
        ->call('completeSale')
        ->assertHasNoErrors()
        ->assertRedirect();

    $sale = Sale::latest('id')->first();
    expect($sale)->not->toBeNull()
        ->and((float) $sale->subtotal)->toBe(1000.00)
        ->and((float) $sale->discount_percentage)->toBe(10.00)
        ->and((float) $sale->discount_amount)->toBe(100.00)
        ->and((float) $sale->total)->toBe(900.00)
        ->and((float) $sale->payment_amount)->toBe(1000.00)
        ->and((float) $sale->change_amount)->toBe(100.00);

    // Verify inventory decremented
    expect((float) $product->fresh()->inventory->quantity)->toBe(8.0);

    // Verify negative movement logged
    $movement = InventoryMovement::where('product_id', $product->id)->where('type', 'sale')->first();
    expect($movement)->not->toBeNull()
        ->and((float) $movement->quantity_change)->toBe(-2.000)
        ->and((float) $movement->quantity_before)->toBe(10.000)
        ->and((float) $movement->quantity_after)->toBe(8.000);

    // Verify audit log
    $audit = AuditLog::where('auditable_type', Sale::class)->where('auditable_id', $sale->id)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->event)->toBe('sale_completed');
});

test('dedicated printable receipt renders cleanly without navigation chrome', function () {
    $user = User::factory()->create(['role' => 'manager']);
    $sale = Sale::create([
        'user_id' => $user->id,
        'invoice_number' => 'INV-TEST-0001',
        'sold_at' => now(),
        'type' => 'retail',
        'subtotal' => 850.00,
        'discount_percentage' => 5.0,
        'discount_amount' => 42.50,
        'total' => 807.50,
        'payment_method' => 'cash',
        'payment_amount' => 1000.00,
        'change_amount' => 192.50,
    ]);

    $response = $this->actingAs($user)->get("/sales/{$sale->id}/receipt");

    $response->assertOk()
        ->assertSee('INV-TEST-0001')
        ->assertSee('Grade A Paint Center')
        ->assertSee('₱807.50')
        ->assertSee('₱192.50')
        ->assertDontSee('Dashboard') // App navigation chrome excluded
        ->assertDontSee('User Management');
});
