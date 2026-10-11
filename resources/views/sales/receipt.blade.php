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
        <div class="text-center pb-2">
            <h1 class="text-base font-bold uppercase tracking-wider text-slate-900 font-heading">Grade A Paint Center</h1>
            <p class="text-[10px] text-slate-500">Lapu-Lapu St., Agdao, Davao City</p>
            @if ($sale->mixingTransaction && in_array($sale->mixingTransaction->status, ['downpaid', 'pending_mix']))
                <p class="text-[10px] font-bold text-slate-800 tracking-wider uppercase mt-1">Custom Mix — Downpayment Receipt</p>
                <p class="text-[9px] text-slate-500">Paint order is queued for formulation</p>
            @elseif ($sale->mixingTransaction && in_array($sale->mixingTransaction->status, ['mixed', 'ready_for_pickup']))
                <p class="text-[10px] font-bold text-slate-800 tracking-wider uppercase mt-1">Custom Mix — Ready for Pickup</p>
                <p class="text-[9px] text-slate-500">Formula completed - Balance due upon release</p>
            @else
                <p class="text-[10px] font-bold text-slate-700 tracking-wider uppercase mt-1">Official Sales Receipt</p>
            @endif
        </div>

        <!-- Transaction Meta -->
        <div class="mt-2 grid grid-cols-2 gap-2 text-[11px] border-t border-slate-200 pt-2">
            <div class="space-y-0.5">
                <p><span class="text-slate-500">Invoice:</span> <strong class="tabular-nums text-slate-900">{{ $sale->invoice_number }}</strong></p>
                <p><span class="text-slate-500">Date:</span> <span class="tabular-nums text-slate-800">{{ $sale->sold_at->format('M d, Y h:i A') }}</span></p>
                @if ($sale->customer_name)
                    <p><span class="text-slate-500">Customer:</span> {{ $sale->customer_name }}{{ $sale->customer_contact ? ' (' . $sale->customer_contact . ')' : '' }}</p>
                @endif
            </div>
            <div class="text-right space-y-0.5">
                <p><span class="text-slate-500">Cashier:</span> <span class="text-slate-900 font-medium">{{ $sale->user?->name ?? 'Staff' }}</span></p>
                <p><span class="text-slate-500">Type:</span> <span class="capitalize  text-slate-900">{{ str_replace('_', ' ', $sale->type) }}</span></p>
                @if ($sale->quotation)
                    <p><span class="text-slate-500">Quote:</span> <span class="font-mono text-slate-800">{{ $sale->quotation->quote_number }}</span></p>
                @endif
            </div>
        </div>

        <!-- Line Items Table -->
        <table class="mt-2 min-w-full text-left text-xs border-t border-slate-200">
            <thead>
                <tr class="text-[10px] font-bold text-slate-500 uppercase">
                    <th class="py-1 text-left">Item</th>
                    <th class="py-1 text-center w-12">Qty</th>
                    <th class="py-1 text-right w-16">Price</th>
                    <th class="py-1 text-right w-20">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sale->items as $item)
                    <tr>
                        <td class="py-1 pr-2">
                            <div class=" text-slate-900 leading-tight">{{ $item->description }}</div>
                            <div class="text-[10px] text-slate-500 leading-tight">
                                {{ $item->product?->sku ?? 'CUSTOM-MIX' }}
                                @if ($item->product?->formattedPackage())
                                    - {{ $item->product->formattedPackage() }}
                                @elseif ($item->product?->packageUnit)
                                    - {{ $item->product->packageUnit->abbreviation }}
                                @endif
                            </div>
                        </td>
                        <td class="py-1 text-center tabular-nums text-slate-700 whitespace-nowrap">
                            {{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }}
                        </td>
                        <td class="py-1 text-right tabular-nums text-slate-600 whitespace-nowrap">
                            @if ((float) $item->unit_price > 0)
                                {{ \App\Support\Currency::format($item->unit_price) }}
                            @else
                                <span class="text-slate-400 italic">Pending</span>
                            @endif
                        </td>
                        <td class="py-1 text-right tabular-nums text-slate-900 whitespace-nowrap">
                            @if ((float) $item->subtotal > 0)
                                {{ \App\Support\Currency::format($item->subtotal) }}
                            @else
                                <span class="text-slate-400 italic font-normal">Pending</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Custom Mix Component Details (if present) -->
        @if ($sale->mixingTransaction)
            <div class="mt-2 rounded text-[10px]">
                <div class="flex items-center justify-between font-bold text-slate-800 mb-1">
                    <span>Custom Mix Specification</span>
                    <span>Output: {{ number_format((float) $sale->mixingTransaction->resulting_quantity, 2) }} {{ $sale->mixingTransaction->resulting_unit }}</span>
                </div>
                <div class="grid grid-cols-2 gap-1 text-slate-700 mb-1.5">
                    <div><span class="text-slate-500">Brand:</span> <strong>{{ $sale->mixingTransaction->brand?->name ?? '—' }}</strong></div>
                </div>
                @if ($sale->mixingTransaction->components->isNotEmpty())
                    <div class="font-semibold text-slate-700 mb-0.5">Formula Materials Used:</div>
                    <div class="space-y-0.5">
                        @foreach ($sale->mixingTransaction->components as $comp)
                            <div class="flex justify-between text-slate-600">
                                <span>{{ $comp->product?->name }}</span>
                                <span class="font-mono text-slate-500">{{ $comp->product?->sku ?? '' }}</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-slate-500 italic">Formula materials will be logged upon mixing preparation.</div>
                @endif
            </div>
        @endif

        <!-- Financial Breakdown -->
        <div class="mt-2 border-t border-slate-200 pt-2 space-y-1 text-xs">
            <div class="flex justify-between text-slate-600">
                <span>Subtotal:</span>
                <span class="font-medium text-slate-900">{{ \App\Support\Currency::format($sale->subtotal) }}</span>
            </div>

            @if ((float) $sale->discount_percentage > 0 || (float) $sale->discount_amount > 0)
                <div class="flex justify-between text-emerald-700 font-semibold">
                    <span>Discount ({{ number_format((float) $sale->discount_percentage, 2) }}%{{ $sale->discount_type && $sale->discount_type !== 'none' ? ' - ' . ucfirst(str_replace('_', ' ', $sale->discount_type)) : '' }}):</span>
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

            @if ($sale->mixingTransaction && in_array($sale->mixingTransaction->status, ['downpaid', 'pending_mix']))
                <div class="flex justify-between text-sm font-bold text-slate-900 pt-1">
                    <span>DOWNPAYMENT PAID:</span>
                    <span>{{ \App\Support\Currency::format($sale->downpayment_amount) }}</span>
                </div>
                <div class="flex justify-between text-slate-600 text-[11px]">
                    <span>Final Total &amp; Balance:</span>
                    <span class="italic text-slate-500">Determined upon mixing</span>
                </div>
            @else
                <div class="flex justify-between text-sm font-bold text-slate-900 pt-1">
                    <span>TOTAL DUE:</span>
                    <span>{{ \App\Support\Currency::format($sale->total) }}</span>
                </div>

                @if ((float) $sale->downpayment_amount > 0 || (float) $sale->balance_due > 0)
                    <div class="flex justify-between text-slate-700">
                        <span>Downpayment Paid:</span>
                        <span class="font-mono font-medium">{{ \App\Support\Currency::format($sale->downpayment_amount) }}</span>
                    </div>
                    @if ((float) $sale->balance_due > 0)
                        <div class="flex justify-between text-slate-900 font-semibold">
                            <span>Balance Due:</span>
                            <span class="font-mono font-bold">{{ \App\Support\Currency::format($sale->balance_due) }}</span>
                        </div>
                    @else
                        <div class="flex justify-between text-slate-700">
                            <span>Remaining Balance:</span>
                            <span class="font-mono font-medium">₱0.00</span>
                        </div>
                    @endif
                    <div class="flex justify-between text-slate-700">
                        <span>Payment Status:</span>
                        <span class=" text-slate-800">
                            {{ str_replace('_', ' ', $sale->payment_status) }}
                        </span>
                    </div>
                @endif
            @endif

            <div class="flex justify-between text-slate-700">
                <span>Cash Tendered:</span>
                <span class="font-mono font-medium">{{ \App\Support\Currency::format($sale->payment_amount) }}</span>
            </div>

            <div class="flex justify-between text-slate-700">
                <span>Change Due:</span>
                <span class="font-mono  text-slate-900">{{ \App\Support\Currency::format($sale->change_amount) }}</span>
            </div>

            <div class="flex justify-between text-slate-500 text-[10px] pt-0.5">
                <span>Payment Method:</span>
                <span class="  text-slate-700">{{ $sale->payment_method ?? 'CASH' }}</span>
            </div>
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
