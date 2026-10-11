<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\PackageUnit;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;

test('regular common purchase deducts storage first then storefront fallback', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $brand = Brand::create(['name' => 'Boysen', 'active' => true]);
    $cat = Category::create(['name' => 'Latex Paint', 'active' => true]);
    $unit = PackageUnit::create(['name' => 'Gallon', 'abbreviation' => 'gal', 'active' => true]);

    $product = Product::create([
        'sku' => 'BOY-COMMON-GAL',
        'name' => 'Boysen Permacoat White',
        'brand_id' => $brand->id,
        'category_id' => $cat->id,
        'package_unit_id' => $unit->id,
        'selling_price' => 600.0,
        'active' => true,
    ]);

    // Storage has 2 gals, Storefront has 3 gals. Total = 5 gals.
    Inventory::create(['product_id' => $product->id, 'location' => 'storage', 'quantity' => 2.0]);
    Inventory::create(['product_id' => $product->id, 'location' => 'storefront', 'quantity' => 3.0]);

    // Customer buys 3 gallons.
    // Rule: Retrieve from storage first (2 gals), then storefront (1 gal).
    session([
        'pos.cart' => [
            'product:'.$product->id => [
                'type' => 'normal',
                'product_id' => $product->id,
                'description' => $product->name,
                'package' => '4L Gallon',
                'brand' => 'Boysen',
                'unit' => 'gal',
                'sku' => $product->sku,
                'quantity' => 3.0,
                'unit_price' => 600.0,
                'subtotal' => 1800.0,
            ],
        ],
    ]);

    Volt::actingAs($user)
        ->test('pages.sales.checkout')
        ->set('tenderedAmount', '2000.00')
        ->call('completeSale')
        ->assertHasNoErrors();

    // Storage should have 0 left (was 2, deducted 2)
    expect((float) $product->fresh()->getStorageStock())->toBe(0.0)
        // Storefront should have 2 left (was 3, deducted 1)
        ->and((float) $product->fresh()->getStorefrontStock())->toBe(2.0)
        // Verify 2 movements logged (one for storage, one for storefront)
        ->and(InventoryMovement::where('product_id', $product->id)->where('location', 'storage')->where('quantity_change', -2.0)->count())->toBe(1)
        ->and(InventoryMovement::where('product_id', $product->id)->where('location', 'storefront')->where('quantity_change', -1.0)->count())->toBe(1);
});

test('repack purchase strictly deducts from storefront at 0.25 gal per liter', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $brand = Brand::create(['name' => 'Boysen', 'active' => true]);
    $cat = Category::create(['name' => 'Latex Paint', 'active' => true]);
    $unit = PackageUnit::create(['name' => 'Gallon', 'abbreviation' => 'gal', 'active' => true]);

    $product = Product::create([
        'sku' => 'BOY-REPACK-GAL',
        'name' => 'Boysen Gloss Acrylic',
        'brand_id' => $brand->id,
        'category_id' => $cat->id,
        'package_unit_id' => $unit->id,
        'selling_price' => 800.0, // 800 per gal -> 200 per liter
        'active' => true,
    ]);

    // Storage has 10 gals, Storefront has 2 gals.
    Inventory::create(['product_id' => $product->id, 'location' => 'storage', 'quantity' => 10.0]);
    Inventory::create(['product_id' => $product->id, 'location' => 'storefront', 'quantity' => 2.0]);

    // In POS sales.index, select product, switch to repack, add 4 liters
    // 4 Liters requires 1.0 gal strictly from storefront. Price = 800/4 * 4 = 800.
    Volt::actingAs($user)
        ->test('pages.sales.index')
        ->call('selectProduct', $product->id)
        ->set('purchaseMode', 'repack')
        ->set('addQty', '4')
        ->call('addToCart')
        ->assertHasNoErrors();

    // Verify cart item
    $cart = session('pos.cart');
    expect($cart)->toHaveKey('product:'.$product->id.':repack')
        ->and($cart['product:'.$product->id.':repack']['unit_price'])->toBe(200.0)
        ->and($cart['product:'.$product->id.':repack']['subtotal'])->toBe(800.0);

    // Proceed to checkout
    Volt::actingAs($user)
        ->test('pages.sales.checkout')
        ->set('tenderedAmount', '1000.00')
        ->call('completeSale')
        ->assertHasNoErrors();

    // Verify stock: Storefront was 2.0, deducted 1.0 gal (4L * 0.25) -> 1.0 gal left
    expect((float) $product->fresh()->getStorefrontStock())->toBe(1.0)
        // Storage should remain untouched (10.0 gals)
        ->and((float) $product->fresh()->getStorageStock())->toBe(10.0);
});

test('repack purchase fails when storefront has insufficient stock even if storage has stock', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $brand = Brand::create(['name' => 'Davies', 'active' => true]);
    $cat = Category::create(['name' => 'Enamel', 'active' => true]);
    $unit = PackageUnit::create(['name' => 'Gallon', 'abbreviation' => 'gal', 'active' => true]);

    $product = Product::create([
        'sku' => 'DAV-ENAMEL',
        'name' => 'Davies Quick Drying Enamel',
        'brand_id' => $brand->id,
        'category_id' => $cat->id,
        'package_unit_id' => $unit->id,
        'selling_price' => 700.0,
        'active' => true,
    ]);

    // Storage has 10 gals, Storefront has only 0.2 gal (insufficient for 2 liters = 0.5 gal)
    Inventory::create(['product_id' => $product->id, 'location' => 'storage', 'quantity' => 10.0]);
    Inventory::create(['product_id' => $product->id, 'location' => 'storefront', 'quantity' => 0.2]);

    Volt::actingAs($user)
        ->test('pages.sales.index')
        ->call('selectProduct', $product->id)
        ->set('purchaseMode', 'repack')
        ->set('addQty', '2')
        ->call('addToCart')
        ->assertHasErrors('addQty');
});

test('non-paint products like tools and primers cannot be repacked', function () {
    $catTools = Category::create(['name' => 'Tools', 'active' => true]);
    $catPrimer = Category::create(['name' => 'Primers', 'active' => true]);
    $unitPcs = PackageUnit::create(['name' => 'Piece', 'abbreviation' => 'pcs', 'active' => true]);
    $unitGal = PackageUnit::create(['name' => 'Gallon', 'abbreviation' => 'gal', 'active' => true]);

    $roller = Product::create([
        'sku' => 'TOOL-ROLLER',
        'name' => 'Paint Roller 9 inch',
        'category_id' => $catTools->id,
        'package_unit_id' => $unitPcs->id,
        'selling_price' => 150.0,
        'active' => true,
    ]);

    $primer = Product::create([
        'sku' => 'EPOXY-PRIMER',
        'name' => 'Epoxy Primer White',
        'category_id' => $catPrimer->id,
        'package_unit_id' => $unitGal->id,
        'selling_price' => 950.0,
        'active' => true,
    ]);

    expect($roller->canBeRepacked())->toBeFalse()
        ->and($primer->canBeRepacked())->toBeFalse();
});

test('collecting balance for custom mix order completes the order and logs audit', function () {
    $cashier = User::factory()->create(['role' => 'admin']);
    $brand = Brand::create(['name' => 'Boysen', 'active' => true]);

    $sale = Sale::create([
        'user_id' => $cashier->id,
        'invoice_number' => 'SALE-MIX-001',
        'sold_at' => now(),
        'type' => 'mixed',
        'subtotal' => 1200.0,
        'total' => 1200.0,
        'downpayment_amount' => 600.0,
        'balance_due' => 600.0,
        'payment_status' => 'downpayment_paid',
        'payment_method' => 'cash',
        'payment_amount' => 600.0,
        'change_amount' => 0.0,
    ]);

    $mix = $sale->mixingTransaction()->create([
        'brand_id' => $brand->id,
        'custom_color' => 'Laguna Teal',
        'price_basis' => 600.0,
        'resulting_quantity' => 2.0,
        'resulting_unit' => 'gal',
        'status' => 'pending_mix',
    ]);

    // Cashier initiates balance collection via POS and completes via checkout
    Volt::actingAs($cashier)
        ->test('pages.sales.index')
        ->call('proceedToCollectBalance', $sale->id)
        ->assertRedirect(route('sales.checkout'));

    expect(session('pos.collect_mix_sale_id'))->toBe($sale->id);

    Volt::actingAs($cashier)
        ->test('pages.sales.checkout')
        ->set('tenderedAmount', '1000.00')
        ->call('completeSale')
        ->assertHasNoErrors()
        ->assertRedirect(route('sales.receipt', ['sale' => $sale]));

    expect((float) $sale->fresh()->balance_due)->toBe(0.0)
        ->and($sale->fresh()->payment_status)->toBe('paid')
        ->and($sale->fresh()->mixingTransaction->status)->toBe('completed')
        ->and((float) $sale->fresh()->change_amount)->toBe(400.0);
});

test('admin password authorization validates administrator credentials for discount', function () {
    $cashier = User::factory()->create(['role' => 'manager']);
    $admin = User::factory()->create(['role' => 'admin', 'password' => Hash::make('secret-admin-pass'), 'active' => true]);

    session([
        'pos.cart' => [
            'test_prod' => [
                'type' => 'normal',
                'product_id' => 1,
                'description' => 'Test Paint',
                'quantity' => 1,
                'unit_price' => 1000.0,
                'subtotal' => 1000.0,
            ],
        ],
    ]);

    $component = Volt::actingAs($cashier)
        ->test('pages.sales.checkout')
        ->set('discountMode', 'fixed')
        ->set('discountValue', '100')
        ->set('discountReason', 'Special Contractor Discount')
        // Try wrong password
        ->set('adminAuthUserId', $admin->id)
        ->set('adminAuthPassword', 'wrong-pass')
        ->call('authorizeDiscount')
        ->assertHasErrors('adminAuthPassword')
        ->assertSet('discountAuthorized', false)
        // Enter correct password
        ->set('adminAuthPassword', 'secret-admin-pass')
        ->call('authorizeDiscount')
        ->assertHasNoErrors()
        ->assertSet('discountAuthorized', true)
        ->assertSet('discountAuthorizedBy', $admin->id);
});

test('repack purchase supports half gallon and eighth gallon fractions', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $brand = Brand::create(['name' => 'Boysen', 'active' => true]);
    $cat = Category::create(['name' => 'Latex Paint', 'active' => true]);
    $unit = PackageUnit::create(['name' => 'Gallon', 'abbreviation' => 'gal', 'active' => true]);

    $product = Product::create([
        'sku' => 'BOY-FRACTIONS',
        'name' => 'Boysen Permacoat Latex',
        'brand_id' => $brand->id,
        'category_id' => $cat->id,
        'package_unit_id' => $unit->id,
        'selling_price' => 800.0,
        'active' => true,
    ]);

    // Storefront has 3 gals
    Inventory::create(['product_id' => $product->id, 'location' => 'storage', 'quantity' => 10.0]);
    Inventory::create(['product_id' => $product->id, 'location' => 'storefront', 'quantity' => 3.0]);

    // 1 unit of 1/2 gal (2L) -> fraction = 0.5, price = 400.0, deducts 0.5 gal
    Volt::actingAs($user)
        ->test('pages.sales.index')
        ->call('selectProduct', $product->id)
        ->set('purchaseMode', 'repack')
        ->set('repackFraction', '0.5')
        ->set('addQty', '1')
        ->call('addToCart')
        ->assertHasNoErrors();

    // 2 units of 1/8 gal (0.5L) -> fraction = 0.125, price = 100.0 each, deducts 2 * 0.125 = 0.25 gal
    Volt::actingAs($user)
        ->test('pages.sales.index')
        ->call('selectProduct', $product->id)
        ->set('purchaseMode', 'repack')
        ->set('repackFraction', '0.125')
        ->set('addQty', '2')
        ->call('addToCart')
        ->assertHasNoErrors();

    $cart = session('pos.cart');
    expect($cart)->toHaveKey('product:'.$product->id.':repack:0_5')
        ->and((float) $cart['product:'.$product->id.':repack:0_5']['unit_price'])->toBe(400.0)
        ->and((float) $cart['product:'.$product->id.':repack:0_5']['subtotal'])->toBe(400.0)
        ->and($cart)->toHaveKey('product:'.$product->id.':repack:0_125')
        ->and((float) $cart['product:'.$product->id.':repack:0_125']['unit_price'])->toBe(100.0)
        ->and((float) $cart['product:'.$product->id.':repack:0_125']['subtotal'])->toBe(200.0);

    // Complete checkout
    Volt::actingAs($user)
        ->test('pages.sales.checkout')
        ->set('tenderedAmount', '1000.00')
        ->call('completeSale')
        ->assertHasNoErrors();

    // Total deducted from storefront = 0.5 + 0.25 = 0.75 gal. Remaining = 3.0 - 0.75 = 2.25 gal
    expect((float) $product->fresh()->getStorefrontStock())->toBe(2.25)
        ->and((float) $product->fresh()->getStorageStock())->toBe(10.0);
});

test('custom mix flow can collect downpayment and balance through checkout page', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $brand = Brand::create(['name' => 'Boysen', 'active' => true]);

    // 1. Queue custom mix downpayment from custom-mixes page
    Volt::actingAs($user)
        ->test('pages.sales.custom-mixes')
        ->set('newBrandId', (string) $brand->id)
        ->set('newCustomColor', 'Warm Ochre #W-204')
        ->set('newQuantity', '2')
        ->set('newResultUnit', 'gal')
        ->set('newDownpaymentAmount', '500')
        ->set('newCustomerName', 'Juan Dela Cruz')
        ->set('newCustomerContact', '09123456789')
        ->call('saveNewMixDownpayment')
        ->assertRedirect(route('sales.checkout'));

    expect(session('pos.cart'))->not->toBeEmpty()
        ->and(session('pos.customer_name'))->toBe('Juan Dela Cruz');

    // 2. Complete downpayment on sales.checkout
    Volt::actingAs($user)
        ->test('pages.sales.checkout')
        ->set('tenderedAmount', '1000.00')
        ->call('completeSale')
        ->assertHasNoErrors();

    $sale = Sale::where('type', 'mixed')->firstOrFail();
    expect((float) $sale->downpayment_amount)->toBe(500.0)
        ->and($sale->payment_status)->toBe('downpayment_paid')
        ->and($sale->mixingTransaction)->not->toBeNull();

    // 3. Mark formula as mixed with balance due
    $sale->update([
        'total' => 1200.0,
        'balance_due' => 700.0,
    ]);
    $sale->mixingTransaction->update(['status' => 'mixed']);

    // 4. Proceed to collect balance on sales.checkout
    session(['pos.collect_mix_sale_id' => $sale->id]);

    Volt::actingAs($user)
        ->test('pages.sales.checkout')
        ->assertSee('Remaining Balance Due:')
        ->set('tenderedAmount', '1000.00')
        ->call('completeSale')
        ->assertHasNoErrors();

    $sale->refresh();
    expect((float) $sale->balance_due)->toBe(0.0)
        ->and($sale->payment_status)->toBe('paid')
        ->and((float) $sale->change_amount)->toBe(300.0)
        ->and($sale->mixingTransaction->fresh()->status)->toBe('completed');
});
