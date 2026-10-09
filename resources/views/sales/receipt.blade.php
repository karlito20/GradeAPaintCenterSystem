<x-receipt-layout>
    <!-- Screen Actions Toolbar (Hidden when printing) -->
    <div class="mb-3 flex items-center justify-between gap-2 print:hidden">
        <div class="flex items-center gap-1.5">
            <a href="{{ route('sales.index') }}"
                class="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 shadow-xs hover:bg-slate-50 transition">
                <svg class="h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span>POS</span>
            </a>
            <a href="{{ route('sales.history') }}"
                class="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 shadow-xs hover:bg-slate-50 transition">
                <span>History</span>
            </a>
        </div>
        <div>
            <button onclick="window.print()" type="button"
                class="inline-flex items-center gap-1.5 rounded bg-[#00a3cc] px-3.5 py-1 text-xs font-bold text-white shadow-xs hover:bg-[#008fb3] transition">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                <span>Print Receipt</span>
            </button>
        </div>
    </div>

    <!-- Official Printable Receipt Container -->
    <div class="print-receipt-container rounded border border-slate-300 bg-white p-4 sm:p-5 shadow-xs text-xs font-sans print:border-none print:shadow-none print:p-0">
        <!-- Store Information & Receipt Header -->
        <div class="text-center border-b border-dashed border-slate-300 pb-2.5">
            <h1 class="text-base font-black uppercase tracking-wider text-slate-900 font-heading">Grade A Paint Center</h1>
            <p class="text-[11px] text-slate-600">Retail Paint, Tinting &amp; Painting Supplies</p>
            <p class="text-[10px] text-slate-500">Lapu-Lapu St., Agdao, Davao City</p>
            <p class="text-[10px] font-bold text-slate-700 tracking-wider uppercase mt-1">Official Sales Receipt</p>
        </div>

        <!-- Transaction Meta -->
        <div class="mt-2.5 grid grid-cols-2 gap-2 text-[11px] border-b border-dashed border-slate-300 pb-2.5">
            <div class="space-y-0.5">
                <p><span class="text-slate-500">Invoice:</span> <strong class="tabular-nums text-slate-900">{{ $sale->invoice_number }}</strong></p>
                <p><span class="text-slate-500">Date:</span> <span class="tabular-nums text-slate-800">{{ $sale->sold_at->format('M d, Y h:i A') }}</span></p>
                @if ($sale->customer_name)
                    <p><span class="text-slate-500">Customer:</span> <strong class="text-slate-900">{{ $sale->customer_name }}</strong>{{ $sale->customer_contact ? ' (' . $sale->customer_contact . ')' : '' }}</p>
                @endif
            </div>
            <div class="text-right space-y-0.5">
                <p><span class="text-slate-500">Cashier:</span> <span class="text-slate-900 font-medium">{{ $sale->user?->name ?? 'Staff' }}</span></p>
                <p><span class="text-slate-500">Type:</span> <span class="capitalize font-semibold text-slate-900">{{ str_replace('_', ' ', $sale->type) }}</span></p>
                @if ($sale->quotation)
                    <p><span class="text-slate-500">Quote:</span> <span class="font-mono text-slate-800">{{ $sale->quotation->quote_number }}</span></p>
                @endif
            </div>
        </div>

        <!-- Line Items Table -->
        <table class="mt-2.5 min-w-full text-left text-xs">
            <thead>
                <tr class="text-[10px] font-bold text-slate-500 uppercase border-b border-slate-300">
                    <th class="py-1 text-left">Item</th>
                    <th class="py-1 text-center w-12">Qty</th>
                    <th class="py-1 text-right w-16">Price</th>
                    <th class="py-1 text-right w-20">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($sale->items as $item)
                    <tr>
                        <td class="py-1.5 pr-2">
                            <div class="font-semibold text-slate-900 leading-tight">{{ $item->description }}</div>
                            <div class="text-[10px] text-slate-500 leading-tight">
                                {{ $item->product?->sku ?? 'CUSTOM-MIX' }}
                                @if ($item->product?->formattedPackage())
                                    · {{ $item->product->formattedPackage() }}
                                @elseif ($item->product?->packageUnit)
                                    · {{ $item->product->packageUnit->abbreviation }}
                                @endif
                            </div>
                        </td>
                        <td class="py-1.5 text-center tabular-nums text-slate-700 whitespace-nowrap">
                            {{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }}
                        </td>
                        <td class="py-1.5 text-right tabular-nums text-slate-600 whitespace-nowrap">
                            {{ \App\Support\Currency::format($item->unit_price) }}
                        </td>
                        <td class="py-1.5 text-right tabular-nums font-bold text-slate-900 whitespace-nowrap">
                            {{ \App\Support\Currency::format($item->subtotal) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Custom Mix Component Details (if present) -->
        @if ($sale->mixingTransaction)
            <div class="mt-2.5 rounded border border-slate-200 bg-slate-50 p-2 text-[10px] print:border print:border-slate-300">
                <div class="flex items-center justify-between font-bold text-slate-800 border-b border-slate-200 pb-1 mb-1">
                    <span>Formula Specification</span>
                    <span>Result: {{ number_format((float) $sale->mixingTransaction->resulting_quantity, 2) }} {{ $sale->mixingTransaction->resulting_unit }}</span>
                </div>
                <div class="space-y-0.5 pl-1">
                    @foreach ($sale->mixingTransaction->components as $comp)
                        <div class="flex justify-between text-slate-600">
                            <span>• {{ $comp->product?->name }}</span>
                            <span class="font-mono">{{ number_format((float) $comp->estimated_quantity, 2) }} {{ $comp->estimated_quantity_unit }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Financial Breakdown -->
        <div class="mt-2.5 border-t border-dashed border-slate-300 pt-2 space-y-1 text-xs">
            <div class="flex justify-between text-slate-600">
                <span>Subtotal:</span>
                <span class="font-medium text-slate-900">{{ \App\Support\Currency::format($sale->subtotal) }}</span>
            </div>

            @if ((float) $sale->discount_percentage > 0)
                <div class="flex justify-between text-emerald-700 font-semibold">
                    <span>Discount ({{ number_format((float) $sale->discount_percentage, 2) }}%{{ $sale->discount_type && $sale->discount_type !== 'none' ? ' · ' . ucfirst(str_replace('_', ' ', $sale->discount_type)) : '' }}):</span>
                    <span>-{{ \App\Support\Currency::format($sale->discount_amount) }}</span>
                </div>
                @if ($sale->discount_reason)
                    <div class="text-[10px] text-slate-600 pl-2 -mt-0.5">
                        <span class="text-slate-400">Reason:</span> {{ $sale->discount_reason }}
                        @if ($sale->discountAuthorizer)
                            <span class="text-slate-500 font-medium"> (Auth: {{ $sale->discountAuthorizer->name }})</span>
                        @endif
                    </div>
                @endif
            @endif

            @if ((float) $sale->tax_amount > 0 || (float) $sale->tax_rate > 0)
                <div class="flex justify-between text-slate-600">
                    <span>12% VAT Included:</span>
                    <span>{{ \App\Support\Currency::format($sale->tax_amount) }}</span>
                </div>
            @endif

            <div class="flex justify-between text-sm font-black text-slate-900 border-t border-slate-300 pt-1.5 pb-0.5">
                <span>TOTAL DUE:</span>
                <span>{{ \App\Support\Currency::format($sale->total) }}</span>
            </div>

            <div class="flex justify-between text-slate-700">
                <span>Cash Tendered:</span>
                <span class="font-mono font-medium">{{ \App\Support\Currency::format($sale->payment_amount) }}</span>
            </div>

            <div class="flex justify-between text-slate-700">
                <span>Change Due:</span>
                <span class="font-mono font-bold text-slate-900">{{ \App\Support\Currency::format($sale->change_amount) }}</span>
            </div>

            <div class="flex justify-between text-slate-500 text-[10px] pt-0.5">
                <span>Payment Method:</span>
                <span class="uppercase font-semibold text-slate-700">{{ $sale->payment_method ?? 'CASH' }}</span>
            </div>
        </div>

        <!-- Receipt Footer Note -->
        <div class="mt-3 border-t border-dashed border-slate-300 pt-2.5 text-center text-[10px] text-slate-500 space-y-0.5">
            <p class="font-medium text-slate-700">Thank you for shopping at Grade A Paint Center!</p>
            <p>Please inspect goods upon release.</p>
            <p class="text-[9px] text-slate-400 mt-1">Printed: {{ now()->format('Y-m-d H:i') }}</p>
        </div>
    </div>

    @if (request()->has('print'))
        <script>
            window.addEventListener('load', () => {
                window.print();
            });
        </script>
    @endif
</x-receipt-layout>
