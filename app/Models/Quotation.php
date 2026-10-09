<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    protected $fillable = [
        'user_id',
        'quote_number',
        'customer_name',
        'customer_contact',
        'notes',
        'status',
        'subtotal',
        'discount_percentage',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'total',
        'valid_until',
        'converted_sale_id',
        'discount_type',
        'discount_reason',
        'discount_authorized_by',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_percentage' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'valid_until' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function convertedSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'converted_sale_id');
    }

    public function isConverted(): bool
    {
        return $this->converted_sale_id !== null;
    }

    public function isExpired(): bool
    {
        return $this->valid_until && $this->valid_until->isPast();
    }

    public function discountAuthorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discount_authorized_by');
    }
}
