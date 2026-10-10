<?php

namespace App\Http\Controllers;

use App\Models\Quotation;
use Illuminate\View\View;

class QuotationPrintController extends Controller
{
    public function __invoke(Quotation $quotation): View
    {
        abort_unless(auth()->user()?->canViewQuotations(), 403);

        return view('sales.quotation-print', [
            'quotation' => $quotation->load([
                'user',
                'items.product.packageUnit',
                'items.product.brand',
                'discountAuthorizer',
                'convertedSale',
            ]),
        ]);
    }
}
