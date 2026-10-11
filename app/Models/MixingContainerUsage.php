<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MixingContainerUsage extends Model
{
    protected $fillable = [
        'mixing_transaction_id',
        'container_id',
        'notes',
    ];

    public function mixingTransaction(): BelongsTo
    {
        return $this->belongsTo(MixingTransaction::class);
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }
}
