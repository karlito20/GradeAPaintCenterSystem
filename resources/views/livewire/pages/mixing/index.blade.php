<?php

use App\Models\MixingTransaction;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public string $productId = '';
    public string $estimatedQuantity = '';
    public string $estimatedQuantityUnit = '';
    public string $notes = '';
    public array $components = [];

    public function addComponent(): void
    {
        if ($this->estimatedQuantityUnit === '' && $this->productId !== '') {
            $this->estimatedQuantityUnit = Product::with('packageUnit')->find($this->productId)?->packageUnit?->abbreviation ?: 'package-equivalent';
        }
        $validated = $this->validate(['productId' => ['required', 'integer', Rule::exists('products', 'id')], 'estimatedQuantity' => ['required', 'numeric', 'gt:0'], 'estimatedQuantityUnit' => ['required', 'string', 'max:30']]);
        $this->components[] = ['product_id' => (int) $validated['productId'], 'estimated_quantity' => (float) $validated['estimatedQuantity'], 'estimated_quantity_unit' => $validated['estimatedQuantityUnit']];
        $this->reset(['productId', 'estimatedQuantity', 'estimatedQuantityUnit']);
    }

    public function removeComponent(int $index): void
    {
        unset($this->components[$index]);
        $this->components = array_values($this->components);
    }

    public function finalizeMix(): void
    {
        $this->validate(['components' => ['required', 'array', 'min:1'], 'components.*.product_id' => ['required', 'exists:products,id'], 'components.*.estimated_quantity' => ['required', 'numeric', 'gt:0'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $products = Product::query()
            ->whereIn('id', collect($this->components)->pluck('product_id'))
            ->get()
            ->keyBy('id');
        $basis = $products->sortByDesc(fn(Product $product): float => (float) $product->selling_price)->first();
        DB::transaction(function () use ($products, $basis): void {
            $sale = Sale::create(['user_id' => auth()->id(), 'invoice_number' => 'MIX-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(4)), 'sold_at' => now(), 'type' => 'custom_mix', 'subtotal' => $basis->selling_price, 'total' => $basis->selling_price]);
            $sale->items()->create(['description' => 'Custom paint mix', 'quantity' => 1, 'unit_price' => $basis->selling_price, 'subtotal' => $basis->selling_price]);
            $mix = MixingTransaction::create(['sale_id' => $sale->id, 'price_basis_product_id' => $basis->id, 'notes' => $this->notes ?: null]);
            foreach ($this->components as $component) {
                $mix->components()->create(['product_id' => $component['product_id'], 'estimated_quantity' => $component['estimated_quantity'], 'estimated_quantity_unit' => $component['estimated_quantity_unit']]);
            }
            AuditLog::create(['user_id' => auth()->id(), 'event' => 'custom_mix_created', 'auditable_type' => MixingTransaction::class, 'auditable_id' => $mix->id, 'context' => ['components' => count($this->components)]]);
        });
        $this->reset(['components', 'notes', 'productId', 'estimatedQuantity', 'estimatedQuantityUnit']);
        session()->flash('status', 'Custom mix sale recorded. Estimated usage is saved for weekly reconciliation.');
    }

    public function render(): mixed
    {
        return view('livewire.pages.mixing.index', ['products' => Product::query()->where('active', true)->orderBy('name')->get()]);
    }
}; ?>

<div class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    @if (session('status'))
        <div class="rounded-md bg-green-50 p-4 text-sm text-green-700">{{ session('status') }}</div>
    @endif
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Custom paint mix</h1>
        <p class="mt-1 text-sm text-gray-600">Record estimated materials without changing official stock until the weekly
            count.</p>
    </div>
    <div class="space-y-6 rounded-lg bg-white p-6 shadow-sm">
        <div class="grid items-end gap-4 sm:grid-cols-[1fr_180px_150px_auto]">
            <div><x-input-label for="productId" value="Material / SKU" /><select wire:model="productId" id="productId"
                    class="mt-1 block w-full rounded-md border-gray-300">
                    <option value="">Choose a material</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}">{{ $product->sku }} · {{ $product->name }}
                            ({{ \App\Support\Currency::format($product->selling_price) }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div><x-input-label for="estimatedQuantity" value="Estimated quantity" /><x-text-input
                    wire:model="estimatedQuantity" id="estimatedQuantity" type="number" step="0.001"
                    class="mt-1 block w-full" /></div>
            <div><x-input-label for="estimatedQuantityUnit" value="Estimated unit" /><select
                    wire:model="estimatedQuantityUnit" id="estimatedQuantityUnit"
                    class="mt-1 block w-full rounded-md border-gray-300">
                    <option value="">Choose unit</option>
                    <option value="ml">ml</option>
                    <option value="L">L</option>
                    <option value="oz">oz</option>
                    <option value="gal">gal</option>
                </select></div><button wire:click="addComponent" type="button"
                class="rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white">Add material</button>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-gray-500">
                        <th class="px-2 py-2">Material</th>
                        <th class="px-2 py-2">SKU</th>
                        <th class="px-2 py-2">Package Unit</th>
                        <th class="px-2 py-2">Estimated Qty</th>
                        <th class="px-2 py-2">Estimated Unit</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($components as $index => $component)
                        <tr wire:key="mix-component-{{ $index }}">
                            <td class="px-2 py-2">{{ $products->firstWhere('id', $component['product_id'])?->name }}
                            </td>
                            <td class="px-2 py-2 font-mono">
                                {{ $products->firstWhere('id', $component['product_id'])?->sku }}</td>
                            <td class="px-2 py-2">
                                {{ $products->firstWhere('id', $component['product_id'])?->packageUnit?->abbreviation ?? '-' }}
                            </td>
                            <td class="px-2 py-2">{{ $component['estimated_quantity'] }}</td>
                            <td class="px-2 py-2">{{ $component['estimated_quantity_unit'] }}</td>
                            <td class="px-2 py-2 text-right"><button wire:click="removeComponent({{ $index }})"
                                    type="button" class="text-red-600">Remove</button></td>
                    </tr>@empty<tr>
                            <td colspan="6" class="px-3 py-6 text-center text-gray-500">Add the base paint and
                                tinting materials used.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div><x-input-label for="notes" value="Notes" />
            <textarea wire:model="notes" id="notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300"></textarea>
        </div>
        <div class="flex justify-end"><x-primary-button>Finalize custom mix</x-primary-button></div>
    </div>
</div>
