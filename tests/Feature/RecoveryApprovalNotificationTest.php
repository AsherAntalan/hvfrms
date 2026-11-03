<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Branch;
use App\Models\Order;
use App\Models\PasswordResetRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\RecoveryApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * In-app bell notifications for email-verified, pending recovery
 * requests. Recipients are derived live from the approval hierarchy; notifying
 * no one unauthorized, never leaking codes/tokens/emails, and clearing for all
 * approvers once the request leaves the pending state.
 */
class RecoveryApprovalNotificationTest extends TestCase
{
    use RefreshDatabase;

    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->w = $this->world();
        Mail::fake();
    }

    private function role(string $name): Role
    {
        return Role::firstOrCreate(['role_name' => $name], ['description' => $name]);
    }

    private function branch(string $name, string $type = 'franchise'): Branch
    {
        return Branch::create(['branch_name' => $name, 'branch_type' => $type, 'is_active' => 1]);
    }

    private function user(string $role, Branch $branch, string $username): User
    {
        return User::create([
            'full_name' => ucfirst($username),
            'username' => $username,
            'password_hash' => Hash::make('original-pass-1'),
            'role_id' => $this->role($role)->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);
    }

    private function world(): array
    {
        $commissary = $this->branch('Commissary', 'commissary');
        $baguio = $this->branch('Baguio');
        $silang = $this->branch('Silang');

        return [
            'baguio' => $baguio,
            'silang' => $silang,
            'commissary' => $commissary,
            'super' => $this->user('super_admin', $commissary, 'super'),
            'owner' => $this->user('owner', $commissary, 'owner'),
            'admin' => $this->user('admin', $commissary, 'admin'),
            'bm' => $this->user('branch_manager', $baguio, 'bmbaguio'),
            'bs' => $this->user('branch_staff', $baguio, 'bsbaguio'),
            'bs2' => $this->user('branch_staff', $silang, 'bssilang'),
            'cm' => $this->user('commissary_manager', $commissary, 'cmmanager'),
            'cs' => $this->user('commissary_staff', $commissary, 'csstaff'),
        ];
    }

    private function pendingRequest(User $user, string $email = 'personal@hv.test'): PasswordResetRequest
    {
        return PasswordResetRequest::create([
            'user_id' => $user->id,
            'requested_by' => $user->id,
            'status' => PasswordResetRequest::STATUS_PENDING,
            'request_email' => $email,
            'email_verified_at' => now(),
            'code_hash' => $user->fresh()->recovery_code_hash,
        ]);
    }

    private function awaitingRequest(User $user): PasswordResetRequest
    {
        return PasswordResetRequest::create([
            'user_id' => $user->id,
            'requested_by' => $user->id,
            'status' => PasswordResetRequest::STATUS_AWAITING_EMAIL,
            'request_email' => 'personal@hv.test',
            'verification_expires_at' => now()->addMinutes(30),
            'code_hash' => $user->fresh()->recovery_code_hash,
        ]);
    }

    private function service(): RecoveryApprovalService
    {
        return app(RecoveryApprovalService::class);
    }

    private function actionableIds(User $viewer): array
    {
        return $this->service()->actionableFor($viewer)->pluck('id')->all();
    }

    public function test_awaiting_email_request_is_not_actionable_until_verified(): void
    {
        $request = $this->awaitingRequest($this->w['bs']);

        $this->assertNotContains($request->id, $this->actionableIds($this->w['bm']));
        $this->assertNotContains($request->id, $this->actionableIds($this->w['admin']));

        // Email verification is the trigger: once pending, it becomes actionable.
        $request->update(['status' => PasswordResetRequest::STATUS_PENDING, 'email_verified_at' => now(), 'verification_expires_at' => null]);

        $this->assertContains($request->id, $this->actionableIds($this->w['bm']));
        $this->assertContains($request->id, $this->actionableIds($this->w['admin']));
    }

    public function test_recipients_follow_the_approval_hierarchy_and_never_the_account_holder(): void
    {
        $staff = $this->pendingRequest($this->w['bs']);
        foreach (['bm', 'admin', 'owner', 'super'] as $approver) {
            $this->assertContains($staff->id, $this->actionableIds($this->w[$approver]), "$approver should see the staff request");
        }
        foreach (['cm', 'bs2', 'bs', 'cs'] as $nonApprover) {
            $this->assertNotContains($staff->id, $this->actionableIds($this->w[$nonApprover]), "$nonApprover must not see the staff request");
        }

        $commissaryStaff = $this->pendingRequest($this->w['cs']);
        $this->assertContains($commissaryStaff->id, $this->actionableIds($this->w['cm']));
        $this->assertContains($commissaryStaff->id, $this->actionableIds($this->w['admin']));
        $this->assertNotContains($commissaryStaff->id, $this->actionableIds($this->w['bm']));

        $manager = $this->pendingRequest($this->w['bm']);
        $this->assertContains($manager->id, $this->actionableIds($this->w['admin']));
        $this->assertNotContains($manager->id, $this->actionableIds($this->w['bm'])); // no self-approval
        $this->assertNotContains($manager->id, $this->actionableIds($this->w['cm']));
    }

    public function test_bell_payload_counts_pending_and_omits_sensitive_fields(): void
    {
        $request = $this->pendingRequest($this->w['bs'], 'secret-inbox@hv.test');
        $code = $this->w['bs']->fresh()->recovery_code;

        $response = $this->actingAs($this->w['bm'])->getJson('/notifications')->assertOk();
        $response->assertJsonFragment(['type' => 'recovery']);
        $response->assertSee('Password reset identity verification needed');
        $response->assertSee($this->w['bs']->full_name);
        $response->assertSee('reset-requests?request='.$request->id);

        $this->assertGreaterThanOrEqual(1, $response->json('total'));
        $response->assertDontSee('secret-inbox@hv.test');
        $response->assertDontSee($code);

        // An unauthorized role gets no recovery item for this request.
        $this->actingAs($this->w['cm'])->getJson('/notifications')->assertOk()->assertDontSee('recovery-'.$request->id);
    }

    public function test_reading_the_bell_does_not_approve_or_remove_the_pending_notification(): void
    {
        $request = $this->pendingRequest($this->w['bs']);

        $this->actingAs($this->w['bm'])->getJson('/notifications')->assertOk();

        // Reading did not change the request or resolve the approval.
        $this->assertSame(PasswordResetRequest::STATUS_PENDING, $request->fresh()->status);
        $this->assertContains($request->id, $this->actionableIds($this->w['bm']));

        $this->actingAs($this->w['bm'])->get('/alerts?category=inventory')->assertOk()
            ->assertDontSee('Account recovery approvals')
            ->assertDontSee('value="account_recovery"', false);
    }

    public function test_recovery_stays_out_of_inventory_alerts_and_review_targets_exact_request(): void
    {
        $request = $this->pendingRequest($this->w['bs'], 'private-inbox@hv.test');
        $code = $this->w['bs']->fresh()->recovery_code;

        $this->actingAs($this->w['bm'])->get('/alerts?category=inventory')->assertOk()
            ->assertDontSee('reset-requests?request='.$request->id)
            ->assertDontSee('private-inbox@hv.test')
            ->assertDontSee($code);

        // Following the link highlights the exact request row (read-only GET).
        $this->actingAs($this->w['bm'])->get(route('reset-requests', ['request' => $request->id]))->assertOk()
            ->assertSee('id="reset-request-'.$request->id.'"', false);
        $this->assertSame(PasswordResetRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_approval_by_one_authorized_approver_clears_it_for_the_others(): void
    {
        $request = $this->pendingRequest($this->w['bs']);
        $this->assertContains($request->id, $this->actionableIds($this->w['bm']));

        $this->actingAs($this->w['bm'])->postJson('/reset-requests/api', [
            'action' => 'verify_identity', 'id' => $request->id, 'identity_verified' => true, 'verification_method' => 'callback',
        ])->assertOk();

        $this->actingAs($this->w['admin'])
            ->postJson('/reset-requests/api', ['action' => 'approve', 'id' => $request->id, 'identity_verified' => true])
            ->assertOk();

        $this->assertNotContains($request->id, $this->actionableIds($this->w['bm']));
        $this->assertNotContains($request->id, $this->actionableIds($this->w['admin']));
        $this->actingAs($this->w['bm'])->getJson('/notifications')->assertOk()->assertDontSee('recovery-'.$request->id);
    }

    public function test_rejected_request_is_no_longer_actionable(): void
    {
        $request = $this->pendingRequest($this->w['bs']);

        $this->actingAs($this->w['admin'])
            ->postJson('/reset-requests/api', ['action' => 'reject', 'id' => $request->id, 'rejection_reason' => 'not_requested'])
            ->assertOk();

        $this->assertNotContains($request->id, $this->actionableIds($this->w['bm']));
        $this->assertNotContains($request->id, $this->actionableIds($this->w['admin']));
    }

    public function test_only_one_notification_per_request_despite_stale_awaiting_rows(): void
    {
        $this->awaitingRequest($this->w['bs']); // older, unverified — must not count
        $pending = $this->pendingRequest($this->w['bs']);

        $actionable = $this->service()->actionableFor($this->w['admin'])
            ->where('user_id', $this->w['bs']->id);

        $this->assertCount(1, $actionable);
        $this->assertSame($pending->id, $actionable->first()->id);
    }

    public function test_bell_groups_categories_and_scope_changes_remove_recovery_notifications(): void
    {
        $request = $this->pendingRequest($this->w['bs']);
        $this->actingAs($this->w['bm'])->get('/reset-requests')->assertOk()
            ->assertSee("label: 'Account Recovery'", false)
            ->assertSee("label: 'Inventory Alerts'", false)
            ->assertSee("label: 'Orders'", false)
            ->assertSee('Review request');
        $this->actingAs($this->w['bm'])->getJson('/notifications')->assertOk()
            ->assertJsonFragment(['category' => 'recovery']);
        $this->w['bm']->update(['branch_id' => $this->w['silang']->id]);
        $this->actingAs($this->w['bm']->fresh())->getJson('/notifications')->assertOk()
            ->assertDontSee('recovery-'.$request->id);
        $this->assertContains($request->id, $this->actionableIds($this->w['admin']));
    }

    public function test_admin_and_manager_notification_categories_scope_recovery_and_orders(): void
    {
        $recovery = $this->pendingRequest($this->w['bs']);
        $otherRecovery = $this->pendingRequest($this->w['bs2']);
        $order = Order::create([
            'order_number' => 'ORD-CATEGORY-1', 'branch_id' => $this->w['baguio']->id,
            'ordered_by' => $this->w['bm']->id, 'status' => 'pending', 'total_amount' => 50,
        ]);
        $otherOrder = Order::create([
            'order_number' => 'ORD-CATEGORY-2', 'branch_id' => $this->w['silang']->id,
            'ordered_by' => $this->w['bs2']->id, 'status' => 'pending', 'total_amount' => 50,
        ]);
        foreach (['admin', 'bm', 'cm'] as $role) {
            $this->actingAs($this->w[$role])->get('/alerts')->assertOk()
                ->assertSee('<title>Notifications', false)
                ->assertDontSee('name="resolved"', false)
                ->assertSee('aria-label="Notification category"', false)
                ->assertDontSee('aria-label="Notification categories"', false)
                ->assertDontSee('<select class="form-select form-select-sm" name="type">', false);
        }
        $this->actingAs($this->w['bm'])->get('/alerts?category=recovery')->assertOk()
            ->assertSee('value="recovery" selected', false)
            ->assertSee('reset-requests?request='.$recovery->id)
            ->assertDontSee('reset-requests?request='.$otherRecovery->id)
            ->assertDontSee('ORD-CATEGORY-1');
        $this->actingAs($this->w['bm'])->get('/alerts?category=orders')->assertOk()
            ->assertSee($order->order_number)->assertDontSee($otherOrder->order_number)
            ->assertDontSee('reset-requests?request='.$recovery->id);
        $this->actingAs($this->w['admin'])->get('/alerts?category=recovery&branch='.$this->w['baguio']->id)->assertOk()
            ->assertSee('reset-requests?request='.$recovery->id)
            ->assertDontSee('reset-requests?request='.$otherRecovery->id);
        $this->actingAs($this->w['admin'])->get('/alerts?category=inventory')->assertOk()
            ->assertDontSee($order->order_number)->assertDontSee('reset-requests?request='.$recovery->id);
    }

    public function test_bulk_review_respects_category_and_does_not_approve_recovery(): void
    {
        $recovery = $this->pendingRequest($this->w['bs']);
        $order = Order::create([
            'order_number' => 'ORD-REVIEW-1', 'branch_id' => $this->w['baguio']->id,
            'ordered_by' => $this->w['bm']->id, 'status' => 'pending', 'total_amount' => 50,
        ]);
        $inventory = Alert::create([
            'alert_type' => 'low_stock', 'severity' => 'warning', 'message' => 'Inventory warning',
            'branch_id' => $this->w['baguio']->id, 'is_resolved' => false,
        ]);
        $orderAlert = Alert::create([
            'alert_type' => 'operational', 'severity' => 'info', 'message' => 'Order warning',
            'order_id' => $order->id, 'branch_id' => $this->w['baguio']->id, 'is_resolved' => false,
        ]);
        $this->actingAs($this->w['admin'])->post(route('alerts.action'), [
            'post_action' => 'resolve_all', 'category' => 'inventory',
        ])->assertRedirect();
        $this->assertTrue($inventory->fresh()->is_resolved);
        $this->assertFalse($orderAlert->fresh()->is_resolved);
        $this->assertSame('pending', $recovery->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
    }
}
