<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'alert_type',
        'order_id',
        'severity',
        'message',
        'item_id',
        'branch_id',
        'batch_id',
        'is_resolved',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'is_resolved' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeRelevant(Builder $query): void
    {
        $query->where(function (Builder $alerts): void {
            $alerts->whereDoesntHave('branch', fn (Builder $branches) => $branches->where('branch_type', 'commissary'))
                ->orWhere(fn (Builder $commissary) => $commissary->forCommissary());
        });
    }

    public function scopeForCommissary(Builder $query): void
    {
        $query->where(function (Builder $alerts): void {
            $alerts->where(function (Builder $operational): void {
                $operational->where('alerts.alert_type', 'operational')->whereNotNull('alerts.order_id');
            })->orWhere(function (Builder $expiry): void {
                $expiry->whereIn('alerts.alert_type', ['expired', 'expiring_soon'])
                    ->whereHas('batch', function (Builder $batches): void {
                        $batches->whereColumn('stock_batches.branch_id', 'alerts.branch_id')
                            ->where('quantity_left', '>', 0)->awaitingDispatch()
                            ->where(function (Builder $dates): void {
                                $today = now('Asia/Manila')->toDateString();
                                $dates->where(function (Builder $expired) use ($today): void {
                                    $expired->where('alerts.alert_type', 'expired')->whereDate('expiry_date', '<', $today);
                                })->orWhere(function (Builder $expiring) use ($today): void {
                                    $expiring->where('alerts.alert_type', 'expiring_soon')->whereDate('expiry_date', '>=', $today)
                                        ->whereDate('expiry_date', '<=', now('Asia/Manila')->addDays(max(1, (int) Setting::get('expiry_warning_days', '7')))->toDateString());
                                });
                            });
                    });
            });
        });
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->relevant();
        if ($user->isCommissaryRole()) {
            $query->forCommissary();
        }
        if ($user->isBranchScoped()) {
            $query->where('alerts.branch_id', $user->branch_id);
        }
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
