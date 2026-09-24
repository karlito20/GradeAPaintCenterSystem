<?php
use App\Models\Sale;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Volt\Component;
new #[Layout('layouts.app')] class extends Component {
    use WithPagination;
    public string $search = '';
    public string $type = '';
    public function render(): mixed
    {
        return view('livewire.pages.sales.history', [
            'sales' => Sale::with(['user', 'items', 'mixingTransaction.components'])
                ->when($this->search, fn($query) => $query->where('invoice_number', 'like', '%' . $this->search . '%')->orWhereHas('items', fn($query) => $query->where('description', 'like', '%' . $this->search . '%')))
                ->when($this->type, fn($query) => $query->where('type', $this->type))
                ->latest('sold_at')
                ->paginate(20),
        ]);
    }
}; ?>
<div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div>
        <h1 class="text-2xl font-bold">Sales History</h1>
        <p class="mt-1 text-sm text-gray-600">Review completed invoices, cashiers, totals, and mix details.</p>
    </div>
    <div class="flex gap-3 rounded-lg bg-white p-4 shadow-sm"><input wire:model.live.debounce.300ms="search" type="search"
            placeholder="Search invoice or item" class="w-full max-w-sm rounded-md border-gray-300"><select
            wire:model.live="type" class="rounded-md border-gray-300">
            <option value="">All sale types</option>
            <option value="normal">Normal</option>
            <option value="mixed">Mixed</option>
            <option value="custom_mix">Custom mix</option>
        </select></div>
    <div class="overflow-hidden rounded-lg bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-3 py-2">Invoice</th>
                        <th class="px-3 py-2">Date</th>
                        <th class="px-3 py-2">Cashier</th>
                        <th class="px-3 py-2">Type</th>
                        <th class="px-3 py-2">Items</th>
                        <th class="px-3 py-2">Total</th>
                        <th class="px-3 py-2">Payment</th>
                        <th class="px-3 py-2">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($sales as $sale)
                        <tr wire:key="sale-history-{{ $sale->id }}">
                            <td class="px-3 py-2 font-mono">{{ $sale->invoice_number }}</td>
                            <td class="px-3 py-2">{{ $sale->sold_at->format('Y-m-d H:i') }}</td>
                            <td class="px-3 py-2">{{ $sale->user?->name ?? 'System' }}</td>
                            <td class="px-3 py-2 capitalize">{{ str_replace('_', ' ', $sale->type) }}</td>
                            <td class="px-3 py-2">{{ $sale->items->count() }}</td>
                            <td class="px-3 py-2 font-semibold">{{ \App\Support\Currency::format($sale->total) }}</td>
                            <td class="px-3 py-2 capitalize">{{ $sale->payment_method ?? '-' }}</td>
                            <td class="px-3 py-2"><a href="{{ route('sales.receipt', $sale) }}" wire:navigate
                                    class="text-[#008fb3]">View Receipt</a></td>
                    </tr>@empty<tr>
                            <td colspan="8" class="px-3 py-6 text-center text-gray-500">No sales found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $sales->links() }}</div>
    </div>
</div>
