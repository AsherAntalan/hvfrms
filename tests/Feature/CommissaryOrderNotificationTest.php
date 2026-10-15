<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A new branch order must reach the Commissary's notification bell, even though
 * Commissary staff are branch-scoped and the order belongs to another branch.
 */
class CommissaryOrderNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $n): Role
    {
        return Role::firstOrCreate(['role_name' => $n], ['description' => $n]);
    }

    private function user(string $role, Branch $b, string $e): User
    {
        return User::create(['full_name' => 'U', 'email' => $e, 'password_hash' => Hash::make('x'), 'role_id' => $this->role($role)->id, 'branch_id' => $b->id, 'status' => 'active']);
    }

    public function test_pending_order_notifies_commissary_roles(): void
    {
        $commissary = Branch::create(['branch_name' => 'Commissary', 'branch_type' => 'commissary', 'is_active' => 1]);
        $silang = Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => 1]);
        $bm = $this->user('branch_manager', $silang, 'bm@t.com');
        $manager = $this->user('commissary_manager', $commissary, 'cm@t.com');
        $staff = $this->user('commissary_staff', $commissary, 'cs@t.com');

        DB::table('orders')->insert([
            'order_number' => 'ORD-N-1', 'branch_id' => $silang->id, 'status' => 'pending',
            'ordered_by' => $bm->id, 'total_amount' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([$manager, $staff] as $u) {
            $res = $this->actingAs($u)->getJson('/notifications')->assertOk();
            $this->assertGreaterThanOrEqual(1, $res->json('sidebar.orders'));   // badge counts the order
            $msgs = collect($res->json('items'))->pluck('message')->implode(' | ');
            $this->assertStringContainsString('ORD-N-1 awaiting approval', $msgs);
        }
    }

    public function test_other_branch_manager_does_not_see_the_order(): void
    {
        $commissary = Branch::create(['branch_name' => 'Commissary', 'branch_type' => 'commissary', 'is_active' => 1]);
        $silang = Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => 1]);
        $dasma = Branch::create(['branch_name' => 'Dasma', 'branch_type' => 'franchise', 'is_active' => 1]);
        $bm = $this->user('branch_manager', $silang, 'bm@t.com');
        $otherBm = $this->user('branch_manager', $dasma, 'obm@t.com');

        DB::table('orders')->insert([
            'order_number' => 'ORD-N-2', 'branch_id' => $silang->id, 'status' => 'pending',
            'ordered_by' => $bm->id, 'total_amount' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->actingAs($otherBm)->getJson('/notifications')->assertOk();
        $msgs = collect($res->json('items'))->pluck('message')->implode(' | ');
        $this->assertStringNotContainsString('ORD-N-2', $msgs);
    }
}
