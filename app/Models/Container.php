<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Container extends Model
{
    protected $fillable = [
        'product_id',
        'location',
        'identifier',
        'capacity',
        'current_quantity',
        'package_unit_id',
        'is_sealed',
        'requires_physical_check',
        'last_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'decimal:3',
            'current_quantity' => 'decimal:3',
            'is_sealed' => 'boolean',
            'requires_physical_check' => 'boolean',
            'last_verified_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function packageUnit(): BelongsTo
    {
        return $this->belongsTo(PackageUnit::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
