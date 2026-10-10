<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SalesReportPdfController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $saleType = $request->query('sale_type');
        $userId = $request->query('user_id');

        $baseQuery = Sale::query()
            ->when(! empty($dateFrom), fn ($q) => $q->whereDate('sold_at', '>=', $dateFrom))
            ->when(! empty($dateTo), fn ($q) => $q->whereDate('sold_at', '<=', $dateTo))
            ->when(! empty($saleType), fn ($q) => $q->where('type', $saleType))
            ->when(! empty($userId), fn ($q) => $q->where('user_id', $userId));

        // Aggregated KPI Totals
        $kpis = (clone $baseQuery)->selectRaw('
            COUNT(*) as total_transactions,
            COALESCE(SUM(subtotal), 0) as gross_subtotal,
            COALESCE(SUM(discount_amount), 0) as total_discounts,
            COALESCE(SUM(tax_amount), 0) as total_tax,
            COALESCE(SUM(total), 0) as total_revenue
        ')->first();

        $totalTransactions = (int) ($kpis->total_transactions ?? 0);
        $grossSubtotal = (float) ($kpis->gross_subtotal ?? 0);
        $totalDiscounts = (float) ($kpis->total_discounts ?? 0);
        $totalTax = (float) ($kpis->total_tax ?? 0);
        $totalRevenue = (float) ($kpis->total_revenue ?? 0);
        $netBeforeTax = max(0, $grossSubtotal - $totalDiscounts);
        $avgOrderValue = $totalTransactions > 0 ? round($totalRevenue / $totalTransactions, 2) : 0.0;

        // Daily breakdown
        $dailySummary = (clone $baseQuery)
            ->selectRaw('
                DATE(sold_at) as sale_date,
                COUNT(*) as count,
                SUM(subtotal) as gross,
                SUM(discount_amount) as discounts,
                SUM(tax_amount) as tax,
                SUM(total) as revenue
            ')
            ->groupBy(DB::raw('DATE(sold_at)'))
            ->orderByDesc('sale_date')
            ->get();

        // Top 10 selling products
        $saleIdsQuery = (clone $baseQuery)->select('id');
        $topProducts = SaleItem::query()
            ->whereIn('sale_id', $saleIdsQuery)
            ->select('description', DB::raw('SUM(quantity) as total_quantity'), DB::raw('SUM(subtotal) as total_sales'))
            ->groupBy('description')
            ->orderByDesc('total_sales')
            ->limit(10)
            ->get();

        // Label for filter criteria
        $filterParts = [];
        if (! empty($dateFrom) && ! empty($dateTo)) {
            $filterParts[] = 'Period: '.$dateFrom.' to '.$dateTo;
        } elseif (! empty($dateFrom)) {
            $filterParts[] = 'From: '.$dateFrom;
        } elseif (! empty($dateTo)) {
            $filterParts[] = 'To: '.$dateTo;
        } else {
            $filterParts[] = 'Period: All Time';
        }

        if (! empty($saleType)) {
            $filterParts[] = 'Type: '.($saleType === 'mixed' ? 'Custom Mix' : 'Standard');
        }

        if (! empty($userId)) {
            $cashier = User::find($userId);
            if ($cashier) {
                $filterParts[] = 'Cashier: '.$cashier->name;
            }
        }

        $filterLabel = implode(' | ', $filterParts);

        // Include start and end date in the download filename
        $minDate = (clone $baseQuery)->min('sold_at');
        $maxDate = (clone $baseQuery)->max('sold_at');
        $startDate = ! empty($dateFrom) ? $dateFrom : ($minDate ? Carbon::parse($minDate)->toDateString() : now()->toDateString());
        $endDate = ! empty($dateTo) ? $dateTo : ($maxDate ? Carbon::parse($maxDate)->toDateString() : now()->toDateString());
        $filename = "grade-a-paint-sales-report-{$startDate}-to-{$endDate}.pdf";

        $user = auth()->user();
        $generatedBy = $user?->name ?? 'Store Cashier';
        $generatedRole = $user ? ucfirst($user->role) : 'Personnel';

        return Pdf::loadView('reports.sales-pdf', [
            'generatedAt' => now(),
            'generatedBy' => $generatedBy,
            'generatedRole' => $generatedRole,
            'filterLabel' => $filterLabel,
            'totalTransactions' => $totalTransactions,
            'grossSubtotal' => $grossSubtotal,
            'totalDiscounts' => $totalDiscounts,
            'netBeforeTax' => $netBeforeTax,
            'totalTax' => $totalTax,
            'totalRevenue' => $totalRevenue,
            'avgOrderValue' => $avgOrderValue,
            'dailySummary' => $dailySummary,
            'topProducts' => $topProducts,
        ])->download($filename);
    }
}
