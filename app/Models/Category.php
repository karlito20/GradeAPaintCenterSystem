<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $table = 'categories';

    protected $fillable = [
        'name',
        'active',
        'is_for_mixing',
    ];

    protected $casts = [
        'active' => 'boolean',
        'is_for_mixing' => 'boolean',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeForMixing(Builder $query): Builder
    {
        return $query->where('is_for_mixing', true);
    }
}
