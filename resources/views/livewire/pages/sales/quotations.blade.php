<?php

use App\Models\AuditLog;
use App\Models\Quotation;
use App\Support\Currency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $status = '';
    public string $dateFrom = '';
    public string $dateTo = '';

    // Quotation detail modal
    public bool $showDetailModal = false;
    public ?int $viewingQuotationId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->canViewQuotations(), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
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

    public function resetFilters(): void
    {
        $this->reset(['search', 'status', 'dateFrom', 'dateTo']);
        $this->resetPage();
    }

    public function viewDetails(int $id): void
    {
        $this->viewingQuotationId = $id;
        $this->showDetailModal = true;
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->viewingQuotationId = null;
    }

    public function updateStatus(int $id, string $newStatus): void
    {
        $allowed = ['draft', 'sent', 'accepted', 'cancelled'];
        if (! in_array($newStatus, $allowed, true)) {
            return;
        }

        $quote = Quotation::findOrFail($id);
        $oldStatus = $quote->status;
        $quote->update(['status' => $newStatus]);

        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => 'quotation_status_updated',
            'auditable_type' => Quotation::class,
            'auditable_id' => $quote->id,
            'context' => [
                'quote_number' => $quote->quote_number,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ],
        ]);

        $this->dispatch('toast', ['type' => 'success', 'message' => "Quotation status updated to {$newStatus}."]);
    }

    public function convertToSale(int $id): void
    {
        $quote = Quotation::with('items.product.packageUnit')->findOrFail($id);

        if ($quote->isConverted()) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'This quotation was already converted to an invoice.']);
            return;
        }

        $cart = [];
        foreach ($quote->items as $item) {
            if ($item->mix_data) {
                $mix = $item->mix_data;
                $key = 'mix:' . Str::uuid();
                $cart[$key] = array_merge($mix, [
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'subtotal' => (float) $item->subtotal,
                ]);
            } else {
                $product = $item->product;
                if (! $product) {
                    continue;
                }
                $key = 'product:' . $product->id;
                $packageLabel = $product->formattedPackage();
                $cart[$key] = [
                    'type' => 'normal',
                    'product_id' => $product->id,
                    'description' => $product->name,
                    'package' => $packageLabel,
                    'brand' => $product->brand?->name ?? '—',
                    'unit' => $product->packageUnit?->abbreviation ?? 'unit',
                    'sku' => $product->sku,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'subtotal' => (float) $item->subtotal,
                ];
            }
        }

        session([
            'pos.cart' => $cart,
            'pos.quotation_id' => $quote->id,
            'pos.customer_name' => $quote->customer_name,
            'pos.customer_contact' => $quote->customer_contact,
            'pos.discount_percentage' => (string) ($quote->discount_percentage ?? '0'),
        ]);

        $this->dispatch('toast', ['type' => 'success', 'message' => "Quotation {$quote->quote_number} loaded into POS cart."]);
        $this->redirectRoute('sales.index');
    }

    public function render(): mixed
    {
        $query = Quotation::query()
            ->with(['user', 'items', 'convertedSale'])
            ->when($this->search, function ($q) {
                $q->where(function ($sub) {
                    $sub->where('quote_number', 'like', '%' . $this->search . '%')
                        ->orWhere('customer_name', 'like', '%' . $this->search . '%')
                        ->orWhere('customer_contact', 'like', '%' . $this->search . '%')
                        ->orWhereHas('user', fn($uq) => $uq->where('name', 'like', '%' . $this->search . '%'));
                });
            })
            ->when($this->status, fn($q) => $q->where('status', $this->status))
            ->when($this->dateFrom, fn($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->latest();

        $selectedQuotation = $this->viewingQuotationId
            ? Quotation::with(['user', 'items.product.packageUnit', 'convertedSale'])->find($this->viewingQuotationId)
            : null;

        $stats = [
            'total' => Quotation::count(),
            'draft' => Quotation::where('status', 'draft')->count(),
            'sent' => Quotation::where('status', 'sent')->count(),
            'accepted' => Quotation::where('status', 'accepted')->count(),
            'total_value' => (float) Quotation::whereNotIn('status', ['cancelled'])->sum('total'),
        ];

        return view('livewire.pages.sales.quotations', [
            'quotations' => $query->paginate(15),
            'selectedQuotation' => $selectedQuotation,
            'stats' => $stats,
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
            <h1 class="font-heading text-xl font-bold tracking-tight text-slate-900">Quotations</h1>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('sales.index') }}" wire:navigate
                class="inline-flex items-center gap-1.5 rounded bg-[#00a3cc] px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-[#008fb3] transition">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span>New Quote (via POS)</span>
            </a>
        </div>
    </div>

    <!-- KPI Summary Row -->
    <div class="grid gap-3 grid-cols-2 lg:grid-cols-4">
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs">
            <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Total Quotes</span>
            <p class="text-2xl font-light tabular-nums text-slate-900 mt-1">{{ number_format($stats['total']) }}</p>
        </div>
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs">
            <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Draft / In Progress</span>
            <p class="text-2xl font-light tabular-nums text-slate-700 mt-1">{{ number_format($stats['draft']) }}</p>
        </div>
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs">
            <span class="text-[11px] font-semibold uppercase tracking-wider text-sky-700">Sent to Customer</span>
            <p class="text-2xl font-light tabular-nums text-sky-800 mt-1">{{ number_format($stats['sent']) }}</p>
        </div>
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs">
            <span class="text-[11px] font-semibold uppercase tracking-wider text-emerald-700">Accepted / Converted</span>
            <p class="text-2xl font-light tabular-nums text-emerald-700 mt-1">{{ number_format($stats['accepted']) }}</p>
        </div>
    </div>

    <!-- Table Container with Horizontal Filter Bar Directly Above Table -->
    <div class="rounded-lg border border-slate-300 bg-white shadow-xs overflow-hidden">
        <!-- Horizontal Filter Bar -->
        <div class="p-3 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center gap-2.5">
            <!-- Search -->
            <div class="flex-1 min-w-[200px]">
                <input wire:model.live.debounce.250ms="search" type="search" placeholder="Search quote #, customer, contact, user..."
                    class="w-full rounded border-slate-300 px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
            </div>

            <!-- Status Filter -->
            <div class="w-36">
                <select wire:model.live="status" class="w-full rounded border-slate-300 px-2 py-1.5 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]">
                    <option value="">All Statuses</option>
                    <option value="draft">Draft</option>
                    <option value="sent">Sent</option>
                    <option value="accepted">Accepted</option>
                    <option value="expired">Expired</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>

            <!-- Date From -->
            <div class="w-36">
                <input wire:model.live="dateFrom" type="date" title="Created From"
                    class="w-full rounded border-slate-300 px-2 py-1.5 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
            </div>

            <!-- Date To -->
            <div class="w-36">
                <input wire:model.live="dateTo" type="date" title="Created To"
                    class="w-full rounded border-slate-300 px-2 py-1.5 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
            </div>

            <!-- Reset Button -->
            <button wire:click="resetFilters" type="button"
                class="rounded border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100 transition">
                Reset
            </button>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto">
            <table class="w-full border-collapse border border-slate-300 text-xs">
                <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-300">
                    <tr>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-left whitespace-nowrap">Quote #</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-left whitespace-nowrap">Date Created</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-left">Customer</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-center whitespace-nowrap">Items</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap">Total Due</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-left whitespace-nowrap">Valid Until</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-center whitespace-nowrap">Status</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-center whitespace-nowrap w-16">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($quotations as $quote)
                        <tr 
                            class="hover:bg-slate-50 transition-colors cursor-default" 
                            wire:key="quote-row-{{ $quote->id }}"
                            @contextmenu.prevent="contextMenu.openFromEvent($event, { id: {{ $quote->id }}, number: '{{ $quote->quote_number }}', status: '{{ $quote->status }}', canConvert: {{ !$quote->isConverted() && $quote->status !== 'cancelled' ? 'true' : 'false' }} })"
                        >
                            <td class="border border-slate-200 px-2.5 py-1.5 font-mono font-medium text-slate-900 whitespace-nowrap">
                                <button wire:click="viewDetails({{ $quote->id }})" type="button" class="text-[#008fb3] hover:underline font-bold text-left">
                                    {{ $quote->quote_number }}
                                </button>
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600 tabular-nums whitespace-nowrap">
                                {{ $quote->created_at->format('M d, Y') }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-800">
                                <div class="font-medium text-slate-900">{{ $quote->customer_name ?: 'Walk-in / Unspecified' }}</div>
                                @if ($quote->customer_contact)
                                    <div class="text-[10px] text-slate-500">{{ $quote->customer_contact }}</div>
                                @endif
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center tabular-nums text-slate-700 whitespace-nowrap">
                                {{ $quote->items->count() }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right font-bold text-slate-900 tabular-nums whitespace-nowrap">
                                {{ $currency::format($quote->total) }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600 tabular-nums whitespace-nowrap">
                                @if ($quote->valid_until)
                                    <span class="{{ $quote->isExpired() ? 'text-amber-600 font-semibold' : '' }}">
                                        {{ $quote->valid_until->format('M d, Y') }}
                                    </span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                @php
                                    $statusClasses = match ($quote->status) {
                                        'draft' => 'text-slate-600',
                                        'sent' => 'text-sky-700 font-bold',
                                        'accepted' => 'text-emerald-700 font-bold',
                                        'expired' => 'text-amber-700 font-bold',
                                        'cancelled' => 'text-rose-700 font-bold',
                                        default => 'text-slate-600',
                                    };
                                @endphp
                                <span class="text-[10px] font-bold uppercase tracking-wider {{ $statusClasses }}">
                                    {{ $quote->status }}
                                </span>
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                <button 
                                    @click.stop="contextMenu.openFromButton($event, { id: {{ $quote->id }}, number: '{{ $quote->quote_number }}', status: '{{ $quote->status }}', canConvert: {{ !$quote->isConverted() && $quote->status !== 'cancelled' ? 'true' : 'false' }} })" 
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
                            <td colspan="8" class="border border-slate-200 px-4 py-8 text-center text-slate-500">
                                No quotations found matching current filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($quotations->hasPages())
            <div class="border-t border-slate-300 px-3 py-2 bg-slate-50">
                {{ $quotations->links() }}
            </div>
        @endif
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
        class="w-48 rounded-lg border border-slate-200 bg-white py-1 shadow-xl ring-1 ring-black/5"
        style="display: none;"
    >
        <button 
            @click="if (contextMenu.item) { $wire.viewDetails(contextMenu.item.id); contextMenu.close(); }"
            type="button" 
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            <span>View Details</span>
        </button>

        <template x-if="contextMenu.item?.canConvert">
            <button 
                @click="if (contextMenu.item) { $wire.convertToSale(contextMenu.item.id); contextMenu.close(); }"
                type="button" 
                class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-emerald-700 hover:bg-emerald-50 transition-colors"
            >
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                <span>Load to POS Cart</span>
            </button>
        </template>

        <template x-if="contextMenu.item?.status === 'draft'">
            <button 
                @click="if (contextMenu.item) { $wire.updateStatus(contextMenu.item.id, 'sent'); contextMenu.close(); }"
                type="button" 
                class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-sky-700 hover:bg-sky-50 transition-colors"
            >
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                <span>Mark as Sent</span>
            </button>
        </template>

        <div class="my-1 border-t border-slate-100"></div>

        <button 
            @click="if (contextMenu.item) { navigator.clipboard.writeText(contextMenu.item.number); $dispatch('toast', { type: 'success', message: 'Quote #' + contextMenu.item.number + ' copied.' }); contextMenu.close(); }"
            type="button" 
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
            <span>Copy Quote #</span>
        </button>

        <template x-if="contextMenu.item?.status !== 'cancelled' && contextMenu.item?.canConvert">
            <button 
                @click="if (contextMenu.item && confirm('Cancel this quotation?')) { $wire.updateStatus(contextMenu.item.id, 'cancelled'); contextMenu.close(); }"
                type="button" 
                class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-rose-700 hover:bg-rose-50 transition-colors"
            >
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                <span>Cancel Quote</span>
            </button>
        </template>
    </div>

    <!-- Quotation Details Modal -->
    @if ($showDetailModal && $selectedQuotation)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <div class="w-full max-w-3xl rounded-lg bg-white shadow-xl overflow-hidden flex flex-col max-h-[90vh]">
                <!-- Modal Header -->
                <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3 bg-slate-50">
                    <div>
                        <h2 class="text-sm font-bold text-slate-900">Quotation Specification</h2>
                        <p class="text-xs font-mono text-slate-500">{{ $selectedQuotation->quote_number }}</p>
                    </div>
                    <button wire:click="closeDetailModal" type="button" class="text-slate-400 hover:text-slate-600 text-lg font-bold">
                        &times;
                    </button>
                </div>

                <!-- Modal Body (Printable Container) -->
                <div id="quotation-print-area" class="overflow-y-auto p-4 space-y-4 text-xs">
                    <!-- Info Meta Grid -->
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 rounded bg-slate-50 p-3 border border-slate-200">
                        <div>
                            <span class="block text-[10px] uppercase font-semibold text-slate-500">Customer</span>
                            <span class="font-bold text-slate-900">{{ $selectedQuotation->customer_name ?: 'Walk-in / Unspecified' }}</span>
                            @if ($selectedQuotation->customer_contact)
                                <span class="block text-[10px] text-slate-600">{{ $selectedQuotation->customer_contact }}</span>
                            @endif
                        </div>
                        <div>
                            <span class="block text-[10px] uppercase font-semibold text-slate-500">Date Issued</span>
                            <span class="text-slate-800">{{ $selectedQuotation->created_at->format('M d, Y h:i A') }}</span>
                            <span class="block text-[10px] text-slate-500">By: {{ $selectedQuotation->user?->name ?? 'Staff' }}</span>
                        </div>
                        <div>
                            <span class="block text-[10px] uppercase font-semibold text-slate-500">Valid Until</span>
                            <span class="font-medium text-slate-800">{{ $selectedQuotation->valid_until ? $selectedQuotation->valid_until->format('M d, Y') : '—' }}</span>
                            <span class="block text-[10px] uppercase font-semibold mt-1">Status: <span class="font-bold">{{ strtoupper($selectedQuotation->status) }}</span></span>
                        </div>
                    </div>

                    @if ($selectedQuotation->notes)
                        <div class="rounded border border-amber-200 bg-amber-50/60 p-2.5 text-xs text-amber-900">
                            <span class="font-bold block text-[10px] uppercase text-amber-800">Quotation Notes / Terms:</span>
                            {{ $selectedQuotation->notes }}
                        </div>
                    @endif

                    <!-- Items Table -->
                    <div>
                        <h3 class="font-bold text-xs uppercase text-slate-800 mb-1.5">Quoted Line Items</h3>
                        <table class="w-full border-collapse border border-slate-200 text-xs">
                            <thead class="bg-slate-100 uppercase text-[10px] font-semibold text-slate-700">
                                <tr>
                                    <th class="border border-slate-200 px-2.5 py-1.5 text-left">SKU</th>
                                    <th class="border border-slate-200 px-2.5 py-1.5 text-left">Item Description</th>
                                    <th class="border border-slate-200 px-2.5 py-1.5 text-left">Package / Spec</th>
                                    <th class="border border-slate-200 px-2.5 py-1.5 text-right">Qty</th>
                                    <th class="border border-slate-200 px-2.5 py-1.5 text-right">Unit Price</th>
                                    <th class="border border-slate-200 px-2.5 py-1.5 text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($selectedQuotation->items as $item)
                                    <tr class="hover:bg-slate-50">
                                        <td class="border border-slate-200 px-2.5 py-1.5 font-mono text-[11px] text-slate-600">
                                            {{ $item->sku ?? ($item->product?->sku ?? 'CUSTOM-MIX') }}
                                        </td>
                                        <td class="border border-slate-200 px-2.5 py-1.5 font-medium text-slate-900">
                                            {{ $item->description }}
                                            @if ($item->mix_data)
                                                <span class="block text-[10px] text-purple-700 font-semibold">Custom Paint Formulation</span>
                                            @endif
                                        </td>
                                        <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600">
                                            {{ $item->package ?? ($item->product?->packageUnit?->abbreviation ?? '—') }}
                                        </td>
                                        <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-900">
                                            {{ number_format((float) $item->quantity, 2) }}
                                        </td>
                                        <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-700">
                                            {{ $currency::format($item->unit_price) }}
                                        </td>
                                        <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold text-slate-900">
                                            {{ $currency::format($item->subtotal) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <!-- Financial Summary -->
                    <div class="flex justify-end">
                        <div class="w-72 rounded bg-slate-50 border border-slate-200 p-3 space-y-1.5 tabular-nums text-xs">
                            <div class="flex justify-between text-slate-600">
                                <span>Subtotal:</span>
                                <span class="font-medium text-slate-900">{{ $currency::format($selectedQuotation->subtotal) }}</span>
                            </div>

                            @if ((float) $selectedQuotation->discount_percentage > 0)
                                <div class="flex justify-between text-emerald-700 font-semibold">
                                    <span>Discount ({{ number_format((float) $selectedQuotation->discount_percentage, 2) }}%{{ $selectedQuotation->discount_type && $selectedQuotation->discount_type !== 'none' ? ' · ' . ucfirst(str_replace('_', ' ', $selectedQuotation->discount_type)) : '' }}):</span>
                                    <span>-{{ $currency::format($selectedQuotation->discount_amount) }}</span>
                                </div>
                                @if ($selectedQuotation->discount_reason)
                                    <div class="text-[10px] text-slate-500 italic pl-1 -mt-0.5">
                                        Reason: {{ $selectedQuotation->discount_reason }}
                                        @if ($selectedQuotation->discountAuthorizer)
                                            <span class="font-medium text-slate-700"> (Auth: {{ $selectedQuotation->discountAuthorizer->name }})</span>
                                        @endif
                                    </div>
                                @endif
                            @endif

                            @if ((float) $selectedQuotation->tax_rate > 0)
                                <div class="flex justify-between text-slate-600">
                                    <span>VAT ({{ number_format((float) $selectedQuotation->tax_rate, 0) }}%):</span>
                                    <span>+{{ $currency::format($selectedQuotation->tax_amount) }}</span>
                                </div>
                            @endif

                            <div class="flex justify-between text-sm font-bold text-slate-900 border-t border-slate-200 pt-2">
                                <span>Total Estimated:</span>
                                <span class="text-base text-slate-900">{{ $currency::format($selectedQuotation->total) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="border-t border-slate-200 px-4 py-3 bg-slate-50 flex items-center justify-between">
                    <button wire:click="closeDetailModal" type="button"
                        class="rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100 transition">
                        Close
                    </button>

                    <div class="flex items-center gap-2">
                        <button onclick="window.print()" type="button"
                            class="rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100 transition">
                            Print
                        </button>

                        @if (! $selectedQuotation->isConverted() && $selectedQuotation->status !== 'cancelled')
                            <button wire:click="convertToSale({{ $selectedQuotation->id }})" type="button"
                                class="rounded bg-[#00a3cc] px-4 py-1.5 text-xs font-bold uppercase tracking-wider text-white hover:bg-[#008fb3] transition shadow-xs">
                                Load to POS Cart
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
