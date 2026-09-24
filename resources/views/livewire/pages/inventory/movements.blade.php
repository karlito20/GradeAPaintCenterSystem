<?php
use App\Models\InventoryMovement;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Volt\Component;
new #[Layout('layouts.app')] class extends Component {
    use WithPagination;
    public string $search = '';
    public string $type = '';
    public function render(): mixed
    {
        return view('livewire.pages.inventory.movements', [
            'movements' => InventoryMovement::with(['product.packageUnit', 'user', 'reference'])
                ->when($this->search, fn($query) => $query->whereHas('product', fn($query) => $query->where('name', 'like', '%' . $this->search . '%')->orWhere('sku', 'like', '%' . $this->search . '%')))
                ->when($this->type, fn($query) => $query->where('type', $this->type))
                ->latest()
                ->paginate(25),
            'types' => InventoryMovement::query()->distinct()->orderBy('type')->pluck('type'),
        ]);
    }
}; ?>
<div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div>
        <h1 class="text-2xl font-bold">Inventory Movements</h1>
        <p class="mt-1 text-sm text-gray-600">Chronological stock changes and resulting quantities.</p>
    </div>
    <div class="flex gap-3 rounded-lg bg-white p-4 shadow-sm"><input wire:model.live.debounce.300ms="search" type="search"
            placeholder="Search product or SKU" class="w-full max-w-sm rounded-md border-gray-300"><select
            wire:model.live="type" class="rounded-md border-gray-300">
            <option value="">All movement types</option>
            @foreach ($types as $movementType)
                <option value="{{ $movementType }}">{{ strtoupper(str_replace('_', ' ', $movementType)) }}</option>
            @endforeach
        </select>
    </div>
    <div class="overflow-hidden rounded-lg bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-3 py-2">Date</th>
                        <th class="px-3 py-2">Product</th>
                        <th class="px-3 py-2">SKU</th>
                        <th class="px-3 py-2">Type</th>
                        <th class="px-3 py-2">Package Unit</th>
                        <th class="px-3 py-2">Change</th>
                        <th class="px-3 py-2">Before</th>
                        <th class="px-3 py-2">After</th>
                        <th class="px-3 py-2">Related Transaction</th>
                        <th class="px-3 py-2">User</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($movements as $movement)
                        <tr wire:key="movement-{{ $movement->id }}">
                            <td class="px-3 py-2">{{ $movement->created_at->format('Y-m-d H:i') }}</td>
                            <td class="px-3 py-2">{{ $movement->product->name }}</td>
                            <td class="px-3 py-2 font-mono">{{ $movement->product->sku }}</td>
                            <td class="px-3 py-2 uppercase">{{ str_replace('_', ' ', $movement->type) }}</td>
                            <td class="px-3 py-2">{{ $movement->product->packageUnit?->abbreviation ?? '-' }}</td>
                            <td
                                class="px-3 py-2 {{ $movement->quantity_change < 0 ? 'text-red-600' : 'text-green-700' }}">
                                {{ $movement->quantity_change }}</td>
                            <td class="px-3 py-2">{{ $movement->quantity_before }}</td>
                            <td class="px-3 py-2">{{ $movement->quantity_after }}</td>
                            <td class="px-3 py-2">
                                {{ $movement->reference_text ?: ($movement->reference_type ? class_basename($movement->reference_type) . ' #' . $movement->reference_id : '-') }}
                            </td>
                            <td class="px-3 py-2">{{ $movement->user?->name ?? 'System' }}</td>
                    </tr>@empty<tr>
                            <td colspan="10" class="px-3 py-6 text-center text-gray-500">No inventory movements
                                found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $movements->links() }}</div>
    </div>
</div>
