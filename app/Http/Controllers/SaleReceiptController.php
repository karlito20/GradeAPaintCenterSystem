<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use Illuminate\View\View;

class SaleReceiptController extends Controller
{
    public function __invoke(Sale $sale): View
    {
        return view('sales.receipt', [
            'sale' => $sale->load([
                'user',
                'items.product.packageUnit',
                'items.product.brand',
                'mixingTransaction.priceBasisProduct',
                'mixingTransaction.components.product.packageUnit',
            ]),
        ]);
    }
}
