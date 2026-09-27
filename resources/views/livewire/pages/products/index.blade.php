<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\PackageUnit;
use App\Models\Product;
use App\Models\SaleItem;
use App\Support\Currency;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $brandId = '';
    public string $categoryId = '';
    public string $packageUnitFilterId = '';
    public string $statusFilter = 'active'; // 'all', 'active', 'deactivated'
    public string $stockFilter = ''; // '', 'low', 'out', 'healthy'
    public bool $sidebarOpen = true;

    // Form modal state
    public bool $showForm = false;
    public ?int $editingProductId = null;
    public string $sku = '';
    public string $name = '';
    public string $brandIdInput = '';
    public string $categoryIdInput = '';
    public string $newCategory = '';
    public string $packageUnitId = '';
    public string $packageSize = '';
    public string $packageUnit = '';
    public string $sellingPrice = '';
    public string $lowStockThreshold = '5';
    public string $manufacturerCode = '';
    public string $description = '';

    // Confirmation modal state
    public ?int $productToToggle = null;
    public ?string $toggleActionType = null; // 'deactivate' or 'reactivate'

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

    public function updatedPackageUnitFilterId(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStockFilter(): void
    {
        $this->resetPage();
    }

    public function selectBrand(string $id): void
    {
        $this->brandId = $id;
        $this->resetPage();
    }

    public function toggleSidebar(): void
    {
        $this->sidebarOpen = !$this->sidebarOpen;
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'brandId', 'categoryId', 'packageUnitFilterId', 'stockFilter']);
        $this->statusFilter = 'active';
        $this->resetPage();
    }

    public function generateSku(): void
    {
        $prefix = 'PROD';
        if ($this->categoryIdInput !== '') {
            $cat = Category::find($this->categoryIdInput);
            if ($cat) {
                $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $cat->name), 0, 4));
            }
        }
        $random = strtoupper(Str::random(4));
        $this->sku = $prefix . '-' . now()->format('ymd') . '-' . $random;
    }

    public function openCreateModal(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function editProduct(int $productId): void
    {
        $product = Product::findOrFail($productId);
        $this->editingProductId = $product->id;
        $this->sku = $product->sku;
        $this->name = $product->name;
        $this->brandIdInput = (string) ($product->brand_id ?? '');
        $this->categoryIdInput = (string) $product->category_id;
        $this->packageUnitId = (string) ($product->package_unit_id ?? '');
        $this->packageSize = (string) ($product->package_size ?? '');
        $this->sellingPrice = (string) $product->selling_price;
        $this->lowStockThreshold = (string) $product->low_stock_threshold;
        $this->manufacturerCode = (string) ($product->manufacturer_code ?? '');
        $this->description = (string) ($product->description ?? '');
        $this->showForm = true;
    }

    public function saveProduct(): void
    {
        // Category resolution
        if ($this->categoryIdInput === '' && $this->newCategory !== '') {
            $this->categoryIdInput = (string) Category::firstOrCreate(['name' => trim($this->newCategory)])->id;
        }

        // Package unit resolution
        if ($this->packageUnitId === '' && $this->packageUnit !== '') {
            $unit = PackageUnit::where('name', $this->packageUnit)
                ->orWhere('abbreviation', $this->packageUnit)
                ->first();
            if (!$unit) {
                $unit = PackageUnit::create([
                    'name' => trim($this->packageUnit),
                    'abbreviation' => substr(trim($this->packageUnit), 0, 5),
                    'active' => true,
                ]);
            }
            $this->packageUnitId = (string) $unit->id;
        }

        if ($this->packageUnitId === '') {
            $defaultUnit = PackageUnit::where('active', true)->first();
            if (!$defaultUnit) {
                $defaultUnit = PackageUnit::create(['name' => 'Piece', 'abbreviation' => 'pc', 'active' => true]);
            }
            $this->packageUnitId = (string) $defaultUnit->id;
        }

        $validated = $this->validate([
            'sku' => ['required', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($this->editingProductId)],
            'name' => ['required', 'string', 'max:255'],
            'categoryIdInput' => ['required', 'integer', Rule::exists('categories', 'id')],
            'brandIdInput' => ['nullable', 'integer', Rule::exists('brands', 'id')],
            'packageUnitId' => ['required', 'integer', Rule::exists('package_units', 'id')],
            'packageSize' => ['nullable', 'numeric', 'min:0'],
            'sellingPrice' => ['required', 'numeric', 'min:0'],
            'lowStockThreshold' => ['required', 'numeric', 'min:0'],
            'manufacturerCode' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [
            'categoryIdInput.required' => 'Please select a product category.',
            'packageUnitId.required' => 'Please select a package unit.',
            'sellingPrice.min' => 'Selling price cannot be negative.',
            'lowStockThreshold.min' => 'Threshold cannot be negative.',
        ]);

        $attributes = [
            'category_id' => $validated['categoryIdInput'],
            'brand_id' => $validated['brandIdInput'] ?: null,
            'package_unit_id' => $validated['packageUnitId'],
            'sku' => strtoupper(trim($validated['sku'])),
            'name' => trim($validated['name']),
            'package_size' => $validated['packageSize'] ?: null,
            'selling_price' => $validated['sellingPrice'],
            'low_stock_threshold' => $validated['lowStockThreshold'],
            'manufacturerCode' => $validated['manufacturerCode'] ?: null,
            'description' => $validated['description'] ?: null,
        ];

        if ($this->editingProductId) {
            $product = Product::findOrFail($this->editingProductId);
            $product->update($attributes);
            $event = 'product_updated';
            $msg = 'Product updated successfully.';
        } else {
            $product = Product::create($attributes + ['active' => true]);
            Inventory::firstOrCreate(['product_id' => $product->id], ['quantity' => 0]);
            $event = 'product_created';
            $msg = 'Product created successfully with initial stock of 0.';
        }

        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => $event,
            'auditable_type' => Product::class,
            'auditable_id' => $product->id,
            'context' => [
                'sku' => $product->sku,
                'name' => $product->name,
                'price' => $product->selling_price,
            ],
        ]);

        $this->resetForm();
        $this->dispatch('toast', ['type' => 'success', 'message' => $msg]);
    }

    public function createProduct(): void
    {
        $this->saveProduct();
    }

    public function confirmToggleStatus(int $productId): void
    {
        $product = Product::findOrFail($productId);
        $this->productToToggle = $product->id;
        $this->toggleActionType = $product->active ? 'deactivate' : 'reactivate';
    }

    public function cancelToggleStatus(): void
    {
        $this->productToToggle = null;
        $this->toggleActionType = null;
    }

    public function executeToggleStatus(): void
    {
        if (!$this->productToToggle) {
            return;
        }

        $product = Product::findOrFail($this->productToToggle);
        $newStatus = !$product->active;
        $product->update(['active' => $newStatus]);

        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => $newStatus ? 'product_reactivated' : 'product_deactivated',
            'auditable_type' => Product::class,
            'auditable_id' => $product->id,
            'context' => ['sku' => $product->sku, 'name' => $product->name],
        ]);

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => $newStatus ? 'Product reactivated.' : 'Product deactivated.',
        ]);

        $this->cancelToggleStatus();
    }

    public function resetForm(): void
    {
        $this->showForm = false;
        $this->editingProductId = null;
        $this->sku = '';
        $this->name = '';
        $this->brandIdInput = '';
        $this->categoryIdInput = '';
        $this->newCategory = '';
        $this->packageUnitId = '';
        $this->packageSize = '';
        $this->packageUnit = '';
        $this->sellingPrice = '';
        $this->lowStockThreshold = '5';
        $this->manufacturerCode = '';
        $this->description = '';
        $this->resetValidation();
    }

    public function render(): mixed
    {
        $query = Product::query()
            ->with(['brand', 'category', 'packageUnit', 'inventory'])
            ->when($this->search !== '', function ($q) {
                $term = '%' . trim($this->search) . '%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('sku', 'like', $term)
                        ->orWhere('manufacturer_code', 'like', $term);
                });
            })
            ->when($this->brandId !== '', fn ($q) => $q->where('brand_id', $this->brandId))
            ->when($this->categoryId !== '', fn ($q) => $q->where('category_id', $this->categoryId))
            ->when($this->packageUnitFilterId !== '', fn ($q) => $q->where('package_unit_id', $this->packageUnitFilterId));

        if ($this->statusFilter === 'active') {
            $query->where('active', true);
        } elseif ($this->statusFilter === 'deactivated') {
            $query->where('active', false);
        }

        if ($this->stockFilter === 'low') {
            $query->whereHas('inventory', function ($q) {
                $q->whereRaw('inventories.quantity > 0 AND inventories.quantity <= products.low_stock_threshold');
            });
        } elseif ($this->stockFilter === 'out') {
            $query->where(function ($q) {
                $q->whereDoesntHave('inventory')
                    ->orWhereHas('inventory', fn ($sub) => $sub->where('quantity', '<=', 0));
            });
        } elseif ($this->stockFilter === 'healthy') {
            $query->whereHas('inventory', function ($q) {
                $q->whereRaw('inventories.quantity > products.low_stock_threshold');
            });
        }

        $allActiveCount = Product::where('active', true)->count();
        $brandList = Brand::where('active', true)->withCount('products')->orderBy('name')->get();

        return view('livewire.pages.products.index', [
            'products' => $query->orderBy('name')->paginate(20),
            'brands' => $brandList,
            'categories' => Category::where('active', true)->orderBy('name')->get(),
            'packageUnits' => PackageUnit::where('active', true)->orderBy('name')->get(),
            'currency' => Currency::class,
            'productToToggleModel' => $this->productToToggle ? Product::find($this->productToToggle) : null,
            'allActiveCount' => $allActiveCount,
        ]);
    }
}; ?>

<div class="space-y-4 w-full min-w-0">
    <!-- Header -->
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between border-b border-slate-300 pb-3">
        <div>
            <h1 class="font-heading text-xl font-bold tracking-tight text-slate-900">Products</h1>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="toggleSidebar" type="button" class="inline-flex items-center px-2.5 py-1.5 rounded border border-slate-300 bg-white text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-sm transition">
                <svg class="mr-1 h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                </svg>
                {{ $sidebarOpen ? 'Hide Filters' : 'Show Filters' }}
            </button>
            <button wire:click="openCreateModal" type="button" class="inline-flex items-center px-3 py-1.5 rounded bg-[#00a3cc] text-white text-xs font-semibold hover:bg-[#008fb3] shadow-sm transition">
                <svg class="mr-1 h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                New Product
            </button>
        </div>
    </div>

    <!-- Brand Filter Bar -->
    <div class="border border-slate-300 bg-white p-2.5 rounded shadow-sm">
        <div class="flex items-center gap-2 flex-wrap">
            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500 shrink-0">Brand:</span>
            <button wire:click="selectBrand('')" type="button"
                    class="px-2.5 py-1 rounded text-xs font-semibold transition border {{ $brandId === '' ? 'border-[#00a3cc] bg-[#00a3cc] text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                All Brands ({{ $allActiveCount }})
            </button>
            @foreach ($brands as $b)
                <button wire:click="selectBrand('{{ $b->id }}')" type="button"
                        class="px-2.5 py-1 rounded text-xs font-semibold transition border {{ $brandId == (string)$b->id ? 'border-[#00a3cc] bg-[#00a3cc] text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                    {{ $b->name }} <span class="font-normal text-[11px] opacity-75">({{ $b->products_count }})</span>
                </button>
            @endforeach
        </div>
    </div>

    <!-- Main Workspace with Dedicated Filter Sidebar & Grid Table -->
    <div class="flex gap-4 items-start">
        <!-- Dedicated Filter Sidebar (Collapsible) -->
        @if ($sidebarOpen)
            <div class="w-64 shrink-0 border border-slate-300 bg-white p-3 rounded shadow-sm space-y-3">
                <div class="flex items-center justify-between border-b border-slate-200 pb-1.5">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-700">Filter Products</span>
                    <button wire:click="resetFilters" type="button" class="text-[11px] text-slate-500 hover:text-slate-800 underline">
                        Reset All
                    </button>
                </div>

                <!-- Search -->
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 mb-1">Search Name / SKU</label>
                    <input wire:model.live.debounce.300ms="search" type="text" placeholder="Type keyword..."
                           class="w-full rounded border-slate-300 text-xs py-1 px-2 focus:border-[#008fb3] focus:ring-[#008fb3]" />
                </div>

                <!-- Category -->
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 mb-1">Category</label>
                    <select wire:model.live="categoryId" class="w-full rounded border-slate-300 text-xs py-1 px-2 focus:border-[#008fb3] focus:ring-[#008fb3]">
                        <option value="">All Categories</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Package Unit -->
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 mb-1">Package Unit</label>
                    <select wire:model.live="packageUnitFilterId" class="w-full rounded border-slate-300 text-xs py-1 px-2 focus:border-[#008fb3] focus:ring-[#008fb3]">
                        <option value="">All Units</option>
                        @foreach ($packageUnits as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->abbreviation }})</option>
                        @endforeach
                    </select>
                </div>

                <!-- Status Filter -->
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 mb-1">Status</label>
                    <select wire:model.live="statusFilter" class="w-full rounded border-slate-300 text-xs py-1 px-2 focus:border-[#008fb3] focus:ring-[#008fb3]">
                        <option value="active">Active Only</option>
                        <option value="deactivated">Deactivated Only</option>
                        <option value="all">All Products</option>
                    </select>
                </div>

                <!-- Stock Level -->
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 mb-1">Stock Level</label>
                    <select wire:model.live="stockFilter" class="w-full rounded border-slate-300 text-xs py-1 px-2 focus:border-[#008fb3] focus:ring-[#008fb3]">
                        <option value="">Any Stock Level</option>
                        <option value="healthy">Healthy Stock</option>
                        <option value="low">Low Stock (&le; threshold)</option>
                        <option value="out">Out of Stock (0 stock)</option>
                    </select>
                </div>
            </div>
        @endif

        <!-- Grid-Based Products Table -->
        <div class="flex-1 min-w-0 space-y-2">
            <!-- Search bar if sidebar is closed -->
            @if (!$sidebarOpen)
                <div class="flex items-center gap-2">
                    <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search product name, SKU, or manufacturer code..."
                           class="w-full max-w-md rounded border-slate-300 text-xs py-1.5 px-3 focus:border-[#008fb3] focus:ring-[#008fb3]" />
                </div>
            @endif

            <!-- Table with explicit visible vertical and horizontal grid lines -->
            <div class="overflow-x-auto border border-slate-300 bg-white shadow-sm">
                <table class="min-w-full border-collapse border border-slate-300 text-xs">
                    <thead class="bg-slate-100 font-semibold uppercase tracking-wider text-slate-700">
                        <tr>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-left w-28">Brand</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-left">Product Name</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-left w-28">SKU</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-left w-28">Category</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-center w-20">Unit</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-right w-24">Selling Price</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-right w-24">Current Stock</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-right w-20">Threshold</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-center w-24">Status</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-center w-36">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @forelse ($products as $product)
                            @php
                                $qty = (float) ($product->inventory?->quantity ?? 0);
                                $threshold = (float) $product->low_stock_threshold;
                                $isOut = $qty <= 0;
                                $isLow = $qty <= $threshold;
                            @endphp
                            <tr class="hover:bg-slate-50 transition-colors">
                                <!-- Brand -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">
                                    {{ $product->brand?->name ?? '—' }}
                                </td>

                                <!-- Product Name -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-slate-900 font-medium min-w-[220px] max-w-md break-words whitespace-normal" title="{{ $product->name }}">
                                    {{ $product->name }}
                                </td>

                                <!-- SKU -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap tabular-nums">
                                    {{ $product->sku }}
                                </td>

                                <!-- Category -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">
                                    {{ $product->category?->name ?? '—' }}
                                </td>

                                <!-- Package Unit -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-center text-slate-700 whitespace-nowrap">
                                    {{ $product->packageUnit?->abbreviation ?? $product->packageUnit?->name ?? '—' }}
                                </td>

                                <!-- Selling Price -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right text-slate-900 font-semibold tabular-nums whitespace-nowrap">
                                    {{ $currency::format($product->selling_price) }}
                                </td>

                                <!-- Current Stock -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums whitespace-nowrap font-bold {{ $isOut ? 'text-rose-700' : ($isLow ? 'text-amber-700' : 'text-slate-900') }}">
                                    {{ number_format($qty, 3) }}
                                </td>

                                <!-- Threshold -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right text-slate-500 tabular-nums whitespace-nowrap">
                                    {{ number_format($threshold, 3) }}
                                </td>

                                <!-- Status: colored border + colored text + transparent background, minimal rounding -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                    @if ($product->active)
                                        <span class="inline-flex px-1.5 py-0.5 rounded border border-emerald-600 text-emerald-700 bg-transparent text-[10px] font-bold">
                                            Active
                                        </span>
                                    @else
                                        <span class="inline-flex px-1.5 py-0.5 rounded border border-slate-400 text-slate-600 bg-transparent text-[10px] font-bold">
                                            Deactivated
                                        </span>
                                    @endif
                                </td>

                                <!-- Actions: Real buttons -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap space-x-1">
                                    <button wire:click="editProduct({{ $product->id }})" type="button"
                                            class="inline-flex items-center px-2 py-0.5 rounded border border-slate-300 bg-white text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-sm transition">
                                        Edit
                                    </button>
                                    <button wire:click="confirmToggleStatus({{ $product->id }})" type="button"
                                            class="inline-flex items-center px-2 py-0.5 rounded border text-xs font-medium shadow-sm transition {{ $product->active ? 'border-rose-300 bg-white text-rose-700 hover:bg-rose-50' : 'border-emerald-300 bg-white text-emerald-700 hover:bg-emerald-50' }}">
                                        {{ $product->active ? 'Deactivate' : 'Reactivate' }}
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="border border-slate-200 px-4 py-8 text-center text-slate-500">
                                    No products found matching current criteria.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($products->hasPages())
                <div class="border border-slate-300 bg-white p-2.5 rounded shadow-sm">
                    {{ $products->links() }}
                </div>
            @endif
        </div>
    </div>

    <!-- Product Create/Edit Modal with Structured Labels -->
    @if ($showForm)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4">
            <div class="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded bg-white p-6 shadow-2xl border border-slate-300 text-xs">
                <div class="flex items-center justify-between border-b border-slate-200 pb-3">
                    <h2 class="font-heading text-sm font-bold text-slate-900 uppercase tracking-wide">
                        {{ $editingProductId ? 'Edit Product SKU' : 'Create New Product SKU' }}
                    </h2>
                    <button wire:click="resetForm" type="button" class="text-xl font-bold text-slate-400 hover:text-slate-700 leading-none">&times;</button>
                </div>

                <form wire:submit="saveProduct" class="mt-4 space-y-5">
                    <!-- Section 1: Identification -->
                    <div class="space-y-3">
                        <h3 class="text-[11px] font-bold uppercase tracking-wider text-slate-500">1. Basic Identification</h3>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="name" class="block font-semibold text-slate-700 mb-1">
                                    Product Display Name <span class="text-rose-600">*</span>
                                </label>
                                <input wire:model="name" id="name" type="text" placeholder="e.g. Boysen Permacoat Semi-Gloss"
                                       class="w-full rounded border-slate-300 text-xs py-2 px-2.5 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                                @error('name') <p class="text-rose-600 text-[11px] mt-0.5">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label for="sku" class="block font-semibold text-slate-700">
                                        Product SKU <span class="text-rose-600">*</span>
                                    </label>
                                    <button wire:click.prevent="generateSku" type="button" class="text-[10px] text-slate-600 hover:underline font-semibold">
                                        Auto-Generate
                                    </button>
                                </div>
                                <input wire:model="sku" id="sku" type="text" placeholder="e.g. BOY-SEMI-4L"
                                       class="w-full rounded border-slate-300 text-xs py-2 px-2.5 font-mono uppercase focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                                @error('sku') <p class="text-rose-600 text-[11px] mt-0.5">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div>
                            <label for="manufacturerCode" class="block font-semibold text-slate-700 mb-1">Manufacturer Code / Color Code</label>
                            <input wire:model="manufacturerCode" id="manufacturerCode" type="text" placeholder="e.g. B-701"
                                   class="w-full rounded border-slate-300 text-xs py-2 px-2.5 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                        </div>
                    </div>

                    <div class="border-t border-slate-200"></div>

                    <!-- Section 2: Classification & Packaging -->
                    <div class="space-y-3">
                        <h3 class="text-[11px] font-bold uppercase tracking-wider text-slate-500">2. Classification & Packaging</h3>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="brandIdInput" class="block font-semibold text-slate-700 mb-1">Brand</label>
                                <select wire:model="brandIdInput" id="brandIdInput" class="w-full rounded border-slate-300 text-xs py-2 px-2.5 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                                    <option value="">-- Choose Brand --</option>
                                    @foreach ($brands as $b)
                                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label for="categoryIdInput" class="block font-semibold text-slate-700 mb-1">
                                    Category <span class="text-rose-600">*</span>
                                </label>
                                <select wire:model="categoryIdInput" id="categoryIdInput" class="w-full rounded border-slate-300 text-xs py-2 px-2.5 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                                    <option value="">-- Choose Category --</option>
                                    @foreach ($categories as $cat)
                                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                    @endforeach
                                </select>
                                @error('categoryIdInput') <p class="text-rose-600 text-[11px] mt-0.5">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="packageUnitId" class="block font-semibold text-slate-700 mb-1">
                                    Package Unit <span class="text-rose-600">*</span>
                                </label>
                                <select wire:model="packageUnitId" id="packageUnitId" class="w-full rounded border-slate-300 text-xs py-2 px-2.5 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                                    @foreach ($packageUnits as $unit)
                                        <option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->abbreviation }})</option>
                                    @endforeach
                                </select>
                                @error('packageUnitId') <p class="text-rose-600 text-[11px] mt-0.5">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="packageSize" class="block font-semibold text-slate-700 mb-1">Package Size</label>
                                <input wire:model="packageSize" id="packageSize" type="number" step="0.001" placeholder="e.g. 4.0"
                                       class="w-full rounded border-slate-300 text-xs py-2 px-2.5 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-slate-200"></div>

                    <!-- Section 3: Pricing & Thresholds -->
                    <div class="space-y-3">
                        <h3 class="text-[11px] font-bold uppercase tracking-wider text-slate-500">3. Pricing & Inventory Controls</h3>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="sellingPrice" class="block font-semibold text-slate-700 mb-1">
                                    Retail Selling Price (₱) <span class="text-rose-600">*</span>
                                </label>
                                <input wire:model="sellingPrice" id="sellingPrice" type="number" step="0.01" min="0" placeholder="0.00"
                                       class="w-full rounded border-slate-300 text-xs py-2 px-2.5 tabular-nums focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                                @error('sellingPrice') <p class="text-rose-600 text-[11px] mt-0.5">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="lowStockThreshold" class="block font-semibold text-slate-700 mb-1">Low Stock Threshold</label>
                                <input wire:model="lowStockThreshold" id="lowStockThreshold" type="number" step="0.001" min="0"
                                       class="w-full rounded border-slate-300 text-xs py-2 px-2.5 tabular-nums focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-slate-200 pt-3 flex justify-end gap-2">
                        <button wire:click="resetForm" type="button" class="px-3.5 py-1.5 rounded border border-slate-300 bg-white text-xs font-medium text-slate-700 hover:bg-slate-50 transition shadow-xs">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-1.5 rounded bg-[#00a3cc] text-white text-xs font-semibold hover:bg-[#008fb3] transition shadow-xs">
                            {{ $editingProductId ? 'Save Changes' : 'Create Product' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Status Toggle Confirmation Modal -->
    @if ($productToToggle && $productToToggleModel)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4">
            <div class="w-full max-w-md rounded bg-white p-5 shadow-2xl border border-slate-300 text-xs space-y-3">
                <div class="flex items-center gap-2">
                    <span class="font-bold text-sm text-slate-900 uppercase">
                        Confirm {{ ucfirst($toggleActionType) }}
                    </span>
                </div>
                <p class="text-slate-700">
                    Are you sure you want to {{ $toggleActionType }} product:
                    <span class="font-semibold text-slate-900">{{ $productToToggleModel->name }}</span>
                    (<span class="font-mono">{{ $productToToggleModel->sku }}</span>)?
                </p>
                <div class="border-t border-slate-200 pt-3 flex justify-end gap-2">
                    <button wire:click="cancelToggleStatus" type="button" class="px-3 py-1.5 rounded border border-slate-300 bg-white text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-sm">
                        Cancel
                    </button>
                    <button wire:click="executeToggleStatus" type="button" class="px-3 py-1.5 rounded text-xs font-semibold text-white shadow-sm {{ $toggleActionType === 'deactivate' ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700' }}">
                        Confirm {{ ucfirst($toggleActionType) }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
