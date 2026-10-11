<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockInItem;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    // Form fields
    public string $poNumber = '';
    public string $supplierName = '';
    public string $orderDate = '';
    public string $expectedDeliveryDate = '';
    public string $notes = '';
    public array $orderItems = [];

    // Inline search
    public string $quickSearch = '';

    // Catalog picker modal
    public bool $showCatalogModal = false;
    public string $catalogSearch = '';
    public string $catalogBrandId = '';
    public string $catalogCategoryId = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->canManagePurchaseOrders(), 403);

        $this->orderDate = date('Y-m-d');

        $todayStr = date('Ymd');
        $countToday = PurchaseOrder::whereDate('created_at', today())->count() + 1;
        $this->poNumber = 'PO-' . $todayStr . '-' . str_pad((string) $countToday, 3, '0', STR_PAD_LEFT);
    }

    public function openCatalogModal(): void
    {
        $this->catalogSearch = '';
        $this->catalogBrandId = '';
        $this->catalogCategoryId = '';
        $this->showCatalogModal = true;
    }

    public function closeCatalogModal(): void
    {
        $this->showCatalogModal = false;
    }

    public function addProductToOrder(int $productId): void
    {
        $product = Product::with(['brand', 'category', 'packageUnit'])->find($productId);
        if (!$product) {
            return;
        }

        foreach ($this->orderItems as $index => $item) {
            if ($item['product_id'] === $product->id) {
                $this->orderItems[$index]['quantity'] = (float) $this->orderItems[$index]['quantity'] + 1;
                $this->orderItems[$index]['subtotal'] = (float) $this->orderItems[$index]['quantity'] * (float) $this->orderItems[$index]['unit_cost'];
                $this->quickSearch = '';
                $this->closeCatalogModal();
                return;
            }
        }

        $estCost = 0.00;
        $lastItem = StockInItem::where('product_id', $product->id)->latest()->first();
        if ($lastItem && $lastItem->unit_cost > 0) {
            $estCost = (float) $lastItem->unit_cost;
        }

        $this->orderItems[] = [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'brand' => $product->brand?->name ?? '—',
            'category' => $product->category?->name ?? '—',
            'unit' => $product->packageUnit?->abbreviation ?? 'pcs',
            'quantity' => 1.0,
            'unit_cost' => $estCost,
            'subtotal' => $estCost,
        ];

        $this->quickSearch = '';
        $this->closeCatalogModal();
    }

    public function updateItemQuantity(int $index, mixed $val): void
    {
        if (!isset($this->orderItems[$index])) {
            return;
        }

        if (!is_numeric($val)) {
            return;
        }

        $qty = max(0.01, min(999999.99, (float) $val));
        $this->orderItems[$index]['quantity'] = $qty;
        $this->orderItems[$index]['subtotal'] = round($qty * (float) $this->orderItems[$index]['unit_cost'], 2);
    }

    public function updateItemCost(int $index, mixed $val): void
    {
        if (!isset($this->orderItems[$index])) {
            return;
        }

        if (!is_numeric($val)) {
            return;
        }

        $cost = max(0, min(999999.99, (float) $val));
        $this->orderItems[$index]['unit_cost'] = $cost;
        $this->orderItems[$index]['subtotal'] = round((float) $this->orderItems[$index]['quantity'] * $cost, 2);
    }

    public function removeOrderItem(int $index): void
    {
        unset($this->orderItems[$index]);
        $this->orderItems = array_values($this->orderItems);
    }

    public function getOrderTotal(): float
    {
        return (float) array_sum(array_column($this->orderItems, 'subtotal'));
    }

    public function savePurchaseOrder(): mixed
    {
        $this->validate([
            'poNumber' => ['required', 'string', 'max:50', 'unique:purchase_orders,po_number'],
            'supplierName' => ['required', 'string', 'max:255'],
            'orderDate' => ['required', 'date'],
            'expectedDeliveryDate' => ['nullable', 'date', 'after_or_equal:orderDate'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'orderItems' => ['required', 'array', 'min:1'],
            'orderItems.*.product_id' => ['required', 'exists:products,id'],
            'orderItems.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'orderItems.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:999999.99'],
        ], [
            'orderItems.min' => 'You must add at least one product item to create a purchase order.',
            'orderItems.required' => 'You must add at least one product item to create a purchase order.',
            'orderItems.*.quantity.min' => 'Quantity must be greater than zero.',
            'orderItems.*.quantity.max' => 'Quantity cannot exceed 999,999.99.',
            'orderItems.*.quantity.numeric' => 'Quantity must be a valid number.',
            'orderItems.*.unit_cost.min' => 'Unit cost cannot be negative.',
            'orderItems.*.unit_cost.max' => 'Unit cost cannot exceed 999,999.99.',
            'orderItems.*.unit_cost.numeric' => 'Unit cost must be a valid number.',
            'expectedDeliveryDate.after_or_equal' => 'Expected delivery date cannot be earlier than the order date.',
        ]);

        $po = DB::transaction(function () {
            $total = $this->getOrderTotal();

            $po = PurchaseOrder::create([
                'user_id' => auth()->id(),
                'po_number' => trim($this->poNumber),
                'supplier_name' => trim($this->supplierName),
                'order_date' => $this->orderDate,
                'expected_delivery_date' => $this->expectedDeliveryDate ?: null,
                'status' => 'pending',
                'notes' => trim($this->notes) ?: null,
                'total_amount' => $total,
            ]);

            foreach ($this->orderItems as $item) {
                $subtotal = (float) $item['quantity'] * (float) $item['unit_cost'];
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $item['product_id'],
                    'quantity' => (float) $item['quantity'],
                    'unit_cost' => (float) $item['unit_cost'],
                    'subtotal' => $subtotal,
                ]);
            }

            AuditLog::create([
                'user_id' => auth()->id(),
                'event' => 'purchase_order_created',
                'auditable_type' => PurchaseOrder::class,
                'auditable_id' => $po->id,
                'context' => [
                    'po_number' => $po->po_number,
                    'supplier' => $po->supplier_name,
                    'total_amount' => $po->total_amount,
                    'items_count' => count($this->orderItems),
                ],
            ]);

            return $po;
        });

        session()->flash('status', "Purchase order {$po->po_number} created successfully.");

        return $this->redirectRoute('products.purchase-orders', navigate: true);
    }

    public function with(): array
    {
        $catalogProducts = collect();
        if ($this->showCatalogModal) {
            $catalogQuery = Product::query()
                ->with(['brand', 'category', 'packageUnit', 'inventories'])
                ->where('active', true);

            if (trim($this->catalogSearch) !== '') {
                $term = '%' . trim($this->catalogSearch) . '%';
                $catalogQuery->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('sku', 'like', $term);
                });
            }

            if ($this->catalogBrandId !== '') {
                $catalogQuery->where('brand_id', $this->catalogBrandId);
            }

            if ($this->catalogCategoryId !== '') {
                $catalogQuery->where('category_id', $this->catalogCategoryId);
            }

            $catalogProducts = $catalogQuery->orderBy('name')->get();
        }

        $quickSearchResults = collect();
        if (trim($this->quickSearch) !== '') {
            $term = '%' . trim($this->quickSearch) . '%';
            $quickSearchResults = Product::query()
                ->with(['brand', 'category', 'packageUnit', 'inventories'])
                ->where('active', true)
                ->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('sku', 'like', $term);
                })
                ->limit(8)
                ->get();
        }

        return [
            'brands' => Brand::where('active', true)->orderBy('name')->get(),
            'categories' => Category::where('active', true)->orderBy('name')->get(),
            'catalogProducts' => $catalogProducts,
            'quickSearchResults' => $quickSearchResults,
            'currency' => Currency::class,
        ];
    }
}; ?>

<div class="space-y-4 w-full min-w-0">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-3 border-b border-slate-300">
        <div class="flex items-center gap-3">
            <a href="{{ route('products.purchase-orders') }}" wire:navigate
                class="inline-flex items-center justify-center h-8 w-8 rounded border border-slate-300 bg-white text-slate-600 hover:bg-slate-50 shadow-xs transition">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
            </a>
            <div>
                <h1 class="font-heading text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Create Purchase Order</h1>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('products.purchase-orders') }}" wire:navigate
                class="px-3 py-1.5 rounded border border-slate-300 bg-white text-xs font-semibold text-slate-700 hover:bg-slate-50 shadow-xs transition">
                Cancel
            </a>
            <button wire:click="savePurchaseOrder" type="button"
                class="inline-flex items-center gap-1.5 rounded bg-[#00a3cc] px-4 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-[#008fb3] transition">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <span>Save Purchase Order</span>
            </button>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="space-y-4">
        <!-- Order Header Information -->
        <div class="rounded-lg shadow-xs space-y-3">
            <h2 class="font-heading font-bold text-xs uppercase tracking-wider text-slate-800 border-b border-slate-200 pb-2">
                Order Information
            </h2>
            
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label for="poNumber" class="block font-semibold text-slate-700 text-xs mb-1">
                        PO Number <span class="text-rose-500">*</span>
                    </label>
                    <input wire:model="poNumber" id="poNumber" type="text"
                        class="w-full rounded border-slate-300 text-xs font-mono font-bold focus:border-[#00a3cc] focus:ring-0" />
                    @error('poNumber')<p class="text-rose-600 text-[11px] mt-0.5">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="supplierName" class="block font-semibold text-slate-700 text-xs mb-1">
                        Supplier Name <span class="text-rose-500">*</span>
                    </label>
                    <input wire:model="supplierName" id="supplierName" type="text" placeholder="e.g. Pacific Paint (Boysen) Phils."
                        class="w-full rounded border-slate-300 text-xs focus:border-[#00a3cc] focus:ring-0" />
                    @error('supplierName')<p class="text-rose-600 text-[11px] mt-0.5">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="orderDate" class="block font-semibold text-slate-700 text-xs mb-1">
                        Order Date <span class="text-rose-500">*</span>
                    </label>
                    <input wire:model="orderDate" id="orderDate" type="date"
                        class="w-full rounded border-slate-300 text-xs tabular-nums focus:border-[#00a3cc] focus:ring-0" />
                    @error('orderDate')<p class="text-rose-600 text-[11px] mt-0.5">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2 border-t border-slate-200">
                <div>
                    <label for="expectedDeliveryDate" class="block font-semibold text-slate-700 text-xs mb-1">
                        Expected Delivery Date
                    </label>
                    <input wire:model="expectedDeliveryDate" id="expectedDeliveryDate" type="date"
                        class="w-full rounded border-slate-300 text-xs tabular-nums focus:border-[#00a3cc] focus:ring-0" />
                    @error('expectedDeliveryDate')<p class="text-rose-600 text-[11px] mt-0.5">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="notes" class="block font-semibold text-slate-700 text-xs mb-1">
                        Notes / Special Instructions
                    </label>
                    <input wire:model="notes" id="notes" type="text" placeholder="e.g. Terms 30 days, deliver to warehouse gate 2"
                        class="w-full rounded border-slate-300 text-xs focus:border-[#00a3cc] focus:ring-0" />
                    @error('notes')<p class="text-rose-600 text-[11px] mt-0.5">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        <!-- Order Items Section -->
        <div class="shadow-xs space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-200 pb-2">
                <div>
                    <h2 class="font-heading font-bold text-xs uppercase tracking-wider text-slate-800">Order Items</h2>
                </div>
                <div>
                    <button wire:click="openCatalogModal" type="button"
                        class="inline-flex items-center gap-1.5 rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-[#00a3cc] hover:bg-slate-50 shadow-xs transition">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                        Browse Product Catalog &rarr;
                    </button>
                </div>
            </div>

            <!-- Quick Typeahead Search Input -->
            <div class="relative">
                <div class="relative">
                    <input 
                        wire:model.live.debounce.200ms="quickSearch"
                        type="search" 
                        placeholder="Type SKU or product name to quickly add to list..." 
                        class="w-full rounded border-slate-300 text-xs pl-8 pr-8 py-2 text-slate-900 focus:border-[#00a3cc] focus:ring-0"
                    />
                    <svg class="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    @if ($quickSearch !== '')
                        <button wire:click="$set('quickSearch', '')" type="button" class="absolute inset-y-0 right-0 flex items-center pr-2.5 text-slate-400 hover:text-slate-600">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    @endif
                </div>

                @if ($quickSearchResults->isNotEmpty())
                    <div class="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-slate-300 bg-white shadow-xl divide-y divide-slate-100">
                        @foreach ($quickSearchResults as $prod)
                            <button 
                                type="button" 
                                wire:click="addProductToOrder({{ $prod->id }})"
                                class="w-full text-left px-3.5 py-2 text-xs hover:bg-slate-50 flex items-center justify-between transition"
                            >
                                <div class="min-w-0 pr-2">
                                    <span class="font-bold text-slate-900 truncate block">{{ $prod->name }}</span>
                                    <div class="text-[11px] text-slate-500 tabular-nums">
                                        <span class="font-mono font-semibold">{{ $prod->sku }}</span> · {{ $prod->brand?->name ?? 'No Brand' }}
                                    </div>
                                </div>
                                <div class="text-right shrink-0">
                                    <span class="text-xs font-semibold text-[#00a3cc]">+ Add</span>
                                </div>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            <!-- Line Items Table (Strict Column Order: SKU, Brand, Prod Name, Unit, Order Qty, Est Cost, Subtotal, Action) -->
            <div class="overflow-x-auto rounded-lg border bg-slate-50 border-slate-300">
                <table class="w-full border-collapse border border-slate-300 text-xs">
                    <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-300">
                        <tr>
                            <th class="border border-slate-300 px-3 py-2 text-left w-24">SKU</th>
                            <th class="border border-slate-300 px-3 py-2 text-left w-24">Brand</th>
                            <th class="border border-slate-300 px-3 py-2 text-left">Product Name</th>
                            <th class="border border-slate-300 px-3 py-2 text-center w-16">Unit</th>
                            <th class="border border-slate-300 px-3 py-2 text-right w-28">Order Qty <span class="text-rose-500">*</span></th>
                            <th class="border border-slate-300 px-3 py-2 text-right w-32">Est. Unit Cost (₱) <span class="text-rose-500">*</span></th>
                            <th class="border border-slate-300 px-3 py-2 text-right w-32">Subtotal</th>
                            <th class="border border-slate-300 px-3 py-2 text-center w-12">Remove</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @forelse ($orderItems as $index => $item)
                            <tr class="hover:bg-slate-50 transition" wire:key="order-item-{{ $item['product_id'] }}">
                                <td class="border border-slate-200 px-3 py-2 font-mono text-slate-700 whitespace-nowrap">{{ $item['sku'] }}</td>
                                <td class="border border-slate-200 px-3 py-2 text-slate-600 whitespace-nowrap">{{ $item['brand'] }}</td>
                                <td class="border border-slate-200 px-3 py-2 font-semibold text-slate-900">{{ $item['name'] }}</td>
                                <td class="border border-slate-200 px-3 py-2 text-center text-slate-600 whitespace-nowrap">{{ $item['unit'] }}</td>
                                <td class="border border-slate-200 px-2.5 py-1 text-right">
                                    <input 
                                        type="number" 
                                        step="0.01" 
                                        min="0.01" 
                                        max="999999.99"
                                        inputmode="decimal"
                                        x-on:keydown="if(['e','E','+','-'].includes($event.key)) $event.preventDefault()"
                                        value="{{ $item['quantity'] }}"
                                        wire:change="updateItemQuantity({{ $index }}, $event.target.value)"
                                        class="w-24 rounded border-slate-300 text-xs py-1 px-2 text-right tabular-nums focus:border-[#00a3cc] focus:ring-0" 
                                    />
                                    @error('orderItems.' . $index . '.quantity')
                                        <p class="text-rose-600 text-[10px] mt-0.5 text-right">{{ $message }}</p>
                                    @enderror
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1 text-right">
                                    <input 
                                        type="number" 
                                        step="0.01" 
                                        min="0" 
                                        max="999999.99"
                                        inputmode="decimal"
                                        x-on:keydown="if(['e','E','+','-'].includes($event.key)) $event.preventDefault()"
                                        value="{{ $item['unit_cost'] }}"
                                        wire:change="updateItemCost({{ $index }}, $event.target.value)"
                                        class="w-28 rounded border-slate-300 text-xs py-1 px-2 text-right tabular-nums focus:border-[#00a3cc] focus:ring-0" 
                                    />
                                    @error('orderItems.' . $index . '.unit_cost')
                                        <p class="text-rose-600 text-[10px] mt-0.5 text-right">{{ $message }}</p>
                                    @enderror
                                </td>
                                <td class="border border-slate-200 px-3 py-2 text-right font-bold tabular-nums text-slate-900 whitespace-nowrap">
                                    {{ $currency::format($item['subtotal']) }}
                                </td>
                                <td class="border border-slate-200 px-3 py-2 text-center whitespace-nowrap">
                                    <button wire:click="removeOrderItem({{ $index }})" type="button" class="text-slate-400 hover:text-rose-600 font-bold transition text-sm">
                                        &times;
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="p-8 text-center text-slate-400">
                                    No items in this purchase order yet. Use the quick search above or click "Browse Product Catalog" to add products.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @error('orderItems')<p class="text-rose-600 text-xs font-semibold">{{ $message }}</p>@enderror

            <!-- Order Summary Card -->
            <div class="flex justify-end pt-2">
                <div class="w-80 rounded-lg  p-4 space-y-2 text-xs tabular-nums">
                    <div class="flex justify-between text-slate-600">
                        <span>Total Line Items:</span>
                        <span class="font-bold text-slate-900">{{ count($orderItems) }}</span>
                    </div>
                    <div class="flex justify-between text-slate-600">
                        <span>Total Quantities:</span>
                        <span class="font-bold text-slate-900">{{ number_format(array_sum(array_column($orderItems, 'quantity')), 2) }}</span>
                    </div>
                    <div class="border-t border-slate-200 pt-2 flex justify-between text-sm font-bold text-slate-900">
                        <span>Estimated Total:</span>
                        <span class="text-base text-slate-900">{{ $currency::format($this->getOrderTotal()) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Catalog Product Picker Modal (Dedicated Single Modal, No Stacking) -->
    @if ($showCatalogModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 sm:p-6">
            <div class="w-full max-w-4xl rounded-lg border border-slate-300 bg-white shadow-xl text-xs overflow-hidden flex flex-col max-h-[85vh]">
                <div class="border-b border-slate-200 bg-slate-50 px-5 py-3.5 flex items-center justify-between">
                    <div>
                        <h2 class="font-heading font-bold text-sm uppercase tracking-wider text-slate-900">Browse Catalog &amp; Add to Purchase Order</h2>
                    </div>
                    <button wire:click="closeCatalogModal" type="button" class="rounded border border-slate-300 bg-white p-1 text-slate-400 hover:text-slate-600 transition">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="p-5 flex-1 flex flex-col space-y-3.5 overflow-hidden">
                    <!-- Filters -->
                    <div class="flex flex-wrap items-center gap-2.5">
                        <div class="flex-1 min-w-[200px]">
                            <input wire:model.live.debounce.250ms="catalogSearch" type="search" placeholder="Search SKU or product name..." class="w-full h-9 rounded border border-slate-300 px-3 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                        </div>
                        <div class="w-40">
                            <select wire:model.live="catalogBrandId" class="w-full h-9 rounded border border-slate-300 pl-2.5 pr-8 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                                <option value="">All Brands</option>
                                @foreach ($brands as $b)
                                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="w-44">
                            <select wire:model.live="catalogCategoryId" class="w-full h-9 rounded border border-slate-300 pl-2.5 pr-8 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                                <option value="">All Categories</option>
                                @foreach ($categories as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if ($catalogSearch !== '' || $catalogBrandId !== '' || $catalogCategoryId !== '')
                            <button wire:click="$set('catalogSearch', ''); $set('catalogBrandId', ''); $set('catalogCategoryId', '');" type="button"
                                class="h-9 px-2 text-xs text-[#00a3cc] hover:text-[#008fb3] underline font-medium inline-flex items-center">
                                Reset
                            </button>
                        @endif
                    </div>

                    <!-- Products Table (Strict Column Order: SKU, Brand, Prod Name, Unit, Cat, Stock, Action) -->
                    <div class="flex-1 overflow-y-auto rounded-lg border border-slate-300">
                        <table class="w-full border-collapse border border-slate-300 text-xs">
                            <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider sticky top-0 border-b border-slate-300">
                                <tr>
                                    <th class="border border-slate-300 px-3 py-2 text-left w-28">SKU</th>
                                    <th class="border border-slate-300 px-3 py-2 text-left w-28">Brand</th>
                                    <th class="border border-slate-300 px-3 py-2 text-left">Product Name</th>
                                    <th class="border border-slate-300 px-3 py-2 text-center w-16">Unit</th>
                                    <th class="border border-slate-300 px-3 py-2 text-left w-28">Category</th>
                                    <th class="border border-slate-300 px-3 py-2 text-right w-24">Current Stock</th>
                                    <th class="border border-slate-300 px-3 py-2 text-center w-20">Select</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                @forelse ($catalogProducts as $prod)
                                    <tr class="hover:bg-slate-50 transition">
                                        <td class="border border-slate-200 px-3 py-2 font-mono text-slate-700 whitespace-nowrap">{{ $prod->sku }}</td>
                                        <td class="border border-slate-200 px-3 py-2 text-slate-600 whitespace-nowrap">{{ $prod->brand?->name ?? '—' }}</td>
                                        <td class="border border-slate-200 px-3 py-2 font-semibold text-slate-900">{{ $prod->name }}</td>
                                        <td class="border border-slate-200 px-3 py-2 text-center text-slate-600 whitespace-nowrap">{{ $prod->packageUnit?->abbreviation ?? '—' }}</td>
                                        <td class="border border-slate-200 px-3 py-2 text-slate-600 whitespace-nowrap">{{ $prod->category?->name ?? '—' }}</td>
                                        <td class="border border-slate-200 px-3 py-2 text-right tabular-nums font-semibold text-slate-800 whitespace-nowrap">{{ number_format((float) ($prod->inventories->sum('quantity') ?? 0), 2) }}</td>
                                        <td class="border border-slate-200 px-3 py-2 text-center whitespace-nowrap">
                                            <button wire:click="addProductToOrder({{ $prod->id }})" type="button" class="rounded bg-[#00a3cc] px-2.5 py-1 text-xs font-semibold text-white hover:bg-[#008fb3] shadow-xs transition">
                                                + Add
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="p-8 text-center text-slate-400">No products match your filter criteria.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
