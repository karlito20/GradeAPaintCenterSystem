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
        $validated = $this->validate([
            'productId' => ['required', 'integer', Rule::exists('products', 'id')],
            'estimatedQuantity' => ['required', 'numeric', 'gt:0'],
            'estimatedQuantityUnit' => ['required', 'string', 'max:30'],
        ]);

        $product = Product::with(['packageUnit', 'category'])->find($this->productId);
        if (\App\Models\Category::where('is_for_mixing', true)->exists() && ! $product?->category?->is_for_mixing) {
            $this->addError('productId', 'Only paints in designated mixing categories can be selected.');
            return;
        }

        $this->components[] = [
            'product_id' => (int) $validated['productId'],
            'estimated_quantity' => (float) $validated['estimatedQuantity'],
            'estimated_quantity_unit' => $validated['estimatedQuantityUnit'],
        ];
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
        $basis = $products->sortByDesc(fn (Product $product): float => (float) $product->selling_price)->first();
        DB::transaction(function () use ($products, $basis): void {
            $sale = Sale::create(['user_id' => auth()->id(), 'invoice_number' => 'MIX-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)), 'sold_at' => now(), 'type' => 'custom_mix', 'subtotal' => $basis->selling_price, 'total' => $basis->selling_price]);
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
        $mixingCategoriesExist = \App\Models\Category::where('is_for_mixing', true)->exists();
        $productsQuery = Product::query()->where('active', true);
        if ($mixingCategoriesExist) {
            $productsQuery->whereHas('category', fn ($q) => $q->where('is_for_mixing', true));
        }

        return view('livewire.pages.mixing.index', [
            'products' => $productsQuery->orderBy('name')->get(),
        ]);
    }
}; ?>

<div class="space-y-4 w-full min-w-0">
    @if (session('status'))
        <div class="rounded border border-emerald-300 bg-emerald-50 p-3 text-xs font-medium text-emerald-800 shadow-xs">{{ session('status') }}</div>
    @endif

    <div>
        <div class="flex items-center gap-2">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Operations</span>
            <span class="text-xs text-slate-300">/</span>
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-700">Paint Center</span>
        </div>
        <h1 class="font-heading text-2xl font-bold tracking-tight text-slate-900">Custom Paint Mixing</h1>
    </div>

    <div class="space-y-4 rounded-lg border border-slate-300 bg-white p-5 shadow-xs">
        <div class="grid items-end gap-3 sm:grid-cols-[1fr_160px_130px_auto]">
            <div>
                <label for="productId" class="block text-[11px] font-semibold text-slate-700 uppercase tracking-wider mb-1">Material Stock Product / SKU</label>
                <select wire:model="productId" id="productId" class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500">
                    <option value="">Choose a material...</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}">{{ $product->sku }} · {{ $product->name }}
                            ({{ \App\Support\Currency::format($product->selling_price) }})
                        </option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('productId')" class="mt-1" />
            </div>

            <div>
                <label for="estimatedQuantity" class="block text-[11px] font-semibold text-slate-700 uppercase tracking-wider mb-1">Estimated Qty</label>
                <input wire:model="estimatedQuantity" id="estimatedQuantity" type="number" step="0.01" placeholder="e.g. 0.50" class="w-full rounded border-slate-300 text-xs font-mono tabular-nums focus:border-slate-500 focus:ring-slate-500" />
                <x-input-error :messages="$errors->get('estimatedQuantity')" class="mt-1" />
            </div>

            <div>
                <label for="estimatedQuantityUnit" class="block text-[11px] font-semibold text-slate-700 uppercase tracking-wider mb-1">Unit</label>
                <select wire:model="estimatedQuantityUnit" id="estimatedQuantityUnit" class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500">
                    <option value="">Unit</option>
                    <option value="ml">ml</option>
                    <option value="L">L</option>
                    <option value="oz">oz</option>
                    <option value="gal">gal</option>
                </select>
                <x-input-error :messages="$errors->get('estimatedQuantityUnit')" class="mt-1" />
            </div>

            <button wire:click="addComponent" type="button" class="inline-flex items-center justify-center rounded bg-slate-900 px-4 py-2 text-xs font-semibold uppercase tracking-wider text-white shadow-xs hover:bg-slate-800 transition">
                Add Material
            </button>
        </div>

        <div class="overflow-hidden rounded-lg border border-slate-300 bg-white">
            <div class="overflow-x-auto w-full">
                <table class="w-full border-collapse border border-slate-300 text-xs">
                    <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider">
                        <tr>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-left">Material</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-left w-32">SKU</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-left w-28">Package Unit</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-right w-28">Estimated Qty</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-center w-24">Estimated Unit</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-right w-20"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @forelse ($components as $index => $component)
                            <tr wire:key="mix-component-{{ $index }}" class="hover:bg-slate-50">
                                <td class="border border-slate-200 px-2.5 py-1.5 font-semibold text-slate-900">{{ $products->firstWhere('id', $component['product_id'])?->name }}</td>
                                <td class="border border-slate-200 px-2.5 py-1.5 font-mono tabular-nums text-slate-600">
                                    {{ $products->firstWhere('id', $component['product_id'])?->sku }}</td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600">
                                    {{ $products->firstWhere('id', $component['product_id'])?->packageUnit?->abbreviation ?? '-' }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right font-mono tabular-nums font-bold text-slate-900">{{ number_format((float) $component['estimated_quantity'], 2) }}</td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-center text-slate-700">{{ $component['estimated_quantity_unit'] }}</td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right">
                                    <button wire:click="removeComponent({{ $index }})" type="button" class="inline-flex items-center rounded border border-slate-300 bg-white px-2 py-0.5 text-xs font-medium text-rose-700 hover:bg-rose-50 shadow-xs transition">Remove</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="border border-slate-200 px-3 py-6 text-center text-slate-500">
                                    No component materials added yet. Add base paint and tinting materials above.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="border-t border-slate-200 pt-3">
            <label for="notes" class="block text-[11px] font-semibold text-slate-700 uppercase tracking-wider mb-1">Mixing Formula Notes</label>
            <textarea wire:model="notes" id="notes" rows="2" placeholder="e.g. 50% primer base + 250ml tinting black..." class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500"></textarea>
        </div>

        <div class="flex justify-end pt-2 border-t border-slate-200">
            <button 
                wire:click="finalizeMix" 
                wire:confirm="Confirm and finalize this custom paint mix?"
                type="button" 
                class="rounded bg-slate-900 px-4 py-2 text-xs font-semibold uppercase tracking-wider text-white shadow-xs hover:bg-slate-800 transition"
            >
                Finalize Custom Mix
            </button>
        </div>
    </div>
</div>
