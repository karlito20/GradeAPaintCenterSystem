<?php

use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;

test('dashboard hides today\'s sales card for mixer role', function () {
    $mixer = User::factory()->create(['role' => 'mixer']);

    $this->actingAs($mixer)
        ->get('/dashboard')
        ->assertOk()
        ->assertDontSee("Today's Sales", false)
        ->assertSee('Low Stock')
        ->assertSee('Out of Stock')
        ->assertSee('Catalog SKUs');
});

test('dashboard displays today\'s sales card for admin and manager', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee("Today's Sales", false)
        ->assertSee('Low Stock')
        ->assertSee('Out of Stock')
        ->assertSee('Catalog SKUs');
});

test('dashboard renders 7-day sales overview line graph and places stock alerts table above it', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->get('/dashboard');

    $response->assertOk();
    $content = $response->getContent();

    // Verify stock alerts appears before 7-day sales overview
    $stockAlertsPos = strpos($content, 'Stock Alerts');
    $salesOverviewPos = strpos($content, '7-Day Sales Overview');

    expect($stockAlertsPos)->not->toBeFalse()
        ->and($salesOverviewPos)->not->toBeFalse()
        ->and($stockAlertsPos)->toBeLessThan($salesOverviewPos);

    // Verify SVG line graph is present
    expect($content)->toContain('<svg viewBox="0 0 700 160"')
        ->and($content)->toContain('id="salesLineGrad"');
});

test('discounts given and inventory outflow do not have negative signs', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $category = Category::create(['name' => 'Paint Test']);
    $product = Product::create([
        'category_id' => $category->id,
        'sku' => 'TEST-DISC-01',
        'name' => 'Test Paint',
        'selling_price' => 500.00,
        'active' => true,
    ]);

    InventoryMovement::create([
        'product_id' => $product->id,
        'user_id' => $admin->id,
        'type' => 'sale',
        'quantity_change' => -5.00,
        'quantity_before' => 20.00,
        'quantity_after' => 15.00,
    ]);

    Sale::create([
        'user_id' => $admin->id,
        'invoice_number' => 'DISC-TEST-01',
        'sold_at' => now(),
        'type' => 'normal',
        'subtotal' => 1000.00,
        'discount_percentage' => 10.00,
        'discount_amount' => 100.00,
        'tax_rate' => 0.00,
        'tax_amount' => 0.00,
        'total' => 900.00,
        'payment_method' => 'cash',
        'payment_amount' => 1000.00,
        'change_amount' => 100.00,
    ]);

    // Check movements page outflow does not have -5.00 with negative sign in KPI card
    $movementsResponse = $this->actingAs($admin)->get('/inventory/movements');
    $movementsResponse->assertOk();
    $movementsContent = $movementsResponse->getContent();
    expect($movementsContent)->toContain('Filtered Outflow (Sales / Deductions)')
        ->and($movementsContent)->not->toContain('text-rose-700 mt-2 text-right">-');

    // Check sales report discounts given KPI card does not have negative sign
    $salesReportResponse = $this->actingAs($admin)->get('/reports/sales');
    $salesReportResponse->assertOk();
    $salesContent = $salesReportResponse->getContent();
    expect($salesContent)->toContain('Discounts Given')
        ->and($salesContent)->not->toContain('text-rose-700 mt-1 text-right">-');
});
