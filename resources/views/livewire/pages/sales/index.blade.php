<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\MixingTransaction;
use App\Models\PackageUnit;
use App\Models\Product;
use App\Models\Sale;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $brandId = '';
    public string $categoryId = '';
    public string $packageUnitId = '';
    public bool $availableOnly = false;
    public bool $showFilters = false;

    public string $productId = '';
    public string $quantity = '1';

    public array $cart = [];

    // Custom Mix Form state
    public bool $showMixModal = false;
    public ?string $editingCartKey = null;
    public string $mixDescription = 'Custom mixed paint';
    public string $mixQuantity = '1';
    public string $mixResultUnit = 'L';
    public string $mixProductId = '';
    public string $mixEstimatedQuantity = '';
    public string $mixEstimatedQuantityUnit = 'L';
    public string $mixNotes = '';
    public array $mixComponents = [];

    public function mount(): void
    {
        $this->cart = session('pos.cart', []);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedBrandId(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryId(): void
    {
        $this->resetPage();
    }

    public function updatedPackageUnitId(): void
    {
        $this->resetPage();
    }

    public function updatedAvailableOnly(): void
    {
        $this->resetPage();
    }

    public function toggleFilters(): void
    {
        $this->showFilters = !$this->showFilters;
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'brandId', 'categoryId', 'packageUnitId', 'availableOnly']);
        $this->resetPage();
    }

    public function addProduct(int $productId): void
    {
        $product = Product::with(['inventory', 'packageUnit', 'brand'])->where('active', true)->findOrFail($productId);
        $key = 'product:' . $product->id;
        $currentInCart = (float) ($this->cart[$key]['quantity'] ?? 0);
        $newQty = $currentInCart + 1;

        $available = (float) ($product->inventory?->quantity ?? 0);
        if ($newQty > $available) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => "Only {$available} available in stock for {$product->name}.",
            ]);
            return;
        }

        $packageLabel = trim(($product->package_size ? rtrim(rtrim((string) $product->package_size, '0'), '.') . ' ' : '') . ($product->packageUnit?->abbreviation ?? ''));

        $this->cart[$key] = [
            'type' => 'normal',
            'product_id' => $product->id,
            'description' => $product->name,
            'package' => $packageLabel,
            'brand' => $product->brand?->name ?? '—',
            'unit' => $product->packageUnit?->abbreviation ?? 'unit',
            'sku' => $product->sku,
            'quantity' => $newQty,
            'unit_price' => (float) $product->selling_price,
            'subtotal' => round($newQty * (float) $product->selling_price, 2),
        ];

        session(['pos.cart' => $this->cart]);
    }

    public function updateCartQuantity(string $key, mixed $value): void
    {
        if (!isset($this->cart[$key])) {
            return;
        }

        $qty = (float) $value;
        if ($qty <= 0) {
            unset($this->cart[$key]);
            session(['pos.cart' => $this->cart]);
            return;
        }

        if ($this->cart[$key]['type'] === 'normal') {
            $product = Product::with('inventory')->find($this->cart[$key]['product_id']);
            $available = (float) ($product?->inventory?->quantity ?? 0);
            if ($qty > $available) {
                $this->dispatch('toast', [
                    'type' => 'warning',
                    'message' => "Stock limit reached ({$available} available).",
                ]);
                $this->cart[$key]['quantity'] = $available;
                $this->cart[$key]['subtotal'] = round($available * (float) $this->cart[$key]['unit_price'], 2);
                session(['pos.cart' => $this->cart]);
                return;
            }
        }

        $this->cart[$key]['quantity'] = $qty;
        $this->cart[$key]['subtotal'] = round($qty * (float) $this->cart[$key]['unit_price'], 2);
        session(['pos.cart' => $this->cart]);
    }

    public function incrementCart(string $key): void
    {
        if (isset($this->cart[$key])) {
            $this->updateCartQuantity($key, (float) $this->cart[$key]['quantity'] + 1);
        }
    }

    public function decrementCart(string $key): void
    {
        if (isset($this->cart[$key])) {
            $this->updateCartQuantity($key, (float) $this->cart[$key]['quantity'] - 1);
        }
    }

    public function removeFromCart(string $key): void
    {
        unset($this->cart[$key]);
        session(['pos.cart' => $this->cart]);
    }

    public function clearCart(): void
    {
        $this->cart = [];
        session()->forget('pos.cart');
    }

    public function cartTotal(): float
    {
        return round(collect($this->cart)->sum('subtotal'), 2);
    }

    public function proceedToPayment(): void
    {
        if (empty($this->cart)) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => 'Your cart is empty. Please select products first.',
            ]);
            return;
        }

        session(['pos.cart' => $this->cart]);
        $this->redirectRoute('sales.checkout');
    }

    public function finalizeSale(): void
    {
        if (empty($this->cart) && $this->productId !== '') {
            $product = Product::with('inventory')->find((int) $this->productId);
            $available = (float) ($product?->inventory?->quantity ?? 0);
            if ((float) $this->quantity > $available) {
                $this->addError('productId', "Requested quantity exceeds available stock for {$product->name}.");
                return;
            }
            $this->addProduct((int) $this->productId);
            $this->updateCartQuantity('product:' . $this->productId, $this->quantity);
        }
        $this->checkout();
    }

    public function checkout(): void
    {
        if (empty($this->cart)) {
            $this->addError('cart', 'Add at least one item before checkout.');
            return;
        }

        $total = $this->cartTotal();

        DB::transaction(function () use ($total): void {
            $hasCustomMix = collect($this->cart)->contains('type', 'custom_mix');
            $sale = Sale::create([
                'user_id' => auth()->id(),
                'invoice_number' => 'SALE-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(4)),
                'sold_at' => now(),
                'type' => $hasCustomMix ? 'mixed' : 'normal',
                'subtotal' => $total,
                'discount_percentage' => 0,
                'discount_amount' => 0,
                'total' => $total,
                'payment_method' => 'cash',
                'payment_amount' => $total,
                'change_amount' => 0,
            ]);

            foreach ($this->cart as $line) {
                if ($line['type'] === 'custom_mix') {
                    $sale->items()->create([
                        'product_id' => null,
                        'description' => $line['description'] . ' (' . ($line['resulting_quantity'] ?? $line['quantity']) . ' ' . ($line['resulting_unit'] ?? 'L') . ')',
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'subtotal' => $line['subtotal'],
                    ]);

                    $mix = MixingTransaction::create([
                        'sale_id' => $sale->id,
                        'price_basis_product_id' => $line['basis_product_id'] ?? null,
                        'resulting_quantity' => $line['resulting_quantity'] ?? $line['quantity'],
                        'resulting_unit' => $line['resulting_unit'] ?? 'L',
                        'notes' => $line['notes'] ?? null,
                    ]);

                    foreach ($line['components'] as $component) {
                        $mix->components()->create([
                            'product_id' => $component['product_id'],
                            'estimated_quantity' => $component['estimated_quantity'],
                            'estimated_quantity_unit' => $component['estimated_quantity_unit'],
                        ]);
                    }
                    continue;
                }

                $product = Product::findOrFail($line['product_id']);
                $inventory = Inventory::where('product_id', $product->id)
                    ->lockForUpdate()
                    ->firstOrCreate(['product_id' => $product->id], ['quantity' => 0]);

                $before = (float) $inventory->quantity;
                if ($before < (float) $line['quantity']) {
                    throw ValidationException::withMessages([
                        'productId' => "Insufficient stock for {$product->name}.",
                    ]);
                }

                $inventory->decrement('quantity', (float) $line['quantity']);
                $after = $before - (float) $line['quantity'];

                $sale->items()->create([
                    'product_id' => $product->id,
                    'description' => $line['description'] . ' ' . ($line['package'] ?? ''),
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'subtotal' => $line['subtotal'],
                ]);

                InventoryMovement::create([
                    'product_id' => $product->id,
                    'user_id' => auth()->id(),
                    'type' => 'sale',
                    'quantity_change' => -(float) $line['quantity'],
                    'quantity_before' => $before,
                    'quantity_after' => $after,
                    'reference_type' => Sale::class,
                    'reference_id' => $sale->id,
                    'reference_text' => 'Invoice #' . $sale->invoice_number,
                ]);
            }

            AuditLog::create([
                'user_id' => auth()->id(),
                'event' => 'sale_completed',
                'auditable_type' => Sale::class,
                'auditable_id' => $sale->id,
                'context' => ['total' => $total, 'lines' => count($this->cart)],
            ]);
        });

        $this->cart = [];
        session()->forget('pos.cart');
    }

    // Custom Mix Methods
    public function openMixModal(): void
    {
        $this->resetMixForm();
        $this->showMixModal = true;
    }

    public function resetMixForm(): void
    {
        $this->showMixModal = false;
        $this->editingCartKey = null;
        $this->mixDescription = 'Custom mixed paint';
        $this->mixQuantity = '1';
        $this->mixResultUnit = 'L';
        $this->mixProductId = '';
        $this->mixEstimatedQuantity = '';
        $this->mixEstimatedQuantityUnit = 'L';
        $this->mixNotes = '';
        $this->mixComponents = [];
        $this->resetValidation();
    }

    public function addMixComponent(): void
    {
        $this->validate([
            'mixProductId' => ['required', 'exists:products,id'],
            'mixEstimatedQuantity' => ['required', 'numeric', 'gt:0'],
            'mixEstimatedQuantityUnit' => ['required', 'string', 'in:ml,L,gal'],
        ], [
            'mixProductId.required' => 'Select a component product.',
            'mixEstimatedQuantity.gt' => 'Estimated quantity must be greater than 0.',
        ]);

        $product = Product::with('packageUnit')->find($this->mixProductId);

        $this->mixComponents[] = [
            'product_id' => (int) $this->mixProductId,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'price' => (float) $product->selling_price,
            'estimated_quantity' => (float) $this->mixEstimatedQuantity,
            'estimated_quantity_unit' => $this->mixEstimatedQuantityUnit,
        ];

        $this->reset(['mixProductId', 'mixEstimatedQuantity']);
    }

    public function removeMixComponent(int $index): void
    {
        unset($this->mixComponents[$index]);
        $this->mixComponents = array_values($this->mixComponents);
    }

    public function currentBasisProduct(): ?array
    {
        if (empty($this->mixComponents)) {
            return null;
        }

        return collect($this->mixComponents)->sortByDesc('price')->first();
    }

    public function addMixToCart(): void
    {
        $this->validate([
            'mixDescription' => ['required', 'string', 'max:255'],
            'mixQuantity' => ['required', 'numeric', 'gt:0'],
            'mixResultUnit' => ['required', 'in:ml,L,gal'],
            'mixComponents' => ['required', 'array', 'min:1'],
        ], [
            'mixComponents.min' => 'You must add at least one component material to the mix.',
        ]);

        $basis = $this->currentBasisProduct();
        if (!$basis) {
            $this->addError('mixComponents', 'Failed to determine price basis.');
            return;
        }

        $resultingQty = (float) $this->mixQuantity;
        $unitPrice = (float) $basis['price'];
        $subtotal = round($resultingQty * $unitPrice, 2);

        $key = $this->editingCartKey ?: ('mix:' . Str::uuid());

        $this->cart[$key] = [
            'type' => 'custom_mix',
            'product_id' => null,
            'description' => trim($this->mixDescription),
            'package' => $resultingQty . ' ' . $this->mixResultUnit,
            'brand' => 'Custom Mix',
            'unit' => $this->mixResultUnit,
            'sku' => 'CUSTOM-MIX',
            'resulting_quantity' => $resultingQty,
            'resulting_unit' => $this->mixResultUnit,
            'quantity' => $resultingQty,
            'unit_price' => $unitPrice,
            'subtotal' => $subtotal,
            'basis_product_id' => $basis['product_id'],
            'basis_product_name' => $basis['product_name'],
            'components' => $this->mixComponents,
            'notes' => $this->mixNotes,
        ];

        session(['pos.cart' => $this->cart]);
        $this->resetMixForm();

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => 'Custom mix added to cart.',
        ]);
    }

    public function editMix(string $key): void
    {
        if (!isset($this->cart[$key]) || $this->cart[$key]['type'] !== 'custom_mix') {
            return;
        }

        $item = $this->cart[$key];
        $this->editingCartKey = $key;
        $this->mixDescription = $item['description'];
        $this->mixQuantity = (string) $item['resulting_quantity'];
        $this->mixResultUnit = $item['resulting_unit'] ?? 'L';
        $this->mixComponents = $item['components'] ?? [];
        $this->mixNotes = $item['notes'] ?? '';
        $this->showMixModal = true;
    }

    public function render(): mixed
    {
        $products = Product::query()
            ->with(['brand', 'category', 'packageUnit', 'inventory'])
            ->where('active', true)
            ->when($this->search, function ($q) {
                $q->where(function ($sub) {
                    $sub->where('name', 'like', '%' . $this->search . '%')
                        ->orWhere('sku', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->brandId, fn($q) => $q->where('brand_id', $this->brandId))
            ->when($this->categoryId, fn($q) => $q->where('category_id', $this->categoryId))
            ->when($this->packageUnitId, fn($q) => $q->where('package_unit_id', $this->packageUnitId))
            ->when($this->availableOnly, fn($q) => $q->whereHas('inventory', fn($iq) => $iq->where('quantity', '>', 0)))
            ->orderBy('name')
            ->paginate(15);

        return view('livewire.pages.sales.index', [
            'products' => $products,
            'brands' => Brand::where('active', true)->orderBy('name')->get(),
            'categories' => Category::where('active', true)->orderBy('name')->get(),
            'packageUnits' => PackageUnit::where('active', true)->orderBy('name')->get(),
            'stockMaterials' => Product::where('active', true)->orderBy('name')->get(),
            'currency' => Currency::class,
        ]);
    }
}; ?>

<div class="mx-auto max-w-[1700px] space-y-3 px-3 py-3 text-xs sm:px-5 lg:px-6">
    <!-- Top Action Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-2.5 border-b border-slate-300">
        <div>
            <h1 class="font-heading text-lg font-bold uppercase tracking-wider text-slate-900">Point of Sale</h1>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="openMixModal" type="button"
                class="inline-flex items-center gap-1.5 rounded bg-[#00a3cc] px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-[#008fb3] transition">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span>+ Custom Mix</span>
            </button>
            <button wire:click="toggleFilters" type="button"
                class="inline-flex items-center gap-1.5 rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50 transition">
                <svg class="h-4 w-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                <span>Filters</span>
                @if ($brandId || $categoryId || $packageUnitId || $availableOnly)
                    <span class="rounded border border-[#008fb3] text-[#008fb3] bg-transparent px-1 py-0.2 text-[10px] font-bold">Active</span>
                @endif
            </button>
        </div>
    </div>

    <!-- Main Workspace: Products Grid (Left) + Grid Cart (Right) -->
    <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_560px] lg:grid-cols-[minmax(0,1fr)_480px]">
        <!-- Product Catalog Section -->
        <section class="space-y-2.5">
            <!-- Search Input Bar -->
            <div class="rounded border border-slate-300 bg-white p-2.5 shadow-sm">
                <div class="relative">
                    <input wire:model.live.debounce.250ms="search" type="search" placeholder="Search product name or SKU..."
                        class="w-full rounded border-slate-300 pl-8 pr-3 py-1.5 text-xs text-slate-900 focus:border-slate-500 focus:ring-0" />
                    <svg class="absolute left-2.5 top-2 h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </div>
            </div>

            <!-- Active Filters Indicator Bar -->
            @if ($brandId || $categoryId || $packageUnitId || $availableOnly)
                <div class="flex items-center justify-between rounded border border-slate-300 bg-slate-50 px-3 py-1.5 text-xs text-slate-600">
                    <span class="font-medium">Filters active on catalog</span>
                    <button wire:click="resetFilters" type="button" class="text-xs text-rose-600 hover:underline font-medium">Clear All Filters</button>
                </div>
            @endif

            <!-- Product Cards Grid -->
            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                @forelse ($products as $product)
                    @php
                        $stock = (float) ($product->inventory?->quantity ?? 0);
                        $isLow = $stock <= (float) $product->low_stock_threshold;
                        $isOut = $stock <= 0;
                    @endphp
                    <button wire:click="addProduct({{ $product->id }})" type="button" wire:key="pos-prod-{{ $product->id }}"
                        @disabled($isOut)
                        class="rounded border border-slate-200 bg-white p-2.5 text-left shadow-sm transition hover:border-slate-400 hover:bg-slate-50/50 flex flex-col justify-between disabled:opacity-50 disabled:cursor-not-allowed group">
                        <div>
                            <div class="flex items-start justify-between gap-1.5">
                                <span class="font-bold text-xs text-slate-900 group-hover:text-slate-700 line-clamp-1">
                                    {{ $product->name }}
                                </span>
                                <span class="tabular-nums text-[10px] text-slate-500 shrink-0">
                                    {{ $product->sku }}
                                </span>
                            </div>
                            <p class="text-[11px] text-slate-500 mt-0.5 truncate">
                                {{ $product->brand?->name ?? 'Unbranded' }} · {{ $product->category?->name }}
                            </p>
                        </div>

                        <div class="mt-2.5 pt-2 border-t border-slate-100 flex items-center justify-between">
                            <div>
                                <span class="text-xs font-bold tabular-nums text-slate-900">{{ $currency::format($product->selling_price) }}</span>
                                <span class="text-[10px] text-slate-500 block">
                                    per {{ $product->package_size ? rtrim(rtrim((string) $product->package_size, '0'), '.') . ' ' : '' }}{{ $product->packageUnit?->abbreviation ?? 'unit' }}
                                </span>
                            </div>
                            <div>
                                @if ($isOut)
                                    <span class="rounded border border-rose-600 text-rose-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold">
                                        Out of stock
                                    </span>
                                @elseif ($isLow)
                                    <span class="rounded border border-amber-600 text-amber-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold tabular-nums">
                                        {{ number_format($stock, 2) }} left
                                    </span>
                                @else
                                    <span class="rounded border border-emerald-600 text-emerald-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold tabular-nums">
                                        {{ number_format($stock, 2) }} in stock
                                    </span>
                                @endif
                            </div>
                        </div>
                    </button>
                @empty
                    <div class="rounded border border-slate-200 bg-white p-8 text-center text-slate-500 sm:col-span-2 xl:col-span-3">
                        <p class="font-medium text-xs">No products match your current search or filters.</p>
                        <button wire:click="resetFilters" type="button" class="mt-2 text-xs text-slate-700 underline font-medium">Clear filters</button>
                    </div>
                @endforelse
            </div>

            <!-- Pagination -->
            <div>
                {{ $products->links() }}
            </div>
        </section>

        <!-- Right Side: Structured Grid-Based Cart -->
        <aside class="sticky top-3 rounded border border-slate-300 bg-white shadow-sm flex flex-col justify-between max-h-[85vh] overflow-hidden">
            <div class="p-2.5 border-b border-slate-300 bg-slate-100 flex items-center justify-between">
                <div>
                    <h2 class="font-heading font-bold text-xs uppercase tracking-wider text-slate-800">Current Cart</h2>
                    <span class="text-[11px] text-slate-500 tabular-nums">{{ count($cart) }} line item(s)</span>
                </div>
                @if (!empty($cart))
                    <button 
                        wire:click="clearCart" 
                        wire:confirm="Are you sure you want to clear all items from the current cart?"
                        type="button" 
                        class="rounded border border-slate-300 bg-white px-2 py-0.5 text-xs font-medium text-rose-700 hover:bg-rose-50 transition shadow-sm"
                    >
                        Clear Cart
                    </button>
                @endif
            </div>

            <!-- Structured Cart Items Table -->
            <div class="flex-1 overflow-y-auto">
                @if (!empty($cart))
                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse border border-slate-300 text-xs">
                            <thead class="bg-slate-100 uppercase text-[10px] font-semibold text-slate-700">
                                <tr>
                                    <th class="border border-slate-300 px-2 py-1.5 text-left">Item / SKU</th>
                                    <th class="border border-slate-300 px-1.5 py-1.5 text-left">Spec</th>
                                    <th class="border border-slate-300 px-1.5 py-1.5 text-right">Price</th>
                                    <th class="border border-slate-300 px-1.5 py-1.5 text-center w-28">Qty</th>
                                    <th class="border border-slate-300 px-2 py-1.5 text-right">Subtotal</th>
                                    <th class="border border-slate-300 px-1 py-1.5 text-center w-8"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($cart as $key => $line)
                                    <tr wire:key="cart-row-{{ $key }}" class="hover:bg-slate-50">
                                        <td class="border border-slate-200 px-2 py-1.5">
                                            <span class="font-bold text-slate-900 block line-clamp-1">{{ $line['description'] }}</span>
                                            <span class="tabular-nums text-[10px] text-slate-500 block">
                                                {{ $line['sku'] }}
                                            </span>
                                            @if ($line['type'] === 'custom_mix')
                                                <button wire:click="editMix('{{ $key }}')" type="button" class="text-[10px] text-purple-700 font-semibold hover:underline">
                                                    Formula: {{ $line['basis_product_name'] ?? 'Custom' }}
                                                </button>
                                            @endif
                                        </td>
                                        <td class="border border-slate-200 px-1.5 py-1.5 text-slate-600 whitespace-nowrap text-[11px]">
                                            {{ $line['package'] ?? $line['unit'] ?? '—' }}
                                        </td>
                                        <td class="border border-slate-200 px-1.5 py-1.5 text-right tabular-nums text-slate-700 whitespace-nowrap">
                                            {{ $currency::format($line['unit_price']) }}
                                        </td>
                                        <td class="border border-slate-200 px-1.5 py-1.5 text-center">
                                            <div class="inline-flex items-center border border-slate-300 rounded bg-white overflow-hidden shadow-xs">
                                                <button wire:click="decrementCart('{{ $key }}')" type="button"
                                                    class="px-1.5 py-0.5 text-xs text-slate-700 hover:bg-slate-100 font-bold">−</button>
                                                <input wire:change="updateCartQuantity('{{ $key }}', $event.target.value)"
                                                    value="{{ $line['quantity'] }}" type="number" step="0.001" min="0.001"
                                                    class="w-12 border-0 p-0 text-center tabular-nums text-xs font-bold text-slate-900 focus:ring-0" />
                                                <button wire:click="incrementCart('{{ $key }}')" type="button"
                                                    class="px-1.5 py-0.5 text-xs text-slate-700 hover:bg-slate-100 font-bold">+</button>
                                            </div>
                                        </td>
                                        <td class="border border-slate-200 px-2 py-1.5 text-right tabular-nums font-bold text-slate-900 whitespace-nowrap">
                                            {{ $currency::format($line['subtotal']) }}
                                        </td>
                                        <td class="border border-slate-200 px-1 py-1.5 text-center">
                                            <button wire:click="removeFromCart('{{ $key }}')" type="button"
                                                class="rounded border border-slate-300 bg-white p-1 text-slate-400 hover:border-rose-400 hover:text-rose-700 transition shadow-xs"
                                                title="Remove item">
                                                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="py-14 text-center text-slate-400 text-xs">
                        <svg class="h-8 w-8 mx-auto text-slate-300 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        <p class="font-medium text-slate-600">Cart is currently empty.</p>
                        <p class="text-[11px] text-slate-400 mt-0.5">Click any product card or create a Custom Mix to begin.</p>
                    </div>
                @endif
            </div>

            <!-- Cart Footer & Checkout Button -->
            <div class="p-3 border-t border-slate-300 bg-slate-100 space-y-2">
                <div class="flex items-center justify-between text-xs">
                    <span class="font-semibold uppercase tracking-wider text-slate-700">Subtotal:</span>
                    <span class="text-base font-black tabular-nums text-slate-900">{{ $currency::format($this->cartTotal()) }}</span>
                </div>

                <button wire:click="proceedToPayment" type="button"
                    @disabled(empty($cart))
                    class="w-full rounded bg-[#00a3cc] px-4 py-2.5 font-bold uppercase tracking-wider text-white shadow-sm hover:bg-[#008fb3] transition disabled:opacity-50 disabled:cursor-not-allowed text-xs">
                    Proceed to Payment &rarr;
                </button>
            </div>
        </aside>
    </div>

    <!-- Filter Modal Drawer -->
    @if ($showFilters)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded border border-slate-300 bg-white p-4 shadow-xl text-xs space-y-3">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <h3 class="font-heading font-bold text-sm uppercase tracking-wider text-slate-800">Filter POS Products</h3>
                    <button wire:click="toggleFilters" type="button" class="text-slate-400 hover:text-slate-700 font-bold text-base">&times;</button>
                </div>

                <div class="space-y-2.5">
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Brand</label>
                        <select wire:model.live="brandId" class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0">
                            <option value="">All Brands</option>
                            @foreach ($brands as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Category</label>
                        <select wire:model.live="categoryId" class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0">
                            <option value="">All Categories</option>
                            @foreach ($categories as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Package Unit</label>
                        <select wire:model.live="packageUnitId" class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0">
                            <option value="">All Package Units</option>
                            @foreach ($packageUnits as $u)
                                <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->abbreviation }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="pt-1">
                        <label class="inline-flex items-center gap-2 text-slate-800 font-medium">
                            <input wire:model.live="availableOnly" type="checkbox" class="rounded border-slate-300 text-slate-900 focus:ring-0">
                            <span>Show In-Stock Only</span>
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-between border-t border-slate-200 pt-3">
                    <button wire:click="resetFilters" type="button"
                        class="rounded border border-slate-300 bg-white px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50 transition shadow-sm">
                        Reset Filters
                    </button>
                    <button wire:click="toggleFilters" type="button"
                        class="rounded bg-[#00a3cc] px-4 py-1.5 font-semibold text-white hover:bg-[#008fb3] transition shadow-sm">
                        Apply &amp; Close
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Custom Mix Modal -->
    @if ($showMixModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-xs">
            <div class="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded border border-slate-300 bg-white p-5 shadow-2xl text-xs space-y-4">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <div>
                        <h2 class="font-heading text-base font-bold uppercase tracking-wider text-slate-900">
                            {{ $editingCartKey ? 'Edit Custom Paint Mixture' : 'Create Custom Paint Mixture' }}
                        </h2>
                    </div>
                    <button wire:click="resetMixForm" type="button" class="text-xl font-bold text-slate-400 hover:text-slate-700">&times;</button>
                </div>

                <!-- Form Fields -->
                <div class="space-y-3">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="mixDescription" class="block font-semibold text-slate-700 mb-1">Mixture Description</label>
                            <input wire:model="mixDescription" id="mixDescription" type="text" placeholder="e.g. Custom Auto Metallic Silver"
                                class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0" />
                            @error('mixDescription') <p class="text-rose-600 text-[11px]">{{ $message }}</p> @enderror
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label for="mixQuantity" class="block font-semibold text-slate-700 mb-1">Resulting Qty</label>
                                <input wire:model="mixQuantity" id="mixQuantity" type="number" step="0.001" min="0.001"
                                    class="w-full rounded border-slate-300 tabular-nums text-xs focus:border-slate-500 focus:ring-0" />
                                @error('mixQuantity') <p class="text-rose-600 text-[11px]">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="mixResultUnit" class="block font-semibold text-slate-700 mb-1">Resulting Unit</label>
                                <select wire:model="mixResultUnit" id="mixResultUnit" class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0">
                                    <option value="L">Liter (L)</option>
                                    <option value="ml">Milliliter (ml)</option>
                                    <option value="gal">Gallon (gal)</option>
                                </select>
                                @error('mixResultUnit') <p class="text-rose-600 text-[11px]">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>

                    <!-- Component Materials Section -->
                    <div class="rounded border border-slate-300 bg-slate-50 p-3 space-y-2">
                        <span class="block font-heading font-bold uppercase tracking-wider text-slate-800 text-[11px]">Mix Components (Estimated Usage)</span>
                        <p class="text-[10px] text-slate-500">Estimates are recorded for customer formula tracking. (Partial cans are reconciled during physical inventory).</p>

                        <div class="grid items-end gap-2 sm:grid-cols-[1fr_110px_100px_auto]">
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-600">Material Stock Product</label>
                                <select wire:model="mixProductId" class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0">
                                    <option value="">Select product SKU...</option>
                                    @foreach ($stockMaterials as $mat)
                                        <option value="{{ $mat->id }}">{{ $mat->sku }} · {{ $mat->name }} ({{ $currency::format($mat->selling_price) }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-600">Estimated Use</label>
                                <input wire:model="mixEstimatedQuantity" type="number" step="0.001" min="0.001" placeholder="Amount"
                                    class="w-full rounded border-slate-300 tabular-nums text-xs focus:border-slate-500 focus:ring-0" />
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-600">Unit</label>
                                <select wire:model="mixEstimatedQuantityUnit" class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0">
                                    <option value="L">L</option>
                                    <option value="ml">ml</option>
                                    <option value="gal">gal</option>
                                </select>
                            </div>
                            <button wire:click="addMixComponent" type="button"
                                class="rounded border border-slate-700 bg-slate-800 px-3 py-1.5 font-semibold text-white hover:bg-slate-900 transition shadow-sm">
                                Add Line
                            </button>
                        </div>
                        @error('mixProductId') <p class="text-rose-600 text-[11px]">{{ $message }}</p> @enderror
                        @error('mixEstimatedQuantity') <p class="text-rose-600 text-[11px]">{{ $message }}</p> @enderror
                        @error('mixComponents') <p class="text-rose-600 text-[11px]">{{ $message }}</p> @enderror

                        <!-- Grid Table of Added Components -->
                        <div class="mt-2 overflow-x-auto border border-slate-300 bg-white rounded">
                            <table class="w-full border-collapse border border-slate-300 text-xs">
                                <thead class="bg-slate-100 uppercase text-[10px] font-semibold text-slate-700">
                                    <tr>
                                        <th class="border border-slate-300 px-2.5 py-1.5 text-left">SKU</th>
                                        <th class="border border-slate-300 px-2.5 py-1.5 text-left">Material Product</th>
                                        <th class="border border-slate-300 px-2.5 py-1.5 text-right">Unit Price</th>
                                        <th class="border border-slate-300 px-2.5 py-1.5 text-right">Est. Quantity</th>
                                        <th class="border border-slate-300 px-2.5 py-1.5 text-center w-16">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($mixComponents as $idx => $comp)
                                        <tr class="hover:bg-slate-50">
                                            <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-600">
                                                {{ $comp['sku'] }}
                                            </td>
                                            <td class="border border-slate-200 px-2.5 py-1.5 font-medium text-slate-900">
                                                {{ $comp['product_name'] }}
                                            </td>
                                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-700">
                                                {{ $currency::format($comp['price']) }}
                                            </td>
                                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold text-slate-900">
                                                {{ number_format((float) $comp['estimated_quantity'], 3) }} {{ $comp['estimated_quantity_unit'] }}
                                            </td>
                                            <td class="border border-slate-200 px-2.5 py-1.5 text-center">
                                                <button wire:click="removeMixComponent({{ $idx }})" type="button"
                                                    class="rounded border border-slate-300 bg-white px-2 py-0.5 text-[11px] font-medium text-rose-700 hover:bg-rose-50 shadow-xs">
                                                    Remove
                                                </button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="border border-slate-200 py-3 text-center text-slate-400 italic">
                                                No component materials added yet.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Pricing Basis Box -->
                    @php $basis = $this->currentBasisProduct(); @endphp
                    @if ($basis)
                        <div class="rounded bg-slate-50 border border-slate-300 p-2.5 flex items-center justify-between">
                            <div>
                                <span class="font-bold text-slate-900 block">Pricing Formula Basis:</span>
                                <span class="text-[11px] text-slate-600">Highest value component: {{ $basis['product_name'] }} ({{ $currency::format($basis['price']) }})</span>
                            </div>
                            <div class="text-right">
                                <span class="text-[10px] text-slate-500 uppercase block">Price per Unit:</span>
                                <span class="font-bold tabular-nums text-slate-900 text-sm">{{ $currency::format($basis['price']) }}</span>
                            </div>
                        </div>
                    @endif

                    <!-- Formula Notes -->
                    <div>
                        <label for="mixNotes" class="block font-semibold text-slate-700 mb-1">Color Formula &amp; Mixing Notes (Optional)</label>
                        <textarea wire:model="mixNotes" id="mixNotes" rows="2" placeholder="e.g. 50% primer base + 250ml tinting black + 100ml thinner..."
                            class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0"></textarea>
                    </div>
                </div>

                <!-- Modal Actions -->
                <div class="pt-3 border-t border-slate-200 flex justify-end gap-2">
                    <button wire:click="resetMixForm" type="button" class="rounded border border-slate-300 bg-white px-3.5 py-1.5 font-medium text-slate-700 hover:bg-slate-50 transition shadow-sm">
                        Cancel
                    </button>
                    <button wire:click="addMixToCart" type="button"
                        class="rounded bg-[#00a3cc] px-4 py-1.5 font-semibold text-white hover:bg-[#008fb3] transition shadow-sm">
                        {{ $editingCartKey ? 'Update Mixture' : 'Add Mixture to Cart' }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

