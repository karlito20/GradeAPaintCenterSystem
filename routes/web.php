<?php

use App\Http\Controllers\InventoryReportController;
use App\Http\Controllers\SaleReceiptController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::redirect('/', '/dashboard');

Volt::route('/dashboard', 'pages.dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Volt::route('/products', 'pages.products.index')
    ->middleware(['auth', 'verified'])
    ->name('products.index');

Volt::route('/references/brands', 'pages.references.brands')->middleware(['auth', 'verified', 'role:dev,admin'])->name('references.brands');
Volt::route('/references/categories', 'pages.references.categories')->middleware(['auth', 'verified', 'role:dev,admin'])->name('references.categories');
Volt::route('/references/package-units', 'pages.references.package-units')->middleware(['auth', 'verified', 'role:dev,admin'])->name('references.package-units');

Volt::route('/inventory/stock-in', 'pages.inventory.stock-in')
    ->middleware(['auth', 'verified'])
    ->name('inventory.stock-in');

Volt::route('/inventory/physical-count', 'pages.inventory.physical-count')
    ->middleware(['auth', 'verified'])
    ->name('inventory.physical-count');

Volt::route('/inventory/movements', 'pages.inventory.movements')->middleware(['auth', 'verified'])->name('inventory.movements');

Volt::route('/sales', 'pages.sales.index')
    ->middleware(['auth', 'verified'])
    ->name('sales.index');

Volt::route('/sales/history', 'pages.sales.history')->middleware(['auth', 'verified'])->name('sales.history');

Volt::route('/sales/checkout', 'pages.sales.checkout')->middleware(['auth', 'verified'])->name('sales.checkout');
Route::get('/sales/{sale}/receipt', SaleReceiptController::class)->middleware(['auth', 'verified'])->name('sales.receipt');

Route::redirect('/mixing', '/sales')
    ->middleware(['auth', 'verified'])
    ->name('mixing.index');

Volt::route('/reports/inventory', 'pages.reports.inventory')
    ->middleware(['auth', 'verified'])
    ->name('reports.inventory');

Volt::route('/audit', 'pages.audit.index')->middleware(['auth', 'verified', 'role:dev,admin'])->name('audit.index');

Volt::route('/user-access', 'pages.user-access.index')->middleware(['auth', 'verified', 'role:dev,admin'])->name('user-access.index');

Route::get('/reports/inventory/pdf', InventoryReportController::class)
    ->middleware(['auth', 'verified'])
    ->name('reports.inventory.pdf');

Volt::route('/reports/sales', 'pages.reports.sales')
    ->middleware(['auth', 'verified'])
    ->name('reports.sales');

Volt::route('/reports/mixing', 'pages.reports.mixing')
    ->middleware(['auth', 'verified'])
    ->name('reports.mixing');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

require __DIR__.'/auth.php';
