<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForecastRecommendation extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'item_id',
        'week_start',
        'avg_weekly',
        'forecast_qty',
        'recommend_qty',
        'pattern',
    ];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'avg_weekly' => 'decimal:2',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
