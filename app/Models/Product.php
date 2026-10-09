<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    protected $fillable = [
        'category_id',
        'brand_id',
        'package_unit_id',
        'sku',
        'name',
        'package_size',
        'selling_price',
        'low_stock_threshold',
        'manufacturer_code',
        'description',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'package_size' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'low_stock_threshold' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function packageUnit(): BelongsTo
    {
        return $this->belongsTo(PackageUnit::class);
    }

    public function inventory(): HasOne
    {
        return $this->hasOne(Inventory::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function formattedPackage(): string
    {
        $unit = $this->packageUnit?->abbreviation ?? '';
        $unitName = strtolower($this->packageUnit?->name ?? '');

        if ($unit === 'pail' || str_contains($unitName, 'pail')) {
            return '16L Pail';
        }

        if ($unit === 'gal' || str_contains($unitName, 'gallon')) {
            return '4L Gallon';
        }

        if ($unit === '1/2 L' || str_contains($unitName, '1/2') || str_contains($unitName, 'pint')) {
            return '1/2L Pint';
        }

        if ($unit === '1/4 L' || str_contains($unitName, '1/4')) {
            return '1/4L Can';
        }

        if ($unit === '1/8 L' || str_contains($unitName, '1/8')) {
            return '1/8L Can';
        }

        if ($unit === 'L' || str_contains($unitName, 'liter')) {
            return '1L Can';
        }

        $size = (float) $this->package_size;
        $formattedSize = $size > 0 ? rtrim(rtrim((string) $size, '0'), '.') : '';

        if ($formattedSize && $unit) {
            return $formattedSize.' '.$unit;
        }

        return $unit ?: ($this->packageUnit?->name ?? '—');
    }
}
