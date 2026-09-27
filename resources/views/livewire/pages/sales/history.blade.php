<?php

use App\Models\Sale;
use App\Support\Currency;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $type = '';
    public string $viewScope = 'all'; // 'today' or 'all'
    public string $dateFrom = '';
    public string $dateTo = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->canViewSalesHistory(), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedViewScope(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function setScope(string $scope): void
    {
        $this->viewScope = $scope;
        if ($scope === 'today') {
            $this->dateFrom = now()->toDateString();
            $this->dateTo = now()->toDateString();
        } else {
            $this->dateFrom = '';
            $this->dateTo = '';
        }
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'type', 'dateFrom', 'dateTo']);
        $this->viewScope = 'all';
        $this->resetPage();
    }

    public function render(): mixed
    {
        $today = Carbon::today();
        $todaySalesCount = Sale::whereDate('sold_at', $today)->count();
        $todaySalesSum = (float) Sale::whereDate('sold_at', $today)->sum('total');

        $query = Sale::query()
            ->with(['user', 'items', 'mixingTransaction'])
            ->when($this->search, function ($q) {
                $q->where(function ($sub) {
                    $sub->where('invoice_number', 'like', '%' . $this->search . '%')
                        ->orWhereHas('user', fn($uq) => $uq->where('name', 'like', '%' . $this->search . '%'))
                        ->orWhereHas('items', fn($iq) => $iq->where('description', 'like', '%' . $this->search . '%'));
                });
            })
            ->when($this->type, fn($q) => $q->where('type', $this->type))
            ->when($this->dateFrom, fn($q) => $q->whereDate('sold_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn($q) => $q->whereDate('sold_at', '<=', $this->dateTo))
            ->latest('sold_at');

        return view('livewire.pages.sales.history', [
            'sales' => $query->paginate(20),
            'todaySalesCount' => $todaySalesCount,
            'todaySalesSum' => $todaySalesSum,
            'currency' => Currency::class,
        ]);
    }
}; ?>

<div class="space-y-4 w-full min-w-0">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-2.5 border-b border-slate-300">
        <div>
            <h1 class="font-heading text-xl font-bold tracking-tight text-slate-900">Sales History</h1>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('sales.index') }}" wire:navigate
                class="inline-flex items-center gap-1.5 rounded bg-[#00a3cc] px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-[#008fb3] transition">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span>POS Sale</span>
            </a>
        </div>
    </div>

    <!-- Today's Transaction Summary KPIs (Right-aligned numbers, bigger light font, neutral labels) -->
    <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs flex items-center justify-between">
            <div class="flex-1">
                <span class="text-xs font-normal uppercase tracking-wider text-slate-500">Today's Completed Invoices</span>
                <p class="text-3xl sm:text-4xl font-light tabular-nums text-slate-900 mt-1 text-right">{{ number_format($todaySalesCount) }}</p>
                <p class="text-[10px] text-slate-400 text-right mt-0.5">Transactions today</p>
            </div>
        </div>

        <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs flex items-center justify-between">
            <div class="flex-1">
                <span class="text-xs font-normal uppercase tracking-wider text-slate-500">Today's Gross Cash Collected</span>
                <p class="text-3xl sm:text-4xl font-light tabular-nums text-emerald-700 mt-1 text-right">{{ $currency::format($todaySalesSum) }}</p>
                <p class="text-[10px] text-slate-400 text-right mt-0.5">Total collected today</p>
            </div>
        </div>
    </div>

    <!-- Main Workspace: Left Filter Panel + Grid Table -->
    <div class="flex flex-col lg:flex-row gap-4 items-start">
        <!-- Left Filter Panel -->
        <aside class="w-full lg:w-56 shrink-0 rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs space-y-3">
            <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-700">Filters</span>
                <button wire:click="resetFilters" type="button" class="text-[11px] text-[#00a3cc] hover:text-[#008fb3] underline font-medium">
                    Reset
                </button>
            </div>

            <!-- Scope -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Time Scope</label>
                <div class="flex items-center gap-1">
                    <button wire:click="setScope('today')" type="button"
                        class="flex-1 rounded px-2 py-1 text-xs font-medium transition text-center {{ $viewScope === 'today' ? 'bg-[#00a3cc] text-white font-semibold' : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                        Today
                    </button>
                    <button wire:click="setScope('all')" type="button"
                        class="flex-1 rounded px-2 py-1 text-xs font-medium transition text-center {{ $viewScope === 'all' ? 'bg-[#00a3cc] text-white font-semibold' : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                        All Time
                    </button>
                </div>
            </div>

            <!-- Search -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Search Keyword</label>
                <input wire:model.live.debounce.250ms="search" type="search" placeholder="Invoice #, cashier..."
                    class="w-full rounded border-slate-300 px-2.5 py-1 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
            </div>

            <!-- Sale Type Filter -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Sale Type</label>
                <select wire:model.live="type" class="w-full rounded border-slate-300 px-2 py-1 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]">
                    <option value="">All Types</option>
                    <option value="normal">Standard Packaged</option>
                    <option value="mixed">Custom Mix</option>
                </select>
            </div>

            <!-- Date From -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Date From</label>
                <input wire:model.live="dateFrom" type="date"
                    class="w-full rounded border-slate-300 px-2 py-1 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
            </div>

            <!-- Date To -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Date To</label>
                <input wire:model.live="dateTo" type="date"
                    class="w-full rounded border-slate-300 px-2 py-1 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
            </div>
        </aside>

        <!-- Sales History Grid-Based Table -->
        <div class="flex-1 min-w-0 w-full overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs">
            <div class="overflow-x-auto w-full">
                <table class="w-full border-collapse border border-slate-300 text-xs">
                    <thead class="bg-slate-100 uppercase text-[10px] font-semibold text-slate-700 tracking-wider">
                        <tr>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-left">Invoice #</th>
                            <th class="border border-slate-300 px-2 py-1.5 text-left">Date</th>
                            <th class="border border-slate-300 px-2 py-1.5 text-left">Time</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-left">Cashier</th>
                            <th class="border border-slate-300 px-2 py-1.5 text-center">Type</th>
                            <th class="border border-slate-300 px-2 py-1.5 text-center">Items</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-right">Subtotal</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-right">Discount</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-right font-bold text-slate-900">Total Due</th>
                            <th class="border border-slate-300 px-2 py-1.5 text-right">Tendered</th>
                            <th class="border border-slate-300 px-2 py-1.5 text-right">Change</th>
                            <th class="border border-slate-300 px-2 py-1.5 text-center w-28">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sales as $sale)
                            <tr class="hover:bg-slate-50 transition">
                                <!-- Invoice # -->
                                <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums font-bold text-slate-900 whitespace-nowrap">
                                    {{ $sale->invoice_number }}
                                </td>

                                <!-- Separate Date Column -->
                                <td class="border border-slate-200 px-2 py-1.5 tabular-nums text-slate-700 whitespace-nowrap">
                                    {{ $sale->sold_at->format('Y-m-d') }}
                                </td>

                                <!-- Separate Time Column -->
                                <td class="border border-slate-200 px-2 py-1.5 tabular-nums text-slate-500 whitespace-nowrap">
                                    {{ $sale->sold_at->format('h:i A') }}
                                </td>

                                <!-- Cashier -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-slate-800 whitespace-nowrap">
                                    {{ $sale->user?->name ?? 'Store Cashier' }}
                                </td>

                                <!-- Sale Type Badge -->
                                <td class="border border-slate-200 px-2 py-1.5 text-center whitespace-nowrap">
                                    @if ($sale->type === 'mixed')
                                        <span class="rounded border border-purple-600 text-purple-700 bg-transparent px-1.5 py-0.5 text-[10px] font-medium">
                                            Custom Mix
                                        </span>
                                    @else
                                        <span class="text-slate-600 text-xs">Standard</span>
                                    @endif
                                </td>

                                <!-- Items count -->
                                <td class="border border-slate-200 px-2 py-1.5 text-center tabular-nums text-slate-700">
                                    {{ $sale->items->count() }}
                                </td>

                                <!-- Subtotal -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-700">
                                    {{ $currency::format($sale->subtotal) }}
                                </td>

                                <!-- Discount -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums whitespace-nowrap">
                                    @if ((float) $sale->discount_percentage > 0)
                                        <span class="text-emerald-700 font-semibold" title="{{ number_format((float) $sale->discount_percentage, 2) }}%">
                                            -{{ $currency::format($sale->discount_amount) }}
                                        </span>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>

                                <!-- Final Total -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold text-slate-900 whitespace-nowrap">
                                    {{ $currency::format($sale->total) }}
                                </td>

                                <!-- Tendered -->
                                <td class="border border-slate-200 px-2 py-1.5 text-right tabular-nums text-slate-600 whitespace-nowrap">
                                    {{ $currency::format($sale->payment_amount) }}
                                </td>

                                <!-- Change -->
                                <td class="border border-slate-200 px-2 py-1.5 text-right tabular-nums text-slate-600 whitespace-nowrap">
                                    {{ $currency::format($sale->change_amount) }}
                                </td>

                                <!-- Actions -->
                                <td class="border border-slate-200 px-2 py-1.5 text-center whitespace-nowrap">
                                    <a href="{{ route('sales.receipt', $sale) }}"
                                        class="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2 py-0.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">
                                        <svg class="h-3 w-3 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                        <span>Receipt</span>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12" class="border border-slate-200 px-4 py-8 text-center text-slate-500">
                                    <p class="font-medium text-xs">No completed sales match the selected filters.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($sales->hasPages())
                <div class="p-2 border-t border-slate-300 bg-slate-50">
                    {{ $sales->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
