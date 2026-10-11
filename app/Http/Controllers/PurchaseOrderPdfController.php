<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PurchaseOrderPdfController extends Controller
{
    public function __invoke(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        abort_unless(auth()->user()?->canManagePurchaseOrders(), 403);

        $purchaseOrder->load([
            'user',
            'items.product.brand',
            'items.product.category',
            'items.product.packageUnit',
        ]);

        $user = auth()->user();
        $generatedBy = $user?->name ?? 'Store Staff';
        $generatedRole = $user ? ucfirst($user->role) : 'Personnel';
        $filename = "grade-a-paint-purchase-order-{$purchaseOrder->po_number}.pdf";

        $pdf = Pdf::loadView('reports.purchase-order-pdf', [
            'purchaseOrder' => $purchaseOrder,
            'generatedAt' => now(),
            'generatedBy' => $generatedBy,
            'generatedRole' => $generatedRole,
        ])->setPaper('a4', 'portrait');

        if ($request->query('download') === '1') {
            return $pdf->download($filename);
        }

        return $pdf->stream($filename);
    }
}
