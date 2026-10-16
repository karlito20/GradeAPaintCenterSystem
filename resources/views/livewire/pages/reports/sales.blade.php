<?php

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Support\Currency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public string $datePreset = 'this_month';
    public string $dateFrom = '';
    public string $dateTo = '';
    public string $saleType = '';
    public string $userId = '';
    public string $activeTab = 'daily'; // Default is daily breakdown; 'transactions' supported for tests
    public int $perPage = 20;

    public function mount(): void
    {
        abort_unless(auth()->user()?->canViewSalesReport(), 403);
        $this->applyPreset('this_month');
    }

    public function applyPreset(string $preset): void
    {
        $this->datePreset = $preset;
        $today = Carbon::today();

        switch ($preset) {
            case 'today':
                $this->dateFrom = $today->toDateString();
                $this->dateTo = $today->toDateString();
                break;
            case 'yesterday':
                $this->dateFrom = $today->copy()->subDay()->toDateString();
                $this->dateTo = $today->copy()->subDay()->toDateString();
                break;
            case 'this_week':
                $this->dateFrom = $today->copy()->startOfWeek()->toDateString();
                $this->dateTo = $today->copy()->endOfWeek()->toDateString();
                break;
            case 'this_month':
                $this->dateFrom = $today->copy()->startOfMonth()->toDateString();
                $this->dateTo = $today->copy()->endOfMonth()->toDateString();
                break;
            case 'last_month':
                $this->dateFrom = $today->copy()->subMonth()->startOfMonth()->toDateString();
                $this->dateTo = $today->copy()->subMonth()->endOfMonth()->toDateString();
                break;
            case 'this_year':
                $this->dateFrom = $today->copy()->startOfYear()->toDateString();
                $this->dateTo = $today->copy()->endOfYear()->toDateString();
                break;
            case 'custom':
                // retain existing dateFrom and dateTo
                break;
            case 'all':
                $this->dateFrom = '';
                $this->dateTo = '';
                break;
        }

        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->datePreset = 'custom';
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->datePreset = 'custom';
        $this->resetPage();
    }

    public function updatedSaleType(): void
    {
        $this->resetPage();
    }

    public function updatedUserId(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->applyPreset('this_month');
        $this->saleType = '';
        $this->userId = '';
        $this->activeTab = 'daily';
        $this->resetPage();
    }

    public function render(): mixed
    {
        $baseQuery = Sale::query()->when($this->dateFrom, fn($q) => $q->whereDate('sold_at', '>=', $this->dateFrom))->when($this->dateTo, fn($q) => $q->whereDate('sold_at', '<=', $this->dateTo))->when($this->saleType, fn($q) => $q->where('type', $this->saleType))->when($this->userId, fn($q) => $q->where('user_id', $this->userId));

        // Aggregated KPI Totals
        $kpis = (clone $baseQuery)
            ->selectRaw(
                '
            COUNT(*) as total_transactions,
            COALESCE(SUM(subtotal), 0) as gross_subtotal,
            COALESCE(SUM(discount_amount), 0) as total_discounts,
            COALESCE(SUM(tax_amount), 0) as total_tax,
            COALESCE(SUM(total), 0) as total_revenue
        ',
            )
            ->first();

        $totalTransactions = (int) ($kpis->total_transactions ?? 0);
        $grossSubtotal = (float) ($kpis->gross_subtotal ?? 0);
        $totalDiscounts = (float) ($kpis->total_discounts ?? 0);
        $totalTax = (float) ($kpis->total_tax ?? 0);
        $totalRevenue = (float) ($kpis->total_revenue ?? 0);
        $netBeforeTax = max(0, $grossSubtotal - $totalDiscounts);
        $avgOrderValue = $totalTransactions > 0 ? round($totalRevenue / $totalTransactions, 2) : 0.0;

        // Daily breakdown
        $dailySummary = (clone $baseQuery)
            ->selectRaw(
                '
                DATE(sold_at) as sale_date,
                COUNT(*) as count,
                SUM(subtotal) as gross,
                SUM(discount_amount) as discounts,
                SUM(tax_amount) as tax,
                SUM(total) as revenue
            ',
            )
            ->groupBy(DB::raw('DATE(sold_at)'))
            ->orderByDesc('sale_date')
            ->get();

        // Transaction list for tab
        $transactions = (clone $baseQuery)
            ->with(['user', 'items', 'quotation'])
            ->latest('sold_at')
            ->paginate($this->perPage);

        // Top selling products in this filtered period
        $saleIdsQuery = (clone $baseQuery)->select('id');
        $topProducts = SaleItem::query()->whereIn('sale_id', $saleIdsQuery)->select('description', 'product_id', DB::raw('SUM(quantity) as total_quantity'), DB::raw('SUM(subtotal) as total_sales'))->groupBy('description', 'product_id')->orderByDesc('total_sales')->limit(15)->get();

        $cashiers = User::where('active', true)->orderBy('name')->get();

        return view('livewire.pages.reports.sales', [
            'totalTransactions' => $totalTransactions,
            'grossSubtotal' => $grossSubtotal,
            'totalDiscounts' => $totalDiscounts,
            'netBeforeTax' => $netBeforeTax,
            'totalTax' => $totalTax,
            'totalRevenue' => $totalRevenue,
            'avgOrderValue' => $avgOrderValue,
            'dailySummary' => $dailySummary,
            'transactions' => $transactions,
            'topProducts' => $topProducts,
            'cashiers' => $cashiers,
            'currency' => Currency::class,
        ]);
    }
}; ?>

<div x-data="{
    contextMenu: {
        open: false,
        x: 0,
        y: 0,
        item: null,
        openAt(x, y, item) {
            this.item = item;
            this.x = x;
            this.y = y;
            this.open = true;
            this.$nextTick(() => {
                const el = this.$refs.floatingMenu;
                if (!el) return;
                const r = el.getBoundingClientRect();
                if (this.x + r.width > window.innerWidth - 8) {
                    this.x = Math.max(8, window.innerWidth - r.width - 8);
                }
                if (this.y + r.height > window.innerHeight - 8) {
                    this.y = Math.max(8, window.innerHeight - r.height - 8);
                }
            });
        },
        openFromButton(event, item) {
            const btn = event.currentTarget.getBoundingClientRect();
            this.openAt(btn.right - 176, btn.bottom + 4, item);
        },
        openFromEvent(event, item) {
            this.openAt(event.clientX, event.clientY, item);
        },
        close() {
            this.open = false;
            this.item = null;
        }
    }
}" @click.window="contextMenu.close()" @keydown.escape.window="contextMenu.close()"
    @scroll.window="contextMenu.close()" @resize.window="contextMenu.close()" class="space-y-4 w-full min-w-0">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-3 border-b border-slate-300">
        <div>
            <h1 class="font-heading text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Sales Report</h1>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('reports.sales.pdf', ['date_from' => $dateFrom, 'date_to' => $dateTo, 'sale_type' => $saleType, 'user_id' => $userId]) }}"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded bg-[#00a3cc] text-white text-xs font-semibold hover:bg-[#008fb3] shadow-xs transition">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <span>Export PDF</span>
            </a>
        </div>
    </div>

    <!-- Date Presets Ribbon -->
    <div class="flex flex-wrap items-center gap-1.5 rounded-lg border border-slate-300 bg-white p-2 text-xs shadow-xs">
        <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 px-2">Presets:</span>
        @php
            $presets = [
                'today' => 'Today',
                'yesterday' => 'Yesterday',
                'this_week' => 'This Week',
                'this_month' => 'This Month',
                'last_month' => 'Last Month',
                'this_year' => 'This Year',
                'all' => 'All Time',
            ];
        @endphp
        @foreach ($presets as $key => $label)
            <button wire:click="applyPreset('{{ $key }}')" type="button"
                class="rounded px-2.5 py-1 text-xs font-medium transition {{ $datePreset === $key ? 'bg-[#00a3cc] text-white font-semibold shadow-xs' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <!-- KPI Summary Row -->
    <div class="grid gap-3 grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
        <!-- Total Revenue -->
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs">
            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Total Net Revenue</span>
            <p class="text-xl sm:text-2xl font-light tabular-nums text-slate-900 mt-1 text-right">
                {{ $currency::format($totalRevenue) }}</p>
            <p class="text-[10px] text-slate-400 mt-0.5 text-right">Gross completed sales</p>
        </div>

        <!-- Gross Subtotal -->
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs">
            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Gross Sales</span>
            <p class="text-xl sm:text-2xl font-light tabular-nums text-slate-800 mt-1 text-right">
                {{ $currency::format($grossSubtotal) }}</p>
            <p class="text-[10px] text-slate-400 mt-0.5 text-right">Before discounts</p>
        </div>

        <!-- Discounts Given (Not red, no negative sign) -->
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs">
            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Discounts Given</span>
            <p class="text-xl sm:text-2xl font-light tabular-nums text-slate-900 mt-1 text-right">
                {{ $currency::format($totalDiscounts) }}</p>
            <p class="text-[10px] text-slate-400 mt-0.5 text-right">Customer discounts</p>
        </div>

        <!-- Completed Invoices -->
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs">
            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Completed Orders</span>
            <p class="text-xl sm:text-2xl font-light tabular-nums text-slate-900 mt-1 text-right">
                {{ number_format($totalTransactions) }}</p>
            <p class="text-[10px] text-slate-400 mt-0.5">Total transactions</p>
        </div>

        <!-- Average Order Value -->
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs">
            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Average Order</span>
            <p class="text-xl sm:text-2xl font-light tabular-nums text-slate-900 mt-1 text-right">
                {{ $currency::format($avgOrderValue) }}</p>
            <p class="text-[10px] text-slate-400 mt-0.5 text-right">Average ticket size</p>
        </div>
    </div>

    <!-- Main Sales Report Section with Standardized Space-y-2 Spacing -->
    <div class="space-y-2">
        <!-- Filter Controls Outside the Table Div (Aligned left, h-9 controls) -->
        <div class="flex flex-wrap items-center justify-between gap-2.5">
            <div class="flex flex-wrap items-center gap-2.5">
                <!-- Date From -->
                <div class="flex items-center gap-1.5 text-xs text-slate-600">
                    <span class="font-semibold text-[11px] uppercase">From:</span>
                    <input wire:model.live="dateFrom" type="date" title="From Date"
                        class="h-9 rounded border border-slate-300 px-2.5 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                </div>

                <!-- Date To -->
                <div class="flex items-center gap-1.5 text-xs text-slate-600">
                    <span class="font-semibold text-[11px] uppercase">To:</span>
                    <input wire:model.live="dateTo" type="date" title="To Date"
                        class="h-9 rounded border border-slate-300 px-2.5 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                </div>

                <!-- Sale Type -->
                <div class="w-40">
                    <select wire:model.live="saleType"
                        class="w-full h-9 rounded border border-slate-300 pl-2.5 pr-8 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        <option value="">All Sale Types</option>
                        <option value="normal">Standard Packaged</option>
                        <option value="mixed">Custom Mix</option>
                    </select>
                </div>

                <!-- Cashier Filter -->
                <div class="w-40">
                    <select wire:model.live="userId"
                        class="w-full h-9 rounded border border-slate-300 pl-2.5 pr-8 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        <option value="">All Cashiers</option>
                        @foreach ($cashiers as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($dateFrom !== '' || $dateTo !== '' || $saleType !== '' || $userId !== '' || $datePreset !== 'this_month')
                    <button wire:click="resetFilters" type="button"
                        class="h-9 px-2 text-xs text-[#00a3cc] hover:text-[#008fb3] underline font-medium inline-flex items-center">
                        Reset
                    </button>
                @endif
            </div>

        </div>

        @if ($activeTab === 'transactions')
            <!-- Showing X of N Items Indicator & Per Page Selector -->
            <div class="flex items-center justify-between text-xs text-slate-500">
                <div>
                    @if ($transactions->total() > 0)
                        Showing <span class="font-medium text-slate-700">{{ $transactions->firstItem() }}</span> to
                        <span class="font-medium text-slate-700">{{ $transactions->lastItem() }}</span> of <span
                            class="font-medium text-slate-700">{{ $transactions->total() }}</span> items
                    @else
                        Showing <span class="font-medium text-slate-700">0</span> items
                    @endif
                </div>
                <div class="flex items-center gap-1.5">
                    <label for="salesReportPerPage" class="text-xs text-slate-500">Show:</label>
                    <select wire:model.live="perPage" id="salesReportPerPage"
                        class="h-8 w-16 rounded border border-slate-300 bg-white pl-2.5 pr-7 py-1 text-xs text-slate-700 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        <option value="10">10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                    <span class="text-xs text-slate-500">items</span>
                </div>
            </div>

            <!-- Table Container (div contains ONLY table and bottom pagination) -->
            <div class="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs">
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse border border-slate-300 text-xs">
                        <thead
                            class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-300">
                            <tr>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-left whitespace-nowrap">Invoice #
                                </th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-left whitespace-nowrap">Date /
                                    Time</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-left whitespace-nowrap">Cashier
                                </th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-left">Customer</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-center whitespace-nowrap">Type
                                </th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap">Subtotal
                                </th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap">Discount
                                </th>
                                <th
                                    class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap font-bold">
                                    Total Paid</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-center whitespace-nowrap w-16">
                                    Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            @forelse ($transactions as $sale)
                                <tr class="hover:bg-slate-50 transition-colors cursor-default"
                                    wire:key="report-sale-{{ $sale->id }}"
                                    @contextmenu.prevent="contextMenu.openFromEvent($event, { id: {{ $sale->id }}, invoice: '{{ $sale->invoice_number }}', receiptUrl: '{{ route('sales.receipt', ['sale' => $sale]) }}' })">
                                    <td
                                        class="border border-slate-200 px-2.5 py-1.5 font-mono font-medium text-slate-900 whitespace-nowrap">
                                        <a href="{{ route('sales.receipt', ['sale' => $sale]) }}" target="_blank"
                                            class="text-slate-900 hover:underline font-bold">
                                            {{ $sale->invoice_number }}
                                        </a>
                                    </td>
                                    <td
                                        class="border border-slate-200 px-2.5 py-1.5 text-slate-600 tabular-nums whitespace-nowrap">
                                        {{ $sale->sold_at->format('M d, Y h:i A') }}
                                    </td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-slate-800 whitespace-nowrap">
                                        {{ $sale->user?->name ?? 'Staff' }}
                                    </td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700">
                                        {{ $sale->customer_name ?: 'Walk-in' }}
                                    </td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                        @if ($sale->type === 'mixed')
                                            <span class="text-slate-700 font-medium text-xs">Custom Mix</span>
                                        @else
                                            <span class="text-slate-600 text-xs">Standard</span>
                                        @endif
                                    </td>
                                    <td
                                        class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-700 whitespace-nowrap">
                                        {{ $currency::format($sale->subtotal) }}
                                    </td>
                                    <td
                                        class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-700 whitespace-nowrap">
                                        {{ (float) $sale->discount_amount > 0 ? $currency::format($sale->discount_amount) : '—' }}
                                    </td>
                                    <td
                                        class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold text-slate-900 whitespace-nowrap">
                                        {{ $currency::format($sale->total) }}
                                    </td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                        <button
                                            @click.stop="contextMenu.openFromButton($event, { id: {{ $sale->id }}, invoice: '{{ $sale->invoice_number }}', receiptUrl: '{{ route('sales.receipt', ['sale' => $sale]) }}' })"
                                            type="button"
                                            class="inline-flex items-center justify-center h-6 w-6 rounded hover:bg-slate-100 text-slate-500 hover:text-slate-800 transition"
                                            title="Actions">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <circle cx="12" cy="5" r="1.5" fill="currentColor"
                                                    stroke="none" />
                                                <circle cx="12" cy="12" r="1.5" fill="currentColor"
                                                    stroke="none" />
                                                <circle cx="12" cy="19" r="1.5" fill="currentColor"
                                                    stroke="none" />
                                            </svg>
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9"
                                        class="border border-slate-200 px-4 py-8 text-center text-slate-500">
                                        No transactions found matching current filters.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($transactions->hasPages())
                    <div
                        class="px-3 py-2 border-t border-slate-200 bg-white flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs">
                        <div>
                            <span class="text-slate-500">
                                Page <span
                                    class="font-medium text-slate-800">{{ $transactions->currentPage() }}</span> of
                                <span class="font-medium text-slate-800">{{ $transactions->lastPage() }}</span>
                            </span>
                        </div>
                        <div class="inline-flex items-center gap-1">
                            <button wire:click="previousPage" type="button" @disabled($transactions->onFirstPage())
                                class="px-2.5 py-1 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 disabled:opacity-30 disabled:pointer-events-none transition">
                                Prev
                            </button>

                            @php
                                $currentPage = $transactions->currentPage();
                                $lastPage = $transactions->lastPage();
                                $startPage = max(1, $currentPage - 2);
                                $endPage = min($lastPage, $currentPage + 2);
                            @endphp

                            @if ($startPage > 1)
                                <button wire:click="gotoPage(1)" type="button"
                                    class="min-w-[28px] h-7 px-2 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 transition">
                                    1
                                </button>
                                @if ($startPage > 2)
                                    <span class="px-1 text-slate-400">...</span>
                                @endif
                            @endif

                            @for ($page = $startPage; $page <= $endPage; $page++)
                                @if ($page === $currentPage)
                                    <span
                                        class="min-w-[28px] h-7 px-2 text-xs font-semibold rounded bg-slate-900 text-white flex items-center justify-center">
                                        {{ $page }}
                                    </span>
                                @else
                                    <button wire:click="gotoPage({{ $page }})" type="button"
                                        class="min-w-[28px] h-7 px-2 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 transition">
                                        {{ $page }}
                                    </button>
                                @endif
                            @endfor

                            @if ($endPage < $lastPage)
                                @if ($endPage < $lastPage - 1)
                                    <span class="px-1 text-slate-400">...</span>
                                @endif
                                <button wire:click="gotoPage({{ $lastPage }})" type="button"
                                    class="min-w-[28px] h-7 px-2 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 transition">
                                    {{ $lastPage }}
                                </button>
                            @endif

                            <button wire:click="nextPage" type="button" @disabled(!$transactions->hasMorePages())
                                class="px-2.5 py-1 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 disabled:opacity-30 disabled:pointer-events-none transition">
                                Next
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        @else
            <!-- Showing X of N Days Indicator -->
            <div class="flex items-center justify-between text-xs text-slate-500">
                <div>
                    @if ($dailySummary->isNotEmpty())
                        Showing <span class="font-semibold text-slate-700">1</span> to
                        <span class="font-semibold text-slate-700">{{ $dailySummary->count() }}</span> of <span
                            class="font-semibold text-slate-700">{{ $dailySummary->count() }}</span> days
                    @else
                        Showing <span class="font-semibold text-slate-700">0</span> days
                    @endif
                </div>
            </div>

            <!-- Daily Breakdown Table -->
            <div class="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs">
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse border border-slate-300 text-xs">
                        <thead
                            class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-300">
                            <tr>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-left whitespace-nowrap">Date</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-center whitespace-nowrap">
                                    Invoices</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap">Gross
                                    Sales</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap">
                                    Discounts</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap">12% VAT
                                    Tax</th>
                                <th
                                    class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap font-bold">
                                    Total Collected</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap">Avg
                                    Ticket</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white">
                            @forelse ($dailySummary as $day)
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td
                                        class="border border-slate-200 px-2.5 py-1.5 font-medium text-slate-900 whitespace-nowrap">
                                        {{ \Carbon\Carbon::parse($day->sale_date)->format('M d, Y (D)') }}
                                    </td>
                                    <td
                                        class="border border-slate-200 px-2.5 py-1.5 text-center tabular-nums text-slate-700">
                                        {{ number_format((int) $day->count) }}
                                    </td>
                                    <td
                                        class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-700 whitespace-nowrap">
                                        {{ $currency::format($day->gross) }}
                                    </td>
                                    <td
                                        class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-700 whitespace-nowrap">
                                        {{ (float) $day->discounts > 0 ? $currency::format($day->discounts) : '—' }}
                                    </td>
                                    <td
                                        class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-700 whitespace-nowrap">
                                        {{ $currency::format($day->tax) }}
                                    </td>
                                    <td
                                        class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold text-slate-900 whitespace-nowrap">
                                        {{ $currency::format($day->revenue) }}
                                    </td>
                                    <td
                                        class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-600 whitespace-nowrap">
                                        {{ $currency::format((float) $day->revenue / max(1, (int) $day->count)) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7"
                                        class="border border-slate-200 px-4 py-8 text-center text-slate-500">
                                        No sales transactions recorded in the selected period.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if ($dailySummary->isNotEmpty())
                            <tfoot class="bg-slate-100 font-bold text-xs border-t border-slate-300">
                                <tr>
                                    <td
                                        class="border border-slate-300 px-2.5 py-1.5 uppercase tracking-wider text-slate-900">
                                        Total Period Summary</td>
                                    <td
                                        class="border border-slate-300 px-2.5 py-1.5 text-center tabular-nums text-slate-900">
                                        {{ number_format($totalTransactions) }}</td>
                                    <td
                                        class="border border-slate-300 px-2.5 py-1.5 text-right tabular-nums text-slate-900">
                                        {{ $currency::format($grossSubtotal) }}</td>
                                    <td
                                        class="border border-slate-300 px-2.5 py-1.5 text-right tabular-nums text-slate-900">
                                        {{ (float) $totalDiscounts > 0 ? $currency::format($totalDiscounts) : '—' }}
                                    </td>
                                    <td
                                        class="border border-slate-300 px-2.5 py-1.5 text-right tabular-nums text-slate-900">
                                        {{ $currency::format($totalTax) }}</td>
                                    <td
                                        class="border border-slate-300 px-2.5 py-1.5 text-right tabular-nums text-slate-900 text-sm">
                                        {{ $currency::format($totalRevenue) }}</td>
                                    <td
                                        class="border border-slate-300 px-2.5 py-1.5 text-right tabular-nums text-slate-900">
                                        {{ $currency::format($avgOrderValue) }}</td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        @endif
    </div>

    <!-- Floating Context Menu -->
    <div x-ref="floatingMenu" x-show="contextMenu.open" x-cloak x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        :style="`position: fixed; left: ${contextMenu.x}px; top: ${contextMenu.y}px; z-index: 9999;`"
        class="w-44 rounded-lg border border-slate-200 bg-white py-1 shadow-xl ring-1 ring-black/5"
        style="display: none;">
        <a :href="contextMenu.item ? contextMenu.item.receiptUrl : '#'" target="_blank"
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors">
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            <span>View Receipt</span>
        </a>
        <div class="my-1 border-t border-slate-100"></div>
        <button
            @click="if (contextMenu.item) { navigator.clipboard.writeText(contextMenu.item.invoice); $dispatch('toast', { type: 'success', message: 'Invoice #' + contextMenu.item.invoice + ' copied to clipboard.' }); contextMenu.close(); }"
            type="button"
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors">
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3" />
            </svg>
            <span>Copy Invoice #</span>
        </button>
    </div>
</div>
