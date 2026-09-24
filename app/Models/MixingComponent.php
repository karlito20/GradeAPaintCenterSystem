<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MixingComponent extends Model
{
    protected $fillable = ['mixing_transaction_id', 'product_id', 'estimated_quantity', 'estimated_quantity_unit'];

    protected function casts(): array
    {
        return ['estimated_quantity' => 'decimal:3'];
    }

    public function mixingTransaction(): BelongsTo
    {
        return $this->belongsTo(MixingTransaction::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
