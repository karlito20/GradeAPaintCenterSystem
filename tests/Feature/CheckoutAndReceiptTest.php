<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\MixingTransaction;
use App\Models\PackageUnit;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\User;
use Livewire\Volt\Volt;

test('checkout processes cash sale with discount, decrements stock, and logs movements and audits', function () {
    $user = User::factory()->create(['role' => 'admin']);
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

    Inventory::create(['product_id' => $product->id, 'location' => 'storefront', 'quantity' => 10.0]);

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
        ->set('discountPercentage', '10') // 10% discount on 1000 = 100 discount, net 900, 0% tax, total 900
        ->set('tenderedAmount', '1100.00')
        ->call('completeSale')
        ->assertHasNoErrors()
        ->assertRedirect();

    $sale = Sale::latest('id')->first();
    expect($sale)->not->toBeNull()
        ->and((float) $sale->subtotal)->toBe(1000.00)
        ->and((float) $sale->discount_percentage)->toBe(10.00)
        ->and((float) $sale->discount_amount)->toBe(100.00)
        ->and((float) $sale->tax_rate)->toBe(0.00)
        ->and((float) $sale->tax_amount)->toBe(0.00)
        ->and((float) $sale->total)->toBe(900.00)
        ->and((float) $sale->payment_amount)->toBe(1100.00)
        ->and((float) $sale->change_amount)->toBe(200.00);

    // Verify inventory decremented
    expect((float) $product->fresh()->getInventory('storefront')->quantity)->toBe(8.0);

    // Verify negative movement logged
    $movement = InventoryMovement::where('product_id', $product->id)->where('type', 'sale')->first();
    expect($movement)->not->toBeNull()
        ->and((float) $movement->quantity_change)->toBe(-2.000)
        ->and((float) $movement->quantity_before)->toBe(10.000)
        ->and((float) $movement->quantity_after)->toBe(8.000);

    // Verify audit log
    $saleAudit = AuditLog::where('auditable_type', Sale::class)->where('auditable_id', $sale->id)->where('event', 'sale_completed')->first();
    expect($saleAudit)->not->toBeNull();

    $discountAudit = AuditLog::where('auditable_type', Sale::class)->where('auditable_id', $sale->id)->where('event', 'sale_discount_applied')->first();
    expect($discountAudit)->not->toBeNull()
        ->and((float) $discountAudit->context['discount_amount'])->toBe(100.00);
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

test('checkout cash tendered validation runs live and reactively triggers and dismisses insufficient amount error', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $brand = Brand::create(['name' => 'Boysen', 'active' => true]);
    $category = Category::create(['name' => 'Latex', 'active' => true]);
    $product = Product::create([
        'sku' => 'TEST-PROD-LIVE',
        'name' => 'Boysen Permacoat',
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'selling_price' => 1000.00,
        'active' => true,
    ]);

    session([
        'pos.cart' => [
            [
                'id' => 'prod_'.$product->id,
                'type' => 'product',
                'product_id' => $product->id,
                'description' => $product->name,
                'unit' => 'gal',
                'unit_price' => 1000.00,
                'quantity' => 1.0,
                'subtotal' => 1000.00,
            ],
        ],
    ]);

    $component = Volt::actingAs($user)
        ->test('pages.sales.checkout');

    // Initially total is 1000.
    // Typing an insufficient amount (500) triggers insufficient amount error
    $component->set('tenderedAmount', '500')
        ->assertHasErrors(['tenderedAmount']);

    // Typing sufficient amount (1000) dismisses error
    $component->set('tenderedAmount', '1000')
        ->assertHasNoErrors(['tenderedAmount']);

    // Typing more than sufficient (1500) remains valid and calculates change (500)
    $component->set('tenderedAmount', '1500')
        ->assertHasNoErrors(['tenderedAmount']);
    expect($component->instance()->changeDue())->toBe(500.00);

    // Clearing to empty string triggers insufficient amount error
    $component->set('tenderedAmount', '')
        ->assertHasErrors(['tenderedAmount']);

    // Changing discount reduces total due, making previously insufficient amount valid
    $component->set('tenderedAmount', '950')
        ->assertHasErrors(['tenderedAmount'])
        ->set('discountPercentage', '10') // Total becomes 900
        ->assertHasNoErrors(['tenderedAmount']);
    expect($component->instance()->changeDue())->toBe(50.00);
});

test('custom mix checkout calculates change when downpayment exceeds final formula price', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $brand = Brand::create(['name' => 'Boysen', 'active' => true]);
    $category = Category::create(['name' => 'Latex', 'active' => true]);
    $basePaint = Product::create([
        'sku' => 'BASE-750',
        'name' => 'Boysen Base 750',
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'selling_price' => 750.00,
        'active' => true,
    ]);

    // Downpayment of 1000 was collected for 1 gal mix
    $sale = Sale::create([
        'user_id' => $user->id,
        'customer_name' => 'Maria Santos',
        'customer_contact' => '0918-123-4567',
        'invoice_number' => 'SALE-MIX-EXCESS',
        'sold_at' => now(),
        'type' => 'mixed',
        'subtotal' => 1000.0,
        'total' => 1000.0,
        'downpayment_amount' => 1000.0,
        'balance_due' => 0.0,
        'payment_status' => 'downpayment_paid',
        'payment_method' => 'cash',
        'payment_amount' => 1000.0,
        'change_amount' => 0.0,
    ]);

    $tx = MixingTransaction::create([
        'sale_id' => $sale->id,
        'brand_id' => $brand->id,
        'custom_color' => 'Sunset Peach',
        'resulting_quantity' => 1.0,
        'resulting_unit' => 'gal',
        'price_basis' => 1000.0,
        'status' => 'pending_mix',
    ]);

    // Mixer logs formula with basePaint (price 750)
    Volt::actingAs($user)
        ->test('pages.sales.custom-mixes')
        ->call('openFormulaModal', $tx->id)
        ->set('formulaProductId', (string) $basePaint->id)
        ->call('addFormulaComponent')
        ->call('saveFormula')
        ->assertHasNoErrors();

    $sale->refresh();
    $tx->refresh();

    // Final total is 750. Downpayment was 1000. Balance is 0. Status is mixed.
    expect((float) $sale->total)->toBe(750.0)
        ->and((float) $sale->downpayment_amount)->toBe(1000.0)
        ->and((float) $sale->balance_due)->toBe(0.0)
        ->and($tx->status)->toBe('mixed');

    // Customer picks up mixed paint, checkout calculates 250 change
    session(['pos.collect_mix_sale_id' => $sale->id]);

    $checkout = Volt::actingAs($user)
        ->test('pages.sales.checkout');

    expect($checkout->instance()->changeDue())->toBe(250.00);

    // Tendered amount defaults to 0.00, customer owes nothing, 0 cash is sufficient
    $checkout->set('tenderedAmount', '0.00')
        ->assertHasNoErrors(['tenderedAmount'])
        ->call('completeSale')
        ->assertHasNoErrors();

    $sale->refresh();
    $tx->refresh();

    expect((float) $sale->balance_due)->toBe(0.0)
        ->and($sale->payment_status)->toBe('paid')
        ->and((float) $sale->change_amount)->toBe(250.0)
        ->and($tx->status)->toBe('completed');
});

test('custom mix checkout calculates extra change when customer tenders cash during release of overpaid downpayment', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $brand = Brand::create(['name' => 'Boysen', 'active' => true]);
    $category = Category::create(['name' => 'Latex', 'active' => true]);
    $basePaint = Product::create([
        'sku' => 'BASE-800',
        'name' => 'Boysen Base 800',
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'selling_price' => 800.00,
        'active' => true,
    ]);

    // Downpayment of 1000 was collected for 1 gal mix
    $sale = Sale::create([
        'user_id' => $user->id,
        'customer_name' => 'Jose Rizal',
        'customer_contact' => '0919-000-1111',
        'invoice_number' => 'SALE-MIX-EXCESS-2',
        'sold_at' => now(),
        'type' => 'mixed',
        'subtotal' => 800.0,
        'total' => 800.0,
        'downpayment_amount' => 1000.0,
        'balance_due' => 0.0,
        'payment_status' => 'downpayment_paid',
        'payment_method' => 'cash',
        'payment_amount' => 1000.0,
        'change_amount' => 0.0,
    ]);

    $tx = MixingTransaction::create([
        'sale_id' => $sale->id,
        'brand_id' => $brand->id,
        'custom_color' => 'Laguna Teal',
        'resulting_quantity' => 1.0,
        'resulting_unit' => 'gal',
        'price_basis' => 800.0,
        'status' => 'mixed',
    ]);

    session(['pos.collect_mix_sale_id' => $sale->id]);

    // Downpayment was 1000, total is 800 (refund 200). Customer tenders 50.00. Total change should be 250.00.
    Volt::actingAs($user)
        ->test('pages.sales.checkout')
        ->set('tenderedAmount', '50.00')
        ->assertHasNoErrors(['tenderedAmount'])
        ->call('completeSale')
        ->assertHasNoErrors();

    $sale->refresh();
    expect((float) $sale->change_amount)->toBe(250.0)
        ->and((float) $sale->payment_amount)->toBe(1050.0)
        ->and($sale->payment_status)->toBe('paid')
        ->and($tx->fresh()->status)->toBe('completed');
});

test('sales history table displays status without balance sublabel', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $sale = Sale::create([
        'user_id' => $user->id,
        'customer_name' => 'Juan Luna',
        'invoice_number' => 'SALE-TEST-STATUS-1',
        'sold_at' => now(),
        'type' => 'normal',
        'subtotal' => 1000.0,
        'total' => 1000.0,
        'downpayment_amount' => 500.0,
        'balance_due' => 500.0,
        'payment_status' => 'downpayment_paid',
        'payment_method' => 'cash',
        'payment_amount' => 500.0,
    ]);

    Volt::actingAs($user)
        ->test('pages.sales.history')
        ->assertSee('Downpayment')
        ->assertDontSee('Bal:');
});

test('custom mixes table displays separate date and time, ready status, and no contact column', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $brand = Brand::create(['name' => 'Davies Test', 'active' => true]);
    $sale = Sale::create([
        'user_id' => $user->id,
        'customer_name' => 'Maria Clara',
        'customer_contact' => '09123456789',
        'invoice_number' => 'SALE-MIX-TABLE-1',
        'sold_at' => now(),
        'type' => 'mixed',
        'subtotal' => 600.0,
        'total' => 600.0,
        'downpayment_amount' => 300.0,
        'balance_due' => 300.0,
        'payment_status' => 'downpayment_paid',
        'payment_method' => 'cash',
        'payment_amount' => 300.0,
    ]);

    MixingTransaction::create([
        'sale_id' => $sale->id,
        'brand_id' => $brand->id,
        'custom_color' => 'Ocean Sky Blue',
        'resulting_quantity' => 2.0,
        'resulting_unit' => 'gal',
        'price_basis' => 600.0,
        'status' => 'mixed',
    ]);

    Volt::actingAs($user)
        ->test('pages.sales.custom-mixes')
        ->assertSee('Ready')
        ->assertSee('text-sky-700')
        ->assertDontSee('Ready for Pickup')
        ->assertDontSee('Contact #')
        ->assertSee('SALE-MIX-TABLE-1')
        ->assertSee(route('sales.receipt', $sale));
});

test('quotations table displays separate date and time with standardized widths and currency format', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $quote = Quotation::create([
        'user_id' => $user->id,
        'customer_name' => 'Diego Silang',
        'quote_number' => 'QT-TEST-ALIGN-1',
        'status' => 'draft',
        'subtotal' => 1500.0,
        'total' => 1500.0,
        'valid_until' => now()->addDays(7),
    ]);

    Volt::actingAs($user)
        ->test('pages.sales.quotations')
        ->assertSee('QT-TEST-ALIGN-1')
        ->assertSee($quote->created_at->format('Y-m-d'))
        ->assertSee($quote->created_at->format('h:i A'))
        ->assertSee($quote->valid_until->format('Y-m-d'))
        ->assertSee('₱1,500.00');
});

test('pos page filter inputs use rounded border matching sales module', function () {
    $user = User::factory()->create(['role' => 'admin']);

    Volt::actingAs($user)
        ->test('pages.sales.index')
        ->assertSee('class="w-full h-9 rounded border border-slate-300 pl-9 pr-3 text-xs bg-white text-slate-900', false);
});

test('custom mix page table removes total downpayment and change columns', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $brand = Brand::create(['name' => 'Davies Test', 'active' => true]);

    $sale = Sale::create([
        'user_id' => $user->id,
        'invoice_number' => 'CM-TEST-COLS-01',
        'sold_at' => now(),
        'type' => 'custom_mix',
        'subtotal' => 1200.0,
        'tax_rate' => 0.0,
        'tax_amount' => 0.0,
        'total' => 1200.0,
        'payment_status' => 'downpayment_paid',
        'downpayment_amount' => 600.0,
        'balance_due' => 600.0,
    ]);

    MixingTransaction::create([
        'sale_id' => $sale->id,
        'user_id' => $user->id,
        'brand_id' => $brand->id,
        'custom_color' => 'Ocean Blue',
        'resulting_quantity' => 2.0,
        'resulting_unit' => 'gal',
        'price_basis' => 600.0,
        'status' => 'pending_mix',
    ]);

    $component = Volt::actingAs($user)
        ->test('pages.sales.custom-mixes');

    $html = $component->html();

    expect($html)->toContain('Invoice #')
        ->and($html)->toContain('Color Name')
        ->and($html)->toContain('Volume')
        ->and($html)->toContain('Status')
        ->and($html)->not->toContain('>Total</th>')
        ->and($html)->not->toContain('>Downpayment</th>')
        ->and($html)->not->toContain('>Change</th>');
});

test('pos page supports toggling between table and grid views with matching product data', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $brand = Brand::create(['name' => 'SuperBrand', 'active' => true]);
    $unit = PackageUnit::create(['name' => 'Gallon', 'abbreviation' => 'gal', 'active' => true]);
    $category = Category::create(['name' => 'Topcoat', 'active' => true]);

    $product = Product::create([
        'sku' => 'TEST-SUPER-GLOSS',
        'name' => 'Super Premium Gloss',
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'package_unit_id' => $unit->id,
        'selling_price' => 850.00,
        'is_active' => true,
        'status' => 'in_stock',
    ]);

    Inventory::create([
        'product_id' => $product->id,
        'location' => 'storefront',
        'quantity' => 15.00,
    ]);

    $component = Volt::actingAs($user)
        ->test('pages.sales.index');

    // Default view mode is table
    expect($component->get('viewMode'))->toBe('table');

    // Switch to grid view
    $component->call('setViewMode', 'grid')
        ->assertSet('viewMode', 'grid');

    $html = $component->html();
    expect($html)->toContain('SuperBrand')
        ->and($html)->toContain('Super Premium Gloss')
        ->and($html)->toContain('gal')
        ->and($html)->toContain('15.00')
        ->and($html)->toContain('₱850.00')
        ->and($html)->toContain('grid-prod-'.$product->id);

    // Switch back to table view
    $component->call('setViewMode', 'table')
        ->assertSet('viewMode', 'table');
});
