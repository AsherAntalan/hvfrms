<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

class StockBatch extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'item_id',
        'branch_id',
        'quantity_in',
        'quantity_left',
        'expiry_date',
        'condition',
        'received_at',
        'created_by',
        'qr_code',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'received_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function scopeAwaitingDispatch(Builder $query): void
    {
        $query->where(function (Builder $batches): void {
            $batches->whereExists(function (QueryBuilder $productions): void {
                $productions->selectRaw('1')->from('order_productions')
                    ->whereColumn('order_productions.stock_batch_id', 'stock_batches.id')
                    ->whereColumn('order_productions.produced_qty', '>', 'order_productions.dispatched_qty');
            })->orWhereExists(function (QueryBuilder $units): void {
                $units->selectRaw('1')->from('order_production_units')
                    ->whereColumn('order_production_units.stock_batch_id', 'stock_batches.id')
                    ->whereNull('order_production_units.dispatched_at');
            });
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
