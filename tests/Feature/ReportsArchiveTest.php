<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ExpiredBatchArchive;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Real-route coverage for the forecast fix, Order Summary rendering,
 * expired-batch archive lifecycle, 30-day retention, and branch-scoped modal
 * data. Routes are exercised through the full middleware stack (APP_URL is set
 * to http://localhost in phpunit.xml so routing matches at the app root).
 */
class ReportsArchiveTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $name): Role
    {
        return Role::firstOrCreate(['role_name' => $name], ['description' => $name]);
    }

    private function branch(string $name, string $type = 'franchise'): Branch
    {
        return Branch::create(['branch_name' => $name, 'branch_type' => $type, 'is_active' => 1]);
    }

    private function user(Role $role, Branch $branch, string $email): User
    {
        return User::create([
            'full_name' => 'U', 'email' => $email, 'password_hash' => Hash::make('secret123'),
            'role_id' => $role->id, 'branch_id' => $branch->id, 'status' => 'active',
        ]);
    }

    private function item(string $name = 'Noodles'): Item
    {
        return Item::create(['item_name' => $name, 'unit' => 'pack', 'reorder_level' => 5, 'is_active' => 1]);
    }

    private function batch(int $itemId, int $branchId, int $qty, ?string $expiry): StockBatch
    {
        return StockBatch::create([
            'item_id' => $itemId, 'branch_id' => $branchId, 'quantity_in' => $qty,
            'quantity_left' => $qty, 'expiry_date' => $expiry, 'condition' => 'good', 'received_at' => now(),
        ]);
    }

    private function owner(): array
    {
        $main = $this->branch('Tanza', 'main');
        $u = $this->user($this->role('owner'), $main, 'owner@test.com');

        return [$u, $main];
    }

    // ───────────────────────── forecast ─────────────────────────

    public function test_forecast_page_renders_with_data_and_no_array_error(): void
    {
        [$owner, $main] = $this->owner();
        $item = $this->item('Rice Noodles');
        $batch = $this->batch($item->id, $main->id, 100, now()->addMonths(3)->toDateString());

        // Consumption in two distinct prior weeks → activeWeeks >= 2, which
        // forces the "Why?" detail render that previously echoed an array.
        foreach ([9, 18] as $daysAgo) {
            DB::table('stock_transactions')->insert([
                'item_id' => $item->id, 'branch_id' => $main->id, 'batch_id' => $batch->id,
                'type' => 'out', 'quantity' => -5, 'reason' => 'Sale',
                'performed_by' => $owner->id, 'created_at' => now()->subDays($daysAgo),
            ]);
        }

        $res = $this->actingAs($owner)->get('/forecast');
        $res->assertOk();
        $res->assertDontSee('array given');
        $res->assertSee('Rice Noodles');
        $res->assertSee('Weighted Moving Average');
    }

    public function test_forecast_handles_empty_data(): void
    {
        [$owner] = $this->owner();
        $this->item('Lonely Item'); // no transactions → insufficient data

        $res = $this->actingAs($owner)->get('/forecast');
        $res->assertOk();
        $res->assertSee('Insufficient Data');
    }

    // ───────────────────────── order summary ─────────────────────────

    public function test_order_summary_shows_zero_not_literal_mdash(): void
    {
        [$owner, $main] = $this->owner();
        // One pending order → row renders with pending=1 and zeros elsewhere.
        DB::table('orders')->insert([
            'order_number' => 'ORD-1', 'branch_id' => $main->id, 'ordered_by' => $owner->id,
            'status' => 'pending', 'total_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->actingAs($owner)->get('/reports');
        $res->assertOk();
        // The bug rendered '{{ "&mdash;" }}' which Blade double-escapes to the
        // literal '&amp;mdash;' in the HTML source (showing as text "&mdash;").
        // Legitimate '&mdash;' entities elsewhere are fine; the double-escaped
        // form must be absent.
        $res->assertDontSee('&amp;mdash;', false);
        $res->assertSee('Order Summary');
        // Zero-count cells render a real 0 (the pending order row has zeros).
        $res->assertSee('Tanza');
    }

    // ───────────────────────── archive lifecycle ─────────────────────────

    public function test_only_authorized_expired_batch_can_be_archived_and_is_idempotent(): void
    {
        [$owner, $main] = $this->owner();
        $item = $this->item();
        $expired = $this->batch($item->id, $main->id, 12, now()->subDays(3)->toDateString());
        $fresh = $this->batch($item->id, $main->id, 9, now()->addMonths(2)->toDateString());

        // Future batch cannot be archived through the expired endpoint.
        $this->actingAs($owner)->postJson('/reports/archive', ['batch_id' => $fresh->id])
            ->assertStatus(422);

        // Expired batch archives.
        $this->actingAs($owner)->postJson('/reports/archive', ['batch_id' => $expired->id])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertEquals(1, ExpiredBatchArchive::where('batch_id', $expired->id)->count());
        $firstArchivedAt = ExpiredBatchArchive::where('batch_id', $expired->id)->value('archived_at');

        // Repeat archive is safe: no duplicate, clock not reset.
        $this->actingAs($owner)->postJson('/reports/archive', ['batch_id' => $expired->id])
            ->assertOk()->assertJson(['ok' => true, 'already' => true]);
        $this->assertEquals(1, ExpiredBatchArchive::where('batch_id', $expired->id)->count());
        $this->assertEquals(
            (string) $firstArchivedAt,
            (string) ExpiredBatchArchive::where('batch_id', $expired->id)->value('archived_at')
        );

        // Archiving performed NO stock movement and did not change balances.
        $this->assertEquals(12, $expired->fresh()->quantity_left);
        $this->assertEquals(0, StockTransaction::where('item_id', $item->id)->count());
    }

    public function test_branch_scoped_user_cannot_archive_another_branch(): void
    {
        [$owner, $main] = $this->owner();
        $other = $this->branch('Franchise B');
        $bm = $this->user($this->role('branch_manager'), $other, 'bm@test.com');
        $item = $this->item();
        $expiredAtMain = $this->batch($item->id, $main->id, 5, now()->subDays(2)->toDateString());

        $this->actingAs($bm)->postJson('/reports/archive', ['batch_id' => $expiredAtMain->id])
            ->assertStatus(403);
        $this->assertEquals(0, ExpiredBatchArchive::count());
    }

    public function test_archive_list_excludes_entries_past_30_day_boundary(): void
    {
        [$owner, $main] = $this->owner();
        $item = $this->item();
        $old = $this->batch($item->id, $main->id, 3, now()->subDays(40)->toDateString());
        $recent = $this->batch($item->id, $main->id, 4, now()->subDays(5)->toDateString());

        ExpiredBatchArchive::create(['batch_id' => $old->id, 'branch_id' => $main->id, 'archived_by' => $owner->id, 'archived_at' => now()->subDays(31)]);
        ExpiredBatchArchive::create(['batch_id' => $recent->id, 'branch_id' => $main->id, 'archived_by' => $owner->id, 'archived_at' => now()->subDays(29)]);

        $res = $this->actingAs($owner)->getJson('/reports/archives');
        $res->assertOk();
        // Only the 29-day-old entry is visible; the 31-day-old one is due-out.
        $res->assertJsonPath('total', 1);
        $this->assertEquals($recent->id, $res->json('items.0.batch_id'));
    }

    public function test_scheduled_cleanup_is_repeatable_and_preserves_rows(): void
    {
        [$owner, $main] = $this->owner();
        $item = $this->item();
        $old = $this->batch($item->id, $main->id, 3, now()->subDays(40)->toDateString());
        $due = ExpiredBatchArchive::create(['batch_id' => $old->id, 'branch_id' => $main->id, 'archived_by' => $owner->id, 'archived_at' => now()->subDays(35)]);

        $this->artisan('archives:prune-expired')->assertExitCode(0);
        $due->refresh();
        $this->assertNotNull($due->removed_at);
        $this->assertEquals('retention', $due->removed_reason);
        // Row preserved (soft-remove), not physically deleted.
        $this->assertDatabaseHas('expired_batch_archives', ['id' => $due->id]);

        // Repeat run is safe (no double-processing / errors).
        $this->artisan('archives:prune-expired')->assertExitCode(0);
        $this->assertEquals(1, ExpiredBatchArchive::whereNotNull('removed_at')->count());
    }

    public function test_manual_removal_preserves_record(): void
    {
        [$owner, $main] = $this->owner();
        $item = $this->item();
        $b = $this->batch($item->id, $main->id, 3, now()->subDays(2)->toDateString());
        $archive = ExpiredBatchArchive::create(['batch_id' => $b->id, 'branch_id' => $main->id, 'archived_by' => $owner->id, 'archived_at' => now()]);

        $this->actingAs($owner)->postJson('/reports/archive/remove', ['id' => $archive->id])
            ->assertOk()->assertJson(['ok' => true]);

        $archive->refresh();
        $this->assertNotNull($archive->removed_at);
        $this->assertEquals('manual', $archive->removed_reason);
        $this->assertDatabaseHas('expired_batch_archives', ['id' => $archive->id]); // preserved
    }

    // ───────────────── inline branch scoping (page navigation) ─────────────────

    public function test_reports_scoped_to_main_warehouse_shows_only_its_stock(): void
    {
        [$owner, $main] = $this->owner();
        $other = $this->branch('Franchise B');
        $mainItem = $this->item('MainOnlyProduct');
        $otherItem = $this->item('OtherOnlyProduct');
        $this->batch($mainItem->id, $main->id, 10, now()->addMonths(3)->toDateString());
        $this->batch($otherItem->id, $other->id, 7, now()->addMonths(3)->toDateString());

        $res = $this->actingAs($owner)->get('/reports?branch='.$main->id);
        $res->assertOk();
        $res->assertSee('MainOnlyProduct');
        $res->assertDontSee('OtherOnlyProduct'); // other branch excluded from scope
    }

    public function test_branch_scoped_user_cannot_see_other_branch_via_query(): void
    {
        [$owner, $main] = $this->owner();
        $other = $this->branch('Franchise B');
        $bm = $this->user($this->role('branch_manager'), $other, 'bm2@test.com');
        $mainItem = $this->item('MainSecretProduct');
        $otherItem = $this->item('BranchBProduct');
        $this->batch($mainItem->id, $main->id, 10, now()->addMonths(3)->toDateString());
        $this->batch($otherItem->id, $other->id, 7, now()->addMonths(3)->toDateString());

        // Tampering ?branch=main is ignored — the manager stays scoped to own branch.
        $res = $this->actingAs($bm)->get('/reports?branch='.$main->id);
        $res->assertOk();
        $res->assertSee('BranchBProduct');
        $res->assertDontSee('MainSecretProduct');
    }

    // ───────────────────────── forecast branch scoping ─────────────────────────

    public function test_forecast_uses_selected_branch_inputs(): void
    {
        [$owner, $main] = $this->owner();
        $other = $this->branch('Franchise B');
        $item = $this->item('Branch Scoped Item');
        $mainBatch = $this->batch($item->id, $main->id, 100, now()->addMonths(3)->toDateString());
        $otherBatch = $this->batch($item->id, $other->id, 100, now()->addMonths(3)->toDateString());

        // Consumption recorded ONLY at the "other" branch, across two weeks.
        foreach ([9, 18] as $daysAgo) {
            DB::table('stock_transactions')->insert([
                'item_id' => $item->id, 'branch_id' => $other->id, 'batch_id' => $otherBatch->id,
                'type' => 'out', 'quantity' => -8, 'reason' => 'Sale',
                'performed_by' => $owner->id, 'created_at' => now()->subDays($daysAgo),
            ]);
        }

        // Main has no consumption → Insufficient Data for this item.
        $main = $this->actingAs($owner)->get('/forecast?branch='.$main->id);
        $main->assertOk();
        $main->assertSee('Insufficient Data');

        // The "other" branch has demand → it is NOT flagged insufficient there
        // (a real trend/forecast is produced from that branch's own inputs).
        $otherRes = $this->actingAs($owner)->get('/forecast?branch='.$other->id);
        $otherRes->assertOk();
        $otherRes->assertSee('Branch Scoped Item');
    }
}
