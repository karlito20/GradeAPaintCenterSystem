<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MixingTransaction extends Model
{
    protected $fillable = [
        'sale_id',
        'brand_id',
        'custom_color',
        'price_basis_product_id',
        'price_basis',
        'resulting_quantity',
        'resulting_unit',
        'notes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'resulting_quantity' => 'decimal:2',
            'price_basis' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function priceBasisProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'price_basis_product_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(MixingComponent::class);
    }

    public function containerUsages(): HasMany
    {
        return $this->hasMany(MixingContainerUsage::class);
    }
}
