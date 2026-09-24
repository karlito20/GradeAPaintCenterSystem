<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Sale extends Model
{
    protected $fillable = [
        'user_id',
        'invoice_number',
        'sold_at',
        'type',
        'subtotal',
        'total',
        'payment_method',
        'payment_amount',
        'change_amount',
    ];

    protected function casts(): array
    {
        return ['sold_at' => 'datetime', 'subtotal' => 'decimal:2', 'total' => 'decimal:2', 'payment_amount' => 'decimal:2', 'change_amount' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function mixingTransaction(): HasOne
    {
        return $this->hasOne(MixingTransaction::class);
    }
}
