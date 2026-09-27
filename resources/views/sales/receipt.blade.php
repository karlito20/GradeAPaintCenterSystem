<x-receipt-layout>
    <!-- Screen Actions Toolbar (Hidden when printing) -->
    <div class="mb-4 flex items-center justify-between gap-3 print:hidden">
        <div class="flex items-center gap-2">
            <a href="{{ route('sales.index') }}"
                class="inline-flex items-center gap-1.5 rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50 transition">
                <svg class="h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span>Back to POS</span>
            </a>
            <a href="{{ route('sales.history') }}"
                class="inline-flex items-center gap-1.5 rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50 transition">
                <span>Sales History</span>
            </a>
        </div>
        <div>
            <button onclick="window.print()" type="button"
                class="inline-flex items-center gap-2 rounded bg-[#00a3cc] px-4 py-1.5 text-xs font-bold text-white shadow-sm hover:bg-[#008fb3] transition">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                <span>Print Official Receipt</span>
            </button>
        </div>
    </div>

    <!-- Official Printable Receipt Container -->
    <div class="print-receipt-container rounded border border-slate-300 bg-white p-6 shadow-sm text-xs font-sans print:border-none print:shadow-none print:p-0">
        <!-- Store Information & Receipt Header -->
        <div class="text-center border-b border-dashed border-slate-300 pb-4">
            <h1 class="text-lg font-black uppercase tracking-wider text-slate-900 font-heading">Grade A Paint Center</h1>
            <p class="text-xs text-slate-600">Retail Paint, Tinting &amp; Painting Accessories</p>
            <p class="text-[11px] text-slate-500">Agdao, Davao City, Philippines</p>
            <p class="text-[11px] text-slate-500 font-mono mt-1">OFFICIAL SALES RECEIPT</p>
        </div>

        <!-- Transaction Meta -->
        <div class="mt-3 grid grid-cols-2 gap-2 text-xs border-b border-dashed border-slate-300 pb-3">
            <div>
                <p><span class="text-slate-500">Invoice:</span> <strong class="tabular-nums text-slate-900">{{ $sale->invoice_number }}</strong></p>
                <p><span class="text-slate-500">Date/Time:</span> <span class="tabular-nums text-slate-900">{{ $sale->sold_at->format('M d, Y h:i A') }}</span></p>
            </div>
            <div class="text-right">
                <p><span class="text-slate-500">Cashier:</span> <span class="text-slate-900 font-medium">{{ $sale->user?->name ?? 'Store Staff' }}</span></p>
                <p><span class="text-slate-500">Sale Type:</span> <span class="capitalize font-semibold text-slate-900">{{ str_replace('_', ' ', $sale->type) }}</span></p>
            </div>
        </div>

        <!-- Line Items Table -->
        <table class="mt-3 min-w-full text-left text-xs divide-y divide-slate-200">
            <thead>
                <tr class="text-[11px] font-bold text-slate-600 uppercase border-b border-slate-300">
                    <th class="py-1.5 pr-2 w-24">Brand</th>
                    <th class="py-1.5 px-2 min-w-[160px]">Item Description</th>
                    <th class="py-1.5 px-2">SKU</th>
                    <th class="py-1.5 px-2">Unit</th>
                    <th class="py-1.5 px-2 text-right">Qty</th>
                    <th class="py-1.5 px-2 text-right">Price</th>
                    <th class="py-1.5 pl-2 text-right">Subtotal</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($sale->items as $item)
                    <tr>
                        <td class="py-2 pr-2 font-medium text-slate-900">{{ $item->description }}</td>
                        <td class="py-2 px-2 tabular-nums text-[11px] text-slate-600">{{ $item->product?->sku ?? 'CUSTOM-MIX' }}</td>
                        <td class="py-2 px-2 text-slate-600">
                            {{ $item->product?->packageUnit?->abbreviation ?? ($sale->mixingTransaction ? $sale->mixingTransaction->resulting_unit : 'unit') }}
                        </td>
                        <td class="py-2 px-2 text-right tabular-nums font-medium">{{ number_format((float) $item->quantity, 3) }}</td>
                        <td class="py-2 px-2 text-right tabular-nums text-slate-600">{{ \App\Support\Currency::format($item->unit_price) }}</td>
                        <td class="py-2 pl-2 text-right tabular-nums font-bold text-slate-900">{{ \App\Support\Currency::format($item->subtotal) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Custom Mix Component Details (if present) -->
        @if ($sale->mixingTransaction)
            <div class="mt-4 rounded-md border border-gray-200 bg-gray-50 p-3 text-[11px] print:border print:border-gray-300">
                <div class="flex items-center justify-between font-bold text-gray-800 border-b border-gray-200 pb-1 mb-2">
                    <span>Custom Mix Formula Specifications:</span>
                    <span>Result: {{ number_format((float) $sale->mixingTransaction->resulting_quantity, 3) }} {{ $sale->mixingTransaction->resulting_unit }}</span>
                </div>
                @if ($sale->mixingTransaction->priceBasisProduct)
                    <p class="text-gray-600 mb-1.5">
                        <span class="font-semibold">Price Basis:</span> {{ $sale->mixingTransaction->priceBasisProduct->name }} ({{ \App\Support\Currency::format($sale->mixingTransaction->priceBasisProduct->selling_price) }})
                    </p>
                @endif
                <p class="font-semibold text-gray-700 mb-1">Estimated Component Materials Used:</p>
                <div class="space-y-1 pl-2">
                    @foreach ($sale->mixingTransaction->components as $comp)
                        <div class="flex justify-between text-gray-600">
                            <span>• {{ $comp->product?->name }} ({{ $comp->product?->sku }})</span>
                            <span class="font-mono">{{ number_format((float) $comp->estimated_quantity, 3) }} {{ $comp->estimated_quantity_unit }}</span>
                        </div>
                    @endforeach
                </div>
                @if ($sale->mixingTransaction->notes)
                    <p class="mt-2 text-gray-500 italic"><span class="font-semibold not-italic">Notes:</span> {{ $sale->mixingTransaction->notes }}</p>
                @endif
            </div>
        @endif

        <!-- Financial Breakdown -->
        <div class="mt-4 border-t border-dashed border-gray-300 pt-3 space-y-1 text-xs">
            <div class="flex justify-between text-gray-600">
                <span>Original Subtotal:</span>
                <span class="font-medium text-gray-900">{{ \App\Support\Currency::format($sale->subtotal) }}</span>
            </div>

            @if ((float) $sale->discount_percentage > 0)
                <div class="flex justify-between text-emerald-700 font-semibold">
                    <span>Discount ({{ number_format((float) $sale->discount_percentage, 2) }}%):</span>
                    <span>-{{ \App\Support\Currency::format($sale->discount_amount) }}</span>
                </div>
            @endif

            <div class="flex justify-between text-sm font-black text-gray-900 border-t border-gray-200 pt-1.5 pb-1">
                <span>TOTAL DUE:</span>
                <span>{{ \App\Support\Currency::format($sale->total) }}</span>
            </div>

            <div class="flex justify-between text-gray-700">
                <span>Cash Tendered:</span>
                <span class="font-mono font-medium">{{ \App\Support\Currency::format($sale->payment_amount) }}</span>
            </div>

            <div class="flex justify-between text-gray-700">
                <span>Change Due:</span>
                <span class="font-mono font-bold text-gray-900">{{ \App\Support\Currency::format($sale->change_amount) }}</span>
            </div>

            <div class="flex justify-between text-gray-500 text-[11px] pt-1">
                <span>Payment Method:</span>
                <span class="uppercase font-semibold text-gray-700">{{ $sale->payment_method ?? 'CASH' }}</span>
            </div>
        </div>

        <!-- Receipt Footer Note -->
        <div class="mt-6 border-t border-dashed border-gray-300 pt-4 text-center text-[11px] text-gray-500 space-y-0.5">
            <p class="font-medium text-gray-700">Thank you for patronizing Grade A Paint Center!</p>
            <p>Please inspect goods upon release.</p>
            <p class="text-[10px] text-gray-400 mt-2">Printed on {{ now()->format('Y-m-d H:i:s') }}</p>
        </div>
    </div>
</x-receipt-layout>
