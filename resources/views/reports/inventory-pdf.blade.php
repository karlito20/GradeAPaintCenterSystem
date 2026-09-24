<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Inventory Report</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #1f2937
        }

        h1 {
            font-size: 20px;
            margin: 0 0 4px
        }

        p {
            margin: 2px 0 12px
        }

        .metrics {
            margin-bottom: 16px
        }

        .metrics span {
            display: inline-block;
            margin-right: 24px
        }

        table {
            width: 100%;
            border-collapse: collapse
        }

        th,
        td {
            border: 1px solid #d1d5db;
            padding: 6px;
            text-align: left
        }

        th {
            background: #f3f4f6;
            font-size: 10px;
            text-transform: uppercase
        }

        .low {
            color: #b91c1c;
            font-weight: bold
        }

        .ok {
            color: #166534
        }
    </style>
</head>

<body>
    <h1>Grade A Paint Center Inventory Report</h1>
    <p>Generated {{ $generatedAt->format('F j, Y g:i A') }}</p>
    <div class="metrics"><span><strong>Active SKUs:</strong> {{ $products->count() }}</span><span><strong>Low
                stock:</strong> {{ $lowStockCount }}</span></div>
    <table>
        <thead>
            <tr>
                <th>Product</th>
                <th>SKU</th>
                <th>Brand</th>
                <th>Category</th>
                <th>Package</th>
                <th>On hand</th>
                <th>Threshold</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($products as $product)
                @php($quantity = (float) ($product->inventory?->quantity ?? 0))
                <tr>
                    <td>{{ $product->name }}</td>
                    <td>{{ $product->sku }}</td>
                    <td>{{ $product->brand?->name ?? '-' }}</td>
                    <td>{{ $product->category->name }}</td>
                    <td>{{ $product->package_size }} {{ $product->packageUnit?->abbreviation }}</td>
                    <td>{{ $quantity }}</td>
                    <td>{{ $product->low_stock_threshold }}</td>
                    <td class="{{ $quantity <= $product->low_stock_threshold ? 'low' : 'ok' }}">
                        {{ $quantity <= $product->low_stock_threshold ? 'Low stock' : 'Healthy' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>

</html>
