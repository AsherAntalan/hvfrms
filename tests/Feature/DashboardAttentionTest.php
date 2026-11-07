<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\PasswordResetRequest;
use App\Models\ReceivingIssue;
use App\Models\Role;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardAttentionTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $role, Branch $branch): User
    {
        return User::create(['full_name' => $role, 'username' => $role, 'role_id' => Role::firstOrCreate(['role_name' => $role])->id,
            'branch_id' => $branch->id, 'password_hash' => bcrypt('password123'), 'status' => 'active']);
    }

    public function test_admin_attention_links_show_actionable_recovery_receiving_and_expired_records(): void
    {
        DB::connection()->getPdo()->sqliteCreateFunction('CURDATE', fn (): string => now()->toDateString());
        $branch = Branch::create(['branch_name' => 'Tanza', 'branch_type' => 'main', 'is_active' => true]);
        $admin = $this->person('admin', $branch);
        $manager = $this->person('branch_manager', $branch);
        $recovery = PasswordResetRequest::create(['user_id' => $manager->id, 'requested_by' => $manager->id,
            'status' => 'pending', 'request_email' => 'verified@example.test', 'email_verified_at' => now()]);
        PasswordResetRequest::create(['user_id' => $manager->id, 'requested_by' => $manager->id,
            'status' => 'pending', 'request_email' => 'unverified@example.test']);
        $order = Order::create(['order_number' => 'ATTENTION-1', 'branch_id' => $branch->id, 'ordered_by' => $manager->id,
            'status' => 'delivering', 'total_amount' => 10]);
        $issue = ReceivingIssue::create(['order_id' => $order->id, 'reported_by' => $manager->id,
            'status' => 'pending', 'reason' => 'Receiving failed', 'receipt' => [], 'snapshot' => []]);
        $other = Order::create(['order_number' => 'ATTENTION-2', 'branch_id' => $branch->id, 'ordered_by' => $manager->id,
            'status' => 'delivered', 'total_amount' => 10]);
        ReceivingIssue::create(['order_id' => $other->id, 'reported_by' => $manager->id,
            'status' => 'pending', 'reason' => 'Stale issue', 'receipt' => [], 'snapshot' => []]);
        $item = Item::create(['item_name' => 'Sauce', 'unit' => 'liter', 'price' => 10, 'is_active' => true]);
        foreach ([[1, now()->subDay()], [0, now()->subDay()], [2, today()]] as [$quantity, $expiry]) {
            StockBatch::create(['item_id' => $item->id, 'branch_id' => $branch->id, 'quantity_in' => 2,
                'quantity_left' => $quantity, 'expiry_date' => $expiry, 'created_by' => $admin->id]);
        }
        $this->actingAs($admin)->get('/dashboard')->assertOk()
            ->assertSee('1 account recovery request to review')->assertSee('1 receiving issue awaiting assistance')
            ->assertSee('1 expired batch to monitor')->assertDontSee('delivery discrepancies')
            ->assertViewHas('stats', fn (array $stats): bool => $stats['recovery_requests'] === 1 && $stats['receiving_issues'] === 1 && $stats['expired'] === 1);
        $this->get(route('reset-requests', ['actionable' => 1]))->assertOk()
            ->assertViewHas('requests', fn ($requests): bool => $requests->count() === 1 && $requests->first()->id === $recovery->id);
        $this->get(route('orders', ['queue' => 'active', 'receiving_issues' => 1]))->assertOk()
            ->assertViewHas('orders', fn ($orders): bool => $orders->count() === 1 && $orders->first()->id === $order->id);
        $issue->update(['status' => 'resolved']);
        $recovery->update(['status' => 'rejected']);
        $this->get('/dashboard')->assertOk()->assertDontSee('account recovery request to review')->assertDontSee('receiving issue awaiting assistance');
        $this->actingAs($manager)->get('/dashboard')->assertOk()->assertDontSee('receiving issue awaiting assistance');
    }
}
