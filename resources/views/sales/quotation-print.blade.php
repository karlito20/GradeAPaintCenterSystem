<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quotation {{ $quotation->quote_number }} - Grade A Paint Center</title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
    <!-- Scripts & Styles -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @media print {
            body {
                background: white !important;
                color: black !important;
                margin: 0 !important;
                padding: 0 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .no-print, .print\:hidden {
                display: none !important;
            }
            .print-container {
                box-shadow: none !important;
                border: none !important;
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            @page {
                margin: 12mm;
                size: auto;
            }
        }
    </style>
</head>
<body class="font-sans antialiased bg-slate-100 text-slate-900 min-h-screen py-6 px-4 print:p-0 print:bg-white">
    <div class="mx-auto max-w-4xl">
        <!-- Screen Actions Toolbar (Hidden on print) -->
        <div class="mb-4 flex items-center justify-between gap-2 print:hidden">
            <div class="flex items-center gap-2">
                <a href="{{ route('sales.quotations') }}"
                    class="inline-flex items-center gap-1.5 rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-xs hover:bg-slate-50 transition">
                    <svg class="h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    <span>Back to Quotations</span>
                </a>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="window.print()" type="button"
                    class="inline-flex items-center gap-1.5 rounded bg-[#00a3cc] px-4 py-1.5 text-xs font-bold text-white shadow-xs hover:bg-[#008fb3] transition">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    <span>Print Quotation</span>
                </button>
            </div>
        </div>

        <!-- Official Formal Quotation Container -->
        <div class="print-container rounded-lg border border-slate-300 bg-white p-8 sm:p-10 shadow-xs text-xs font-sans print:border-none print:shadow-none print:p-0">
            <!-- Formal Company Header -->
            <div class="border-b-2 border-[#00a3cc] pb-4 mb-5 flex flex-col sm:flex-row sm:items-start justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-black uppercase tracking-tight text-slate-900 font-heading">
                        Grade A Paint Center
                    </h1>
                    <p class="text-xs font-medium text-slate-600 mt-0.5">Retail Paint, Tinting &amp; Painting Supplies</p>
                    <p class="text-[11px] text-slate-500">Lapu-Lapu St., Agdao, Davao City</p>
                    <p class="text-[11px] text-slate-500">Contact: (082) 227-1234 · info@gradeapaint.com</p>
                </div>
                <div class="sm:text-right">
                    <span class="inline-block rounded bg-sky-50 border border-sky-200 text-[#008fb3] font-bold text-xs uppercase px-2.5 py-1 tracking-wider mb-1.5">
                        Price Quotation
                    </span>
                    <p class="font-mono text-sm font-bold text-slate-900">{{ $quotation->quote_number }}</p>
                    <p class="text-[11px] text-slate-500">Date Issued: <strong class="text-slate-800">{{ $quotation->created_at->format('F d, Y') }}</strong></p>
                    @if ($quotation->valid_until)
                        <p class="text-[11px] text-slate-500">Valid Until: <strong class="text-slate-800">{{ $quotation->valid_until->format('F d, Y') }}</strong></p>
                    @endif
                </div>
            </div>

            <!-- Client & Quotation Meta Grid -->
            <div class="grid grid-cols-2 gap-4 rounded-md border border-slate-200 bg-slate-50/70 p-4 mb-6 text-xs">
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500 block mb-1">Quotation Prepared For:</span>
                    <p class="text-sm font-bold text-slate-900">{{ $quotation->customer_name ?: 'Walk-in / Valued Client' }}</p>
                    @if ($quotation->customer_contact)
                        <p class="text-slate-600 mt-0.5">Contact: {{ $quotation->customer_contact }}</p>
                    @endif
                </div>
                <div class="text-right">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500 block mb-1">Quotation Status:</span>
                    @php
                        $statusBadge = match($quotation->status) {
                            'draft' => 'bg-slate-100 text-slate-700 border-slate-300',
                            'sent' => 'bg-sky-50 text-sky-800 border-sky-300',
                            'accepted' => 'bg-emerald-50 text-emerald-800 border-emerald-300',
                            'expired' => 'bg-amber-50 text-amber-800 border-amber-300',
                            'cancelled' => 'bg-rose-50 text-rose-800 border-rose-300',
                            default => 'bg-slate-100 text-slate-700 border-slate-300',
                        };
                    @endphp
                    <span class="inline-block px-2 py-0.5 text-[10px] font-bold uppercase rounded border {{ $statusBadge }}">
                        {{ $quotation->status }}
                    </span>
                    <p class="text-slate-600 mt-1">Prepared By: <strong class="text-slate-800">{{ $quotation->user?->name ?? 'Sales Representative' }}</strong></p>
                </div>
            </div>

            <!-- Itemized Line Items Table -->
            <div class="overflow-x-auto mb-6">
                <table class="w-full border-collapse border border-slate-300 text-xs">
                    <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-300">
                        <tr>
                            <th class="border border-slate-300 px-3 py-2 text-left w-12 text-center">#</th>
                            <th class="border border-slate-300 px-3 py-2 text-left w-24">SKU</th>
                            <th class="border border-slate-300 px-3 py-2 text-left">Description / Specification</th>
                            <th class="border border-slate-300 px-3 py-2 text-left w-24">Unit / Spec</th>
                            <th class="border border-slate-300 px-3 py-2 text-right w-16">Qty</th>
                            <th class="border border-slate-300 px-3 py-2 text-right w-24">Unit Price</th>
                            <th class="border border-slate-300 px-3 py-2 text-right w-28">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @foreach ($quotation->items as $index => $item)
                            <tr class="hover:bg-slate-50">
                                <td class="border border-slate-200 px-3 py-2 text-center text-slate-500 tabular-nums">
                                    {{ $index + 1 }}
                                </td>
                                <td class="border border-slate-200 px-3 py-2 font-mono text-[11px] text-slate-600">
                                    {{ $item->sku ?? ($item->product?->sku ?? 'CUSTOM-MIX') }}
                                </td>
                                <td class="border border-slate-200 px-3 py-2">
                                    <div class="font-semibold text-slate-900">{{ $item->description }}</div>
                                    @if ($item->mix_data)
                                        <div class="text-[10px] text-purple-700 mt-0.5">Special paint tint &amp; formula mix</div>
                                    @endif
                                </td>
                                <td class="border border-slate-200 px-3 py-2 text-slate-600">
                                    {{ $item->package ?? ($item->product?->formattedPackage() ?? $item->product?->packageUnit?->abbreviation ?? '—') }}
                                </td>
                                <td class="border border-slate-200 px-3 py-2 text-right tabular-nums text-slate-900 font-medium">
                                    {{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }}
                                </td>
                                <td class="border border-slate-200 px-3 py-2 text-right tabular-nums text-slate-700">
                                    {{ \App\Support\Currency::format($item->unit_price) }}
                                </td>
                                <td class="border border-slate-200 px-3 py-2 text-right tabular-nums font-bold text-slate-900">
                                    {{ \App\Support\Currency::format($item->subtotal) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Financial Summary Box -->
            <div class="flex flex-col sm:flex-row justify-between items-start gap-4 mb-6">
                <!-- Notes & Terms -->
                <div class="flex-1 w-full sm:max-w-md">
                    @if ($quotation->notes)
                        <div class="rounded border border-slate-200 bg-slate-50 p-3 text-xs mb-3">
                            <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-600 mb-0.5">Special Notes / Remarks:</span>
                            <p class="text-slate-700 whitespace-pre-line">{{ $quotation->notes }}</p>
                        </div>
                    @endif

                    <div class="text-[10px] text-slate-500 space-y-1">
                        <p class="font-semibold text-slate-600 uppercase tracking-wider">Terms &amp; Conditions:</p>
                        <p>1. Prices quoted are valid until {{ $quotation->valid_until ? $quotation->valid_until->format('F d, Y') : 'the specified validity date' }}.</p>
                        <p>2. Payment terms: Cash, check, or approved store terms upon delivery/pickup.</p>
                        <p>3. Custom color tint mixes and special order items are non-refundable once mixed.</p>
                        <p>4. Goods remain property of Grade A Paint Center until full settlement.</p>
                    </div>
                </div>

                <!-- Financial Calculation -->
                <div class="w-full sm:w-72 rounded border border-slate-300 bg-slate-50 p-3 space-y-2 text-xs">
                    <div class="flex justify-between text-slate-600">
                        <span>Subtotal:</span>
                        <span class="font-medium text-slate-900 tabular-nums">{{ \App\Support\Currency::format($quotation->subtotal) }}</span>
                    </div>

                    @if ((float) $quotation->discount_percentage > 0)
                        <div class="flex justify-between text-emerald-700 font-semibold">
                            <span>Discount ({{ number_format((float) $quotation->discount_percentage, 2) }}%{{ $quotation->discount_type && $quotation->discount_type !== 'none' ? ' · ' . ucfirst(str_replace('_', ' ', $quotation->discount_type)) : '' }}):</span>
                            <span class="tabular-nums">-{{ \App\Support\Currency::format($quotation->discount_amount) }}</span>
                        </div>
                        @if ($quotation->discount_reason)
                            <div class="text-[10px] text-slate-500 italic pl-1 -mt-1">
                                Reason: {{ $quotation->discount_reason }}
                            </div>
                        @endif
                    @endif

                    <div class="border-t-2 border-slate-300 pt-2 flex justify-between items-baseline">
                        <span class="text-sm font-bold text-slate-900">Total Quoted Amount:</span>
                        <span class="text-base font-black text-slate-900 tabular-nums">{{ \App\Support\Currency::format($quotation->total) }}</span>
                    </div>
                </div>
            </div>

            <!-- Formal Signature Space -->
            <div class="pt-6 border-t border-slate-300 mt-8">
                <div class="grid grid-cols-2 gap-8 text-xs">
                    <!-- Prepared by signature -->
                    <div>
                        <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-10">
                            Prepared &amp; Issued By:
                        </span>
                        <div class="border-b border-slate-900 mb-1.5 w-64"></div>
                        <p class="font-bold text-slate-900">{{ $quotation->user?->name ?? 'Authorized Personnel' }}</p>
                        <p class="text-[11px] text-slate-500">Sales Representative / Grade A Paint Center</p>
                        <p class="text-[10px] text-slate-400 mt-1">Date Signed: <span class="font-mono">____________________</span></p>
                    </div>

                    <!-- Client Conforme signature -->
                    <div>
                        <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-10">
                            Conforme / Accepted By:
                        </span>
                        <div class="border-b border-slate-900 mb-1.5 w-64"></div>
                        <p class="font-bold text-slate-900">{{ $quotation->customer_name ?: 'Authorized Client Representative' }}</p>
                        <p class="text-[11px] text-slate-500">Signature over Printed Name</p>
                        <p class="text-[10px] text-slate-400 mt-1">Date Signed: <span class="font-mono">____________________</span></p>
                    </div>
                </div>
            </div>

            <!-- Document Footer Note -->
            <div class="mt-8 pt-3 border-t border-slate-200 text-center text-[10px] text-slate-400">
                Grade A Paint Center — Official Price Quotation Document — Lapu-Lapu St., Agdao, Davao City
            </div>
        </div>
    </div>
</body>
</html>
