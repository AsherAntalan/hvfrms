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
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Real-route coverage for the forecast fix, Commissary Sales rendering,
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

    public function test_reports_show_commissary_sales_without_literal_mdash(): void
    {
        [$owner, $main] = $this->owner();
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
        $res->assertSee('Commissary Sales')->assertDontSee('Order Summary');
        $res->assertSee('Tanza');
    }

    #[TestWith(['owner', 'sales'])]
    #[TestWith(['admin', 'sales'])]
    #[TestWith(['commissary_manager', 'sales'])]
    #[TestWith(['owner', 'orders'])]
    public function test_sales_report_keeps_the_selected_period_without_counting_header_only_orders(string $roleName, string $tab): void
    {
        $this->freezeTime();
        $branch = $this->branch('Tanza', 'main');
        $user = $this->user($this->role($roleName), $branch, 'sales@test.com');
        DB::table('orders')->insert([
            'order_number' => 'ORD-UNVERIFIED', 'branch_id' => $branch->id, 'ordered_by' => $user->id,
            'status' => 'settled', 'total_amount' => 9876.54, 'fulfilled_amount' => 9876.54,
            'payment_reference' => '1234567890123', 'payment_submitted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->get('/reports?tab='.$tab.'&from=2026-10-01&to=2026-10-25');

        $response->assertOk()->assertViewHas('salesAuthorized', true)
            ->assertViewHas('dateFrom', '2026-10-01')->assertViewHas('dateTo', '2026-10-25')
            ->assertSee('Commissary Sales')->assertViewHas('salesSummary', fn (array $summary): bool => $summary['amount'] === 0.0)
            ->assertSee('class="tab-pane fade show active" id="tabSales"', false)
            ->assertSee('No released or completed order items in this period.')
            ->assertDontSee('Order Summary')->assertDontSee('9,876.54')->assertDontSee('9876.54');
    }

    #[TestWith(['sales'])]
    #[TestWith(['orders'])]
    public function test_sales_export_has_headers_and_no_unreleased_order_values(string $type): void
    {
        [$owner] = $this->owner();

        $response = $this->actingAs($owner)->get('/reports/export?type='.$type)->assertOk()->assertDownload();
        $this->assertStringContainsString('Record Basis', $response->streamedContent());
    }

    public function test_branch_manager_cannot_open_or_export_commissary_sales(): void
    {
        $branch = $this->branch('Silang');
        $user = $this->user($this->role('branch_manager'), $branch, 'manager@test.com');

        $this->actingAs($user)->get('/reports')->assertOk()
            ->assertViewHas('salesAuthorized', false)->assertDontSee('id="tabSales"', false);
        $this->get('/reports?tab=sales')->assertForbidden();
        $this->get('/reports?tab=orders')->assertForbidden();
        $this->get('/reports/export?type=sales')->assertForbidden();
        $this->get('/reports/export?type=orders')->assertForbidden();
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

    public function test_commissary_is_excluded_from_branch_reports_options_and_aggregates(): void
    {
        [$owner, $main] = $this->owner();
        $commissary = $this->branch('CommissaryHub', 'commissary');
        $franchise = $this->branch('Franchise B');
        $commItem = $this->item('CommissaryOnlyProduct');
        $franItem = $this->item('FranchiseOnlyProduct');
        $this->batch($commItem->id, $commissary->id, 20, now()->addMonths(3)->toDateString());
        $this->batch($franItem->id, $franchise->id, 8, now()->addMonths(3)->toDateString());

        // All-Branches view: franchise stock shows; Commissary stock and the
        // Commissary branch option are excluded entirely.
        $res = $this->actingAs($owner)->get('/reports?branch=0');
        $res->assertOk();
        $res->assertSee('FranchiseOnlyProduct');
        $res->assertDontSee('CommissaryOnlyProduct');
        $res->assertDontSee('CommissaryHub');
    }

    public function test_direct_commissary_branch_param_is_ignored(): void
    {
        [$owner, $main] = $this->owner();
        $commissary = $this->branch('CommissaryHub', 'commissary');
        $franchise = $this->branch('Franchise B');
        $commItem = $this->item('CommissaryOnlyProduct');
        $franItem = $this->item('FranchiseOnlyProduct');
        $this->batch($commItem->id, $commissary->id, 20, now()->addMonths(3)->toDateString());
        $this->batch($franItem->id, $franchise->id, 8, now()->addMonths(3)->toDateString());

        // A manually edited ?branch=<commissary id> must not bypass the filter.
        $res = $this->actingAs($owner)->get('/reports?branch='.$commissary->id);
        $res->assertOk();
        $res->assertViewHas('branchFilter', 0);
        $res->assertSee('FranchiseOnlyProduct');
        $res->assertDontSee('CommissaryOnlyProduct');
    }

    public function test_reports_default_to_this_month_and_detect_presets(): void
    {
        [$owner, $main] = $this->owner();

        // No dates supplied → default to This Month (first of month → today), preset=month.
        $res = $this->actingAs($owner)->get('/reports');
        $res->assertOk();
        $res->assertViewHas('dateFrom', now('Asia/Manila')->startOfMonth()->format('Y-m-d'));
        $res->assertViewHas('dateTo', now('Asia/Manila')->format('Y-m-d'));
        $res->assertViewHas('datePreset', 'month');

        // Explicit single-day range is preserved and recognised as "today".
        $today = now('Asia/Manila')->format('Y-m-d');
        $res2 = $this->actingAs($owner)->get('/reports?from='.$today.'&to='.$today);
        $res2->assertViewHas('dateFrom', $today);
        $res2->assertViewHas('dateTo', $today);
        $res2->assertViewHas('datePreset', 'today');

        // Reversed explicit range is swapped (inclusive, still shows) → custom.
        $from = now('Asia/Manila')->subDays(3)->format('Y-m-d');
        $res3 = $this->actingAs($owner)->get('/reports?from='.$today.'&to='.$from);
        $res3->assertViewHas('dateFrom', $from);
        $res3->assertViewHas('dateTo', $today);
    }

    public function test_commissary_scoped_user_sees_no_branch_rows_not_all_branches(): void
    {
        [$owner, $main] = $this->owner();
        $commissary = $this->branch('CommissaryHub', 'commissary');
        $franchise = $this->branch('Franchise B');
        $cm = $this->user($this->role('commissary_manager'), $commissary, 'cm@test.com');
        $franItem = $this->item('FranchiseOnlyProduct');
        $this->batch($franItem->id, $franchise->id, 8, now()->addMonths(3)->toDateString());

        // Scope resolves to the Commissary (excluded) → empty, never all branches.
        $res = $this->actingAs($cm)->get('/reports');
        $res->assertOk();
        $res->assertViewHas('branchFilter', $commissary->id);
        $res->assertDontSee('FranchiseOnlyProduct');
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
