<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentAccount extends Model
{
    protected $fillable = [
        'type', 'provider', 'account_name', 'account_number', 'instructions', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function label(): string
    {
        return $this->provider.($this->account_number ? ' — '.$this->account_number : '');
    }
}
