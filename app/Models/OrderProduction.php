<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderProduction extends Model
{
    protected $fillable = [
        'order_id',
        'order_item_id',
        'item_id',
        'destination_branch_id',
        'produced_qty',
        'dispatched_qty',
        'production_at',
        'expiry_date',
        'condition',
        'qr_code',
        'prepared_by',
        'stock_batch_id',
        'source_type',
    ];

    protected function casts(): array
    {
        return [
            'production_at' => 'datetime',
            'expiry_date' => 'date',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /** Remaining produced units not yet scanned out for dispatch. */
    public function undispatched(): int
    {
        return max(0, (int) $this->produced_qty - (int) $this->dispatched_qty);
    }

    /** Deterministic order/production QR for one line's produced output. */
    public static function qrFor(int $orderId, int $orderItemId): string
    {
        return 'HV-PROD-'.str_pad((string) $orderId, 5, '0', STR_PAD_LEFT)
            .'-'.str_pad((string) $orderItemId, 5, '0', STR_PAD_LEFT);
    }

    /** Parse a production QR → order_item_id, or null. */
    public static function orderItemIdFromQr(string $qr): ?int
    {
        if (preg_match('/^HV-PROD-\d+-(\d+)$/i', trim($qr), $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
