<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\PackageUnit;
use App\Models\User;
use Livewire\Volt\Volt;

test('admin can create, update, and toggle brand with audit logs', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    Volt::actingAs($admin)
        ->test('pages.references.brands')
        ->set('name', 'Nippon Paint')
        ->call('save')
        ->assertHasNoErrors();

    $brand = Brand::where('name', 'Nippon Paint')->first();
    expect($brand)->not->toBeNull()
        ->and($brand->active)->toBeTrue();

    // Update
    Volt::actingAs($admin)
        ->test('pages.references.brands')
        ->set('editingId', $brand->id)
        ->set('name', 'Nippon Paint Coatings')
        ->call('save')
        ->assertHasNoErrors();

    expect($brand->fresh()->name)->toBe('Nippon Paint Coatings');

    // Toggle active
    Volt::actingAs($admin)
        ->test('pages.references.brands')
        ->set('confirmingToggleId', $brand->id)
        ->call('executeToggle')
        ->assertHasNoErrors();

    expect($brand->fresh()->active)->toBeFalse();

    // Verify audit logs
    expect(AuditLog::where('auditable_type', Brand::class)->where('auditable_id', $brand->id)->count())->toBe(3);
});

test('admin can create, update, and toggle category with audit logs', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    Volt::actingAs($admin)
        ->test('pages.references.categories')
        ->set('name', 'Epoxy Floor Primer')
        ->call('save')
        ->assertHasNoErrors();

    $cat = Category::where('name', 'Epoxy Floor Primer')->first();
    expect($cat)->not->toBeNull()
        ->and($cat->active)->toBeTrue();

    // Toggle active
    Volt::actingAs($admin)
        ->test('pages.references.categories')
        ->set('confirmingToggleId', $cat->id)
        ->call('executeToggle')
        ->assertHasNoErrors();

    expect($cat->fresh()->active)->toBeFalse();
});

test('admin can create, update, and toggle package unit with audit logs', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    Volt::actingAs($admin)
        ->test('pages.references.package-units')
        ->set('name', '16-Liter Tin')
        ->set('abbreviation', 'tin')
        ->call('save')
        ->assertHasNoErrors();

    $unit = PackageUnit::where('name', '16-Liter Tin')->first();
    expect($unit)->not->toBeNull()
        ->and($unit->abbreviation)->toBe('tin')
        ->and($unit->active)->toBeTrue();

    // Toggle active
    Volt::actingAs($admin)
        ->test('pages.references.package-units')
        ->set('confirmingToggleId', $unit->id)
        ->call('executeToggle')
        ->assertHasNoErrors();

    expect($unit->fresh()->active)->toBeFalse();
});
