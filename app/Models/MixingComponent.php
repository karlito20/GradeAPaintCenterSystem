<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MixingComponent extends Model
{
    protected $fillable = ['mixing_transaction_id', 'product_id'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function mixingTransaction(): BelongsTo
    {
        return $this->belongsTo(MixingTransaction::class);
    }
}
