<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MixingTransaction extends Model
{
    protected $fillable = ['sale_id', 'price_basis_product_id', 'resulting_quantity', 'resulting_unit', 'notes'];

    protected function casts(): array
    {
        return ['resulting_quantity' => 'decimal:3'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function priceBasisProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'price_basis_product_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(MixingComponent::class);
    }
}
