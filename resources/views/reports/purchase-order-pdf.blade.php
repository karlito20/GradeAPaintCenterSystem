<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Purchase Order {{ $purchaseOrder->po_number }} - Grade A Paint Center</title>
    <style>
        body {
            font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif;
            font-size: 9px;
            color: #1f2937;
            margin: 15px;
            line-height: 1.35;
        }
        .header {
            border-bottom: 2px solid #008fb3;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .store-name {
            font-size: 16px;
            font-weight: bold;
            color: #008fb3;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .sub-header {
            font-size: 8.5px;
            color: #6b7280;
            margin: 2px 0 0;
        }
        .report-title {
            font-size: 13px;
            font-weight: bold;
            color: #111827;
            margin: 6px 0 2px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .meta-line {
            font-size: 8.5px;
            color: #4b5563;
            margin-bottom: 8px;
        }
        .info-card {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .info-card td {
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            padding: 6px 10px;
            vertical-align: top;
        }
        .field-label {
            font-size: 7.5px;
            text-transform: uppercase;
            color: #64748b;
            font-weight: bold;
            display: block;
            margin-bottom: 2px;
        }
        .field-value {
            font-size: 10px;
            font-weight: bold;
            color: #0f172a;
        }
        .status-badge {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 9px;
        }
        .status-pending { color: #1f2937; }
        .status-ordered { color: #0284c7; }
        .status-received { color: #047857; }
        .status-cancelled { color: #b91c1c; }

        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
            margin-bottom: 12px;
        }
        table.data-table th, table.data-table td {
            border: 1px solid #cbd5e1;
            padding: 5px 6px;
            text-align: left;
        }
        table.data-table th {
            background-color: #f1f5f9;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            color: #334155;
            letter-spacing: 0.3px;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .font-mono { font-family: 'DejaVu Sans Mono', 'DejaVu Sans', monospace; }
        .font-bold { font-weight: bold; }

        .notes-box {
            border: 1px solid #fed7aa;
            background-color: #fffbeb;
            padding: 6px 10px;
            border-radius: 3px;
            margin-bottom: 12px;
            font-size: 8.5px;
            color: #78350f;
        }

        .signatures {
            margin-top: 25px;
            margin-bottom: 15px;
        }
        .signatures table {
            width: 100%;
            border-collapse: collapse;
        }
        .signatures td {
            width: 50%;
            vertical-align: top;
            padding: 0 10px;
        }
        .sig-label {
            font-size: 8px;
            font-weight: bold;
            color: #475569;
            text-transform: uppercase;
            margin-bottom: 35px;
        }
        .sig-line {
            border-bottom: 1.5px solid #0f172a;
            width: 100%;
            margin-bottom: 4px;
        }
        .sig-name {
            font-size: 9.5px;
            font-weight: bold;
            color: #0f172a;
        }
        .sig-sub {
            font-size: 8px;
            color: #475569;
        }

        .footer {
            margin-top: 20px;
            border-top: 1px solid #e2e8f0;
            padding-top: 6px;
            font-size: 7.5px;
            color: #94a3b8;
            text-align: center;
        }
    </style>
</head>
<body>
    <!-- Header Block -->
    <div class="header">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="vertical-align: top;">
                    <div class="store-name">Grade A Paint Center</div>
                    <div class="sub-header">Lapu-Lapu St., Agdao, Davao City</div>
                    <div class="report-title">Purchase Order Document</div>
                </td>
                <td style="vertical-align: top; text-align: right;">
                    <div style="font-size: 14px; font-weight: bold; color: #008fb3; font-family: 'DejaVu Sans Mono', monospace;">
                        {{ $purchaseOrder->po_number }}
                    </div>
                    <div class="meta-line" style="margin-top: 3px;">
                        Date: <strong>{{ $purchaseOrder->order_date ? $purchaseOrder->order_date->format('M d, Y') : now()->format('M d, Y') }}</strong>
                    </div>
                    @php
                        $statusClass = match ($purchaseOrder->status) {
                            'pending' => 'status-pending',
                            'ordered' => 'status-ordered',
                            'received' => 'status-received',
                            'cancelled' => 'status-cancelled',
                            default => '',
                        };
                    @endphp
                    <div>
                        <span class="status-badge {{ $statusClass }}">Status: {{ strtoupper($purchaseOrder->status) }}</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- PO Information Details Grid -->
    <table class="info-card">
        <tr>
            <td style="width: 25%;">
                <span class="field-label">Supplier / Vendor</span>
                <span class="field-value">{{ $purchaseOrder->supplier_name }}</span>
            </td>
            <td style="width: 25%;">
                <span class="field-label">Created By</span>
                <span class="field-value">{{ $purchaseOrder->user?->name ?? 'Store Staff' }}</span>
            </td>
            <td style="width: 25%;">
                <span class="field-label">Expected Delivery</span>
                <span class="field-value font-mono">
                    {{ $purchaseOrder->expected_delivery_date ? $purchaseOrder->expected_delivery_date->format('M d, Y') : 'Not specified' }}
                </span>
            </td>
            <td style="width: 25%;">
                <span class="field-label">Received At</span>
                <span class="field-value font-mono">
                    {{ $purchaseOrder->received_at ? $purchaseOrder->received_at->format('M d, Y H:i') : 'Pending' }}
                </span>
            </td>
        </tr>
    </table>

    @if ($purchaseOrder->notes)
        <div class="notes-box">
            <strong>Order Notes / Instructions:</strong> {{ $purchaseOrder->notes }}
        </div>
    @endif

    <!-- Line Items Table (Column Order: #, SKU, Brand, Product Name, Unit, Qty, Unit Cost, Subtotal) -->
    <table class="data-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 25px;">#</th>
                <th style="width: 95px;">SKU</th>
                <th style="width: 85px;">Brand</th>
                <th>Product Description</th>
                <th class="text-center" style="width: 45px;">Unit</th>
                <th class="text-right" style="width: 55px;">Qty</th>
                <th class="text-right" style="width: 75px;">Unit Cost</th>
                <th class="text-right" style="width: 85px;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @php
                $totalQty = 0;
            @endphp
            @forelse ($purchaseOrder->items as $index => $item)
                @php
                    $qty = (float) $item->quantity;
                    $cost = (float) $item->unit_cost;
                    $subtotal = (float) $item->subtotal;
                    $totalQty += $qty;
                @endphp
                <tr>
                    <td class="text-center font-mono">{{ $index + 1 }}</td>
                    <td class="font-mono">{{ $item->product?->sku ?? '—' }}</td>
                    <td>{{ $item->product?->brand?->name ?? '—' }}</td>
                    <td class="font-bold">{{ $item->product?->name ?? 'Unknown Product' }}</td>
                    <td class="text-center">{{ $item->product?->packageUnit?->abbreviation ?? ($item->product?->packageUnit?->name ?? '—') }}</td>
                    <td class="text-right font-mono">{{ number_format($qty, 2) }}</td>
                    <td class="text-right font-mono">{{ \App\Support\Currency::format($cost) }}</td>
                    <td class="text-right font-mono font-bold">{{ \App\Support\Currency::format($subtotal) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center" style="padding: 15px; color: #64748b;">
                        No line items found in this purchase order.
                    </td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr style="background-color: #f8fafc; font-weight: bold;">
                <td colspan="5" class="text-right" style="font-size: 8.5px; text-transform: uppercase; color: #475569;">
                    Total Units:
                </td>
                <td class="text-right font-mono" style="font-size: 9px; color: #0f172a;">
                    {{ number_format($totalQty, 2) }}
                </td>
                <td class="text-right" style="font-size: 8.5px; text-transform: uppercase; color: #475569;">
                    Total Amount:
                </td>
                <td class="text-right font-mono" style="font-size: 10px; color: #008fb3;">
                    {{ \App\Support\Currency::format($purchaseOrder->total_amount) }}
                </td>
            </tr>
        </tfoot>
    </table>

    <!-- Signatory Section -->
    <div class="signatures">
        <table>
            <tr>
                <td>
                    <div class="sig-label">Prepared By:</div>
                    <div class="sig-line"></div>
                    <div class="sig-name">{{ $generatedBy }}</div>
                    <div class="sig-sub">{{ $generatedRole }}</div>
                    <div class="sig-sub" style="margin-top: 2px;">Date: {{ now()->format('M d, Y') }}</div>
                </td>
                <td>
                    <div class="sig-label">Supplier Conforme / Received By:</div>
                    <div class="sig-line"></div>
                    <div class="sig-name">{{ $purchaseOrder->supplier_name }}</div>
                    <div class="sig-sub">Representative Signature</div>
                    <div class="sig-sub" style="margin-top: 2px;">Date: ____________________</div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
