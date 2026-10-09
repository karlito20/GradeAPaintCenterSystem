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
        'customer_name',
        'customer_contact',
        'invoice_number',
        'sold_at',
        'type',
        'subtotal',
        'discount_percentage',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'total',
        'payment_method',
        'payment_amount',
        'change_amount',
        'quotation_id',
        'discount_type',
        'discount_reason',
        'discount_authorized_by',
    ];

    protected function casts(): array
    {
        return [
            'sold_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_percentage' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'payment_amount' => 'decimal:2',
            'change_amount' => 'decimal:2',
        ];
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

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function discountAuthorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discount_authorized_by');
    }
}
