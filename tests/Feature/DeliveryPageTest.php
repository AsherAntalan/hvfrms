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
 * The single unified Delivery page: active deliveries grouped by branch (with a
 * per-branch "new" notification count), delivery history served into the same
 * page, and the legacy history route folded back into it.
 */
class DeliveryPageTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $n): Role
    {
        return Role::firstOrCreate(['role_name' => $n], ['description' => $n]);
    }

    private function branch(string $n, string $t = 'franchise'): Branch
    {
        return Branch::create(['branch_name' => $n, 'branch_type' => $t, 'is_active' => 1]);
    }

    private function user(Role $r, Branch $b, string $e): User
    {
        return User::create(['full_name' => 'U', 'email' => $e, 'password_hash' => Hash::make('secret123'), 'role_id' => $r->id, 'branch_id' => $b->id, 'status' => 'active']);
    }

    private function order(Branch $b, string $number, string $status, int $by): int
    {
        return DB::table('orders')->insertGetId([
            'order_number' => $number, 'branch_id' => $b->id, 'status' => $status,
            'ordered_by' => $by, 'total_amount' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_active_deliveries_group_by_branch_with_new_count(): void
    {
        $main = $this->branch('Tanza', 'main');
        $silang = $this->branch('Silang');
        $dasma = $this->branch('Dasma');
        $owner = $this->user($this->role('owner'), $main, 'o@t.com');
        $sb = $this->user($this->role('branch_manager'), $silang, 'bm@t.com');

        $this->order($silang, 'ORD-S-1', 'pending', $sb->id);     // new
        $this->order($silang, 'ORD-S-2', 'delivering', $sb->id);  // active, not new
        $this->order($dasma, 'ORD-D-1', 'approved', $owner->id);  // active, not new

        $res = $this->actingAs($owner)->get('/delivery')->assertOk();
        $res->assertViewHas('branches', function ($branches) use ($silang) {
            $silangGroup = $branches->firstWhere('branch_id', $silang->id);

            return $silangGroup
                && $silangGroup['count'] === 2
                && $silangGroup['new'] === 1;
        });
        $res->assertSee('Silang')->assertSee('Dasma')->assertSee('1 new');
    }

    public function test_branch_scoped_user_sees_only_their_branch(): void
    {
        $main = $this->branch('Tanza', 'main');
        $silang = $this->branch('Silang');
        $dasma = $this->branch('Dasma');
        $sb = $this->user($this->role('branch_manager'), $silang, 'bm@t.com');

        $this->order($silang, 'ORD-S-9', 'pending', $sb->id);
        $this->order($dasma, 'ORD-D-9', 'pending', $sb->id);

        $this->actingAs($sb)->get('/delivery')->assertOk()
            ->assertViewHas('branches', fn ($branches) => $branches->count() === 1
                && $branches->first()['branch_id'] === $silang->id);
    }

    public function test_history_tab_shows_closed_orders(): void
    {
        $main = $this->branch('Tanza', 'main');
        $silang = $this->branch('Silang');
        $owner = $this->user($this->role('owner'), $main, 'o@t.com');
        $sb = $this->user($this->role('branch_manager'), $silang, 'bm@t.com');

        $this->order($silang, 'ORD-H-1', 'settled', $sb->id);

        $this->actingAs($owner)->get('/delivery')->assertOk()
            ->assertViewHas('history', fn ($history) => $history->contains('order_number', 'ORD-H-1'));
    }

    public function test_legacy_history_route_redirects_into_delivery_page(): void
    {
        $main = $this->branch('Tanza', 'main');
        $owner = $this->user($this->role('owner'), $main, 'o@t.com');

        $this->actingAs($owner)->get('/delivery/history')
            ->assertRedirect(route('delivery', ['tab' => 'history']));
    }
}
