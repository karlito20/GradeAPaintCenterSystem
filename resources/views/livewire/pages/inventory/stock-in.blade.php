<?php

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\StockIn;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public string $receivedAt = '';
    public string $productId = '';
    public string $quantity = '';
    public array $items = [];

    public function mount(): void
    {
        $this->receivedAt = now()->toDateString();
    }

    public function addItem(): void
    {
        $validated = $this->validate(['productId' => ['required', 'integer', Rule::exists('products', 'id')], 'quantity' => ['required', 'numeric', 'gt:0']]);
        $this->items[] = ['product_id' => (int) $validated['productId'], 'quantity' => (float) $validated['quantity']];
        $this->reset(['productId', 'quantity']);
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function saveStockIn(): void
    {
        $this->validate(['receivedAt' => ['required', 'date'], 'items' => ['required', 'array', 'min:1'], 'items.*.product_id' => ['required', 'exists:products,id'], 'items.*.quantity' => ['required', 'numeric', 'gt:0']]);
        DB::transaction(function (): void {
            $stockIn = StockIn::create(['user_id' => auth()->id(), 'received_at' => $this->receivedAt]);
            foreach ($this->items as $item) {
                $inventory = Inventory::query()
                    ->where('product_id', $item['product_id'])
                    ->lockForUpdate()
                    ->firstOrCreate(['product_id' => $item['product_id']], ['quantity' => 0]);
                $before = (float) $inventory->quantity;
                $inventory->increment('quantity', $item['quantity']);
                $stockIn->items()->create(['product_id' => $item['product_id'], 'quantity' => $item['quantity']]);
                InventoryMovement::create(['product_id' => $item['product_id'], 'user_id' => auth()->id(), 'type' => 'stock_in', 'quantity_change' => $item['quantity'], 'quantity_before' => $before, 'quantity_after' => $before + $item['quantity'], 'reference_type' => StockIn::class, 'reference_id' => $stockIn->id, 'reference_text' => 'Stock-in #' . $stockIn->id]);
            }
            AuditLog::create(['user_id' => auth()->id(), 'event' => 'stock_in_created', 'auditable_type' => StockIn::class, 'auditable_id' => $stockIn->id, 'context' => ['items' => count($this->items)]]);
        });
        $this->reset(['productId', 'quantity', 'items']);
        $this->receivedAt = now()->toDateString();
        session()->flash('status', 'Stock-in recorded and inventory updated.');
    }

    public function render(): mixed
    {
        return view('livewire.pages.inventory.stock-in', ['products' => Product::query()->where('active', true)->orderBy('name')->get()]);
        return view('livewire.pages.inventory.stock-in', ['products' => Product::query()->with('packageUnit')->where('active', true)->orderBy('name')->get()]);
    }
}; ?>

<div class="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    @if (session('status'))
        <div class="rounded-md bg-green-50 p-4 text-sm text-green-700">{{ session('status') }}</div>
    @endif
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Stock In</h1>
        <p class="mt-1 text-sm text-gray-600">Record stock received from a supplier or another store.</p>
    </div>
    <form wire:submit="saveStockIn" class="space-y-6 rounded-lg bg-white p-6 shadow-sm">
        <div class="max-w-xs"><x-input-label for="receivedAt" value="Received date" /><x-text-input wire:model="receivedAt"
                id="receivedAt" type="date" class="mt-1 block w-full" /></div>
        <div class="grid items-end gap-4 sm:grid-cols-[1fr_180px_auto]">
            <div><x-input-label for="productId" value="Product" /><select wire:model="productId" id="productId"
                    class="mt-1 block w-full rounded-md border-gray-300">
                    <option value="">Choose a SKU</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}">{{ $product->sku }} · {{ $product->name }}</option>
                    @endforeach
                </select>
            </div>
            <div><x-input-label for="quantity" value="Package quantity" /><x-text-input wire:model="quantity"
                    id="quantity" type="number" step="0.001" class="mt-1 block w-full" /></div><button
                wire:click.prevent="addItem" type="button"
                class="rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white">Add line</button>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-gray-500">
                        <th class="px-2 py-2">Product</th>
                        <th class="px-2 py-2">SKU</th>
                        <th class="px-2 py-2">Package Unit</th>
                        <th class="px-2 py-2">Quantity</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($items as $index => $item)
                        <tr wire:key="stock-in-{{ $index }}">
                            <td class="px-2 py-2">{{ $products->firstWhere('id', $item['product_id'])?->name }}</td>
                            <td class="px-2 py-2 font-mono">{{ $products->firstWhere('id', $item['product_id'])?->sku }}
                            </td>
                            <td class="px-2 py-2">
                                {{ $products->firstWhere('id', $item['product_id'])?->packageUnit?->abbreviation }}</td>
                            <td class="px-2 py-2">{{ $item['quantity'] }}</td>
                            <td class="px-2 py-2 text-right"><button wire:click="removeItem({{ $index }})"
                                    type="button" class="text-red-600">Remove</button></td>
                    </tr>@empty<tr>
                            <td colspan="5" class="px-3 py-6 text-center text-gray-500">Add at least one product
                                line.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="flex justify-end"><x-primary-button>Save stock-in</x-primary-button></div>
    </form>
</div>
