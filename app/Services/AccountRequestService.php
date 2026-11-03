<?php

namespace App\Services;

use App\Models\AccountRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AccountRequestService
{
    public function visibleTo(User $actor): Builder
    {
        $query = AccountRequest::with(['requester.role', 'branch', 'user']);
        if (! $actor->canAuthorizeResets()) {
            $query->where('requested_by', $actor->id)->where('branch_id', $actor->branch_id)
                ->where('requested_role', $actor->isBranchManager() ? 'branch_staff' : 'commissary_staff');
        }

        return $query;
    }

    public function validRequester(AccountRequest $entry): bool
    {
        $manager = $entry->requester;

        return $manager && $manager->status === 'active' && $manager->isManagerRole()
            && (int) $manager->branch_id === (int) $entry->branch_id
            && $entry->branch?->is_active
            && $entry->requested_role === ($manager->isBranchManager() ? 'branch_staff' : 'commissary_staff');
    }

    public function issuePassword(AccountRequest $entry, User $user): void
    {
        $password = Str::random(20);
        $expires = now()->addDay();
        $user->update([
            'password_hash' => Hash::make($password), 'must_change_password' => true,
            'temporary_password_expires_at' => $expires, 'failed_attempts' => 0, 'locked_until' => null,
        ]);
        $entry->update([
            'temporary_password' => $password, 'credential_expires_at' => $expires,
            'credential_revealed_at' => null,
        ]);
    }

    public function audit(User $actor, AccountRequest $entry, string $action): void
    {
        AuditLog::create([
            'user_id' => $actor->id, 'action' => $action, 'table_name' => 'account_requests',
            'record_id' => $entry->id, 'ip_address' => request()->ip(),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function notificationItemsFor(User $actor): array
    {
        if ($actor->status !== 'active' || (! $actor->canAuthorizeResets() && ! $actor->isManagerRole())) {
            return [];
        }
        $query = $this->visibleTo($actor);
        if ($actor->canAuthorizeResets()) {
            $query->where('status', 'pending');
        } else {
            $query->where('status', 'approved')->whereNull('credential_revealed_at')
                ->whereHas('user', fn (Builder $users) => $users->where('must_change_password', true)->where('status', 'active'));
        }

        return $query->latest('updated_at')->get()->map(fn (AccountRequest $entry): array => [
            'id' => 'account-request-'.$entry->id.'-'.$entry->status,
            'type' => 'account_request', 'category' => 'accounts', 'severity' => 'info',
            'message' => ($entry->status === 'pending' ? 'New account approval needed: ' : 'Account request approved: ').$entry->full_name,
            'context' => $entry->branch?->branch_name ?? '', 'time' => $entry->updated_at->diffForHumans(),
            'branch_id' => $entry->branch_id,
            'link' => route('account-requests', ['request' => $entry->id]),
        ])->all();
    }
}
