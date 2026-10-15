<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const HISTORY_STATUSES = ['delivered', 'settled', 'cancelled', 'declined'];

    protected $fillable = [
        'order_number',
        'branch_id',
        'status',
        'ordered_by',
        'approved_by',
        'approved_at',
        'prepared_by',
        'prepared_at',
        'delivered_at',
        'confirmed_by',
        'confirmed_at',
        'settled_at',
        'declined_by',
        'declined_at',
        'decline_reason',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
        'branch_confirmed_by',
        'branch_confirmed_at',
        'labels_print_requested_at',
        'remarks',
        'total_amount',
        'fulfilled_amount',
        'payment_reference',
        'payment_screenshot_path',
        'payment_submitted_at',
        'proforma_snapshot',
        'archived_at',
        'archived_by',
    ];

    protected function casts(): array
    {
        return [
            'payment_submitted_at' => 'datetime',
            'proforma_snapshot' => 'array',
            'archived_at' => 'datetime',
            'total_amount' => 'decimal:2',
            'fulfilled_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'prepared_at' => 'datetime',
            'delivered_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'settled_at' => 'datetime',
            'labels_print_requested_at' => 'datetime',
        ];
    }

    public function isProformaAvailable(): bool
    {
        return $this->approved_by !== null && $this->approved_at !== null
            && in_array($this->status, ['approved', 'preparing', 'delivering', 'delivered', 'settled'], true);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function orderedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function preparedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function discrepancies(): HasMany
    {
        return $this->hasMany(DeliveryDiscrepancy::class);
    }
}
