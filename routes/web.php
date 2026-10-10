<?php

use App\Http\Controllers\InventoryReportController;
use App\Http\Controllers\QuotationPrintController;
use App\Http\Controllers\SaleReceiptController;
use App\Http\Controllers\SalesReportPdfController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::redirect('/', '/dashboard');

Volt::route('/dashboard', 'pages.dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// Sales Section
Volt::route('/sales', 'pages.sales.index')
    ->middleware(['auth', 'verified'])
    ->name('sales.index');

Volt::route('/products', 'pages.products.index')
    ->middleware(['auth', 'verified'])
    ->name('products.index');

Volt::route('/sales/history', 'pages.sales.history')
    ->middleware(['auth', 'verified', 'role:superadmin,admin,manager'])
    ->name('sales.history');

Volt::route('/sales/checkout', 'pages.sales.checkout')
    ->middleware(['auth', 'verified'])
    ->name('sales.checkout');

Volt::route('/sales/quotations', 'pages.sales.quotations')
    ->middleware(['auth', 'verified', 'role:superadmin,admin,manager,mixer'])
    ->name('sales.quotations');

Route::get('/quotations/{quotation}/print', QuotationPrintController::class)
    ->middleware(['auth', 'verified', 'role:superadmin,admin,manager,mixer'])
    ->name('quotations.print');

Volt::route('/reports/sales', 'pages.reports.sales')
    ->middleware(['auth', 'verified', 'role:superadmin,admin,manager'])
    ->name('reports.sales');

Route::get('/reports/sales/pdf', SalesReportPdfController::class)
    ->middleware(['auth', 'verified', 'role:superadmin,admin,manager'])
    ->name('reports.sales.pdf');

Route::get('/sales/{sale}/receipt', SaleReceiptController::class)
    ->middleware(['auth', 'verified'])
    ->name('sales.receipt');

Route::redirect('/mixing', '/sales')
    ->middleware(['auth', 'verified'])
    ->name('mixing.index');

// Inventory Section
Volt::route('/inventory/stock-in', 'pages.inventory.stock-in')
    ->middleware(['auth', 'verified'])
    ->name('inventory.stock-in');

Volt::route('/inventory', 'pages.reports.inventory')
    ->middleware(['auth', 'verified'])
    ->name('inventory.index');

Volt::route('/inventory/movements', 'pages.inventory.movements')
    ->middleware(['auth', 'verified'])
    ->name('inventory.movements');

Volt::route('/inventory/physical-count', 'pages.inventory.physical-count')
    ->middleware(['auth', 'verified'])
    ->name('inventory.physical-count');

Route::get('/inventory/pdf', InventoryReportController::class)
    ->middleware(['auth', 'verified'])
    ->name('inventory.pdf');

// Aliases for compatibility
Route::redirect('/reports/inventory', '/inventory')->name('reports.inventory');
Route::get('/reports/inventory/pdf', InventoryReportController::class)->middleware(['auth', 'verified'])->name('reports.inventory.pdf');
Route::redirect('/reports/mixing', '/sales')->name('reports.mixing');

// Administrative Section (superadmin, admin)
Volt::route('/audit', 'pages.audit.index')
    ->middleware(['auth', 'verified', 'role:superadmin,admin'])
    ->name('audit.index');

Volt::route('/user-access', 'pages.user-access.index')
    ->middleware(['auth', 'verified', 'role:superadmin,admin'])
    ->name('user-access.index');

Volt::route('/admin/backup', 'pages.admin.backup')
    ->middleware(['auth', 'verified', 'role:superadmin,admin'])
    ->name('backup.index');

// Developer Tools Section (superadmin only)
Volt::route('/dev/troubleshooting', 'pages.dev.troubleshooting')
    ->middleware(['auth', 'verified', 'role:superadmin'])
    ->name('dev.troubleshooting');

// Settings Section (superadmin, admin)
Volt::route('/settings/brands', 'pages.references.brands')
    ->middleware(['auth', 'verified', 'role:superadmin,admin'])
    ->name('references.brands');

Volt::route('/settings/categories', 'pages.references.categories')
    ->middleware(['auth', 'verified', 'role:superadmin,admin'])
    ->name('references.categories');

Volt::route('/settings/package-units', 'pages.references.package-units')
    ->middleware(['auth', 'verified', 'role:superadmin,admin'])
    ->name('references.package-units');

// Legacy route aliases for reference settings
Route::redirect('/references/brands', '/settings/brands');
Route::redirect('/references/categories', '/settings/categories');
Route::redirect('/references/package-units', '/settings/package-units');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

require __DIR__.'/auth.php';
