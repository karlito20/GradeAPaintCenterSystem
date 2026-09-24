<?php

use App\Models\MixingComponent;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;
    public function render(): mixed
    {
        return view('livewire.pages.reports.mixing', [
            'components' => MixingComponent::query()
                ->with(['product', 'mixingTransaction.sale'])
                ->latest()
                ->paginate(25),
        ]);
    }
}; ?>

<div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Mixing usage report</h1>
        <p class="mt-1 text-sm text-gray-600">Estimated component usage retained for weekly physical reconciliation.</p>
    </div>
    <div class="overflow-hidden rounded-lg bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-3 py-2">Sale</th>
                        <th class="px-3 py-2">Material</th>
                        <th class="px-3 py-2">SKU</th>
                        <th class="px-3 py-2">Package Unit</th>
                        <th class="px-3 py-2">Estimated Qty</th>
                        <th class="px-3 py-2">Estimated Unit</th>
                        <th class="px-3 py-2">Recorded</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($components as $component)
                        <tr wire:key="mix-report-{{ $component->id }}">
                            <td class="px-3 py-2 font-mono">{{ $component->mixingTransaction->sale->invoice_number }}
                            </td>
                            <td class="px-3 py-2">{{ $component->product->name }}</td>
                            <td class="px-3 py-2 font-mono">{{ $component->product->sku }}</td>
                            <td class="px-3 py-2">{{ $component->product->packageUnit?->abbreviation ?? '-' }}</td>
                            <td class="px-3 py-2">{{ $component->estimated_quantity }}</td>
                            <td class="px-3 py-2">{{ $component->estimated_quantity_unit }}</td>
                            <td class="px-3 py-2">{{ $component->created_at->format('Y-m-d') }}</td>
                    </tr>@empty<tr>
                            <td colspan="7" class="px-3 py-6 text-center text-gray-500">No mixing usage recorded.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $components->links() }}</div>
    </div>
</div>
