<?php

namespace Tests\Feature;

use App\Models\AccountRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The connected Create Branch -> Create User flow: admin creates a branch, then
 * (separately) an authorized branch user for it, with a one-time temporary
 * password revealed privately and a forced first-login change.
 */
class CreateBranchUserFlowTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Branch> */
    private array $branches;

    /** @var array<string, User> */
    private array $people;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (['super_admin', 'owner', 'admin', 'branch_manager', 'branch_staff', 'commissary_manager', 'commissary_staff'] as $name) {
            Role::firstOrCreate(['role_name' => $name], ['description' => $name]);
        }
        $this->branches = [
            'main' => Branch::create(['branch_name' => 'Tanza', 'branch_type' => 'main', 'is_active' => true]),
            'franchise' => Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => true]),
            'commissary' => Branch::create(['branch_name' => 'Commissary', 'branch_type' => 'commissary', 'is_active' => true]),
            'inactive' => Branch::create(['branch_name' => 'Closed', 'branch_type' => 'franchise', 'is_active' => false]),
        ];
        $this->people = [
            'admin' => $this->person('admin', $this->branches['main'], 'the.admin', 'admin@hv.test'),
            'manager' => $this->person('branch_manager', $this->branches['franchise'], 'the.manager'),
            'staff' => $this->person('branch_staff', $this->branches['franchise'], 'the.staff'),
        ];
    }

    private function person(string $role, Branch $branch, string $username, ?string $email = null): User
    {
        return User::create([
            'full_name' => ucfirst($role), 'username' => $username, 'email' => $email,
            'password_hash' => Hash::make('secret123'),
            'role_id' => Role::where('role_name', $role)->firstOrFail()->id,
            'branch_id' => $branch->id, 'status' => 'active',
        ]);
    }

    private function roleId(string $name): int
    {
        return Role::where('role_name', $name)->firstOrFail()->id;
    }

    /** @param array<string, mixed> $overrides */
    private function createUser(array $overrides = [])
    {
        return $this->actingAs($this->people['admin'])->postJson(route('users.api'), array_merge([
            'action' => 'save', 'full_name' => 'New Hire', 'username' => 'new.hire',
            'role_id' => $this->roleId('branch_staff'), 'branch_id' => $this->branches['franchise']->id,
        ], $overrides));
    }

    // 1 + 2 + 3: branch creation exposes the shortcut, creates no user, and the
    // shortcut preselects only a genuinely active branch.
    public function test_branch_creation_exposes_shortcut_without_creating_a_user(): void
    {
        $before = User::count();
        $response = $this->actingAs($this->people['admin'])->postJson(route('branches.api'), [
            'action' => 'save', 'branch_name' => 'Dasma', 'branch_type' => 'franchise',
        ])->assertOk()->assertJson(['ok' => true]);
        $newId = $response->json('id');
        $this->assertNotNull($newId);
        $this->assertSame($before, User::count());

        $this->actingAs($this->people['admin'])->get(route('users', ['branch' => $newId]))
            ->assertOk()->assertViewHas('preselectBranchId', $newId);
    }

    public function test_shortcut_ignores_inactive_or_forged_branch_ids(): void
    {
        $this->actingAs($this->people['admin'])->get(route('users', ['branch' => $this->branches['inactive']->id]))
            ->assertOk()->assertViewHas('preselectBranchId', fn ($value) => $value === null);
        $this->actingAs($this->people['admin'])->get(route('users', ['branch' => 999999]))
            ->assertOk()->assertViewHas('preselectBranchId', fn ($value) => $value === null);
    }

    // 4 + 13: the Users page creates an authorized branch user with onboarding +
    // recovery-code records in place, and leaks no secret in the response.
    public function test_admin_creates_authorized_branch_user(): void
    {
        $response = $this->createUser()->assertOk()->assertJson(['ok' => true])
            ->assertJsonMissingPath('password')->assertJsonMissingPath('recovery_code')
            ->assertHeader('Cache-Control', 'no-store, private');
        $entry = AccountRequest::whereKey($response->json('account_request_id'))->firstOrFail();
        $this->assertSame('admin_created', $entry->origin);
        $this->assertSame('approved', $entry->status);
        $this->assertSame($this->people['admin']->id, (int) $entry->requested_by);

        $user = User::where('username', 'new.hire')->firstOrFail();
        $this->assertTrue($user->must_change_password);
        $this->assertSame($this->branches['franchise']->id, (int) $user->branch_id);
        $this->assertNotNull($user->recovery_code);              // recovery code initialized
        $this->assertNotNull($user->getAttribute('recovery_code_hash'));
    }

    // 5 + 6: role choices match the location type; elevated and mismatched roles
    // are rejected server-side even when the form is bypassed.
    public function test_role_and_branch_compatibility_is_enforced(): void
    {
        $this->createUser(['username' => 'bm1', 'role_id' => $this->roleId('branch_manager'), 'branch_id' => $this->branches['franchise']->id])->assertOk();
        $this->createUser(['username' => 'cm1', 'role_id' => $this->roleId('commissary_manager'), 'branch_id' => $this->branches['commissary']->id])->assertOk();
        $this->createUser(['username' => 'cs1', 'role_id' => $this->roleId('commissary_staff'), 'branch_id' => $this->branches['commissary']->id])->assertOk();
        // Main store (Tanza) is a normal store: it takes branch roles, not commissary.
        $this->createUser(['username' => 'bs_main', 'role_id' => $this->roleId('branch_staff'), 'branch_id' => $this->branches['main']->id])->assertOk();

        // Mismatched combinations.
        $this->createUser(['username' => 'x1', 'role_id' => $this->roleId('commissary_staff'), 'branch_id' => $this->branches['franchise']->id])->assertUnprocessable();
        $this->createUser(['username' => 'x2', 'role_id' => $this->roleId('branch_manager'), 'branch_id' => $this->branches['commissary']->id])->assertUnprocessable();

        // Elevated roles are never creatable through this flow.
        foreach (['admin', 'owner', 'super_admin'] as $elevated) {
            $this->createUser(['username' => 'x_'.$elevated, 'role_id' => $this->roleId($elevated), 'branch_id' => $this->branches['franchise']->id])->assertUnprocessable();
            $this->assertDatabaseMissing('users', ['username' => 'x_'.$elevated]);
        }
    }

    // 7: inactive / nonexistent branches are rejected.
    public function test_inactive_or_nonexistent_branch_is_rejected(): void
    {
        $this->createUser(['username' => 'ib', 'branch_id' => $this->branches['inactive']->id])->assertUnprocessable();
        $this->createUser(['username' => 'nb', 'branch_id' => 999999])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['username' => 'ib']);
        $this->assertDatabaseMissing('users', ['username' => 'nb']);
    }

    // 8: duplicate (case-insensitive) and reserved usernames are rejected with no
    // partial records left behind.
    public function test_duplicate_and_reserved_usernames_are_rejected_atomically(): void
    {
        $this->createUser(['username' => 'taken.name'])->assertOk();
        $usersAfterFirst = User::count();
        $requestsAfterFirst = AccountRequest::count();

        $this->createUser(['username' => 'TAKEN.NAME'])->assertUnprocessable();
        $this->assertSame($usersAfterFirst, User::count());
        $this->assertSame($requestsAfterFirst, AccountRequest::count());

        // A username reserved by a pending manager request cannot be reused.
        AccountRequest::create([
            'requested_by' => $this->people['manager']->id, 'branch_id' => $this->branches['franchise']->id,
            'full_name' => 'Pending', 'username' => 'reserved.name', 'requested_role' => 'branch_staff', 'status' => 'pending',
        ]);
        $this->createUser(['username' => 'reserved.name'])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['username' => 'reserved.name']);
    }

    // 9: managers and staff cannot reach the admin creation endpoint.
    public function test_managers_and_staff_cannot_create_users(): void
    {
        foreach (['manager', 'staff'] as $who) {
            $this->actingAs($this->people[$who])->postJson(route('users.api'), [
                'action' => 'save', 'full_name' => 'Nope', 'username' => 'nope.'.$who,
                'role_id' => $this->roleId('branch_staff'), 'branch_id' => $this->branches['franchise']->id,
            ])->assertForbidden();
            $this->assertDatabaseMissing('users', ['username' => 'nope.'.$who]);
        }
    }

    // 10: the temporary password is hashed on the user and encrypted at rest on
    // the delivery record, and first login forces a password change.
    public function test_temporary_password_is_hashed_and_first_login_is_enforced(): void
    {
        $entryId = $this->createUser(['username' => 'first.login'])->assertOk()->json('account_request_id');
        $user = User::where('username', 'first.login')->firstOrFail();

        $password = $this->reveal($entryId)->assertOk()->json('temporary_password');
        $entry = AccountRequest::whereKey($entryId)->firstOrFail();
        $this->assertNotSame($password, $entry->getRawOriginal('temporary_password')); // encrypted at rest
        $this->assertTrue(Hash::check($password, $user->fresh()->password_hash));       // hashed on the user

        $this->app['auth']->logout();
        $this->post(route('login.submit'), ['login' => 'first.login', 'password' => $password])->assertRedirect(route('password.initial'));
        $this->get(route('qr'))->assertRedirect(route('password.initial'));
        $this->post(route('password.initial.update'), [
            'current_password' => $password, 'password' => 'My-Own-Password1!', 'password_confirmation' => 'My-Own-Password1!',
        ])->assertRedirect(route('login'));
        $this->assertFalse($user->fresh()->must_change_password);
        $this->post(route('login.submit'), ['login' => 'first.login', 'password' => 'My-Own-Password1!'])->assertRedirect(route('qr'));
    }

    // 11: reveal is admin-only, one-time, current-password gated, expiring, and
    // never cached.
    public function test_reveal_permissions_expiry_and_single_use(): void
    {
        $entryId = $this->createUser(['username' => 'reveal.me'])->assertOk()->json('account_request_id');

        // Wrong current password is rejected.
        $this->reveal($entryId, 'wrong')->assertUnprocessable();
        // A non-authorizing role cannot reveal.
        $this->actingAs($this->people['manager'])->postJson(route('account-requests.api'), [
            'id' => $entryId, 'action' => 'reveal', 'current_password' => 'secret123',
        ])->assertNotFound();

        $password = $this->reveal($entryId)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('temporary_password');
        // One-time only.
        $this->reveal($entryId)->assertUnprocessable();
        $this->assertNull(AccountRequest::whereKey($entryId)->firstOrFail()->temporary_password);
        $this->assertNotEmpty($password);

        // Replacement by any authorizing admin re-enables a single reveal.
        $this->actingAs($this->people['admin'])->postJson(route('account-requests.api'), ['id' => $entryId, 'action' => 'replace'])->assertOk();
        $replacement = $this->reveal($entryId)->assertOk()->json('temporary_password');
        $this->assertNotSame($password, $replacement);

        // Expired credentials cannot be revealed.
        $this->travel(25)->hours();
        $this->actingAs($this->people['admin'])->postJson(route('account-requests.api'), ['id' => $entryId, 'action' => 'replace'])->assertOk();
        $this->travel(25)->hours();
        $this->reveal($entryId)->assertUnprocessable();
    }

    // 12: no password or recovery code leaks through the response, notifications,
    // or the account-requests listing.
    public function test_secrets_do_not_leak_through_responses_or_notifications(): void
    {
        $entryId = $this->createUser(['username' => 'no.leak'])->assertOk()->json('account_request_id');
        $password = $this->reveal($entryId)->assertOk()->json('temporary_password');

        $this->actingAs($this->people['admin'])->getJson('/notifications')->assertOk()->assertDontSee($password);
        $this->actingAs($this->people['admin'])->get(route('account-requests'))->assertOk()->assertDontSee($password);
        $this->assertStringNotContainsString($password, AuditLog::all()->toJson());
    }

    // 14: a failed user creation leaves an already-created branch intact.
    public function test_user_creation_failure_preserves_the_branch(): void
    {
        $branchId = $this->actingAs($this->people['admin'])->postJson(route('branches.api'), [
            'action' => 'save', 'branch_name' => 'Imus', 'branch_type' => 'franchise',
        ])->assertOk()->json('id');

        // Creation fails validation (elevated role) — the branch must remain.
        $this->createUser(['username' => 'fails', 'role_id' => $this->roleId('admin'), 'branch_id' => $branchId])->assertUnprocessable();
        $this->assertDatabaseHas('branches', ['id' => $branchId, 'branch_name' => 'Imus']);
        $this->assertDatabaseMissing('users', ['username' => 'fails']);
    }

    // 15: the existing manager-request -> admin-approval staff flow still works
    // end to end alongside admin-created accounts.
    public function test_manager_request_approval_flow_still_works(): void
    {
        $this->actingAs($this->people['manager'])->post(route('account-requests.store'), [
            'full_name' => 'Requested Staff', 'username' => 'requested.staff',
        ])->assertRedirect(route('account-requests'));
        $entry = AccountRequest::where('username', 'requested.staff')->firstOrFail();
        $this->assertSame('manager_request', $entry->origin);

        $this->actingAs($this->people['admin'])->postJson(route('account-requests.api'), ['id' => $entry->id, 'action' => 'approve'])->assertOk();
        $this->assertTrue($entry->fresh()->user->must_change_password);

        // The requesting manager reveals once; an admin cannot reveal a manager request.
        $this->actingAs($this->people['admin'])->postJson(route('account-requests.api'), [
            'id' => $entry->id, 'action' => 'reveal', 'current_password' => 'secret123',
        ])->assertForbidden();
        $this->actingAs($this->people['manager'])->postJson(route('account-requests.api'), [
            'id' => $entry->id, 'action' => 'reveal', 'current_password' => 'secret123',
        ])->assertOk()->assertJsonPath('ok', true);
    }

    private function reveal(int $entryId, string $currentPassword = 'secret123')
    {
        return $this->actingAs($this->people['admin'])->postJson(route('account-requests.api'), [
            'id' => $entryId, 'action' => 'reveal', 'current_password' => $currentPassword,
        ]);
    }
}
