<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    /**
     * Update or create inventory balance for a product at a location.
     */
    public function updateInventoryBalance(int $productId, string $location, float $quantityChange): Inventory
    {
        $inventory = Inventory::firstOrCreate(
            ['product_id' => $productId, 'location' => $location],
            ['quantity' => 0]
        );

        $inventory->quantity += $quantityChange;
        $inventory->save();

        return $inventory;
    }

    /**
     * Log inventory movement.
     */
    public function logMovement(array $data): InventoryMovement
    {
        return InventoryMovement::create($data);
    }

    /**
     * Transfer stock from one location to another.
     */
    public function transferStock(Product $product, string $fromLocation, string $toLocation, float $quantity, ?int $userId = null, ?string $notes = null): void
    {
        DB::transaction(function () use ($product, $fromLocation, $toLocation, $quantity, $userId, $notes) {
            $fromInventory = $this->updateInventoryBalance($product->id, $fromLocation, -$quantity);
            $toInventory = $this->updateInventoryBalance($product->id, $toLocation, $quantity);

            $refText = 'Transferred from '.ucfirst($fromLocation).' to '.ucfirst($toLocation);
            if (! empty($notes)) {
                $refText .= ' ('.Str::limit($notes, 30).')';
            }

            $this->logMovement([
                'product_id' => $product->id,
                'location' => $fromLocation,
                'user_id' => $userId,
                'type' => 'transfer_out',
                'quantity_change' => -$quantity,
                'quantity_before' => $fromInventory->quantity + $quantity,
                'quantity_after' => $fromInventory->quantity,
                'reference_type' => Product::class,
                'reference_id' => $product->id,
                'reference_text' => $refText,
                'reason' => $notes,
            ]);

            $this->logMovement([
                'product_id' => $product->id,
                'location' => $toLocation,
                'user_id' => $userId,
                'type' => 'transfer_in',
                'quantity_change' => $quantity,
                'quantity_before' => $toInventory->quantity - $quantity,
                'quantity_after' => $toInventory->quantity,
                'reference_type' => Product::class,
                'reference_id' => $product->id,
                'reference_text' => $refText,
                'reason' => $notes,
            ]);
        });
    }

    /**
     * Deduct stock for a regular or non-paint sale: storage first, then storefront.
     */
    public function deductRegularSaleStock(Product $product, float $quantity, int $saleId, string $invoiceNumber, int $userId): void
    {
        $storageInv = Inventory::firstOrCreate(
            ['product_id' => $product->id, 'location' => 'storage'],
            ['quantity' => 0]
        );
        $storefrontInv = Inventory::firstOrCreate(
            ['product_id' => $product->id, 'location' => 'storefront'],
            ['quantity' => 0]
        );

        $storageQty = (float) $storageInv->quantity;
        $storefrontQty = (float) $storefrontInv->quantity;
        $totalAvailable = $storageQty + $storefrontQty;

        if ($totalAvailable < $quantity) {
            throw ValidationException::withMessages([
                'cart' => "Insufficient stock for {$product->name}. Total available (Storage + Storefront): {$totalAvailable}.",
            ]);
        }

        $storageDeduct = min($quantity, max(0.0, $storageQty));
        $storefrontDeduct = $quantity - $storageDeduct;

        if ($storageDeduct > 0) {
            $storageInv->decrement('quantity', $storageDeduct);
            $this->logMovement([
                'product_id' => $product->id,
                'location' => 'storage',
                'user_id' => $userId,
                'type' => 'sale',
                'quantity_change' => -$storageDeduct,
                'quantity_before' => $storageQty,
                'quantity_after' => $storageQty - $storageDeduct,
                'reference_type' => Sale::class,
                'reference_id' => $saleId,
                'reference_text' => 'Invoice #'.$invoiceNumber.' (Storage)',
            ]);
        }

        if ($storefrontDeduct > 0) {
            $storefrontInv->decrement('quantity', $storefrontDeduct);
            $this->logMovement([
                'product_id' => $product->id,
                'location' => 'storefront',
                'user_id' => $userId,
                'type' => 'sale',
                'quantity_change' => -$storefrontDeduct,
                'quantity_before' => $storefrontQty,
                'quantity_after' => $storefrontQty - $storefrontDeduct,
                'reference_type' => Sale::class,
                'reference_id' => $saleId,
                'reference_text' => 'Invoice #'.$invoiceNumber.' (Storefront)',
            ]);
        }
    }

    /**
     * Deduct stock for a repack purchase: strictly from storefront (1 liter = 0.25 gal).
     */
    public function deductRepackSaleStock(Product $product, float $repackQuantityLiters, int $saleId, string $invoiceNumber, int $userId): void
    {
        $galDeduct = $repackQuantityLiters * 0.25;

        $storefrontInv = Inventory::firstOrCreate(
            ['product_id' => $product->id, 'location' => 'storefront'],
            ['quantity' => 0]
        );

        $storefrontQty = (float) $storefrontInv->quantity;

        if ($storefrontQty < $galDeduct) {
            throw ValidationException::withMessages([
                'cart' => "Insufficient Storefront stock for {$product->name} repack. Requires {$galDeduct} gal, available in storefront: {$storefrontQty} gal. Please transfer stock from storage first.",
            ]);
        }

        $storefrontInv->decrement('quantity', $galDeduct);
        $this->logMovement([
            'product_id' => $product->id,
            'location' => 'storefront',
            'user_id' => $userId,
            'type' => 'sale',
            'quantity_change' => -$galDeduct,
            'quantity_before' => $storefrontQty,
            'quantity_after' => $storefrontQty - $galDeduct,
            'reference_type' => Sale::class,
            'reference_id' => $saleId,
            'reference_text' => "Invoice #{$invoiceNumber} (Repack {$repackQuantityLiters}L)",
        ]);
    }
}
