<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\MixingTransaction;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Livewire\Volt\Volt;

test('custom mixing enforces single brand and derives price from highest material', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $brandA = Brand::create(['name' => 'Boysen', 'active' => true]);
    $brandB = Brand::create(['name' => 'Davies', 'active' => true]);
    $category = Category::create(['name' => 'Mixing Paints', 'active' => true]);

    $expensive = Product::create([
        'category_id' => $category->id,
        'brand_id' => $brandA->id,
        'sku' => 'BOY-BASE',
        'name' => 'Boysen Permacoat White',
        'selling_price' => 500,
        'active' => true,
    ]);
    $cheap = Product::create([
        'category_id' => $category->id,
        'brand_id' => $brandA->id,
        'sku' => 'BOY-TINT',
        'name' => 'Boysen Thalo Blue Tint',
        'selling_price' => 120,
        'active' => true,
    ]);
    $otherBrand = Product::create([
        'category_id' => $category->id,
        'brand_id' => $brandB->id,
        'sku' => 'DAV-BASE',
        'name' => 'Davies Base',
        'selling_price' => 450,
        'active' => true,
    ]);

    Inventory::create(['product_id' => $expensive->id, 'location' => 'storefront', 'quantity' => 5]);
    Inventory::create(['product_id' => $cheap->id, 'location' => 'storefront', 'quantity' => 5]);
    Inventory::create(['product_id' => $otherBrand->id, 'location' => 'storefront', 'quantity' => 5]);

    // Test in POS sales.index
    Volt::actingAs($user)
        ->test('pages.sales.index')
        ->set('mixCustomerName', 'Juan Dela Cruz')
        ->set('mixCustomerContact', '0917-123-4567')
        ->set('mixBrandId', (string) $brandA->id)
        ->set('mixCustomColor', 'Sky Blue #B-101')
        ->set('mixQuantity', '2')
        ->set('mixResultUnit', 'gal')
        // Try adding material from another brand: should fail
        ->set('mixProductId', (string) $otherBrand->id)
        ->call('addMixComponent')
        ->assertHasErrors('mixProductId');

    // Add materials from the selected brand
    Volt::actingAs($user)
        ->test('pages.sales.index')
        ->set('mixCustomerName', 'Juan Dela Cruz')
        ->set('mixCustomerContact', '0917-123-4567')
        ->set('mixBrandId', (string) $brandA->id)
        ->set('mixCustomColor', 'Sky Blue #B-101')
        ->set('mixQuantity', '2')
        ->set('mixResultUnit', 'gal')
        ->set('mixProductId', (string) $expensive->id)
        ->call('addMixComponent')
        ->set('mixProductId', (string) $cheap->id)
        ->call('addMixComponent')
        ->call('proceedMixToCheckout')
        ->assertHasNoErrors()
        ->assertRedirect(route('sales.checkout'));

    // Complete downpayment checkout in sales.checkout
    // Order Total: 2 gal * 500 = 1000. Downpayment: 500. Tendered: 600. Change: 100.
    Volt::actingAs($user)
        ->test('pages.sales.checkout')
        ->set('downpaymentAmount', '500')
        ->set('tenderedAmount', '600')
        ->call('completeSale')
        ->assertHasNoErrors();

    $sale = Sale::query()->where('type', 'mixed')->with(['items', 'mixingTransaction.components'])->firstOrFail();

    expect((float) $sale->total)->toBe(1000.0)
        ->and((float) $sale->downpayment_amount)->toBe(500.0)
        ->and((float) $sale->balance_due)->toBe(500.0)
        ->and($sale->payment_status)->toBe('downpayment_paid')
        ->and($sale->mixingTransaction->custom_color)->toBe('Sky Blue #B-101')
        ->and($sale->mixingTransaction->brand_id)->toBe($brandA->id)
        ->and((float) $sale->mixingTransaction->price_basis)->toBe(500.0)
        ->and($sale->mixingTransaction->status)->toBe('pending_mix')
        ->and($sale->mixingTransaction->components)->toHaveCount(2)
        // Verify no real-time stock deduction took place for mixing materials
        ->and((float) $expensive->fresh()->getStorefrontStock())->toBe(5.0)
        ->and((float) $cheap->fresh()->getStorefrontStock())->toBe(5.0)
        ->and(InventoryMovement::where('product_id', $expensive->id)->count())->toBe(0)
        // Verify materials flagged for physical check
        ->and($expensive->fresh()->requires_physical_check)->toBeTrue()
        ->and($cheap->fresh()->requires_physical_check)->toBeTrue();
});

test('retail and custom mix orders cannot be combined in the same cart', function () {
    $user = User::factory()->create();
    $brand = Brand::create(['name' => 'Guilder', 'active' => true]);
    $category = Category::create(['name' => 'Paints', 'active' => true]);
    $product = Product::create([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'sku' => 'RET-1',
        'name' => 'Retail Gallon',
        'selling_price' => 300,
        'active' => true,
    ]);
    Inventory::create(['product_id' => $product->id, 'location' => 'storage', 'quantity' => 10]);

    // First add standard product
    Volt::actingAs($user)
        ->test('pages.sales.index')
        ->call('quickAddToCart', $product->id)
        ->assertSet('cart.product:'.$product->id.'.type', 'normal')
        // Attempting to open mix modal warns separation
        ->call('openMixModal')
        ->assertDispatched('toast');
});

test('custom mix follows full lifecycle downpay -> mix -> price determined -> full pay -> release', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $brand = Brand::create(['name' => 'Boysen', 'active' => true]);
    $brandDavies = Brand::create(['name' => 'Davies', 'active' => true]);
    $cat = Category::create(['name' => 'Custom Colors', 'active' => true]);

    $basePaint = Product::create([
        'sku' => 'BOY-BASE-1',
        'name' => 'Boysen Mixing Base White',
        'brand_id' => $brand->id,
        'category_id' => $cat->id,
        'selling_price' => 600.0,
        'active' => true,
    ]);
    $tinter = Product::create([
        'sku' => 'BOY-TINT-1',
        'name' => 'Boysen Hansa Yellow',
        'brand_id' => $brand->id,
        'category_id' => $cat->id,
        'selling_price' => 250.0,
        'active' => true,
    ]);
    $daviesProduct = Product::create([
        'sku' => 'DAV-BASE-1',
        'name' => 'Davies Base',
        'brand_id' => $brandDavies->id,
        'category_id' => $cat->id,
        'selling_price' => 700.0,
        'active' => true,
    ]);

    // STAGE 1: Customer requests 2 gallons of custom color and pays ₱500 downpayment
    Volt::actingAs($user)
        ->test('pages.sales.index')
        ->set('mixCustomerName', 'Robert Lim')
        ->set('mixCustomerContact', '0918-987-6543')
        ->set('mixBrandId', (string) $brand->id)
        ->set('mixCustomColor', 'Warm Sunshine #S-201')
        ->set('mixQuantity', '2')
        ->set('mixResultUnit', 'gal')
        ->set('mixDownpaymentAmount', '500.00')
        ->set('mixTenderedAmount', '1000.00')
        ->call('saveCustomMixDownpayment')
        ->assertHasNoErrors();

    $sale = Sale::where('customer_name', 'Robert Lim')->firstOrFail();
    $tx = MixingTransaction::where('sale_id', $sale->id)->firstOrFail();

    expect($sale->type)->toBe('mixed')
        ->and((float) $sale->downpayment_amount)->toBe(500.0)
        ->and((float) $sale->total)->toBe(0.0)
        ->and($sale->payment_status)->toBe('downpayment_paid')
        ->and($tx->status)->toBe('downpaid')
        ->and($tx->components)->toHaveCount(0);

    // STAGE 2: Mixer opens custom mixes queue, enters materials used (enforces brand)
    Volt::actingAs($user)
        ->test('pages.sales.custom-mixes')
        ->call('openFormulaModal', $tx->id)
        // Trying to add Davies product to Boysen mix should fail
        ->set('formulaProductId', (string) $daviesProduct->id)
        ->call('addFormulaComponent')
        ->assertHasErrors('formulaProductId');

    // Add Boysen materials
    Volt::actingAs($user)
        ->test('pages.sales.custom-mixes')
        ->call('openFormulaModal', $tx->id)
        ->set('formulaProductId', (string) $basePaint->id)
        ->call('addFormulaComponent')
        ->set('formulaProductId', (string) $tinter->id)
        ->call('addFormulaComponent')
        ->call('saveFormula')
        ->assertHasNoErrors();

    $sale->refresh();
    $tx->refresh();

    // Price basis should be 600 (highest material: basePaint)
    // Total price = 600 * 2 gals = 1200. Downpayment = 500. Balance due = 700.
    expect((float) $tx->price_basis)->toBe(600.0)
        ->and($tx->price_basis_product_id)->toBe($basePaint->id)
        ->and($tx->status)->toBe('mixed')
        ->and($tx->components)->toHaveCount(2)
        ->and((float) $sale->total)->toBe(1200.0)
        ->and((float) $sale->balance_due)->toBe(700.0)
        ->and($sale->payment_status)->toBe('downpayment_paid')
        // Materials flagged for physical check
        ->and($basePaint->fresh()->requires_physical_check)->toBeTrue()
        ->and($tinter->fresh()->requires_physical_check)->toBeTrue();

    // STAGE 3: Customer arrives for pickup, pays remaining ₱700 balance through checkout page
    Volt::actingAs($user)
        ->test('pages.sales.custom-mixes')
        ->call('proceedToCollectBalance', $sale->id)
        ->assertRedirect(route('sales.checkout'));

    expect(session('pos.collect_mix_sale_id'))->toBe($sale->id);

    Volt::actingAs($user)
        ->test('pages.sales.checkout')
        ->set('tenderedAmount', '1000.00')
        ->call('completeSale')
        ->assertHasNoErrors();

    $sale->refresh();
    $tx->refresh();

    expect((float) $sale->balance_due)->toBe(0.0)
        ->and($sale->payment_status)->toBe('paid')
        ->and((float) $sale->change_amount)->toBe(300.0)
        ->and($tx->status)->toBe('completed');
});
