<?php

use App\Models\Sale;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;
    public string $from = '';
    public string $to = '';

    public function render(): mixed
    {
        return view('livewire.pages.reports.sales', ['sales' => Sale::query()->with('items')->when($this->from, fn($query) => $query->whereDate('sold_at', '>=', $this->from))->when($this->to, fn($query) => $query->whereDate('sold_at', '<=', $this->to))->latest('sold_at')->paginate(25)]);
    }
}; ?>

<div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Sales report</h1>
        <p class="mt-1 text-sm text-gray-600">Review normal and custom-mix sales by date.</p>
    </div>
    <div class="flex flex-col gap-3 rounded-lg bg-white p-4 shadow-sm sm:flex-row sm:items-end">
        <div><x-input-label for="from" value="From" /><x-text-input wire:model.live="from" id="from"
                type="date" class="mt-1" /></div>
        <div><x-input-label for="to" value="To" /><x-text-input wire:model.live="to" id="to"
                type="date" class="mt-1" /></div>
    </div>
    <div class="overflow-hidden rounded-lg bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-3 py-2">Invoice</th>
                        <th class="px-3 py-2">Date</th>
                        <th class="px-3 py-2">Type</th>
                        <th class="px-3 py-2">Items</th>
                        <th class="px-3 py-2">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($sales as $sale)
                        <tr wire:key="sale-report-{{ $sale->id }}">
                            <td class="px-3 py-2 font-mono">{{ $sale->invoice_number }}</td>
                            <td class="px-3 py-2">{{ $sale->sold_at->format('Y-m-d H:i') }}</td>
                            <td class="px-3 py-2 capitalize">{{ str_replace('_', ' ', $sale->type) }}</td>
                            <td class="px-3 py-2">{{ $sale->items->count() }}</td>
                            <td class="px-3 py-2 font-semibold">{{ \App\Support\Currency::format($sale->total) }}</td>
                    </tr>@empty<tr>
                            <td colspan="5" class="px-6 py-10 text-center text-gray-500">No sales found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $sales->links() }}</div>
    </div>
</div>
