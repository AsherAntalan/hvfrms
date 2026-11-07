<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    protected $fillable = [
        'item_name',
        'category_id',
        'unit',
        'pack_description',
        'price',
        'image_path',
        'qr_code',
        'reorder_level',
        'shelf_life_days',
        'shelf_life_months',
        'remarks',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
            'shelf_life_months' => 'integer',
        ];
    }

    public function shelfLifeMonths(): ?int
    {
        if ($this->shelf_life_months) {
            return (int) $this->shelf_life_months;
        }

        if ($this->shelf_life_days) {
            return null;
        }

        return match (strtolower($this->category?->name ?? '')) {
            'sauces', 'sauces (big sizes)', 'bread', 'frozen products', 'frozen food' => 6,
            'juice', 'juices' => 12,
            default => null,
        };
    }

    public function expiryFromProduction(DateTimeInterface|string $productionDate): ?string
    {
        $date = CarbonImmutable::parse($productionDate);
        $months = $this->shelfLifeMonths();

        if ($months) {
            return $date->addMonthsNoOverflow($months)->toDateString();
        }

        return $this->shelf_life_days ? $date->addDays((int) $this->shelf_life_days)->toDateString() : null;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function stockBatches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    public function stockTransactions(): HasMany
    {
        return $this->hasMany(StockTransaction::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }
}
