<?php

use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockIn;
use App\Support\Currency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    // Filters
    public string $search = '';
    public string $statusFilter = '';
    public string $dateFrom = '';
    public string $dateTo = '';
    public int $perPage = 15;

    // View Details Modal State
    public bool $showDetailModal = false;
    public ?int $viewingPoId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->canManagePurchaseOrders(), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
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

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->resetPage();
    }

    public function viewDetails(int $id): void
    {
        $this->viewingPoId = $id;
        $this->showDetailModal = true;
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->viewingPoId = null;
    }

    public function markAsOrdered(int $id): void
    {
        $po = PurchaseOrder::findOrFail($id);
        if ($po->status !== 'pending') {
            return;
        }

        $po->update(['status' => 'ordered']);

        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => 'purchase_order_ordered',
            'auditable_type' => PurchaseOrder::class,
            'auditable_id' => $po->id,
            'context' => ['po_number' => $po->po_number],
        ]);

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => 'Purchase Order ' . $po->po_number . ' marked as Ordered.',
        ]);
    }

    public function receiveOrder(int $id): void
    {
        $po = PurchaseOrder::with(['items.product.category', 'items.product.packageUnit'])->findOrFail($id);
        if ($po->status === 'received' || $po->status === 'cancelled') {
            return;
        }

        DB::transaction(function () use ($po): void {
            // Create Stock In batch
            $stockIn = StockIn::create([
                'user_id' => auth()->id(),
                'received_at' => now(),
                'reference' => $po->po_number,
                'notes' => 'Received from Purchase Order ' . $po->po_number . ' (' . $po->supplier_name . ')',
            ]);

            foreach ($po->items as $item) {
                $qty = (float) $item->quantity;
                $cost = (float) $item->unit_cost;
                $product = $item->product;

                if (!$product) {
                    continue;
                }

                $isPaint = $product->category && stripos($product->category->name, 'paint') !== false;

                // Update Storage inventory balance
                $inventory = Inventory::query()
                    ->where('product_id', $product->id)
                    ->where('location', 'storage')
                    ->lockForUpdate()
                    ->firstOrCreate(['product_id' => $product->id, 'location' => 'storage'], ['quantity' => 0]);

                $before = (float) $inventory->quantity;
                $after = $before + $qty;
                $inventory->quantity = $after;
                $inventory->save();

                // Create StockInItem record
                $stockIn->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $qty,
                    'unit_cost' => $cost,
                ]);

                $refText = 'PO ' . $po->po_number . ' (' . $po->supplier_name . ')';

                InventoryMovement::create([
                    'product_id' => $product->id,
                    'location' => 'storage',
                    'user_id' => auth()->id(),
                    'type' => 'stock_in',
                    'quantity_change' => $qty,
                    'quantity_before' => $before,
                    'quantity_after' => $after,
                    'reference_type' => StockIn::class,
                    'reference_id' => $stockIn->id,
                    'reference_text' => $refText,
                ]);
            }

            $po->update([
                'status' => 'received',
                'received_at' => now(),
            ]);

            AuditLog::create([
                'user_id' => auth()->id(),
                'event' => 'purchase_order_received',
                'auditable_type' => PurchaseOrder::class,
                'auditable_id' => $po->id,
                'context' => [
                    'po_number' => $po->po_number,
                    'stock_in_id' => $stockIn->id,
                ],
            ]);
        });

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => 'Purchase Order ' . $po->po_number . ' received and stocked into storage balance.',
        ]);
    }

    public function cancelOrder(int $id): void
    {
        $po = PurchaseOrder::findOrFail($id);
        if ($po->status === 'received') {
            return;
        }

        $po->update(['status' => 'cancelled']);

        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => 'purchase_order_cancelled',
            'auditable_type' => PurchaseOrder::class,
            'auditable_id' => $po->id,
            'context' => ['po_number' => $po->po_number],
        ]);

        $this->dispatch('toast', [
            'type' => 'info',
            'message' => 'Purchase Order ' . $po->po_number . ' marked as Cancelled.',
        ]);
    }

    public function deleteOrder(int $id): void
    {
        $po = PurchaseOrder::findOrFail($id);
        if ($po->status !== 'pending' && $po->status !== 'cancelled') {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => 'Only pending or cancelled purchase orders can be deleted.',
            ]);
            return;
        }

        $poNumber = $po->po_number;
        $po->delete();

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => 'Purchase Order ' . $poNumber . ' deleted.',
        ]);
    }

    public function with(): array
    {
        $query = PurchaseOrder::query()
            ->with(['user', 'items.product.brand', 'items.product.category', 'items.product.packageUnit'])
            ->when($this->search !== '', function ($q) {
                $term = '%' . trim($this->search) . '%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('po_number', 'like', $term)
                        ->orWhere('supplier_name', 'like', $term)
                        ->orWhereHas('items.product', fn($prod) => $prod->where('name', 'like', $term)->orWhere('sku', 'like', $term));
                });
            })
            ->when($this->statusFilter !== '', fn($q) => $q->where('status', $this->statusFilter))
            ->when($this->dateFrom !== '', fn($q) => $q->whereDate('order_date', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn($q) => $q->whereDate('order_date', '<=', $this->dateTo))
            ->latest('id');

        $purchaseOrders = $query->paginate($this->perPage);

        // Stats calculation
        $stats = [
            'total' => PurchaseOrder::count(),
            'pending' => PurchaseOrder::where('status', 'pending')->count(),
            'ordered' => PurchaseOrder::where('status', 'ordered')->count(),
            'received' => PurchaseOrder::where('status', 'received')->count(),
            'total_spend' => (float) PurchaseOrder::where('status', '!=', 'cancelled')->sum('total_amount'),
        ];

        $viewingPo = $this->viewingPoId ? PurchaseOrder::with(['user', 'items.product.brand', 'items.product.category', 'items.product.packageUnit'])->find($this->viewingPoId) : null;

        return [
            'purchaseOrders' => $purchaseOrders,
            'stats' => $stats,
            'viewingPo' => $viewingPo,
            'currency' => Currency::class,
        ];
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
            <h1 class="font-heading text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Purchase Orders</h1>
        </div>
        <div class="flex items-center gap-2">
            <a 
                href="{{ route('products.purchase-orders.create') }}" 
                wire:navigate
                class="inline-flex items-center gap-1.5 rounded bg-[#00a3cc] px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-[#008fb3] transition"
            >
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                <span>New Purchase Order</span>
            </a>
        </div>
    </div>

    <!-- KPI Summary Row (No colored bullet points) -->
    <div class="grid gap-3 grid-cols-2 lg:grid-cols-4">
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs">
            <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Total Purchase Orders</span>
            <p class="text-2xl font-light tabular-nums text-slate-900 mt-1">{{ number_format($stats['total']) }}</p>
        </div>
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs">
            <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-600">Pending / Draft</span>
            <p class="text-2xl font-light tabular-nums text-amber-700 mt-1">{{ number_format($stats['pending']) }}</p>
        </div>
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs">
            <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-600">Ordered / In Transit</span>
            <p class="text-2xl font-light tabular-nums text-sky-700 mt-1">{{ number_format($stats['ordered']) }}</p>
        </div>
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs">
            <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-600">Received into Stock</span>
            <p class="text-2xl font-light tabular-nums text-emerald-700 mt-1">{{ number_format($stats['received']) }}</p>
        </div>
    </div>

    <!-- Main Purchase Orders Section with Standardized Space-y-2 Spacing -->
    <div class="space-y-2">
        <!-- Filter Controls Outside the Table Div (Aligned left, h-9 controls) -->
        <div class="flex flex-wrap items-center gap-2.5">
            <div class="w-64">
                <input 
                    id="po-search"
                    wire:model.live.debounce.300ms="search" 
                    type="search" 
                    placeholder="Search PO #, supplier, or product..."
                    class="w-full h-9 rounded border border-slate-300 px-3 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" 
                />
            </div>
            <div class="w-40">
                <select id="po-status" wire:model.live="statusFilter" class="w-full h-9 rounded border border-slate-300 pl-2.5 pr-8 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="">All Statuses</option>
                    <option value="pending">Pending</option>
                    <option value="ordered">Ordered</option>
                    <option value="received">Received</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            <div class="flex items-center gap-1.5 text-xs text-slate-600">
                <span class="font-semibold text-[11px] uppercase">From:</span>
                <input id="po-date-from" wire:model.live="dateFrom" type="date" title="Date From" class="h-9 rounded border border-slate-300 px-2.5 text-xs bg-white text-slate-900 tabular-nums focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
            </div>
            <div class="flex items-center gap-1.5 text-xs text-slate-600">
                <span class="font-semibold text-[11px] uppercase">To:</span>
                <input id="po-date-to" wire:model.live="dateTo" type="date" title="Date To" class="h-9 rounded border border-slate-300 px-2.5 text-xs bg-white text-slate-900 tabular-nums focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
            </div>
            @if ($search !== '' || $statusFilter !== '' || $dateFrom !== '' || $dateTo !== '')
                <button wire:click="resetFilters" type="button"
                    class="h-9 px-2 text-xs text-[#00a3cc] hover:text-[#008fb3] underline font-medium inline-flex items-center">
                    Reset
                </button>
            @endif
        </div>

        <!-- Showing X of N Items Indicator (Left) & Customizable Amount Per Page (Right) -->
        <div class="flex items-center justify-between text-xs text-slate-500">
            <div>
                @if ($purchaseOrders->total() > 0)
                    Showing <span class="font-medium text-slate-700">{{ $purchaseOrders->firstItem() }}</span> to
                    <span class="font-medium text-slate-700">{{ $purchaseOrders->lastItem() }}</span> of <span
                        class="font-medium text-slate-700">{{ $purchaseOrders->total() }}</span> items
                @else
                    Showing <span class="font-medium text-slate-700">0</span> items
                @endif
            </div>
            <div class="flex items-center gap-1.5">
                <label for="poPerPage" class="text-xs text-slate-500">Show:</label>
                <select wire:model.live="perPage" id="poPerPage"
                    class="h-8 w-16 rounded border border-slate-300 bg-white pl-2.5 pr-7 py-1 text-xs text-slate-700 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="10">10</option>
                    <option value="15">15</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select>
                <span class="text-xs text-slate-500">items</span>
            </div>
        </div>

        <!-- Table Container (div contains ONLY table and bottom pagination) -->
        <div class="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs">
            <div class="overflow-x-auto w-full">
                <table class="w-full border-collapse border border-slate-300 text-xs">
                    <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-300">
                        <tr>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-left whitespace-nowrap">PO Number</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-left">Supplier</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-left whitespace-nowrap">Order Date</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-left whitespace-nowrap">Expected Delivery</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-center whitespace-nowrap">Items</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap">Total Amount</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-center whitespace-nowrap">Status</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-center whitespace-nowrap w-16">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @forelse ($purchaseOrders as $po)
                            <tr 
                                class="hover:bg-slate-50 transition-colors cursor-default" 
                                wire:key="po-row-{{ $po->id }}"
                                @contextmenu.prevent="contextMenu.openFromEvent($event, { id: {{ $po->id }}, number: '{{ $po->po_number }}', status: '{{ $po->status }}' })"
                            >
                                <td class="border border-slate-200 px-2.5 py-1.5 font-mono font-medium text-slate-900 whitespace-nowrap">
                                    <button wire:click="viewDetails({{ $po->id }})" type="button" class="text-[#00a3cc] hover:underline font-bold text-left">
                                        {{ $po->po_number }}
                                    </button>
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-slate-900 font-medium">
                                    {{ $po->supplier_name }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600 tabular-nums whitespace-nowrap">
                                    {{ $po->order_date ? $po->order_date->format('M d, Y') : '—' }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600 tabular-nums whitespace-nowrap">
                                    {{ $po->expected_delivery_date ? $po->expected_delivery_date->format('M d, Y') : '—' }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-center tabular-nums text-slate-700 whitespace-nowrap">
                                    {{ $po->items->count() }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right font-bold text-slate-900 tabular-nums whitespace-nowrap">
                                    {{ $currency::format($po->total_amount) }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                    @php
                                        $statusClasses = match ($po->status) {
                                            'pending' => 'text-amber-700',
                                            'ordered' => 'text-sky-700',
                                            'received' => 'text-emerald-700',
                                            'cancelled' => 'text-rose-700',
                                            default => 'text-slate-600',
                                        };
                                    @endphp
                                    <span class="text-[10px] font-bold uppercase tracking-wider {{ $statusClasses }}">
                                        {{ $po->status }}
                                    </span>
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                    <button 
                                        @click.stop="contextMenu.openFromButton($event, { id: {{ $po->id }}, number: '{{ $po->po_number }}', status: '{{ $po->status }}' })" 
                                        type="button" 
                                        class="inline-flex items-center justify-center h-6 w-6 rounded hover:bg-slate-100 text-slate-500 hover:text-slate-800 transition"
                                        title="Options"
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
                                <td colspan="8" class="border border-slate-200 p-8 text-center text-slate-400">
                                    No purchase orders found matching the filter criteria.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Flush and Flat Pagination -->
            @if ($purchaseOrders->hasPages())
                <div class="px-3 py-2 border-t border-slate-200 bg-white flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs">
                    <div>
                        <span class="text-slate-500">
                            Page <span class="font-medium text-slate-800">{{ $purchaseOrders->currentPage() }}</span> of
                            <span class="font-medium text-slate-800">{{ $purchaseOrders->lastPage() }}</span>
                        </span>
                    </div>
                    <div class="inline-flex items-center gap-1">
                        <button wire:click="previousPage" type="button" @disabled($purchaseOrders->onFirstPage())
                            class="px-2.5 py-1 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 disabled:opacity-30 disabled:pointer-events-none transition">
                            Prev
                        </button>

                        @php
                            $currentPage = $purchaseOrders->currentPage();
                            $lastPage = $purchaseOrders->lastPage();
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

                        <button wire:click="nextPage" type="button" @disabled(!$purchaseOrders->hasMorePages())
                            class="px-2.5 py-1 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 disabled:opacity-30 disabled:pointer-events-none transition">
                            Next
                        </button>
                    </div>
                </div>
            @endif
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
        class="w-52 rounded-lg border border-slate-200 bg-white py-1 shadow-xl ring-1 ring-black/5"
        style="display: none;"
    >
        <button 
            type="button" 
            @click="if (contextMenu.item) { const id = contextMenu.item.id; contextMenu.close(); $wire.viewDetails(id); }"
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            <span>View Details</span>
        </button>

        <a 
            :href="contextMenu.item ? '/products/purchase-orders/' + contextMenu.item.id + '/pdf' : '#'"
            target="_blank"
            @click="contextMenu.close()"
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <span>Export / Print PDF</span>
        </a>

        <div class="my-1 border-t border-slate-100"></div>

        <button 
            type="button" 
            x-show="contextMenu.item && contextMenu.item.status === 'pending'"
            @click="if (contextMenu.item) { const id = contextMenu.item.id; contextMenu.close(); $wire.markAsOrdered(id); }"
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            <span>Mark as Ordered</span>
        </button>

        <button 
            type="button" 
            x-show="contextMenu.item && (contextMenu.item.status === 'ordered' || contextMenu.item.status === 'pending')"
            @click="if (contextMenu.item) { const id = contextMenu.item.id; const num = contextMenu.item.number; contextMenu.close(); if (confirm(`Receive items from ${num} into Storage balance?`)) { $wire.receiveOrder(id); } }"
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <span>Receive into Stock</span>
        </button>

        <button 
            type="button" 
            x-show="contextMenu.item && contextMenu.item.status !== 'received' && contextMenu.item.status !== 'cancelled'"
            @click="if (contextMenu.item) { const id = contextMenu.item.id; const num = contextMenu.item.number; contextMenu.close(); if (confirm(`Cancel purchase order ${num}?`)) { $wire.cancelOrder(id); } }"
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
            <span>Cancel Order</span>
        </button>

        <button 
            type="button" 
            @click="if (contextMenu.item) { const num = contextMenu.item.number; contextMenu.close(); navigator.clipboard.writeText(num); $dispatch('toast', { type: 'success', message: 'PO #' + num + ' copied.' }); }"
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
            <span>Copy PO #</span>
        </button>

        <div x-show="contextMenu.item && (contextMenu.item.status === 'pending' || contextMenu.item.status === 'cancelled')" class="my-1 border-t border-slate-100"></div>

        <button 
            type="button" 
            x-show="contextMenu.item && (contextMenu.item.status === 'pending' || contextMenu.item.status === 'cancelled')"
            @click="if (contextMenu.item) { const id = contextMenu.item.id; const num = contextMenu.item.number; contextMenu.close(); if (confirm(`Permanently delete ${num}?`)) { $wire.deleteOrder(id); } }"
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-rose-700 hover:bg-rose-50 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            <span>Delete Order</span>
        </button>
    </div>

    <!-- View Purchase Order Detail Modal & Printable Slip -->
    @if ($showDetailModal && $viewingPo)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 sm:p-6 overflow-y-auto">
            <div class="w-full max-w-4xl rounded-lg border border-slate-300 bg-white shadow-2xl text-xs overflow-hidden flex flex-col max-h-[92vh]">
                <!-- Modal Top Header -->
                <div class="border-b border-slate-200 bg-slate-100 px-5 py-3.5 flex items-center justify-between print:hidden">
                    <div class="flex items-center gap-2.5">
                        <h2 class="font-heading font-bold text-sm uppercase tracking-wider text-slate-900">
                            Purchase Order: {{ $viewingPo->po_number }}
                        </h2>
                        @php
                            $detailStatusClasses = match ($viewingPo->status) {
                                'pending' => 'text-amber-700',
                                'ordered' => 'text-sky-700',
                                'received' => 'text-emerald-700',
                                'cancelled' => 'text-rose-700',
                                default => 'text-slate-600',
                            };
                        @endphp
                        <span class="text-xs font-bold uppercase tracking-wider {{ $detailStatusClasses }}">
                            ({{ $viewingPo->status }})
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('products.purchase-orders.pdf', $viewingPo->id) }}" target="_blank"
                            class="inline-flex items-center gap-1.5 rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition shadow-xs">
                            <svg class="h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            Export PDF
                        </a>
                        <button wire:click="closeDetailModal" type="button" class="rounded border border-slate-300 bg-white p-1 text-slate-400 hover:text-slate-600 transition">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Modal Content (Printable Slip Format) -->
                <div class="p-6 overflow-y-auto flex-1 space-y-5">
                    <!-- Company Header for Slip -->
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between border-b border-slate-200 pb-4 gap-3">
                        <div>
                            <h1 class="font-heading text-lg font-bold text-slate-900 tracking-tight">GRADE A PAINT CENTER</h1>
                            <p class="text-[11px] text-slate-500">Official Purchase Order Document</p>
                        </div>
                        <div class="text-right tabular-nums">
                            <div class="text-xs font-mono font-bold text-slate-900">{{ $viewingPo->po_number }}</div>
                            <div class="text-[11px] text-slate-500">Date: {{ $viewingPo->order_date ? $viewingPo->order_date->format('F d, Y') : '—' }}</div>
                        </div>
                    </div>

                    <!-- Meta Information Grid -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 p-3.5 bg-slate-50 rounded-lg border border-slate-200 text-xs">
                        <div>
                            <span class="text-[10px] uppercase font-semibold text-slate-500 block">Supplier</span>
                            <span class="font-bold text-slate-900 text-sm">{{ $viewingPo->supplier_name }}</span>
                        </div>
                        <div>
                            <span class="text-[10px] uppercase font-semibold text-slate-500 block">Created By</span>
                            <span class="font-medium text-slate-800">{{ $viewingPo->user?->name ?? 'System' }}</span>
                        </div>
                        <div>
                            <span class="text-[10px] uppercase font-semibold text-slate-500 block">Expected Delivery</span>
                            <span class="font-medium text-slate-800 tabular-nums">
                                {{ $viewingPo->expected_delivery_date ? $viewingPo->expected_delivery_date->format('M d, Y') : 'Not specified' }}
                            </span>
                        </div>
                        <div>
                            <span class="text-[10px] uppercase font-semibold text-slate-500 block">Received Date</span>
                            <span class="font-medium text-slate-800 tabular-nums">
                                {{ $viewingPo->received_at ? $viewingPo->received_at->format('M d, Y H:i') : 'Pending' }}
                            </span>
                        </div>
                    </div>

                    @if ($viewingPo->notes)
                        <div class="p-3 bg-amber-50/60 rounded border border-amber-200 text-xs text-amber-900">
                            <span class="font-bold">Order Notes:</span> {{ $viewingPo->notes }}
                        </div>
                    @endif

                    <!-- Items Table (Strict Column Order: SKU, Brand, Prod Name, Unit, Order Qty, Est Cost, Subtotal) -->
                    <div class="overflow-x-auto rounded-lg border border-slate-300">
                        <table class="w-full border-collapse border border-slate-300 text-xs">
                            <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-300">
                                <tr>
                                    <th class="border border-slate-300 px-3 py-2 text-left w-28">SKU</th>
                                    <th class="border border-slate-300 px-3 py-2 text-left w-28">Brand</th>
                                    <th class="border border-slate-300 px-3 py-2 text-left">Product Name</th>
                                    <th class="border border-slate-300 px-3 py-2 text-center w-20">Unit</th>
                                    <th class="border border-slate-300 px-3 py-2 text-right w-28">Order Qty</th>
                                    <th class="border border-slate-300 px-3 py-2 text-right w-32">Unit Cost</th>
                                    <th class="border border-slate-300 px-3 py-2 text-right w-32">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                @foreach ($viewingPo->items as $item)
                                    <tr class="hover:bg-slate-50 transition">
                                        <td class="border border-slate-200 px-3 py-2 font-mono text-slate-700 whitespace-nowrap">{{ $item->product?->sku ?? '—' }}</td>
                                        <td class="border border-slate-200 px-3 py-2 text-slate-600 whitespace-nowrap">{{ $item->product?->brand?->name ?? '—' }}</td>
                                        <td class="border border-slate-200 px-3 py-2 font-semibold text-slate-900">{{ $item->product?->name ?? 'Unknown' }}</td>
                                        <td class="border border-slate-200 px-3 py-2 text-center text-slate-600 whitespace-nowrap">{{ $item->product?->packageUnit?->abbreviation ?? '—' }}</td>
                                        <td class="border border-slate-200 px-3 py-2 text-right tabular-nums font-semibold text-slate-900 whitespace-nowrap">{{ number_format((float) $item->quantity, 2) }}</td>
                                        <td class="border border-slate-200 px-3 py-2 text-right tabular-nums text-slate-700 whitespace-nowrap">{{ $currency::format($item->unit_cost) }}</td>
                                        <td class="border border-slate-200 px-3 py-2 text-right tabular-nums font-bold text-slate-900 whitespace-nowrap">{{ $currency::format($item->subtotal) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-slate-50 border-t border-slate-300 font-bold">
                                <tr>
                                    <td colspan="4" class="px-3 py-2 text-right uppercase text-[10px] text-slate-500">Totals:</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-slate-900">{{ number_format((float) $viewingPo->items->sum('quantity'), 2) }}</td>
                                    <td class="px-3 py-2 text-right uppercase text-[10px] text-slate-500">Total Amount:</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-sm text-[#008fb3]">{{ $currency::format($viewingPo->total_amount) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Modal Bottom Actions (Hidden when printing) -->
                <div class="border-t border-slate-200 bg-slate-50 px-5 py-3 flex items-center justify-between print:hidden">
                    <div class="flex items-center gap-2">
                        @if ($viewingPo->status === 'pending')
                            <button wire:click="markAsOrdered({{ $viewingPo->id }})" type="button"
                                class="px-3 py-1.5 rounded bg-sky-600 text-white text-xs font-semibold hover:bg-sky-700 transition shadow-xs">
                                Mark as Ordered
                            </button>
                        @endif

                        @if ($viewingPo->status === 'ordered' || $viewingPo->status === 'pending')
                            <button wire:click="receiveOrder({{ $viewingPo->id }})" 
                                wire:confirm="Receive items from {{ $viewingPo->po_number }} into Storage inventory balance?"
                                type="button"
                                class="px-3.5 py-1.5 rounded bg-emerald-600 text-white text-xs font-semibold hover:bg-emerald-700 transition shadow-xs">
                                Receive into Stock
                            </button>
                        @endif

                        @if ($viewingPo->status !== 'received' && $viewingPo->status !== 'cancelled')
                            <button wire:click="cancelOrder({{ $viewingPo->id }})" 
                                wire:confirm="Cancel purchase order {{ $viewingPo->po_number }}?"
                                type="button"
                                class="px-3 py-1.5 rounded border border-rose-300 bg-white text-rose-700 text-xs font-semibold hover:bg-rose-50 transition shadow-xs">
                                Cancel Order
                            </button>
                        @endif
                    </div>

                    <button wire:click="closeDetailModal" type="button"
                        class="px-4 py-1.5 rounded border border-slate-300 bg-white text-xs font-medium text-slate-700 hover:bg-slate-100 transition shadow-xs">
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
