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

class AccountRequestTest extends TestCase
{
    use RefreshDatabase;

    private array $people;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $first = Branch::create(['branch_name' => 'Baguio', 'branch_type' => 'franchise', 'is_active' => true]);
        $second = Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => true]);
        foreach (['admin', 'branch_manager', 'branch_staff', 'commissary_manager', 'commissary_staff'] as $name) {
            Role::firstOrCreate(['role_name' => $name], ['description' => $name]);
        }
        $this->people = [
            'admin' => $this->person('admin', $first, 'administrator'),
            'manager' => $this->person('branch_manager', $first, 'managerone'),
            'other' => $this->person('branch_manager', $second, 'managertwo'),
            'peer' => $this->person('branch_manager', $first, 'managerpeer'),
            'staff' => $this->person('branch_staff', $first, 'existingstaff'),
            'commissary' => $this->person('commissary_manager', $second, 'commissarymanager'),
        ];
    }

    private function person(string $role, Branch $branch, string $username): User
    {
        return User::create([
            'full_name' => $username, 'username' => $username, 'branch_id' => $branch->id,
            'role_id' => Role::where('role_name', $role)->firstOrFail()->id,
            'password_hash' => Hash::make('manager-secret-123'), 'status' => 'active',
        ]);
    }

    private function submit(string $username = 'newstaff'): AccountRequest
    {
        $this->actingAs($this->people['manager'])->post(route('account-requests.store'), [
            'full_name' => 'New Employee', 'username' => $username,
            'branch_id' => $this->people['other']->branch_id, 'requested_role' => 'admin',
        ])->assertRedirect(route('account-requests'));

        return AccountRequest::where('username', $username)->firstOrFail();
    }

    private function action(User $actor, AccountRequest $entry, string $action, array $extra = [])
    {
        return $this->actingAs($actor)->postJson(route('account-requests.api'), array_merge(['id' => $entry->id, 'action' => $action], $extra));
    }

    private function approve(AccountRequest $entry): void
    {
        $this->action($this->people['admin'], $entry, 'approve')->assertOk()->assertJsonMissingPath('temporary_password');
        $entry->refresh();
    }

    private function reveal(AccountRequest $entry): string
    {
        return $this->action($this->people['manager'], $entry, 'reveal', ['current_password' => 'manager-secret-123'])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('temporary_password');
    }

    public function test_manager_request_admin_approval_and_single_password_delivery(): void
    {
        $entry = $this->submit();
        $this->assertDatabaseMissing('users', ['username' => 'newstaff']);
        $this->assertSame('branch_staff', $entry->requested_role);
        $this->assertSame($this->people['manager']->branch_id, $entry->branch_id);
        $this->actingAs($this->people['admin'])->getJson('/notifications')->assertOk()->assertJsonFragment(['type' => 'account_request']);
        $this->approve($entry);
        $plain = $entry->temporary_password;
        $this->assertNotSame($plain, $entry->getRawOriginal('temporary_password'));
        $this->assertArrayNotHasKey('temporary_password', $entry->toArray());
        $this->assertTrue(Hash::check($plain, $entry->user->password_hash));
        $this->assertTrue($entry->user->must_change_password);
        $this->actingAs($this->people['manager'])->getJson('/notifications')->assertOk()
            ->assertSee('Account request approved')->assertDontSee($plain);
        $this->actingAs($this->people['manager'])->get('/alerts?category=accounts')->assertOk()
            ->assertSee('Account request approved')->assertDontSee($plain);
        $this->actingAs($this->people['manager'])->get(route('account-requests', ['request' => $entry->id]))->assertOk()
            ->assertSee('Reveal temporary password once')->assertDontSee($plain);
        $this->action($this->people['manager'], $entry, 'reveal', ['current_password' => 'wrong-password'])->assertUnprocessable();
        $this->assertSame($plain, $this->reveal($entry));
        $this->assertNull($entry->fresh()->temporary_password);
        $this->action($this->people['manager'], $entry, 'reveal', ['current_password' => 'manager-secret-123'])->assertUnprocessable();
        $this->actingAs($this->people['manager'])->getJson('/notifications')->assertOk()->assertDontSee('account-request-'.$entry->id.'-approved');
        $this->assertStringNotContainsString($plain, AuditLog::all()->toJson());
        $this->action($this->people['admin'], $entry, 'approve')->assertUnprocessable();
        $this->assertSame(1, User::where('username', 'newstaff')->count());
    }

    public function test_permissions_prevent_privilege_escalation_and_cross_branch_access(): void
    {
        $entry = $this->submit();
        $this->actingAs($this->people['staff'])->get('/account-requests')->assertForbidden();
        $this->actingAs($this->people['staff'])->post('/account-requests', ['full_name' => 'X', 'username' => 'xstaff'])->assertForbidden();
        $this->action($this->people['manager'], $entry, 'approve')->assertForbidden();
        $this->action($this->people['other'], $entry, 'approve')->assertNotFound();
        $this->approve($entry);
        $this->action($this->people['peer'], $entry, 'reveal', ['current_password' => 'manager-secret-123'])->assertNotFound();
        $this->action($this->people['admin'], $entry, 'reveal', ['current_password' => 'manager-secret-123'])->assertForbidden();
        $this->actingAs($this->people['manager'])->postJson('/users/api', ['action' => 'save'])->assertForbidden();
        $this->actingAs($this->people['manager'])->get('/users')->assertOk()->assertSee('newstaff')->assertDontSee('managertwo');
    }

    public function test_duplicate_usernames_and_changed_requester_scope_block_creation(): void
    {
        $entry = $this->submit();
        $this->actingAs($this->people['manager'])->post('/account-requests', ['full_name' => 'Duplicate', 'username' => 'NEWSTAFF'])
            ->assertSessionHasErrors('username');
        $this->people['manager']->update(['branch_id' => $this->people['other']->branch_id]);
        $this->action($this->people['admin'], $entry, 'approve')->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['username' => 'newstaff']);
    }

    public function test_rejection_requires_reason_and_does_not_create_account(): void
    {
        $entry = $this->submit();
        $this->action($this->people['admin'], $entry, 'reject')->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->action($this->people['admin'], $entry, 'reject', ['reason' => 'Duplicate employee'])->assertOk();
        $this->assertSame('rejected', $entry->fresh()->status);
        $this->assertDatabaseMissing('users', ['username' => 'newstaff']);
        $this->actingAs($this->people['manager'])->get('/account-requests')->assertOk()->assertSee('Duplicate employee');
        $this->actingAs($this->people['admin'])->getJson('/notifications')->assertOk()->assertDontSee('account-request-'.$entry->id.'-pending');
    }

    public function test_first_login_forces_password_change_and_blocks_direct_access(): void
    {
        $entry = $this->submit();
        $this->approve($entry);
        $password = $this->reveal($entry);
        $this->app['auth']->logout();
        $this->post('/login', ['login' => 'newstaff', 'password' => $password])->assertRedirect(route('password.initial'));
        $this->get('/qr')->assertRedirect(route('password.initial'));
        $this->postJson('/orders/api', ['action' => 'view', 'id' => 1])->assertForbidden();
        $this->get('/initial-password')->assertOk()->assertSee('Set your own password');
        $this->post('/initial-password', ['current_password' => 'wrong', 'password' => 'personal-password-999', 'password_confirmation' => 'personal-password-999'])->assertSessionHasErrors('current_password');
        $this->post('/initial-password', ['current_password' => $password, 'password' => $password, 'password_confirmation' => $password])->assertSessionHasErrors('password');
        $this->post('/initial-password', ['current_password' => $password, 'password' => 'personal-password-999', 'password_confirmation' => 'personal-password-999'])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertFalse($entry->user()->first()->must_change_password);
        $this->assertTrue(Hash::check('personal-password-999', $entry->user()->first()->password_hash));
        $this->post('/login', ['login' => 'newstaff', 'password' => $password])->assertSessionHas('error');
        $this->post('/login', ['login' => 'newstaff', 'password' => 'personal-password-999'])->assertRedirect(route('qr'));
        $this->action($this->people['admin'], $entry, 'replace')->assertUnprocessable();
    }

    public function test_expired_credentials_and_replacement_invalidate_old_password_and_sessions(): void
    {
        $entry = $this->submit();
        $this->approve($entry);
        $original = $this->reveal($entry);
        $fingerprint = hash('sha256', $entry->user()->first()->password_hash);
        $this->travel(24)->hours();
        $this->app['auth']->logout();
        $this->post('/login', ['login' => 'newstaff', 'password' => $original])->assertSessionHas('error');
        $this->action($this->people['admin'], $entry, 'replace')->assertOk();
        $replacement = $this->reveal($entry);
        $this->assertNotSame($original, $replacement);
        $this->assertFalse(Hash::check($original, $entry->user()->first()->password_hash));
        $this->actingAs($entry->user()->first())->withSession(['password_fingerprint' => $fingerprint])->get('/qr')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_commissary_manager_requests_only_commissary_staff_and_no_get_reveals_secrets(): void
    {
        $this->actingAs($this->people['commissary'])->post('/account-requests', ['full_name' => 'Commissary Employee', 'username' => 'newcommissary', 'requested_role' => 'admin'])->assertRedirect();
        $entry = AccountRequest::where('username', 'newcommissary')->firstOrFail();
        $this->assertSame('commissary_staff', $entry->requested_role);
        $this->assertSame($this->people['commissary']->branch_id, $entry->branch_id);
        $this->actingAs($this->people['other'])->get(route('account-requests', ['request' => $entry->id]))->assertOk()->assertDontSee('newcommissary');
        $this->actingAs($this->people['admin'])->get('/account-requests/api?action=approve&id='.$entry->id)->assertStatus(405);
        $this->assertSame('pending', $entry->fresh()->status);
    }

    public function test_expired_or_changed_account_credentials_cannot_be_revealed(): void
    {
        $entry = $this->submit();
        $this->approve($entry);
        $this->travel(24)->hours();
        $this->action($this->people['manager'], $entry, 'reveal', ['current_password' => 'manager-secret-123'])->assertUnprocessable();
        $this->assertNull($entry->fresh()->credential_revealed_at);
        $this->action($this->people['admin'], $entry, 'replace')->assertOk();
        $entry->user()->first()->update(['role_id' => Role::where('role_name', 'admin')->firstOrFail()->id]);
        $this->action($this->people['manager'], $entry, 'reveal', ['current_password' => 'manager-secret-123'])->assertUnprocessable();
        $this->action($this->people['admin'], $entry, 'replace')->assertUnprocessable();
        $this->assertNull($entry->fresh()->credential_revealed_at);
    }
}
