<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhysicalInventoryItem extends Model
{
    protected $fillable = ['physical_inventory_id', 'product_id', 'system_quantity', 'physical_quantity', 'variance', 'reason'];

    protected function casts(): array
    {
        return ['system_quantity' => 'decimal:3', 'physical_quantity' => 'decimal:3', 'variance' => 'decimal:3'];
    }

    public function physicalInventory(): BelongsTo
    {
        return $this->belongsTo(PhysicalInventory::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
