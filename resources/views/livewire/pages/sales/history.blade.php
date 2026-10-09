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

    <!-- Table Container with Horizontal Filter Bar Directly Above Table (Matching Quotations Design) -->
    <div class="rounded-lg border border-slate-300 bg-white shadow-xs overflow-hidden">
        <!-- Horizontal Filter Bar -->
        <div class="p-3 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center gap-2.5">
            <!-- Search Keyword -->
            <div class="flex-1 min-w-[200px]">
                <input wire:model.live.debounce.250ms="search" type="search" placeholder="Search invoice #, cashier, item..."
                    class="w-full rounded border-slate-300 px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
            </div>

            <!-- Time Scope -->
            <div class="inline-flex rounded border border-slate-300 p-0.5 bg-white">
                <button wire:click="setScope('today')" type="button"
                    class="rounded px-2.5 py-1 text-xs font-medium transition {{ $viewScope === 'today' ? 'bg-[#00a3cc] text-white font-semibold shadow-xs' : 'text-slate-700 hover:text-slate-900' }}">
                    Today
                </button>
                <button wire:click="setScope('all')" type="button"
                    class="rounded px-2.5 py-1 text-xs font-medium transition {{ $viewScope === 'all' ? 'bg-[#00a3cc] text-white font-semibold shadow-xs' : 'text-slate-700 hover:text-slate-900' }}">
                    All Time
                </button>
            </div>

            <!-- Sale Type Filter -->
            <div class="w-36">
                <select wire:model.live="type" class="w-full rounded border-slate-300 px-2 py-1.5 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]">
                    <option value="">All Types</option>
                    <option value="normal">Standard Packaged</option>
                    <option value="mixed">Custom Mix</option>
                </select>
            </div>

            <!-- Date From -->
            <div class="w-36">
                <input wire:model.live="dateFrom" type="date" title="Sold From"
                    class="w-full rounded border-slate-300 px-2 py-1.5 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
            </div>

            <!-- Date To -->
            <div class="w-36">
                <input wire:model.live="dateTo" type="date" title="Sold To"
                    class="w-full rounded border-slate-300 px-2 py-1.5 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
            </div>

            <!-- Reset Button -->
            @if ($search !== '' || $type !== '' || $dateFrom !== '' || $dateTo !== '' || $viewScope !== 'all')
                <button wire:click="resetFilters" type="button"
                    class="rounded border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100 transition">
                    Reset
                </button>
            @endif
        </div>

        <!-- Table -->
        <div class="overflow-x-auto">
            <table class="w-full border-collapse border border-slate-300 text-xs">
                <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-300">
                    <tr>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-left whitespace-nowrap">Invoice #</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-left whitespace-nowrap">Date</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-left whitespace-nowrap">Time</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-left whitespace-nowrap">Cashier</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-center whitespace-nowrap">Type</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-center whitespace-nowrap">Items</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap">Subtotal</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap">Discount</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap font-bold text-slate-900">Total Due</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap">Tendered</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap">Change</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-center whitespace-nowrap w-16">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($sales as $sale)
                        <tr 
                            class="hover:bg-slate-50 transition-colors cursor-default" 
                            wire:key="sale-row-{{ $sale->id }}"
                            @contextmenu.prevent="contextMenu.openFromEvent($event, { id: {{ $sale->id }}, invoice: '{{ $sale->invoice_number }}', receiptUrl: '{{ route('sales.receipt', $sale) }}' })"
                        >
                            <!-- Invoice # -->
                            <td class="border border-slate-200 px-2.5 py-1.5 font-mono font-medium text-slate-900 tabular-nums whitespace-nowrap">
                                <a href="{{ route('sales.receipt', $sale) }}" class="text-[#008fb3] hover:underline font-bold">
                                    {{ $sale->invoice_number }}
                                </a>
                            </td>

                            <!-- Date Column -->
                            <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-700 whitespace-nowrap">
                                {{ $sale->sold_at->format('Y-m-d') }}
                            </td>

                            <!-- Time Column -->
                            <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-500 whitespace-nowrap">
                                {{ $sale->sold_at->format('h:i A') }}
                            </td>

                            <!-- Cashier -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-800 whitespace-nowrap">
                                {{ $sale->user?->name ?? 'Store Cashier' }}
                            </td>

                            <!-- Sale Type (no border) -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                @if ($sale->type === 'mixed')
                                    <span class="text-purple-700 font-semibold text-xs">Custom Mix</span>
                                @else
                                    <span class="text-slate-600 text-xs">Standard</span>
                                @endif
                            </td>

                            <!-- Items count -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center tabular-nums text-slate-700">
                                {{ $sale->items->count() }}
                            </td>

                            <!-- Subtotal -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-700 whitespace-nowrap">
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
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-600 whitespace-nowrap">
                                {{ $currency::format($sale->payment_amount) }}
                            </td>

                            <!-- Change -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-600 whitespace-nowrap">
                                {{ $currency::format($sale->change_amount) }}
                            </td>

                            <!-- Actions -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                <button 
                                    @click.stop="contextMenu.openFromButton($event, { id: {{ $sale->id }}, invoice: '{{ $sale->invoice_number }}', receiptUrl: '{{ route('sales.receipt', $sale) }}' })" 
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
                            <td colspan="12" class="border border-slate-200 px-4 py-8 text-center text-slate-500">
                                <p class="font-medium text-xs">No completed sales match the selected filters.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($sales->hasPages())
            <div class="border-t border-slate-300 px-3 py-2 bg-slate-50">
                {{ $sales->links() }}
            </div>
        @endif
    </div>

    <!-- Floating Context Menu -->
    <div 
        x-ref="floatingMenu"
        x-show="contextMenu.open" 
        x-cloak
        @click.stop
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
        <button 
            type="button"
            @click="if (contextMenu.item) { const url = contextMenu.item.receiptUrl; contextMenu.close(); window.location.href = url; }"
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors cursor-pointer"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <span>View Receipt</span>
        </button>
        <button 
            type="button"
            @click="if (contextMenu.item) { const url = contextMenu.item.receiptUrl + '?print=1'; contextMenu.close(); window.open(url, '_blank'); }"
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors cursor-pointer"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
            <span>Print Receipt</span>
        </button>
        <div class="my-1 border-t border-slate-100"></div>
        <button 
            @click="if (contextMenu.item) { navigator.clipboard.writeText(contextMenu.item.invoice); $dispatch('toast', { type: 'success', message: 'Invoice #' + contextMenu.item.invoice + ' copied to clipboard.' }); contextMenu.close(); }"
            type="button" 
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors cursor-pointer"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
            <span>Copy Invoice #</span>
        </button>
    </div>
</div>
