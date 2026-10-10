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
    public string $activeTab = 'daily'; // 'daily', 'transactions', 'products'

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
        $baseQuery = Sale::query()
            ->when($this->dateFrom, fn($q) => $q->whereDate('sold_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn($q) => $q->whereDate('sold_at', '<=', $this->dateTo))
            ->when($this->saleType, fn($q) => $q->where('type', $this->saleType))
            ->when($this->userId, fn($q) => $q->where('user_id', $this->userId));

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

        // Transaction list for tab
        $transactions = (clone $baseQuery)
            ->with(['user', 'items', 'quotation'])
            ->latest('sold_at')
            ->paginate(20);

        // Top selling products in this filtered period
        $saleIdsQuery = (clone $baseQuery)->select('id');
        $topProducts = SaleItem::query()
            ->whereIn('sale_id', $saleIdsQuery)
            ->select('description', 'product_id', DB::raw('SUM(quantity) as total_quantity'), DB::raw('SUM(subtotal) as total_sales'))
            ->groupBy('description', 'product_id')
            ->orderByDesc('total_sales')
            ->limit(15)
            ->get();

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

<div 
    x-data="{
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
    }"
    @click.window="contextMenu.close()"
    @keydown.escape.window="contextMenu.close()"
    @scroll.window="contextMenu.close()"
    @resize.window="contextMenu.close()"
    class="space-y-4 w-full min-w-0"
>
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-2.5 border-b border-slate-300">
        <div>
            <h1 class="font-heading text-xl font-bold tracking-tight text-slate-900">Sales Report</h1>
            <p class="text-[11px] text-slate-500 mt-0.5">Revenue summaries, transactional breakdown, and product volume analysis</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('reports.sales.pdf', ['date_from' => $dateFrom, 'date_to' => $dateTo, 'sale_type' => $saleType, 'user_id' => $userId]) }}"
                class="inline-flex items-center gap-1.5 rounded bg-[#00a3cc] px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-[#008fb3] transition shadow-xs">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
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
                class="rounded px-2.5 py-1 text-xs font-medium transition {{ $datePreset === $key ? 'bg-[#00a3cc] text-white font-semibold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <!-- KPI Summary Row -->
    <div class="grid gap-3 grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
        <!-- Total Revenue -->
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs">
            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Total Net Revenue</span>
            <p class="text-xl sm:text-2xl font-light tabular-nums text-emerald-700 mt-1 text-right">{{ $currency::format($totalRevenue) }}</p>
            <p class="text-[10px] text-slate-400 mt-0.5 text-right">Gross completed sales</p>
        </div>

        <!-- Gross Subtotal -->
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs">
            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Gross Sales</span>
            <p class="text-xl sm:text-2xl font-light tabular-nums text-slate-800 mt-1 text-right">{{ $currency::format($grossSubtotal) }}</p>
            <p class="text-[10px] text-slate-400 mt-0.5 text-right">Before discounts</p>
        </div>

        <!-- Discounts Given (Not red, no negative sign) -->
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs">
            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Discounts Given</span>
            <p class="text-xl sm:text-2xl font-light tabular-nums text-slate-900 mt-1 text-right">{{ $currency::format($totalDiscounts) }}</p>
            <p class="text-[10px] text-slate-400 mt-0.5 text-right">Customer discounts</p>
        </div>

        <!-- Completed Invoices -->
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs">
            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Completed Orders</span>
            <p class="text-xl sm:text-2xl font-light tabular-nums text-slate-900 mt-1">{{ number_format($totalTransactions) }}</p>
            <p class="text-[10px] text-slate-400 mt-0.5">Total transactions</p>
        </div>

        <!-- Average Order Value -->
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs">
            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Average Order</span>
            <p class="text-xl sm:text-2xl font-light tabular-nums text-slate-900 mt-1 text-right">{{ $currency::format($avgOrderValue) }}</p>
            <p class="text-[10px] text-slate-400 mt-0.5 text-right">Average ticket size</p>
        </div>
    </div>

    <!-- Filter Bar Directly Above Table -->
    <div class="rounded-lg border border-slate-300 bg-white shadow-xs overflow-hidden">
        <div class="p-3 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2.5">
                <!-- Date From -->
                <div class="flex items-center gap-1.5 text-xs text-slate-600">
                    <span class="font-semibold text-[11px] uppercase">From:</span>
                    <input wire:model.live="dateFrom" type="date"
                        class="rounded border-slate-300 px-2 py-1 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
                </div>

                <!-- Date To -->
                <div class="flex items-center gap-1.5 text-xs text-slate-600">
                    <span class="font-semibold text-[11px] uppercase">To:</span>
                    <input wire:model.live="dateTo" type="date"
                        class="rounded border-slate-300 px-2 py-1 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
                </div>

                <!-- Sale Type -->
                <div class="w-36">
                    <select wire:model.live="saleType" class="w-full rounded border-slate-300 px-2 py-1 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]">
                        <option value="">All Sale Types</option>
                        <option value="normal">Standard Packaged</option>
                        <option value="mixed">Custom Mix</option>
                    </select>
                </div>

                <!-- Cashier Filter -->
                <div class="w-36">
                    <select wire:model.live="userId" class="w-full rounded border-slate-300 px-2 py-1 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]">
                        <option value="">All Cashiers</option>
                        @foreach ($cashiers as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>

                <button wire:click="resetFilters" type="button"
                    class="rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100 transition">
                    Reset
                </button>
            </div>

            <!-- View Tab Switcher -->
            <div class="flex items-center gap-1 border border-slate-200 rounded p-0.5 bg-slate-100 text-xs">
                <button wire:click="$set('activeTab', 'daily')" type="button"
                    class="rounded px-2.5 py-1 font-medium transition {{ $activeTab === 'daily' ? 'bg-white text-slate-900 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900' }}">
                    Daily Breakdown
                </button>
                <button wire:click="$set('activeTab', 'transactions')" type="button"
                    class="rounded px-2.5 py-1 font-medium transition {{ $activeTab === 'transactions' ? 'bg-white text-slate-900 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900' }}">
                    Transactions List
                </button>
                <button wire:click="$set('activeTab', 'products')" type="button"
                    class="rounded px-2.5 py-1 font-medium transition {{ $activeTab === 'products' ? 'bg-white text-slate-900 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900' }}">
                    Top Products
                </button>
            </div>
        </div>

        <!-- Tab 1: Daily Summary Breakdown -->
        @if ($activeTab === 'daily')
            <div class="overflow-x-auto">
                <table class="w-full border-collapse border border-slate-300 text-xs">
                    <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-300">
                        <tr>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-left whitespace-nowrap">Date</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-center whitespace-nowrap">Invoices</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap">Gross Sales</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap">Discounts</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap font-bold">Total Collected</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap">Avg Ticket</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @forelse ($dailySummary as $day)
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="border border-slate-200 px-2.5 py-1.5 font-medium text-slate-900 whitespace-nowrap">
                                    {{ \Carbon\Carbon::parse($day->sale_date)->format('M d, Y (D)') }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-center tabular-nums text-slate-700">
                                    {{ number_format((int) $day->count) }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-700 whitespace-nowrap">
                                    {{ $currency::format($day->gross) }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-700 whitespace-nowrap">
                                    {{ (float) $day->discounts > 0 ? $currency::format($day->discounts) : '—' }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold text-emerald-800 whitespace-nowrap">
                                    {{ $currency::format($day->revenue) }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-600 whitespace-nowrap">
                                    {{ $currency::format((float) $day->revenue / max(1, (int) $day->count)) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="border border-slate-200 px-4 py-8 text-center text-slate-500">
                                    No sales transactions recorded in the selected period.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($dailySummary->isNotEmpty())
                        <tfoot class="bg-slate-100 font-bold text-xs border-t border-slate-300">
                            <tr>
                                <td class="border border-slate-300 px-2.5 py-1.5 uppercase tracking-wider text-slate-900">Total Period Summary</td>
                                <td class="border border-slate-300 px-2.5 py-1.5 text-center tabular-nums text-slate-900">{{ number_format($totalTransactions) }}</td>
                                <td class="border border-slate-300 px-2.5 py-1.5 text-right tabular-nums text-slate-900">{{ $currency::format($grossSubtotal) }}</td>
                                <td class="border border-slate-300 px-2.5 py-1.5 text-right tabular-nums text-slate-900">{{ $currency::format($totalDiscounts) }}</td>
                                <td class="border border-slate-300 px-2.5 py-1.5 text-right tabular-nums text-emerald-900 text-sm">{{ $currency::format($totalRevenue) }}</td>
                                <td class="border border-slate-300 px-2.5 py-1.5 text-right tabular-nums text-slate-900">{{ $currency::format($avgOrderValue) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        @endif

        <!-- Tab 2: Individual Transactions List -->
        @if ($activeTab === 'transactions')
            <div class="overflow-x-auto">
                <table class="w-full border-collapse border border-slate-300 text-xs">
                    <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-300">
                        <tr>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-left whitespace-nowrap">Invoice #</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-left whitespace-nowrap">Date / Time</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-left whitespace-nowrap">Cashier</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-left">Customer</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-center whitespace-nowrap">Type</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap">Subtotal</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap">Discount</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap font-bold">Total Paid</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-center whitespace-nowrap w-16">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @forelse ($transactions as $sale)
                            <tr 
                                class="hover:bg-slate-50 transition-colors cursor-default" 
                                wire:key="report-sale-{{ $sale->id }}"
                                @contextmenu.prevent="contextMenu.openFromEvent($event, { id: {{ $sale->id }}, invoice: '{{ $sale->invoice_number }}', receiptUrl: '{{ route('sales.receipt', ['sale' => $sale]) }}' })"
                            >
                                <td class="border border-slate-200 px-2.5 py-1.5 font-mono font-medium text-slate-900 whitespace-nowrap">
                                    <a href="{{ route('sales.receipt', ['sale' => $sale]) }}" target="_blank" class="text-[#008fb3] hover:underline font-bold">
                                        {{ $sale->invoice_number }}
                                    </a>
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600 tabular-nums whitespace-nowrap">
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
                                        <span class="text-purple-700 font-semibold text-xs">Custom Mix</span>
                                    @else
                                        <span class="text-slate-600 text-xs">Standard</span>
                                    @endif
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-700 whitespace-nowrap">
                                    {{ $currency::format($sale->subtotal) }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-700 whitespace-nowrap">
                                    {{ (float) $sale->discount_amount > 0 ? $currency::format($sale->discount_amount) : '—' }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold text-slate-900 whitespace-nowrap">
                                    {{ $currency::format($sale->total) }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                    <button 
                                        @click.stop="contextMenu.openFromButton($event, { id: {{ $sale->id }}, invoice: '{{ $sale->invoice_number }}', receiptUrl: '{{ route('sales.receipt', ['sale' => $sale]) }}' })" 
                                        type="button" 
                                        class="inline-flex items-center justify-center h-6 w-6 rounded hover:bg-slate-100 text-slate-500 hover:text-slate-800 transition"
                                        title="Actions"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <circle cx="12" cy="5" r="1.5" fill="currentColor" stroke="none" />
                                            <circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none" />
                                            <circle cx="12" cy="19" r="1.5" fill="currentColor" stroke="none" />
                                        </svg>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="border border-slate-200 px-4 py-8 text-center text-slate-500">
                                    No transactions found matching current filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($transactions->hasPages())
                <div class="border-t border-slate-300 px-3 py-2 bg-slate-50">
                    {{ $transactions->links() }}
                </div>
            @endif
        @endif

        <!-- Tab 3: Top Selling Products -->
        @if ($activeTab === 'products')
            <div class="overflow-x-auto">
                <table class="w-full border-collapse border border-slate-300 text-xs">
                    <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-300">
                        <tr>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-left">Product / Item Description</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap w-32">Units Sold</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap font-bold w-36">Gross Revenue</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @forelse ($topProducts as $item)
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="border border-slate-200 px-2.5 py-1.5 font-medium text-slate-900">
                                    {{ $item->description }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-800">
                                    {{ number_format((float) $item->total_quantity, 2) }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold text-emerald-800">
                                    {{ $currency::format($item->total_sales) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="border border-slate-200 px-4 py-8 text-center text-slate-500">
                                    No product sales recorded in the selected period.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <!-- Formal Report Certification & Signature Block -->
    <div class="pt-4 border-t border-slate-300 flex flex-col sm:flex-row sm:items-end justify-between gap-4">
        <div class="text-[11px] text-slate-500">
            <p>Official system generated report reflecting verified POS audit records.</p>
            <p class="text-[10px] text-slate-400 mt-0.5">Report Period: {{ $dateFrom ? \Carbon\Carbon::parse($dateFrom)->format('M d, Y') : 'Start' }} to {{ $dateTo ? \Carbon\Carbon::parse($dateTo)->format('M d, Y') : 'Present' }}</p>
        </div>
        <div class="w-full sm:w-72 rounded-lg border border-slate-300 bg-white p-4 shadow-xs">
            <span class="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-8">
                Report Prepared &amp; Certified By:
            </span>
            <div class="border-b border-slate-900 mb-1.5"></div>
            <p class="font-bold text-slate-900 text-xs">{{ auth()->user()->name }}</p>
            <p class="text-[11px] text-slate-500">{{ ucfirst(auth()->user()->role) }}</p>
            <p class="text-[10px] text-slate-400 mt-2">Date Signed: <span class="font-mono">____________________</span></p>
        </div>
    </div>

    <!-- Floating Context Menu -->
    <div 
        x-ref="floatingMenu"
        x-show="contextMenu.open" 
        x-cloak
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        :style="`position: fixed; left: ${contextMenu.x}px; top: ${contextMenu.y}px; z-index: 9999;`"
        class="w-44 rounded-lg border border-slate-200 bg-white py-1 shadow-xl ring-1 ring-black/5"
        style="display: none;"
    >
        <a 
            :href="contextMenu.item ? contextMenu.item.receiptUrl : '#'"
            target="_blank"
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <span>View Receipt</span>
        </a>
        <div class="my-1 border-t border-slate-100"></div>
        <button 
            @click="if (contextMenu.item) { navigator.clipboard.writeText(contextMenu.item.invoice); $dispatch('toast', { type: 'success', message: 'Invoice #' + contextMenu.item.invoice + ' copied to clipboard.' }); contextMenu.close(); }"
            type="button" 
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
            <span>Copy Invoice #</span>
        </button>
    </div>
</div>
