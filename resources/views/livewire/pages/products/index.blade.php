<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\PackageUnit;
use App\Models\Product;
use App\Models\SaleItem;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public bool $showForm = false;
    public ?int $editingProductId = null;
    public string $sku = '';
    public string $name = '';
    public string $brandId = '';
    public string $categoryId = '';
    public string $newCategory = '';
    public string $packageUnitId = '';
    public string $packageSize = '';
    public string $packageUnit = '';
    public string $sellingPrice = '';
    public string $lowStockThreshold = '0';
    public string $manufacturerCode = '';

    public function saveProduct(): void
    {
        if ($this->categoryId === '' && $this->newCategory !== '') {
            $this->categoryId = (string) Category::firstOrCreate(['name' => trim($this->newCategory)])->id;
        }
        if ($this->packageUnitId === '' && $this->packageUnit !== '') {
            $this->packageUnitId = (string) PackageUnit::where('name', $this->packageUnit)->orWhere('abbreviation', $this->packageUnit)->value('id');
        }
        if ($this->packageUnitId === '') {
            $this->packageUnitId = (string) PackageUnit::where('active', true)->value('id');
        }

        $validated = $this->validate([
            'sku' => ['required', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($this->editingProductId)],
            'name' => ['required', 'string', 'max:255'],
            'brandId' => ['nullable', 'integer', Rule::exists('brands', 'id')],
            'categoryId' => ['required', 'integer', Rule::exists('categories', 'id')],
            'packageUnitId' => ['required', 'integer', Rule::exists('package_units', 'id')],
            'packageSize' => ['nullable', 'numeric', 'min:0'],
            'sellingPrice' => ['required', 'numeric', 'min:0'],
            'lowStockThreshold' => ['required', 'numeric', 'min:0'],
            'manufacturerCode' => ['nullable', 'string', 'max:100'],
        ]);

        $attributes = [
            'category_id' => $validated['categoryId'],
            'brand_id' => $validated['brandId'] ?: null,
            'package_unit_id' => $validated['packageUnitId'],
            'sku' => strtoupper(trim($validated['sku'])),
            'name' => trim($validated['name']),
            'package_size' => $validated['packageSize'] ?: null,
            'selling_price' => $validated['sellingPrice'],
            'low_stock_threshold' => $validated['lowStockThreshold'],
            'manufacturer_code' => $validated['manufacturerCode'] ?: null,
        ];

        $product = $this->editingProductId ? tap(Product::findOrFail($this->editingProductId))->update($attributes) : Product::create($attributes + ['active' => true]);

        if (!$this->editingProductId) {
            Inventory::create(['product_id' => $product->id, 'quantity' => 0]);
        }

        AuditLog::create(['user_id' => auth()->id(), 'event' => $this->editingProductId ? 'product_updated' : 'product_created', 'auditable_type' => Product::class, 'auditable_id' => $product->id, 'context' => ['sku' => $product->sku, 'name' => $product->name]]);

        $this->resetForm();
        session()->flash('status', 'Product saved successfully.');
    }

    public function createProduct(): void
    {
        $this->saveProduct();
    }

    public function editProduct(int $productId): void
    {
        $product = Product::findOrFail($productId);
        $this->editingProductId = $product->id;
        $this->showForm = true;
        $this->sku = $product->sku;
        $this->name = $product->name;
        $this->brandId = (string) ($product->brand_id ?? '');
        $this->categoryId = (string) $product->category_id;
        $this->packageUnitId = (string) ($product->package_unit_id ?? '');
        $this->packageSize = (string) ($product->package_size ?? '');
        $this->sellingPrice = (string) $product->selling_price;
        $this->lowStockThreshold = (string) $product->low_stock_threshold;
        $this->manufacturerCode = (string) ($product->manufacturer_code ?? '');
    }

    public function deleteProduct(int $productId): void
    {
        $product = Product::findOrFail($productId);
        if (SaleItem::where('product_id', $productId)->exists() || $product->inventoryMovements()->exists()) {
            $product->update(['active' => false]);
            AuditLog::create(['user_id' => auth()->id(), 'event' => 'product_deactivated', 'auditable_type' => Product::class, 'auditable_id' => $product->id, 'context' => ['reason' => 'historical references']]);
            session()->flash('status', 'Product has history and was deactivated instead of deleted.');
            return;
        }
        $product->delete();
        AuditLog::create(['user_id' => auth()->id(), 'event' => 'product_deleted', 'auditable_type' => Product::class, 'auditable_id' => $productId]);
        session()->flash('status', 'Product deleted successfully.');
    }

    public function toggleProductStatus(int $productId): void
    {
        $product = Product::findOrFail($productId);
        $product->update(['active' => !$product->active]);
        AuditLog::create(['user_id' => auth()->id(), 'event' => $product->active ? 'product_reactivated' : 'product_deactivated', 'auditable_type' => Product::class, 'auditable_id' => $product->id]);
        session()->flash('status', $product->active ? 'Product reactivated.' : 'Product deactivated.');
    }

    public function resetForm(): void
    {
        $this->reset(['showForm', 'editingProductId', 'sku', 'name', 'brandId', 'categoryId', 'newCategory', 'packageUnitId', 'packageSize', 'packageUnit', 'sellingPrice', 'manufacturerCode']);
        $this->lowStockThreshold = '0';
    }

    public function render(): mixed
    {
        $products = Product::query()
            ->with(['brand', 'category', 'packageUnit', 'inventory'])
            ->when($this->search, fn($query) => $query->where(fn($query) => $query->where('name', 'like', '%' . $this->search . '%')->orWhere('sku', 'like', '%' . $this->search . '%')))
            ->when($this->categoryId, fn($query) => $query->where('category_id', $this->categoryId))
            ->latest()
            ->paginate(15);

        return view('livewire.pages.products.index', [
            'products' => $products,
            'categories' => Category::query()->orderBy('name')->get(),
            'brands' => Brand::query()->orderBy('name')->get(),
            'packageUnits' => PackageUnit::query()->where('active', true)->orderBy('name')->get(),
        ]);
    }
}; ?>

<div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    @if (session('status'))
        <div class="rounded-md bg-green-50 p-4 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Product catalog</h1>
            <p class="mt-1 text-sm text-gray-600">Manage every sellable package as its own SKU.</p>
        </div>
        <button wire:click="$set('showForm', true)" type="button"
            class="rounded-md bg-[#00a3cc] px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-[#008fb3]">Add
            product</button>
    </div>

    <div class="flex flex-col gap-3 rounded-lg bg-white p-4 shadow-sm sm:flex-row">
        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search name or SKU"
            class="w-full rounded-md border-gray-300 sm:max-w-sm">
        <select wire:model.live="categoryId" class="rounded-md border-gray-300 sm:max-w-xs">
            <option value="">All categories</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}">{{ $category->name }}</option>
            @endforeach
        </select>
    </div>

    <div class="overflow-hidden rounded-lg bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-3 py-2">Product</th>
                        <th class="px-3 py-2">SKU</th>
                        <th class="px-3 py-2">Brand</th>
                        <th class="px-3 py-2">Category</th>
                        <th class="px-3 py-2">Package Unit</th>
                        <th class="px-3 py-2">Price</th>
                        <th class="px-3 py-2">Stock</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($products as $product)
                        <tr wire:key="product-{{ $product->id }}">
                            <td class="px-3 py-2 font-medium text-gray-900">{{ $product->name }}</td>
                            <td class="px-3 py-2 font-mono text-gray-600">{{ $product->sku }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $product->brand?->name ?? 'No brand' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $product->category->name }}</td>
                            <td class="px-3 py-2 text-gray-600">
                                {{ $product->package_size ? rtrim(rtrim((string) $product->package_size, '0'), '.') : '-' }}
                                {{ $product->packageUnit?->abbreviation }}</td>
                            <td class="px-3 py-2">{{ \App\Support\Currency::format($product->selling_price) }}</td>
                            <td
                                class="px-3 py-2 {{ $product->inventory && $product->inventory->quantity <= $product->low_stock_threshold ? 'font-semibold text-red-600' : 'text-gray-700' }}">
                                {{ $product->inventory?->quantity ?? 0 }}</td>
                            <td class="px-3 py-2"><span
                                    class="font-semibold {{ $product->active ? 'text-green-700' : 'text-gray-500' }}">{{ $product->active ? 'Active' : 'Deactivated' }}</span>
                            </td>
                            <td class="space-x-3 whitespace-nowrap px-3 py-2"><button
                                    wire:click="editProduct({{ $product->id }})" type="button"
                                    class="text-[#008fb3]">Edit</button><button
                                    wire:click="toggleProductStatus({{ $product->id }})"
                                    wire:confirm="{{ $product->active ? 'Deactivate this product?' : 'Reactivate this product?' }}"
                                    type="button"
                                    class="{{ $product->active ? 'text-red-600' : 'text-green-700' }}">{{ $product->active ? 'Deactivate' : 'Reactivate' }}</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-3 py-6 text-center text-gray-500">No products found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $products->links() }}</div>
    </div>

    @if ($showForm)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 px-4">
            <form wire:submit="saveProduct"
                class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-white p-6 shadow-xl">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900">
                        {{ $editingProductId ? 'Edit product' : 'Add product' }}</h2><button wire:click="resetForm"
                        type="button" class="text-gray-500">&times;</button>
                </div>
                <div class="mt-6 grid gap-4 sm:grid-cols-2">
                    <x-input-label for="sku" value="SKU" /><x-text-input wire:model="sku" id="sku"
                        required />
                    <x-input-label for="name" value="Product name" /><x-text-input wire:model="name" id="name"
                        required />
                    <x-input-label for="categoryId" value="Category" /><select wire:model="categoryId" id="categoryId"
                        class="rounded-md border-gray-300" required>
                        <option value="">Choose category</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                    <x-input-label for="brandId" value="Brand" /><select wire:model="brandId" id="brandId"
                        class="rounded-md border-gray-300">
                        <option value="">No brand</option>
                        @foreach ($brands as $brand)
                            <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                        @endforeach
                    </select>
                    <x-input-label for="packageSize" value="Package size" /><x-text-input wire:model="packageSize"
                        id="packageSize" type="number" step="0.001" />
                    <x-input-label for="packageUnitId" value="Package unit" /><select wire:model="packageUnitId"
                        id="packageUnitId" class="rounded-md border-gray-300" required>
                        <option value="">Choose package unit</option>
                        @foreach ($packageUnits as $packageUnit)
                            <option value="{{ $packageUnit->id }}">{{ $packageUnit->name }}
                                ({{ $packageUnit->abbreviation }})
                            </option>
                        @endforeach
                    </select>
                    <x-input-label for="sellingPrice" value="Selling price" /><x-text-input wire:model="sellingPrice"
                        id="sellingPrice" type="number" step="0.01" required />
                    <x-input-label for="lowStockThreshold" value="Low-stock threshold" /><x-text-input
                        wire:model="lowStockThreshold" id="lowStockThreshold" type="number" step="0.001"
                        required />
                </div>
                <div class="mt-6 flex justify-end gap-3"><button wire:click="resetForm" type="button"
                        class="rounded-md border px-4 py-2 text-sm">Cancel</button><x-primary-button>{{ $editingProductId ? 'Save changes' : 'Create product' }}</x-primary-button>
                </div>
            </form>
        </div>
    @endif
</div>
