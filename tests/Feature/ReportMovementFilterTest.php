<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Reports date filter (inclusive both ends, reversed range swapped with a
 * notice) and signed Stock-Movement Adjustments / Net.
 */
class ReportMovementFilterTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $owner;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => 1]);
        $role = Role::firstOrCreate(['role_name' => 'owner'], ['description' => 'owner']);
        $this->owner = User::create(['full_name' => 'O', 'email' => 'o@t.com', 'password_hash' => Hash::make('x'), 'role_id' => $role->id, 'branch_id' => $this->branch->id, 'status' => 'active']);
        $this->item = Item::create(['item_name' => 'Pho', 'unit' => 'pack', 'price' => 10, 'is_active' => 1]);
    }

    private function tx(string $type, int $qty, string $date): void
    {
        DB::table('stock_transactions')->insert([
            'item_id' => $this->item->id, 'branch_id' => $this->branch->id,
            'type' => $type, 'quantity' => $qty, 'reason' => 'test',
            'created_at' => $date.' 15:30:00',
        ]);
    }

    public function test_date_to_is_inclusive(): void
    {
        $this->tx('in', 5, '2026-10-16'); // on the To boundary, mid-afternoon

        $res = $this->actingAs($this->owner)->get('/reports?from=2026-10-16&to=2026-10-16&branch='.$this->branch->id)->assertOk();
        $res->assertViewHas('moveRows', function ($rows) {
            return collect($rows)->contains(fn ($r) => (int) $r->total_in === 5);
        });
    }

    public function test_reversed_range_is_swapped_with_notice(): void
    {
        $this->tx('in', 7, '2026-10-10');

        $res = $this->actingAs($this->owner)->get('/reports?from=2026-10-16&to=2026-10-01&branch='.$this->branch->id)->assertOk();
        $res->assertViewHas('dateFrom', '2026-10-01')
            ->assertViewHas('dateTo', '2026-10-16')
            ->assertViewHas('dateNotice', fn ($n) => ! empty($n));
    }

    public function test_invalid_date_falls_back_to_default(): void
    {
        $res = $this->actingAs($this->owner)->get('/reports?from=not-a-date&to=2026-10-16&branch='.$this->branch->id)->assertOk();
        $res->assertViewHas('dateFrom', fn ($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1);
    }

    public function test_disposals_fold_into_stock_out(): void
    {
        $this->tx('in', 10, '2026-10-10');
        $this->tx('out', -4, '2026-10-11');          // sale/usage
        $this->tx('adjustment', -3, '2026-10-12');   // disposal — counts as stock out
        $this->tx('expired', -2, '2026-10-13');      // expiry — counts as stock out

        $res = $this->actingAs($this->owner)->get('/reports?from=2026-10-01&to=2026-10-16&branch='.$this->branch->id)->assertOk();
        $res->assertViewHas('moveRows', function ($rows) {
            $r = collect($rows)->first();

            // Stock Out = 4 + 3 + 2 = 9; Net = In - Out = 10 - 9 = 1. No Adjustments column.
            return (int) $r->total_in === 10 && (int) $r->total_out === 9 && ! isset($r->total_adj);
        });
    }
}
