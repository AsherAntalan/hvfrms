<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceivingIssue extends Model
{
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected $fillable = [
        'order_id', 'type', 'reported_by', 'resolved_by', 'status', 'reason',
        'receipt', 'photo_path', 'snapshot', 'resolution_reason', 'resolved_at',
    ];

    protected function casts(): array
    {
        return ['receipt' => 'array', 'snapshot' => 'array', 'resolved_at' => 'datetime'];
    }
}
