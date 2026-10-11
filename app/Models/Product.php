<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'requires_physical_check',
    ];

    protected function casts(): array
    {
        return [
            'package_size' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'low_stock_threshold' => 'decimal:2',
            'active' => 'boolean',
            'requires_physical_check' => 'boolean',
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

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function getInventory(string $location = 'storage'): ?Inventory
    {
        return $this->inventories()->where('location', $location)->first();
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

    public function isPaint(): bool
    {
        $catName = strtolower($this->category?->name ?? '');
        if (str_contains($catName, 'tool') || str_contains($catName, 'hardware')) {
            return false;
        }

        $unitAbbr = strtolower($this->packageUnit?->abbreviation ?? '');
        $unitName = strtolower($this->packageUnit?->name ?? '');
        if (in_array($unitAbbr, ['gal', 'gallon']) || in_array($unitName, ['gallon', 'gal'])) {
            return true;
        }

        return str_contains($catName, 'paint')
            || str_contains($catName, 'tint')
            || str_contains($catName, 'mixing')
            || str_contains($catName, 'acrylic');
    }

    public function canBeRepacked(): bool
    {
        $catName = strtolower($this->category?->name ?? '');
        if (str_contains($catName, 'primer') || str_contains($catName, 'catalyst') || str_contains($catName, 'tool')) {
            return false;
        }

        $unitAbbr = strtolower($this->packageUnit?->abbreviation ?? '');

        return $this->isPaint() && in_array($unitAbbr, ['gal', 'gallon', 'gals']);
    }

    public function getStorageStock(): float
    {
        return (float) ($this->inventories->where('location', 'storage')->first()?->quantity ?? 0);
    }

    public function getStorefrontStock(): float
    {
        return (float) ($this->inventories->where('location', 'storefront')->first()?->quantity ?? 0);
    }

    public function getTotalAvailableStock(): float
    {
        return $this->getStorageStock() + $this->getStorefrontStock();
    }
}
