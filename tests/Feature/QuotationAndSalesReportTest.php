<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\PackageUnit;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;

test('a cashier can save the pos cart as a quotation and load it back into the cart', function () {
    $user = User::factory()->create(['role' => 'manager']);
    $brand = Brand::create(['name' => 'Boysen', 'active' => true]);
    $category = Category::create(['name' => 'Latex', 'active' => true]);
    $unit = PackageUnit::create(['name' => 'Gallon', 'abbreviation' => 'gal', 'active' => true]);

    $product = Product::create([
        'sku' => 'QUOTE-PROD-01',
        'name' => 'Boysen Permacoat White',
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'package_unit_id' => $unit->id,
        'package_size' => 4.0,
        'selling_price' => 600.00,
        'low_stock_threshold' => 2,
        'active' => true,
    ]);
    Inventory::create(['product_id' => $product->id, 'quantity' => 20.0]);

    // Add product to POS cart, open quote modal, and save quote
    Volt::actingAs($user)
        ->test('pages.sales.index')
        ->set('selectedProductId', (string) $product->id)
        ->set('addQty', '2')
        ->call('addToCart')
        ->call('openQuoteModal')
        ->set('quoteCustomerName', 'Juan Dela Cruz')
        ->set('quoteCustomerContact', '09123456789')
        ->set('quoteNotes', 'Pick up by Friday')
        ->set('quoteDiscountPercentage', '5')
        ->set('quoteTaxApplied', true)
        ->call('saveAsQuotation')
        ->assertHasNoErrors()
        ->assertRedirect(route('sales.quotations'));

    $quote = Quotation::with('items')->latest('id')->first();
    expect($quote)->not->toBeNull()
        ->and($quote->customer_name)->toBe('Juan Dela Cruz')
        ->and($quote->customer_contact)->toBe('09123456789')
        ->and((float) $quote->subtotal)->toBe(1200.00)
        ->and((float) $quote->discount_amount)->toBe(60.00) // 5% of 1200
        ->and((float) $quote->tax_rate)->toBe(0.00)
        ->and((float) $quote->tax_amount)->toBe(0.00)
        ->and((float) $quote->total)->toBe(1140.00)
        ->and($quote->status)->toBe('draft')
        ->and($quote->items)->toHaveCount(1)
        ->and((float) $quote->items->first()->quantity)->toBe(2.00);

    // Verify loading quote back into POS cart
    Volt::actingAs($user)
        ->test('pages.sales.index')
        ->call('loadQuote', $quote->id)
        ->assertHasNoErrors();

    expect(session('pos.cart'))->not->toBeEmpty()
        ->and(session('pos.quotation_id'))->toBe($quote->id)
        ->and(session('pos.customer_name'))->toBe('Juan Dela Cruz');
});

test('quotations management page allows viewing, status updates, and converting to sale', function () {
    $user = User::factory()->create(['role' => 'manager']);
    $quote = Quotation::create([
        'user_id' => $user->id,
        'quote_number' => 'QUO-TEST-001',
        'customer_name' => 'Maria Clara',
        'customer_contact' => '09998887777',
        'subtotal' => 1000.00,
        'discount_percentage' => 0.00,
        'discount_amount' => 0.00,
        'tax_rate' => 12.00,
        'tax_amount' => 120.00,
        'total' => 1120.00,
        'status' => 'draft',
        'valid_until' => now()->addDays(7),
    ]);

    // View quotations page, update status to sent
    Volt::actingAs($user)
        ->test('pages.sales.quotations')
        ->call('viewDetails', $quote->id)
        ->assertSet('showDetailModal', true)
        ->call('updateStatus', $quote->id, 'sent')
        ->assertHasNoErrors();

    expect($quote->fresh()->status)->toBe('sent');

    // Convert quotation to sale
    Volt::actingAs($user)
        ->test('pages.sales.quotations')
        ->call('convertToSale', $quote->id)
        ->assertRedirect(route('sales.index'));

    expect(session('pos.quotation_id'))->toBe($quote->id);
});

test('completing a checkout converts the linked quotation and stores 12% VAT and customer details', function () {
    $user = User::factory()->create(['role' => 'manager']);
    $category = Category::create(['name' => 'Paint']);
    $product = Product::create([
        'category_id' => $category->id,
        'sku' => 'TEST-CONVERT-SKU',
        'name' => 'Premium Gloss White',
        'selling_price' => 500.00,
        'active' => true,
    ]);
    Inventory::create(['product_id' => $product->id, 'quantity' => 10.0]);

    $quote = Quotation::create([
        'user_id' => $user->id,
        'quote_number' => 'QUO-CONVERT-123',
        'customer_name' => 'Don Crisostomo',
        'customer_contact' => '09112223333',
        'subtotal' => 500.00,
        'discount_percentage' => 0.00,
        'discount_amount' => 0.00,
        'tax_rate' => 12.00,
        'tax_amount' => 60.00,
        'total' => 560.00,
        'status' => 'sent',
        'valid_until' => now()->addDays(7),
    ]);

    session([
        'pos.cart' => [
            'prod_'.$product->id => [
                'type' => 'normal',
                'product_id' => $product->id,
                'description' => $product->name,
                'package' => '1 gal',
                'quantity' => 1.0,
                'unit_price' => 500.00,
                'subtotal' => 500.00,
            ],
        ],
        'pos.quotation_id' => $quote->id,
        'pos.customer_name' => $quote->customer_name,
        'pos.customer_contact' => $quote->customer_contact,
    ]);

    Volt::actingAs($user)
        ->test('pages.sales.checkout')
        ->set('discountPercentage', '0')
        ->set('tenderedAmount', '600.00') // Subtotal 500 + 0% tax = 500 total due
        ->call('completeSale')
        ->assertHasNoErrors()
        ->assertRedirect();

    $sale = Sale::latest('id')->first();
    expect($sale)->not->toBeNull()
        ->and($sale->customer_name)->toBe('Don Crisostomo')
        ->and($sale->quotation_id)->toBe($quote->id)
        ->and((float) $sale->tax_rate)->toBe(0.00)
        ->and((float) $sale->tax_amount)->toBe(0.00)
        ->and((float) $sale->total)->toBe(500.00)
        ->and((float) $sale->change_amount)->toBe(100.00);

    // Quotation should now be marked as accepted with converted_sale_id
    expect($quote->fresh()->status)->toBe('accepted')
        ->and($quote->fresh()->converted_sale_id)->toBe($sale->id)
        ->and($quote->fresh()->isConverted())->toBeTrue();

    // Verify the quotation row does not display "Converted to Sale" label
    Volt::actingAs($user)
        ->test('pages.sales.quotations')
        ->assertDontSee('Converted to Sale');
});

test('dashboard does not show sales history and full inventory shortcuts', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->get('/dashboard')
        ->assertOk()
        ->assertDontSee('Sales History &rarr;', false)
        ->assertDontSee('Full Inventory &rarr;', false);
});

test('sales report computes total revenue, 12% VAT tax, and filters by date preset', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $cashier = User::factory()->create(['role' => 'cashier']);

    // Create sales in different dates
    Carbon::setTestNow('2026-10-15 14:00:00');

    Sale::create([
        'user_id' => $cashier->id,
        'invoice_number' => 'SALE-REPORT-01',
        'sold_at' => now(), // today
        'type' => 'normal',
        'subtotal' => 1000.00,
        'discount_percentage' => 0.00,
        'discount_amount' => 0.00,
        'tax_rate' => 12.00,
        'tax_amount' => 120.00,
        'total' => 1120.00,
        'payment_method' => 'cash',
        'payment_amount' => 1120.00,
        'change_amount' => 0.00,
    ]);

    Sale::create([
        'user_id' => $cashier->id,
        'invoice_number' => 'SALE-REPORT-02',
        'sold_at' => now()->subMonths(2), // 2 months ago
        'type' => 'normal',
        'subtotal' => 2000.00,
        'discount_percentage' => 10.00,
        'discount_amount' => 200.00,
        'tax_rate' => 12.00,
        'tax_amount' => 216.00,
        'total' => 2016.00,
        'payment_method' => 'cash',
        'payment_amount' => 2100.00,
        'change_amount' => 84.00,
    ]);

    // Test sales report with 'this_month' preset (should only include SALE-REPORT-01)
    Volt::actingAs($manager)
        ->test('pages.reports.sales')
        ->set('activeTab', 'transactions')
        ->call('applyPreset', 'this_month')
        ->assertSee('SALE-REPORT-01')
        ->assertDontSee('SALE-REPORT-02');

    // Test with 'all' preset (should include both)
    Volt::actingAs($manager)
        ->test('pages.reports.sales')
        ->set('activeTab', 'transactions')
        ->call('applyPreset', 'all')
        ->assertSee('SALE-REPORT-01')
        ->assertSee('SALE-REPORT-02');

    Carbon::setTestNow();
});

test('unauthorized users cannot access quotations or sales report', function () {
    $cashier = User::factory()->create(['role' => 'cashier']);

    Volt::actingAs($cashier)
        ->test('pages.sales.quotations')
        ->assertForbidden();

    Volt::actingAs($cashier)
        ->test('pages.reports.sales')
        ->assertForbidden();
});

test('manager can download sales report pdf and cashier is forbidden', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $cashier = User::factory()->create(['role' => 'cashier']);

    Sale::create([
        'user_id' => $manager->id,
        'invoice_number' => 'PDF-TEST-01',
        'sold_at' => now(),
        'type' => 'normal',
        'subtotal' => 1000.00,
        'discount_percentage' => 0.00,
        'discount_amount' => 0.00,
        'tax_rate' => 12.00,
        'tax_amount' => 120.00,
        'total' => 1120.00,
        'payment_method' => 'cash',
        'payment_amount' => 1120.00,
        'change_amount' => 0.00,
    ]);

    test()->actingAs($manager)
        ->get(route('reports.sales.pdf'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    test()->actingAs($cashier)
        ->get(route('reports.sales.pdf'))
        ->assertForbidden();
});

test('mixer can access quotations list and details', function () {
    $mixer = User::factory()->create(['role' => 'mixer']);
    $quote = Quotation::create([
        'user_id' => $mixer->id,
        'quote_number' => 'QUO-MIXER-01',
        'customer_name' => 'John Architect',
        'subtotal' => 1200.00,
        'discount_percentage' => 0.00,
        'discount_amount' => 0.00,
        'tax_rate' => 12.00,
        'tax_amount' => 144.00,
        'total' => 1344.00,
        'status' => 'draft',
        'valid_until' => now()->addDays(7),
    ]);

    test()->actingAs($mixer)
        ->get(route('sales.quotations'))
        ->assertOk();

    Volt::actingAs($mixer)
        ->test('pages.sales.quotations')
        ->call('viewDetails', $quote->id)
        ->assertSet('showDetailModal', true);
});

test('mixer checkout with discount requires manager password authorization', function () {
    $mixer = User::factory()->create(['role' => 'mixer']);
    $manager = User::factory()->create([
        'role' => 'manager',
        'password' => Hash::make('secret123'),
    ]);

    $category = Category::create(['name' => 'Mixing Base']);
    $product = Product::create([
        'category_id' => $category->id,
        'sku' => 'MIX-PROD-01',
        'name' => 'Gloss White Base',
        'selling_price' => 1000.00,
        'active' => true,
    ]);
    Inventory::create(['product_id' => $product->id, 'quantity' => 10.0]);

    session([
        'pos.cart' => [
            'item_1' => [
                'type' => 'normal',
                'product_id' => $product->id,
                'description' => $product->name,
                'package' => '1 gal',
                'quantity' => 1.0,
                'unit_price' => 1000.00,
                'subtotal' => 1000.00,
            ],
        ],
    ]);

    // 1. Mixer attempts to complete sale with discount without authorization -> fails
    $test = Volt::actingAs($mixer)
        ->test('pages.sales.checkout')
        ->set('discountPercentage', '10')
        ->set('discountReason', 'Regular customer loyalty discount')
        ->set('tenderedAmount', '1100.00')
        ->call('completeSale')
        ->assertHasErrors(['discountPercentage']);

    // 2. Authorizing with incorrect manager password -> fails
    $test->set('managerAuthUserId', $manager->id)
        ->set('managerAuthPassword', 'wrongpassword')
        ->call('authorizeDiscount')
        ->assertHasErrors(['managerAuthPassword']);

    // 3. Authorizing with correct manager password -> succeeds
    $test->set('managerAuthPassword', 'secret123')
        ->call('authorizeDiscount')
        ->assertHasNoErrors()
        ->assertSet('discountAuthorized', true)
        ->assertSet('discountAuthorizedBy', $manager->id);

    // 4. Now mixer can complete the sale
    $test->call('completeSale')
        ->assertHasNoErrors()
        ->assertRedirect();

    $sale = Sale::latest('id')->first();
    expect($sale)->not->toBeNull()
        ->and((float) $sale->discount_percentage)->toBe(10.00)
        ->and($sale->discount_reason)->toBe('Regular customer loyalty discount')
        ->and($sale->discount_authorized_by)->toBe($manager->id);

    // Verify discount audit log records authorizing manager
    $audit = AuditLog::where('event', 'sale_discount_applied')->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->context['authorized_by_id'])->toBe($manager->id)
        ->and($audit->context['cashier_role'])->toBe('mixer');
});

test('discounted sale displays discount reason and authorizer on receipt', function () {
    $manager = User::factory()->create(['role' => 'manager', 'name' => 'Manager Alex']);
    $sale = Sale::create([
        'user_id' => $manager->id,
        'invoice_number' => 'RECEIPT-DISC-01',
        'sold_at' => now(),
        'type' => 'normal',
        'subtotal' => 1000.00,
        'discount_type' => 'employee',
        'discount_percentage' => 15.00,
        'discount_amount' => 150.00,
        'discount_reason' => 'Employee: Alex Cruz (EMP-09)',
        'discount_authorized_by' => $manager->id,
        'tax_rate' => 12.00,
        'tax_amount' => 102.00,
        'total' => 952.00,
        'payment_method' => 'cash',
        'payment_amount' => 1000.00,
        'change_amount' => 48.00,
    ]);

    test()->actingAs($manager)
        ->get("/sales/{$sale->id}/receipt")
        ->assertOk()
        ->assertSee('Discount (15.00% · Employee)')
        ->assertSee('Employee: Alex Cruz (EMP-09)')
        ->assertSee('Auth: Manager Alex');
});

test('user can open edit modal, modify quotation line items and details, and save', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $quote = Quotation::create([
        'user_id' => $manager->id,
        'quote_number' => 'QUO-EDIT-001',
        'customer_name' => 'Original Customer',
        'customer_contact' => '09111111111',
        'subtotal' => 500.00,
        'discount_percentage' => 0.00,
        'discount_amount' => 0.00,
        'tax_rate' => 0.00,
        'tax_amount' => 0.00,
        'total' => 500.00,
        'status' => 'draft',
        'valid_until' => now()->addDays(7),
    ]);

    $quote->items()->create([
        'description' => 'Original Item',
        'sku' => 'ORIG-SKU',
        'package' => '1 gal',
        'quantity' => 1.0,
        'unit_price' => 500.00,
        'subtotal' => 500.00,
    ]);

    $category = Category::create(['name' => 'Enamel']);
    $newProduct = Product::create([
        'category_id' => $category->id,
        'sku' => 'ADD-SKU-01',
        'name' => 'Additional Gloss Enamel',
        'selling_price' => 250.00,
        'active' => true,
    ]);

    Volt::actingAs($manager)
        ->test('pages.sales.quotations')
        ->call('openEditModal', $quote->id)
        ->assertSet('showEditModal', true)
        ->assertSet('editCustomerName', 'Original Customer')
        ->set('editCustomerName', 'Updated Contractor Corp')
        ->set('editCustomerContact', '09223334444')
        ->call('addProductToEdit', $newProduct->id)
        ->call('saveQuotationChanges')
        ->assertHasNoErrors()
        ->assertSet('showEditModal', false);

    $refreshed = $quote->fresh(['items']);
    expect($refreshed->customer_name)->toBe('Updated Contractor Corp')
        ->and($refreshed->customer_contact)->toBe('09223334444')
        ->and($refreshed->items)->toHaveCount(2)
        ->and((float) $refreshed->subtotal)->toBe(750.00)
        ->and((float) $refreshed->total)->toBe(750.00);
});

test('user can view printable quotation with official company header and signature section', function () {
    $manager = User::factory()->create(['role' => 'manager', 'name' => 'Manager Juan']);
    $quote = Quotation::create([
        'user_id' => $manager->id,
        'quote_number' => 'QUO-PRINT-001',
        'customer_name' => 'Acme Construction',
        'customer_contact' => '09334445555',
        'subtotal' => 1200.00,
        'discount_percentage' => 10.00,
        'discount_amount' => 120.00,
        'tax_rate' => 0.00,
        'tax_amount' => 0.00,
        'total' => 1080.00,
        'status' => 'sent',
        'valid_until' => now()->addDays(14),
    ]);

    $quote->items()->create([
        'description' => 'Heavy Duty Primer',
        'sku' => 'PRIMER-01',
        'package' => '4 L',
        'quantity' => 2.0,
        'unit_price' => 600.00,
        'subtotal' => 1200.00,
    ]);

    test()->actingAs($manager)
        ->get(route('quotations.print', $quote->id))
        ->assertOk()
        ->assertSee('Grade A Paint Center')
        ->assertSee('QUO-PRINT-001')
        ->assertSee('Acme Construction')
        ->assertSee('Heavy Duty Primer')
        ->assertSee('Prepared &amp; Issued By', false)
        ->assertSee('Conforme / Accepted By', false);
});

test('sales report page displays formal signature certification block instead of header label', function () {
    $manager = User::factory()->create(['role' => 'manager', 'name' => 'Report Auditor']);

    Volt::actingAs($manager)
        ->test('pages.reports.sales')
        ->assertDontSee('Report produced by')
        ->assertSee('Report Prepared &amp; Certified By', false)
        ->assertSee('Report Auditor');
});
