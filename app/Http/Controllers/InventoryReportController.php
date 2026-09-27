<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class InventoryReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $search = $request->query('search');
        $brandId = $request->query('brand_id');
        $categoryId = $request->query('category_id');
        $stockStatus = $request->query('stock_status', 'all');

        $query = Product::query()
            ->with(['brand', 'category', 'packageUnit', 'inventory'])
            ->where('active', true)
            ->when(! empty($search), function ($q) use ($search) {
                $term = '%'.trim($search).'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('sku', 'like', $term);
                });
            })
            ->when(! empty($brandId), fn ($q) => $q->where('brand_id', $brandId))
            ->when(! empty($categoryId), fn ($q) => $q->where('category_id', $categoryId))
            ->orderBy('name');

        $products = $query->get();

        // Filter by stock status if requested
        if ($stockStatus === 'low') {
            $products = $products->filter(function (Product $p): bool {
                $qty = (float) ($p->inventory?->quantity ?? 0);

                return $qty > 0 && $qty <= (float) $p->low_stock_threshold;
            });
        } elseif ($stockStatus === 'out') {
            $products = $products->filter(function (Product $p): bool {
                return (float) ($p->inventory?->quantity ?? 0) <= 0;
            });
        } elseif ($stockStatus === 'healthy') {
            $products = $products->filter(function (Product $p): bool {
                return (float) ($p->inventory?->quantity ?? 0) > (float) $p->low_stock_threshold;
            });
        }

        $allActive = Product::query()->with('inventory')->where('active', true)->get();
        $totalSkus = $products->count();
        $lowStockCount = $products->filter(fn (Product $p): bool => (float) ($p->inventory?->quantity ?? 0) > 0 && (float) ($p->inventory?->quantity ?? 0) <= (float) $p->low_stock_threshold)->count();
        $outOfStockCount = $products->filter(fn (Product $p): bool => (float) ($p->inventory?->quantity ?? 0) <= 0)->count();
        $totalStockValue = $products->sum(fn (Product $p): float => ((float) ($p->inventory?->quantity ?? 0)) * ((float) $p->selling_price));

        $filterLabel = null;
        if (! empty($brandId)) {
            $b = Brand::find($brandId);
            if ($b) {
                $filterLabel = 'Brand: '.$b->name;
            }
        }
        if (! empty($categoryId)) {
            $c = Category::find($categoryId);
            if ($c) {
                $filterLabel = ($filterLabel ? $filterLabel.' | ' : '').'Category: '.$c->name;
            }
        }

        return Pdf::loadView('reports.inventory-pdf', [
            'products' => $products,
            'generatedAt' => now(),
            'totalSkus' => $totalSkus,
            'lowStockCount' => $lowStockCount,
            'outOfStockCount' => $outOfStockCount,
            'totalStockValue' => $totalStockValue,
            'filterLabel' => $filterLabel,
            'stockStatus' => $stockStatus,
        ])->download('grade-a-paint-inventory-report-'.now()->format('Y-m-d').'.pdf');
    }
}
