<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Receipt {{ $sale->invoice_number }}</h2>
    </x-slot>
    <div class="mx-auto max-w-2xl px-4 py-5 text-sm sm:px-6">
        <div class="rounded-lg bg-white p-5 shadow-sm">
            <div class="flex justify-between border-b pb-3">
                <div>
                    <h1 class="text-lg font-bold">Grade A Paint Center</h1>
                    <p>{{ $sale->invoice_number }}</p>
                </div>
                <div class="text-right">
                    <p>{{ $sale->sold_at->format('Y-m-d H:i') }}</p>
                    <p>{{ $sale->user?->name }}</p>
                </div>
            </div>
            <table class="mt-4 w-full">
                <thead class="border-b text-left text-xs uppercase text-gray-500">
                    <tr>
                        <th class="py-2">Item</th>
                        <th class="py-2">Package</th>
                        <th class="py-2">Qty</th>
                        <th class="py-2 text-right">Subtotal</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @foreach ($sale->items as $item)
                        <tr>
                            <td class="py-2">{{ $item->description }}</td>
                            <td class="py-2">{{ $item->product?->packageUnit?->abbreviation ?? 'Custom Mix' }}</td>
                            <td class="py-2">{{ $item->quantity }}</td>
                            <td class="py-2 text-right">{{ \App\Support\Currency::format($item->subtotal) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-4 space-y-1 border-t pt-3 text-right">
                <p>Total: <strong>{{ \App\Support\Currency::format($sale->total) }}</strong></p>
                <p>Tendered: {{ \App\Support\Currency::format($sale->payment_amount) }}</p>
                <p>Change: {{ \App\Support\Currency::format($sale->change_amount) }}</p>
            </div>
            @if ($sale->mixingTransaction)
                <div class="mt-4 border-t pt-3">
                    <h3 class="font-semibold">Custom Mix: {{ $sale->mixingTransaction->resulting_quantity }}
                        {{ $sale->mixingTransaction->resulting_unit }}</h3>
                    @foreach ($sale->mixingTransaction->components as $component)
                        <p>{{ $component->product->name }}: {{ $component->estimated_quantity }}
                            {{ $component->estimated_quantity_unit }}</p>
                    @endforeach
                </div>
            @endif
            <div class="mt-5 print:hidden"><button onclick="window.print()" type="button"
                    class="rounded-md bg-[#00a3cc] px-4 py-2 font-semibold text-white">Print Receipt</button></div>
        </div>
    </div>
</x-app-layout>
