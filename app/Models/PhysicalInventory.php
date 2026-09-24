<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PhysicalInventory extends Model
{
    protected $fillable = ['user_id', 'counted_at', 'status', 'notes'];

    protected function casts(): array
    {
        return ['counted_at' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PhysicalInventoryItem::class);
    }
}
