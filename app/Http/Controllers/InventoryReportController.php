<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
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
        $from = $request->query('from');
        $to = $request->query('to');

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
            ->orderBy('sku');

        $products = $query->get();

        // Stock status filtering
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

        // Date range normalization
        $fromStart = $from ? Carbon::parse($from)->startOfDay() : Carbon::createFromTimestamp(0);
        $toEnd = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();

        // Calculate movements summary in single query
        $movementsData = InventoryMovement::query()
            ->selectRaw('
                product_id,
                SUM(CASE WHEN created_at > ? THEN quantity_change ELSE 0 END) as after_to,
                SUM(CASE WHEN created_at >= ? AND created_at <= ? AND quantity_change > 0 THEN quantity_change ELSE 0 END) as period_in,
                SUM(CASE WHEN created_at >= ? AND created_at <= ? AND quantity_change < 0 THEN ABS(quantity_change) ELSE 0 END) as period_out
            ', [$toEnd, $fromStart, $toEnd, $fromStart, $toEnd])
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        // Attach balance figures to each product
        $reportItems = $products->map(function (Product $p) use ($movementsData) {
            $mv = $movementsData->get($p->id);
            $current = (float) ($p->inventory?->quantity ?? 0);
            $afterTo = $mv ? (float) $mv->after_to : 0.0;
            $stockIn = $mv ? (float) $mv->period_in : 0.0;
            $stockOut = $mv ? (float) $mv->period_out : 0.0;

            $remainingBalance = max(0.0, $current - $afterTo);
            $startingBalance = max(0.0, $remainingBalance - $stockIn + $stockOut);

            return (object) [
                'product' => $p,
                'sku' => $p->sku,
                'name' => $p->name,
                'brand_name' => $p->brand?->name ?? '—',
                'category_name' => $p->category?->name ?? '—',
                'unit' => $p->packageUnit?->abbreviation ?? 'pcs',
                'selling_price' => (float) $p->selling_price,
                'starting_balance' => $startingBalance,
                'stock_in' => $stockIn,
                'stock_out' => $stockOut,
                'remaining_balance' => $remainingBalance,
                'threshold' => (float) $p->low_stock_threshold,
                'valuation' => $remainingBalance * (float) $p->selling_price,
            ];
        });

        $totalSkus = $reportItems->count();
        $lowStockCount = $reportItems->filter(fn ($item): bool => $item->remaining_balance > 0 && $item->remaining_balance <= $item->threshold)->count();
        $outOfStockCount = $reportItems->filter(fn ($item): bool => $item->remaining_balance <= 0)->count();
        $totalStockValue = $reportItems->sum(fn ($item): float => $item->valuation);

        $filterParts = [];
        if (! empty($brandId)) {
            $b = Brand::find($brandId);
            if ($b) {
                $filterParts[] = 'Brand: '.$b->name;
            }
        }
        if (! empty($categoryId)) {
            $c = Category::find($categoryId);
            if ($c) {
                $filterParts[] = 'Category: '.$c->name;
            }
        }
        $filterLabel = ! empty($filterParts) ? implode(' | ', $filterParts) : null;

        $dateLabel = null;
        if ($from && $to) {
            $dateLabel = Carbon::parse($from)->format('M d, Y').' to '.Carbon::parse($to)->format('M d, Y');
            $filename = 'grade-a-paint-inventory-report-'.$from.'-to-'.$to.'.pdf';
        } elseif ($from) {
            $dateLabel = 'From '.Carbon::parse($from)->format('M d, Y');
            $filename = 'grade-a-paint-inventory-report-from-'.$from.'.pdf';
        } elseif ($to) {
            $dateLabel = 'Up to '.Carbon::parse($to)->format('M d, Y');
            $filename = 'grade-a-paint-inventory-report-up-to-'.$to.'.pdf';
        } else {
            $dateLabel = 'All Time / Current Balance';
            $filename = 'grade-a-paint-inventory-report-'.now()->format('Y-m-d').'.pdf';
        }

        $user = auth()->user();
        $generatedBy = $user?->name ? ($user->name.' ('.ucfirst($user->role).')') : 'Store Staff';

        return Pdf::loadView('reports.inventory-pdf', [
            'reportItems' => $reportItems,
            'generatedAt' => now(),
            'generatedBy' => $generatedBy,
            'totalSkus' => $totalSkus,
            'lowStockCount' => $lowStockCount,
            'outOfStockCount' => $outOfStockCount,
            'totalStockValue' => $totalStockValue,
            'filterLabel' => $filterLabel,
            'dateLabel' => $dateLabel,
            'stockStatus' => $stockStatus,
        ])->setPaper('a4', 'landscape')->download($filename);
    }
}
