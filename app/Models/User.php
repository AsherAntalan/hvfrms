<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'full_name',
        'email',
        'password_hash',
        'profile_photo',
        'role_id',
        'branch_id',
        'status',
        'failed_attempts',
        'locked_until',
        'reset_token',
        'reset_token_expires',
    ];

    protected $hidden = [
        'password_hash',
        'reset_token',
        'reset_token_expires',
        'failed_attempts',
        'locked_until',
    ];

    protected function casts(): array
    {
        return [
            'locked_until' => 'datetime',
            'reset_token_expires' => 'datetime',
        ];
    }

    /**
     * Override the default auth password field.
     * The database column is `password_hash` instead of `password`.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    // -------------------------------------------------------
    // Relationships
    // -------------------------------------------------------

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdStockBatches(): HasMany
    {
        return $this->hasMany(StockBatch::class, 'created_by');
    }

    public function performedStockTransactions(): HasMany
    {
        return $this->hasMany(StockTransaction::class, 'performed_by');
    }

    public function placedOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'ordered_by');
    }

    // -------------------------------------------------------
    // Role helper methods
    // -------------------------------------------------------

    public function isOwner(): bool
    {
        return in_array($this->role->role_name, ['super_admin', 'owner']);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role->role_name === 'super_admin';
    }

    public function isBranchManager(): bool
    {
        return $this->role->role_name === 'branch_manager';
    }

    public function isBranchStaff(): bool
    {
        return $this->role->role_name === 'branch_staff';
    }

    public function isWarehouseAdmin(): bool
    {
        return $this->role->role_name === 'warehouse_admin';
    }

    public function isWarehouseStaff(): bool
    {
        return $this->role->role_name === 'warehouse_staff';
    }

    public function isWarehouseRole(): bool
    {
        return in_array($this->role->role_name, ['warehouse_admin', 'warehouse_staff']);
    }

    public function isBranchScoped(): bool
    {
        return !$this->isOwner();
    }

    public function canManageInventory(): bool
    {
        return in_array($this->role->role_name, ['super_admin', 'owner', 'branch_manager', 'warehouse_admin']);
    }

    public function canManageCatalog(): bool
    {
        return $this->isOwner();
    }

    public function canAccessWarehouse(): bool
    {
        return in_array($this->role->role_name, ['super_admin', 'owner', 'warehouse_admin', 'warehouse_staff']);
    }
}
