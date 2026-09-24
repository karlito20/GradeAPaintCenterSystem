<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class InventoryReportController extends Controller
{
    public function __invoke(): Response
    {
        $products = Product::query()->with(['brand', 'category', 'packageUnit', 'inventory'])->where('active', true)->orderBy('name')->get();

        return Pdf::loadView('reports.inventory-pdf', [
            'products' => $products,
            'generatedAt' => now(),
            'lowStockCount' => $products->filter(fn (Product $product): bool => (float) ($product->inventory?->quantity ?? 0) <= (float) $product->low_stock_threshold)->count(),
        ])->download('inventory-report-'.now()->format('Y-m-d').'.pdf');
    }
}
