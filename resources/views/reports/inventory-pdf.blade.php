<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Grade A Paint Center - Inventory Report</title>
    <style>
        body {
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #1f2937;
            margin: 15px;
            line-height: 1.3;
        }
        .header {
            border-bottom: 2px solid #008fb3;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .store-name {
            font-size: 18px;
            font-weight: bold;
            color: #008fb3;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .sub-header {
            font-size: 9px;
            color: #6b7280;
            margin: 2px 0 0;
        }
        .report-title {
            font-size: 14px;
            font-weight: bold;
            color: #111827;
            margin: 8px 0 2px;
        }
        .meta-line {
            font-size: 9px;
            color: #4b5563;
            margin-bottom: 10px;
        }
        .kpi-container {
            width: 100%;
            margin-bottom: 12px;
            border-collapse: collapse;
        }
        .kpi-box {
            border: 1px solid #e5e7eb;
            background: #f9fafb;
            padding: 6px 10px;
            text-align: center;
        }
        .kpi-label {
            font-size: 8px;
            text-transform: uppercase;
            color: #6b7280;
            font-weight: bold;
            display: block;
        }
        .kpi-val {
            font-size: 13px;
            font-weight: bold;
            color: #111827;
            margin-top: 2px;
            display: block;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }
        table.data-table th, table.data-table td {
            border: 1px solid #d1d5db;
            padding: 5px 6px;
            text-align: left;
        }
        table.data-table th {
            background-color: #f3f4f6;
            font-size: 8.5px;
            font-weight: bold;
            text-transform: uppercase;
            color: #374151;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .font-mono {
            font-family: monospace;
        }
        .status-healthy {
            color: #047857;
            font-weight: bold;
        }
        .status-low {
            color: #b45309;
            font-weight: bold;
        }
        .status-out {
            color: #b91c1c;
            font-weight: bold;
        }
        .footer {
            margin-top: 15px;
            border-top: 1px solid #e5e7eb;
            padding-top: 6px;
            font-size: 8px;
            color: #9ca3af;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1 class="store-name">Grade A Paint Center</h1>
        <div class="sub-header">Lapu-Lapu St., Agdao, Davao City · POS & Inventory Management System</div>
        <div class="report-title">Inventory Stock Valuation & Balance Report</div>
        <div class="meta-line">
            Generated: {{ $generatedAt->format('F d, Y · h:i A') }}
            @if (!empty($filterLabel))
                &nbsp;|&nbsp; <strong>Filter:</strong> {{ $filterLabel }}
            @endif
            @if ($stockStatus !== 'all')
                &nbsp;|&nbsp; <strong>Scope:</strong> {{ ucfirst($stockStatus) }} Stock Only
            @endif
        </div>
    </div>

    <!-- Summary KPI Table -->
    <table class="kpi-container">
        <tr>
            <td class="kpi-box">
                <span class="kpi-label">Active SKUs</span>
                <span class="kpi-val">{{ $totalSkus }}</span>
            </td>
            <td class="kpi-box">
                <span class="kpi-label">Low Stock Items</span>
                <span class="kpi-val" style="color: #b45309;">{{ $lowStockCount }}</span>
            </td>
            <td class="kpi-box">
                <span class="kpi-label">Out of Stock</span>
                <span class="kpi-val" style="color: #b91c1c;">{{ $outOfStockCount }}</span>
            </td>
            <td class="kpi-box">
                <span class="kpi-label">Total Stock Valuation</span>
                <span class="kpi-val" style="color: #008fb3;">₱{{ number_format($totalStockValue, 2) }}</span>
            </td>
        </tr>
    </table>

    <!-- Inventory Data Table -->
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 24%;">Product Name</th>
                <th style="width: 14%;">SKU</th>
                <th style="width: 13%;">Brand</th>
                <th style="width: 14%;">Category</th>
                <th style="width: 8%;">Unit</th>
                <th style="width: 9%;" class="text-right">Price</th>
                <th style="width: 9%;" class="text-right">Stock</th>
                <th style="width: 9%;" class="text-center">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($products as $product)
                @php
                    $qty = (float) ($product->inventory?->quantity ?? 0);
                    $threshold = (float) $product->low_stock_threshold;
                    $unit = $product->packageUnit?->abbreviation ?? 'pcs';
                    
                    if ($qty <= 0) {
                        $statusClass = 'status-out';
                        $statusText = 'Out of Stock';
                    } elseif ($qty <= $threshold) {
                        $statusClass = 'status-low';
                        $statusText = 'Low Stock';
                    } else {
                        $statusClass = 'status-healthy';
                        $statusText = 'Healthy';
                    }
                @endphp
                <tr>
                    <td><strong>{{ $product->name }}</strong></td>
                    <td class="font-mono">{{ $product->sku }}</td>
                    <td>{{ $product->brand?->name ?? '—' }}</td>
                    <td>{{ $product->category?->name ?? '—' }}</td>
                    <td>{{ $unit }}</td>
                    <td class="text-right">₱{{ number_format((float) $product->selling_price, 2) }}</td>
                    <td class="text-right font-mono" style="font-weight: bold;">{{ number_format($qty, 3) }}</td>
                    <td class="text-center {{ $statusClass }}">{{ $statusText }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center" style="padding: 15px; color: #6b7280;">
                        No products match the selected criteria.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Grade A Paint Center — Confidential Store Inventory Record — Lapu-Lapu St., Agdao, Davao City
    </div>
</body>
</html>
