<?php

namespace App\Models;

use App\Services\ResetCodeService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'must_change_password', 'temporary_password_expires_at',
        'full_name',
        'username',
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
        'recovery_code',
        'recovery_code_hash',
        'password_hash',
        'reset_token',
        'reset_token_expires',
        'failed_attempts',
        'locked_until',
    ];

    protected function casts(): array
    {
        return [
            'must_change_password' => 'boolean',
            'temporary_password_expires_at' => 'datetime',
            'recovery_code' => 'encrypted',
            'locked_until' => 'datetime',
            'reset_token_expires' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if ($user->role?->role_name !== 'super_admin') {
                $code = app(ResetCodeService::class)->generate();
                $user->recovery_code = $code['plain'];
                $user->recovery_code_hash = $code['hash'];
            }
        });
    }

    public function canViewRecoveryCodeFor(User $target): bool
    {
        return $this->id !== $target->id
            && ! $target->isSuperAdmin()
            && ($this->canRequestResetFor($target)
                || ($this->canAuthorizeResets() && $this->roleRank() > $target->roleRank()));
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

    // Owner-level authority. The internal super_admin (developer) and the
    // business Admin share Owner's application permissions; their distinct
    // role_id/account keeps attribution and audit traceability separate.
    public function isOwner(): bool
    {
        return in_array($this->role->role_name, ['super_admin', 'owner', 'admin']);
    }

    public function isAdmin(): bool
    {
        return $this->role->role_name === 'admin';
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

    // Commissary (separate production/dispatch location) roles — renamed from
    // main_branch_* (and warehouse_* before that). Legacy method names are kept
    // as aliases so every existing call site stays valid.
    public function isCommissaryManager(): bool
    {
        return $this->role->role_name === 'commissary_manager';
    }

    public function isCommissaryStaff(): bool
    {
        return $this->role->role_name === 'commissary_staff';
    }

    public function isCommissaryRole(): bool
    {
        return in_array($this->role->role_name, ['commissary_manager', 'commissary_staff']);
    }

    public function isWarehouseAdmin(): bool
    {
        return $this->isCommissaryManager();
    }

    public function isWarehouseStaff(): bool
    {
        return $this->isCommissaryStaff();
    }

    public function isWarehouseRole(): bool
    {
        return $this->isCommissaryRole();
    }

    public function isMainBranchManager(): bool
    {
        return $this->isCommissaryManager();
    }

    public function isMainBranchStaff(): bool
    {
        return $this->isCommissaryStaff();
    }

    public function isMainBranchRole(): bool
    {
        return $this->isCommissaryRole();
    }

    public function isBranchScoped(): bool
    {
        return ! $this->isOwner();
    }

    public function canManageInventory(): bool
    {
        return $this->isOwner() || $this->isBranchManager() || $this->isCommissaryManager();
    }

    /**
     * Authorized inventory handler: may dispose goods and archive/restore/remove
     * inventory and expired-batch archive records. The Commissary Manager is a
     * supply-planning MONITOR only (production role), so inventory handling is
     * limited to the Owner and the Branch Manager (for their own branch). This is
     * deliberately narrower than canManageInventory(), which still grants the
     * Commissary Manager read/monitor access and Commissary operations.
     */
    public function canHandleInventory(): bool
    {
        return $this->canManageInventory() && ! $this->isCommissaryManager() && ! $this->isAdmin();
    }

    public function canManageCatalog(): bool
    {
        return $this->isOwner() || $this->isCommissaryManager();
    }

    public function canManageProductAvailability(): bool
    {
        return $this->isOwner() || $this->isMainBranchManager();
    }

    public function canAccessWarehouse(): bool
    {
        return $this->isOwner() || $this->isCommissaryRole();
    }

    // -------------------------------------------------------
    // Password-recovery hierarchy
    // -------------------------------------------------------

    /**
     * Branch/commissary managers — the tier that requests staff resets and is
     * itself reset directly by an authorizing admin.
     */
    public function isManagerRole(): bool
    {
        return $this->isBranchManager() || $this->isCommissaryManager();
    }

    /**
     * Operational staff — the tier whose resets must be requested by their
     * same-branch manager and approved by an authorizing admin.
     */
    public function isStaffRole(): bool
    {
        return $this->isBranchStaff() || $this->isCommissaryStaff();
    }

    /**
     * Recovery authority rank. A reset may only be approved, authorized, or
     * initiated by an actor whose rank is strictly higher than the target's, so
     * no one can reset an account of equal or higher authority.
     */
    public function roleRank(): int
    {
        return match ($this->role->role_name) {
            'super_admin' => 4,
            'owner', 'admin' => 3,
            'branch_manager', 'commissary_manager' => 2,
            'branch_staff', 'commissary_staff' => 1,
            default => 0,
        };
    }

    /**
     * Owner-level accounts (owner, admin, super_admin) may approve staff reset
     * requests and directly authorize manager resets.
     */
    public function canAuthorizeResets(): bool
    {
        return $this->isOwner();
    }

    /**
     * A manager may request a reset for a staff member of the SAME branch whose
     * role matches their own line (branch manager ⇄ branch staff, commissary
     * manager ⇄ commissary staff).
     */
    public function canRequestResetFor(User $target): bool
    {
        if (! $this->isManagerRole() || ! $target->isStaffRole()) {
            return false;
        }
        if ((int) $this->branch_id !== (int) $target->branch_id) {
            return false;
        }

        return ($this->isBranchManager() && $target->isBranchStaff())
            || ($this->isCommissaryManager() && $target->isCommissaryStaff());
    }

    /**
     * Managers verify staff identity; only the admin tier approves resets.
     * Managers require an admin; admin/owner accounts require a super admin.
     */
    public function canApproveResetFor(User $target): bool
    {
        if ($this->id === $target->id || $this->status !== 'active' || $target->status !== 'active') {
            return false;
        }
        if ($target->isStaffRole()) {
            return $this->canAuthorizeResets() && $this->roleRank() > $target->roleRank();
        }

        return $this->canAuthorizeResets() && ! $target->isSuperAdmin() && $this->roleRank() > $target->roleRank();
    }

    public function canVerifyResetFor(User $target): bool
    {
        return $this->id !== $target->id && $this->status === 'active' && $target->status === 'active'
            && $this->canRequestResetFor($target);
    }

    /**
     * Direct (no-approval) reset initiation: an admin-tier account initiates a
     * manager reset; the super admin initiates an admin/owner reset. Staff never
     * qualify here — they go through the manager-request + approval flow.
     */
    public function canDirectlyInitiateResetFor(User $target): bool
    {
        if ($target->isStaffRole()) {
            return false;
        }
        if (! $this->canAuthorizeResets()) {
            return false;
        }

        return $this->roleRank() > $target->roleRank();
    }
}
