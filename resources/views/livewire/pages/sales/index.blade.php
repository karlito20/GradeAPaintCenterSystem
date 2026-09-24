<?php

use App\Models\Brand;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\MixingTransaction;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $brandId = '';
    public string $categoryId = '';
    public string $packageUnit = '';
    public bool $availableOnly = true;
    public array $cart = [];
    public string $productId = '';
    public string $quantity = '1';
    public bool $showMixPanel = false;
    public string $mixDescription = 'Custom paint mix';
    public string $mixQuantity = '1';
    public string $mixResultUnit = '';
    public string $mixProductId = '';
    public string $mixEstimatedQuantity = '';
    public string $mixEstimatedQuantityUnit = '';
    public string $mixNotes = '';
    public array $mixComponents = [];

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
    public function updatedPackageUnit(): void
    {
        $this->resetPage();
    }
    public function updatedAvailableOnly(): void
    {
        $this->resetPage();
    }

    public function addProduct(int $productId): void
    {
        $product = Product::with(['inventory', 'packageUnit'])
            ->where('active', true)
            ->findOrFail($productId);
        $key = 'product:' . $product->id;
        $quantity = (float) ($this->cart[$key]['quantity'] ?? 0) + 1;
        $available = (float) ($product->inventory?->quantity ?? 0);
        if ($quantity > $available) {
            $this->addError('cart', "Only {$available} package-equivalents of {$product->name} are available.");
            return;
        }
        $this->cart[$key] = ['type' => 'normal', 'product_id' => $product->id, 'description' => $product->name, 'package' => trim($product->package_size . ' ' . $product->packageUnit?->abbreviation), 'sku' => $product->sku, 'quantity' => $quantity, 'unit_price' => (float) $product->selling_price, 'subtotal' => $quantity * (float) $product->selling_price];
    }

    public function updateCartQuantity(string $key, string $value): bool
    {
        if (!isset($this->cart[$key])) {
            return false;
        }
        $quantity = (float) $value;
        if ($quantity <= 0) {
            unset($this->cart[$key]);
            return true;
        }
        if ($this->cart[$key]['type'] === 'normal' && $quantity > (float) (Product::with('inventory')->find($this->cart[$key]['product_id'])?->inventory?->quantity ?? 0)) {
            $this->addError('cart', 'Requested quantity exceeds available stock.');
            $this->addError('productId', 'Requested quantity exceeds available stock.');
            return false;
        }
        $this->cart[$key]['quantity'] = $quantity;
        $this->cart[$key]['subtotal'] = $quantity * (float) $this->cart[$key]['unit_price'];
        return true;
    }

    public function incrementCart(string $key): void
    {
        $this->updateCartQuantity($key, (string) ((float) $this->cart[$key]['quantity'] + 1));
    }
    public function decrementCart(string $key): void
    {
        $this->updateCartQuantity($key, (string) ((float) $this->cart[$key]['quantity'] - 1));
    }
    public function removeFromCart(string $key): void
    {
        unset($this->cart[$key]);
    }
    public function openMixPanel(): void
    {
        $this->showMixPanel = true;
    }

    public function addMixComponent(): void
    {
        if ($this->mixEstimatedQuantityUnit === '' && $this->mixProductId !== '') {
            $this->mixEstimatedQuantityUnit = Product::with('packageUnit')->find($this->mixProductId)?->packageUnit?->abbreviation ?: 'package-equivalent';
        }
        $validated = $this->validate(['mixProductId' => ['required', 'exists:products,id'], 'mixEstimatedQuantity' => ['required', 'numeric', 'gt:0'], 'mixEstimatedQuantityUnit' => ['required', 'string', 'max:30']]);
        $this->mixComponents[] = ['product_id' => (int) $validated['mixProductId'], 'estimated_quantity' => (float) $validated['mixEstimatedQuantity'], 'estimated_quantity_unit' => $validated['mixEstimatedQuantityUnit']];
        $this->reset(['mixProductId', 'mixEstimatedQuantity', 'mixEstimatedQuantityUnit']);
    }

    public function removeMixComponent(int $index): void
    {
        unset($this->mixComponents[$index]);
        $this->mixComponents = array_values($this->mixComponents);
    }

    public function addMixToCart(): void
    {
        if ($this->mixResultUnit === '') {
            $this->mixResultUnit = 'L';
        }
        $this->validate(['mixDescription' => ['required', 'string', 'max:255'], 'mixQuantity' => ['required', 'numeric', 'gt:0'], 'mixResultUnit' => ['required', 'in:ml,L,gal'], 'mixComponents' => ['required', 'array', 'min:1'], 'mixComponents.*.product_id' => ['required', 'exists:products,id'], 'mixComponents.*.estimated_quantity' => ['required', 'numeric', 'gt:0'], 'mixComponents.*.estimated_quantity_unit' => ['required', 'string', 'max:30']]);
        $products = Product::whereIn('id', collect($this->mixComponents)->pluck('product_id'))
            ->get()
            ->keyBy('id');
        $basis = $products->sortByDesc(fn(Product $product): float => (float) $product->selling_price)->first();
        $key = 'mix:' . Str::uuid();
        $quantity = (float) $this->mixQuantity;
        $this->cart[$key] = ['type' => 'custom_mix', 'description' => $this->mixDescription, 'package' => $quantity . ' ' . $this->mixResultUnit, 'resulting_quantity' => $quantity, 'resulting_unit' => $this->mixResultUnit, 'sku' => 'CUSTOM MIX', 'quantity' => $quantity, 'unit_price' => (float) $basis->selling_price, 'subtotal' => $quantity * (float) $basis->selling_price, 'basis_product_id' => $basis->id, 'basis_product_name' => $basis->name, 'components' => $this->mixComponents, 'notes' => $this->mixNotes];
        $this->resetMixForm();
    }

    public function editMix(string $key): void
    {
        $mix = $this->cart[$key] ?? null;
        if (!$mix || $mix['type'] !== 'custom_mix') {
            return;
        }
        $this->mixDescription = $mix['description'];
        $this->mixQuantity = (string) $mix['quantity'];
        $this->mixResultUnit = $mix['resulting_unit'] ?? '';
        $this->mixComponents = $mix['components'];
        $this->mixNotes = $mix['notes'] ?? '';
        unset($this->cart[$key]);
        $this->showMixPanel = true;
    }
    public function resetMixForm(): void
    {
        $this->reset(['showMixPanel', 'mixDescription', 'mixQuantity', 'mixResultUnit', 'mixProductId', 'mixEstimatedQuantity', 'mixEstimatedQuantityUnit', 'mixNotes', 'mixComponents']);
        $this->mixDescription = 'Custom paint mix';
        $this->mixQuantity = '1';
    }
    public function cartTotal(): float
    {
        return round(collect($this->cart)->sum('subtotal'), 2);
    }

    public function proceedToPayment(): void
    {
        if ($this->cart === []) {
            $this->addError('cart', 'Add at least one item before payment.');
            return;
        }
        session(['pos.cart' => $this->cart]);
        $this->redirectRoute('sales.checkout');
    }

    public function finalizeSale(): void
    {
        if ($this->cart === [] && $this->productId !== '') {
            $this->addProduct((int) $this->productId);
            if (!$this->updateCartQuantity('product:' . $this->productId, $this->quantity)) {
                return;
            }
        }
        $this->checkout();
    }

    public function checkout(): void
    {
        if ($this->cart === []) {
            $this->addError('cart', 'Add at least one item before checkout.');
            return;
        }
        $total = $this->cartTotal();
        DB::transaction(function () use ($total): void {
            $sale = Sale::create(['user_id' => auth()->id(), 'invoice_number' => 'SALE-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(4)), 'sold_at' => now(), 'type' => collect($this->cart)->contains('type', 'custom_mix') ? 'mixed' : 'normal', 'subtotal' => $total, 'total' => $total, 'payment_method' => 'cash', 'payment_amount' => $total, 'change_amount' => 0]);
            foreach ($this->cart as $line) {
                if ($line['type'] === 'custom_mix') {
                    $sale->items()->create(['description' => $line['description'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'subtotal' => $line['subtotal']]);
                    $mix = MixingTransaction::create(['sale_id' => $sale->id, 'price_basis_product_id' => $line['basis_product_id'], 'notes' => $line['notes'] ?: null]);
                    foreach ($line['components'] as $component) {
                        $mix->components()->create(['product_id' => $component['product_id'], 'estimated_quantity' => $component['estimated_quantity'], 'estimated_quantity_unit' => $component['estimated_quantity_unit']]);
                    }
                    continue;
                }
                $product = Product::findOrFail($line['product_id']);
                $inventory = Inventory::where('product_id', $product->id)
                    ->lockForUpdate()
                    ->firstOrCreate(['product_id' => $product->id], ['quantity' => 0]);
                $before = (float) $inventory->quantity;
                if ($before < $line['quantity']) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['productId' => "Insufficient stock for {$product->name}."]);
                }
                $inventory->decrement('quantity', $line['quantity']);
                $sale->items()->create(['product_id' => $product->id, 'description' => $line['description'] . ' ' . $line['package'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'subtotal' => $line['subtotal']]);
                InventoryMovement::create(['product_id' => $product->id, 'user_id' => auth()->id(), 'type' => 'sale', 'quantity_change' => -$line['quantity'], 'quantity_before' => $before, 'quantity_after' => $before - $line['quantity'], 'reference_type' => Sale::class, 'reference_id' => $sale->id]);
            }
            AuditLog::create(['user_id' => auth()->id(), 'event' => 'sale_completed', 'auditable_type' => Sale::class, 'auditable_id' => $sale->id, 'context' => ['total' => $total, 'lines' => count($this->cart)]]);
        });
        $this->reset(['cart', 'productId']);
    }

    public function render(): mixed
    {
        return view('livewire.pages.sales.index', [
            'products' => Product::with(['brand', 'category', 'packageUnit', 'inventory'])
                ->where('active', true)
                ->when($this->search, fn($query) => $query->where(fn($query) => $query->where('name', 'like', '%' . $this->search . '%')->orWhere('sku', 'like', '%' . $this->search . '%')))
                ->when($this->brandId, fn($query) => $query->where('brand_id', $this->brandId))
                ->when($this->categoryId, fn($query) => $query->where('category_id', $this->categoryId))
                ->when($this->packageUnit, fn($query) => $query->where('package_unit_id', $this->packageUnit))
                ->when($this->availableOnly, fn($query) => $query->whereHas('inventory', fn($query) => $query->where('quantity', '>', 0)))
                ->orderBy('name')
                ->paginate(12),
            'brands' => Brand::orderBy('name')->get(),
            'categories' => Category::orderBy('name')->get(),
            'packageUnits' => \App\Models\PackageUnit::where('active', true)->orderBy('name')->get(),
            'mixProducts' => Product::where('active', true)->orderBy('name')->get(),
        ]);
    }
}; ?>
<div class="mx-auto max-w-[1600px] space-y-4 px-4 py-5 text-sm sm:px-6 lg:px-8">
    @if (session('status'))
        <div class="rounded-md bg-green-50 p-3 text-green-700">{{ session('status') }}</div>
    @endif
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Point of sale</h1>
            <p class="mt-1 text-gray-600">Build the cart, then proceed to cash payment.</p>
        </div><button wire:click="openMixPanel" type="button"
            class="rounded-md bg-[#00a3cc] px-4 py-2 font-semibold text-white">+ Custom Mix</button>
    </div>
    <div class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
        <section class="space-y-3">
            <div class="grid gap-2 rounded-lg bg-white p-3 shadow-sm sm:grid-cols-2 xl:grid-cols-5"><input
                    wire:model.live.debounce.300ms="search" type="search" placeholder="Search product or SKU"
                    class="rounded-md border-gray-300 xl:col-span-2"><select wire:model.live="brandId"
                    class="rounded-md border-gray-300">
                    <option value="">All brands</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="categoryId" class="rounded-md border-gray-300">
                    <option value="">All categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="packageUnit" class="rounded-md border-gray-300">
                    <option value="">All package units</option>
                    @foreach ($packageUnits as $unit)
                        <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                    @endforeach
                </select>
                <label class="flex items-center gap-2 text-gray-600 xl:col-span-5"><input
                        wire:model.live="availableOnly" type="checkbox" class="rounded border-gray-300"> Available
                    only</label>
            </div>
            @error('cart')
                <div class="rounded-md bg-red-50 p-2 text-red-700">{{ $message }}</div>
            @enderror
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @forelse ($products as $product)
                    <button wire:click="addProduct({{ $product->id }})" type="button"
                        wire:key="pos-product-{{ $product->id }}"
                        class="rounded-lg border border-gray-200 bg-white p-3 text-left shadow-sm hover:border-[#00a3cc]">
                        <div class="flex justify-between gap-2">
                            <div>
                                <p class="font-semibold text-gray-900">{{ $product->name }}</p>
                                <p class="text-xs text-gray-500">{{ $product->brand?->name ?? 'Unbranded' }} ·
                                    {{ $product->package_size }} {{ $product->packageUnit?->abbreviation }}</p>
                            </div><span class="font-mono text-xs text-gray-500">{{ $product->sku }}</span>
                        </div>
                        <div class="mt-3 flex justify-between"><span
                                class="font-bold">{{ \App\Support\Currency::format($product->selling_price) }}</span><span
                                class="text-xs text-gray-500">{{ $product->inventory?->quantity ?? 0 }} on hand</span>
                        </div>
                </button>@empty<div
                        class="rounded bg-white p-8 text-center text-gray-500 sm:col-span-2 xl:col-span-3">No products
                        match these filters.</div>
                @endforelse
            </div>
            {{ $products->links() }}
        </section>
        <aside class="sticky top-3 rounded-lg bg-white p-4 shadow-lg">
            <div class="flex justify-between border-b pb-3">
                <div>
                    <h2 class="font-semibold">Current cart</h2>
                    <p class="text-xs text-gray-500">{{ count($cart) }} line(s)</p>
                </div><button wire:click="$set('cart', [])" type="button" class="text-xs text-red-600">Clear</button>
            </div>
            <div class="max-h-[55vh] space-y-3 overflow-y-auto py-3">
                @forelse ($cart as $key => $line)
                    <div wire:key="cart-{{ $key }}" class="border-b pb-3">
                        <div class="flex justify-between gap-2">
                            <div>
                                <p class="font-medium">{{ $line['description'] }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ $line['type'] === 'custom_mix' ? 'Custom Mix · ' . $line['basis_product_name'] : $line['package'] . ' · ' . $line['sku'] }}
                                </p>
                            </div><button wire:click="removeFromCart('{{ $key }}')" type="button"
                                class="text-xs text-red-600">Remove</button>
                        </div>
                        <div class="mt-2 flex items-center justify-between">
                            <div class="flex rounded border"><button wire:click="decrementCart('{{ $key }}')"
                                    type="button" class="px-2 py-1">−</button><input
                                    wire:change="updateCartQuantity('{{ $key }}', $event.target.value)"
                                    value="{{ $line['quantity'] }}"
                                    class="w-12 border-0 p-1 text-center text-xs"><button
                                    wire:click="incrementCart('{{ $key }}')" type="button"
                                    class="px-2 py-1">+</button></div>
                            <strong>{{ \App\Support\Currency::format($line['subtotal']) }}</strong>
                        </div>
                        @if ($line['type'] === 'custom_mix')
                            <button wire:click="editMix('{{ $key }}')" type="button"
                                class="mt-1 text-xs text-[#008fb3]">Edit mix</button>
                        @endif
                    </div>
                @empty<div class="py-8 text-center text-gray-500">Cart is empty.</div>
                @endforelse
            </div>
            <div class="border-t pt-3">
                <div class="mb-3 flex justify-between text-lg font-bold">
                    <span>Total</span><span>{{ \App\Support\Currency::format($this->cartTotal()) }}</span>
                </div><button wire:click="proceedToPayment" type="button"
                    class="w-full rounded-md bg-[#00a3cc] px-4 py-3 font-bold uppercase tracking-wide text-white">Proceed
                    to Payment</button>
            </div>
        </aside>
    </div>
    @if ($showMixPanel)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 px-4">
            <div class="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-lg bg-white p-5 shadow-xl">
                <div class="flex justify-between">
                    <h2 class="text-lg font-semibold">Add custom mix to cart</h2><button wire:click="resetMixForm"
                        type="button" class="text-2xl">&times;</button>
                </div>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div><x-input-label for="mixDescription" value="Result description" /><x-text-input
                            wire:model="mixDescription" id="mixDescription" class="mt-1 block w-full" /></div>
                    <div><x-input-label for="mixQuantity" value="Resulting quantity" /><x-text-input
                            wire:model="mixQuantity" id="mixQuantity" type="number" step="0.001"
                            class="mt-1 block w-full" /></div>
                    <div><x-input-label for="mixResultUnit" value="Resulting unit" /><select
                            wire:model="mixResultUnit" id="mixResultUnit"
                            class="mt-1 block w-full rounded-md border-gray-300">
                            <option value="">Choose unit</option>
                            <option value="ml">ml</option>
                            <option value="L">L</option>
                            <option value="gal">gal</option>
                        </select></div>
                </div>
                <div class="mt-4 grid items-end gap-3 sm:grid-cols-[1fr_150px_130px_auto]"><select
                        wire:model="mixProductId" class="rounded-md border-gray-300">
                        <option value="">Choose material</option>
                        @foreach ($mixProducts as $product)
                            <option value="{{ $product->id }}">{{ $product->sku }} · {{ $product->name }}</option>
                        @endforeach
                    </select>
                    <x-text-input wire:model="mixEstimatedQuantity" type="number" step="0.001"
                        placeholder="Estimated use" /><select wire:model="mixEstimatedQuantityUnit"
                        class="rounded-md border-gray-300">
                        <option value="">Unit</option>
                        <option value="ml">ml</option>
                        <option value="L">L</option>
                        <option value="gal">gal</option>
                    </select><button wire:click="addMixComponent" type="button"
                        class="rounded-md bg-gray-800 px-3 py-2 font-semibold text-white">Add</button>
                </div>
                <div class="mt-3 space-y-2">
                    @forelse ($mixComponents as $index => $component)
                        <div class="flex justify-between bg-gray-50 px-3 py-2 text-xs">
                            <span>{{ $mixProducts->firstWhere('id', $component['product_id'])?->name }} ·
                                {{ $component['estimated_quantity'] }}
                                {{ $component['estimated_quantity_unit'] }}</span><button
                                wire:click="removeMixComponent({{ $index }})" type="button"
                                class="text-red-600">Remove</button>
                    </div>@empty<p class="text-gray-500">Add base and
                            tint materials.</p>
                    @endforelse
                </div>
                <textarea wire:model="mixNotes" rows="2" placeholder="Mix notes"
                    class="mt-3 block w-full rounded-md border-gray-300"></textarea>
                <div class="mt-4 flex justify-end gap-2"><button wire:click="resetMixForm" type="button"
                        class="rounded border px-3 py-2">Cancel</button><button wire:click="addMixToCart"
                        type="button" class="rounded-md bg-[#00a3cc] px-3 py-2 font-semibold text-white">Add mix to
                        cart</button></div>
            </div>
        </div>
    @endif
    +
</div>
