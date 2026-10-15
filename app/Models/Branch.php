<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $fillable = [
        'branch_name',
        'branch_type',
        'address',
        'contact_number',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * The active source/production location (the separate Commissary) that
     * branch orders are prepared and dispatched from. Resolved through ONE
     * explicit mechanism: the configured `commissary_branch_id` setting, else
     * the single active branch of type `commissary`. Falls back to the active
     * main store so the system keeps working until a Commissary is configured —
     * never an arbitrary first record or hardcoded id.
     */
    public static function commissary(): ?self
    {
        $configuredId = (int) Setting::get('commissary_branch_id', '0');
        if ($configuredId > 0) {
            $byId = static::where('id', $configuredId)->where('is_active', true)->first();
            if ($byId) {
                return $byId;
            }
        }

        $byType = static::where('branch_type', 'commissary')->where('is_active', true)->get();
        if ($byType->count() === 1) {
            return $byType->first();
        }
        if ($byType->count() > 1) {
            // Ambiguous: refuse to guess. Configuration must name the location.
            return null;
        }

        // Fallback until a Commissary exists: the active main store.
        return static::where('branch_type', 'main')->where('is_active', true)->first();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function stockBatches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    public function stockTransactions(): HasMany
    {
        return $this->hasMany(StockTransaction::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }
}
