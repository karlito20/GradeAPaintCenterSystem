<?php

use App\Models\InventoryMovement;
use App\Models\AuditLog;
use App\Models\PhysicalInventory;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public array $counts = [];
    public string $countedAt = '';
    public string $notes = '';
    public string $search = '';
    public string $brandId = '';
    public string $categoryId = '';
    public string $packageUnitId = '';

    public function mount(): void
    {
        $this->countedAt = now()->toDateString();
        $this->counts = Product::query()->with('inventory')->pluck('id')->mapWithKeys(fn(int $id): array => [$id => ''])->all();
    }

    public function confirmCount(): void
    {
        $this->validate(['countedAt' => ['required', 'date'], 'counts' => ['required', 'array'], 'counts.*' => ['nullable', 'numeric', 'min:0']]);
        DB::transaction(function (): void {
            $count = PhysicalInventory::create(['user_id' => auth()->id(), 'counted_at' => $this->countedAt, 'notes' => $this->notes ?: null]);
            foreach ($this->counts as $productId => $physicalQuantity) {
                if ($physicalQuantity === '' || $physicalQuantity === null) {
                    continue;
                }
                $product = Product::query()->with('inventory')->lockForUpdate()->findOrFail($productId);
                $inventory = $product->inventory;
                $systemQuantity = (float) ($inventory?->quantity ?? 0);
                $physical = (float) $physicalQuantity;
                $count->items()->create(['product_id' => $product->id, 'system_quantity' => $systemQuantity, 'physical_quantity' => $physical, 'variance' => $physical - $systemQuantity]);
                $inventory?->update(['quantity' => $physical]);
                InventoryMovement::create(['product_id' => $product->id, 'user_id' => auth()->id(), 'type' => 'physical_adjustment', 'quantity_change' => $physical - $systemQuantity, 'quantity_before' => $systemQuantity, 'quantity_after' => $physical, 'reference_type' => PhysicalInventory::class, 'reference_id' => $count->id, 'reason' => $this->notes]);
            }
            AuditLog::create(['user_id' => auth()->id(), 'event' => 'physical_inventory_confirmed', 'auditable_type' => PhysicalInventory::class, 'auditable_id' => $count->id, 'context' => ['items' => count($this->counts)]]);
        });
        $this->reset('notes');
        $this->mount();
        session()->flash('status', 'Physical inventory count confirmed.');
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

    public function render(): mixed
    {
        return view('livewire.pages.inventory.physical-count', [
            'products' => Product::query()
                ->with(['inventory', 'category', 'brand', 'packageUnit'])
                ->where('active', true)
                ->when($this->search, fn($query) => $query->where(fn($query) => $query->where('name', 'like', '%' . $this->search . '%')->orWhere('sku', 'like', '%' . $this->search . '%')))
                ->when($this->brandId, fn($query) => $query->where('brand_id', $this->brandId))
                ->when($this->categoryId, fn($query) => $query->where('category_id', $this->categoryId))
                ->when($this->packageUnitId, fn($query) => $query->where('package_unit_id', $this->packageUnitId))
                ->orderBy('name')
                ->paginate(25),
            'brands' => \App\Models\Brand::where('active', true)->orderBy('name')->get(),
            'categories' => \App\Models\Category::where('active', true)->orderBy('name')->get(),
            'packageUnits' => \App\Models\PackageUnit::where('active', true)->orderBy('name')->get(),
        ]);
    }
}; ?>

<div class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    @if (session('status'))
        <div class="rounded-md bg-green-50 p-4 text-sm text-green-700">{{ session('status') }}</div>
    @endif
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Weekly physical inventory</h1>
        <p class="mt-1 text-sm text-gray-600">Enter what is physically measured; the confirmed count becomes official
            stock.</p>
    </div>
    <form wire:submit="confirmCount" class="space-y-4 rounded-lg bg-white p-4 shadow-sm">
        <div class="max-w-xs"><x-input-label for="countedAt" value="Count date" /><x-text-input wire:model="countedAt"
                id="countedAt" type="date" class="mt-1 block w-full" /></div>
        <div class="flex flex-wrap gap-2"><input wire:model.live.debounce.300ms="search" type="search"
                placeholder="Search product or SKU" class="rounded-md border-gray-300"><select wire:model.live="brandId"
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
            <select wire:model.live="packageUnitId" class="rounded-md border-gray-300">
                <option value="">All package units</option>
                @foreach ($packageUnits as $unit)
                    <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-gray-500">
                        <th class="px-2 py-2">Product</th>
                        <th class="px-2 py-2">SKU</th>
                        <th class="px-2 py-2">Brand</th>
                        <th class="px-2 py-2">Category</th>
                        <th class="px-2 py-2">Package Unit</th>
                        <th class="px-2 py-2">System Stock</th>
                        <th class="px-2 py-2">Physical Stock</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($products as $product)
                        <tr wire:key="count-{{ $product->id }}">
                            <td class="px-2 py-2 font-medium">{{ $product->name }}</td>
                            <td class="px-2 py-2 font-mono">{{ $product->sku }}</td>
                            <td class="px-2 py-2">{{ $product->brand?->name ?? '-' }}</td>
                            <td class="px-2 py-2">{{ $product->category->name }}</td>
                            <td class="px-2 py-2">{{ $product->packageUnit?->abbreviation ?? '-' }}</td>
                            <td class="px-2 py-2">{{ $product->inventory?->quantity ?? 0 }}</td>
                            <td class="px-2 py-2"><input wire:model="counts.{{ $product->id }}" type="number"
                                    min="0" step="0.001" class="w-40 rounded-md border-gray-300"
                                    placeholder="Skip"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div>{{ $products->links() }}</div>
        <div><x-input-label for="notes" value="Reason / notes" />
            <textarea wire:model="notes" id="notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300"></textarea>
        </div>
        <div class="flex justify-end"><x-primary-button>Confirm physical count</x-primary-button></div>
    </form>
</div>
