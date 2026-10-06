<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'name',
        'goods_type',
        'description',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }
}
