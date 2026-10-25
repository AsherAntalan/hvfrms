<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ExpiredBatchArchive;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportBatchDateFilterTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{user: User, branch: Branch} */
    private function context(string $roleName = 'owner'): array
    {
        $this->travelTo(Carbon::parse('2026-10-25 12:00:00', 'Asia/Manila'));
        $branch = Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => true]);
        $role = Role::firstOrCreate(['role_name' => $roleName], ['description' => $roleName]);
        $user = User::create([
            'full_name' => 'Report Manager', 'email' => 'reports@example.test',
            'password_hash' => Hash::make('password'), 'role_id' => $role->id,
            'branch_id' => $branch->id, 'status' => 'active',
        ]);

        return compact('user', 'branch');
    }

    private function item(string $name): Item
    {
        return Item::create(['item_name' => $name, 'unit' => 'pack', 'price' => 10, 'reorder_level' => 5, 'is_active' => true]);
    }

    private function batch(Item $item, Branch $branch, string $receivedAt, ?string $expiry, int $quantity): StockBatch
    {
        return StockBatch::create([
            'item_id' => $item->id, 'branch_id' => $branch->id,
            'quantity_in' => $quantity + 20, 'quantity_left' => $quantity,
            'received_at' => $receivedAt, 'expiry_date' => $expiry, 'condition' => 'good',
        ]);
    }

    private function saveBrowserArtifact(string $name, string $html): void
    {
        if ($directory = getenv('REPORT_BATCH_DATE_BROWSER_ARTIFACTS')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/'.$name.'.html', $html);
        }
    }

    public function test_stock_summary_counts_current_remaining_stock_received_within_inclusive_dates(): void
    {
        ['user' => $user, 'branch' => $branch] = $this->context();
        $item = $this->item('Noodles');
        $this->batch($item, $branch, '2026-10-01 00:00:00', '2026-11-01', 3);
        $this->batch($item, $branch, '2026-10-25 23:59:59', '2026-11-03', 4);
        $this->batch($item, $branch, '2026-09-30 23:59:59', '2026-10-27', 90);
        $this->batch($item, $branch, '2026-10-26 00:00:00', '2026-10-28', 91);
        $this->batch($item, $branch, '2026-10-15 12:00:00', '2026-10-29', 0);
        $this->batch($this->item('Old Stock Only'), $branch, '2026-09-20 12:00:00', '2026-11-01', 80);

        $response = $this->actingAs($user)->get('/reports?tab=stock&from=2026-10-01&to=2026-10-25')->assertOk();

        $rows = $response->viewData('stockRows');
        $this->assertCount(1, $rows);
        $this->assertSame('Noodles', $rows[0]->item_name);
        $this->assertSame(7, (int) $rows[0]->total_qty);
        $this->assertSame(2, (int) $rows[0]->active_batches);
        $this->assertSame('2026-11-01', Carbon::parse($rows[0]->nearest_expiry)->toDateString());
        $this->saveBrowserArtifact('stock', $response->getContent());
    }

    public function test_expiry_report_filters_expiry_dates_without_filtering_received_dates_or_losing_archive_totals(): void
    {
        ['user' => $user, 'branch' => $branch] = $this->context();
        $item = $this->item('Noodles');
        $first = $this->batch($item, $branch, '2026-09-01 12:00:00', '2026-10-01', 3);
        $today = $this->batch($item, $branch, '2026-09-01 12:00:00', '2026-10-25', 4);
        $archived = $this->batch($item, $branch, '2026-09-01 12:00:00', '2026-10-10', 2);
        ExpiredBatchArchive::create(['batch_id' => $archived->id, 'branch_id' => $branch->id, 'archived_by' => $user->id, 'archived_at' => now()]);
        $this->batch($item, $branch, '2026-10-15 12:00:00', '2026-09-30', 90);
        $this->batch($item, $branch, '2026-10-15 12:00:00', '2026-10-26', 91);
        $this->batch($item, $branch, '2026-10-15 12:00:00', null, 92);
        $this->batch($item, $branch, '2026-10-15 12:00:00', '2026-10-15', 0);

        $response = $this->actingAs($user)->get('/reports?tab=expiry&from=2026-10-01&to=2026-10-25')->assertOk();

        $this->assertSame([$first->id], array_column($response->viewData('expired'), 'batch_id'));
        $this->assertSame([$today->id], array_column($response->viewData('expiringSoon'), 'batch_id'));
        $this->assertSame([], $response->viewData('expiryOk'));
        $response->assertViewHas('expiredPhysicalQty', 5)->assertViewHas('expiredArchivedCount', 1);
        $this->saveBrowserArtifact('expiry', $response->getContent());
    }

    /** @return array<string, array{string, int, list<string>}> */
    public static function exportTypes(): array
    {
        return [
            'stock uses receipt date' => ['stock', 0, ['ExpiryOutside', 'Included', 'NoExpiry']],
            'expiry uses expiry date' => ['expiry', 1, ['Included', 'ReceivedOutside']],
        ];
    }

    /** @param list<string> $expectedProducts */
    #[DataProvider('exportTypes')]
    public function test_csv_exports_apply_the_same_date_basis_as_the_report(string $type, int $productColumn, array $expectedProducts): void
    {
        ['user' => $user, 'branch' => $branch] = $this->context();
        $this->batch($this->item('Included'), $branch, '2026-10-15 12:00:00', '2026-10-15', 3);
        $this->batch($this->item('ReceivedOutside'), $branch, '2026-09-30 12:00:00', '2026-10-15', 4);
        $this->batch($this->item('ExpiryOutside'), $branch, '2026-10-15 12:00:00', '2026-09-30', 5);
        $this->batch($this->item('NoExpiry'), $branch, '2026-10-15 12:00:00', null, 6);
        $commissary = Branch::create(['branch_name' => 'Commissary', 'branch_type' => 'commissary', 'is_active' => true]);
        $this->batch($this->item('ExcludedCommissary'), $commissary, '2026-10-15 12:00:00', '2026-10-15', 7);

        $response = $this->actingAs($user)->get('/reports/export?type='.$type.'&from=2026-10-01&to=2026-10-25')->assertOk();

        $lines = preg_split('/\r\n|\r|\n/', trim($response->streamedContent()));
        $rows = array_map('str_getcsv', array_slice($lines, 1));
        $products = array_column($rows, $productColumn);
        sort($products);
        $this->assertSame($expectedProducts, $products);
    }

    /** @return array<string, array{string, string, int}> */
    public static function scopedReportTypes(): array
    {
        return [
            'stock scope' => ['stock', 'stockRows', 0],
            'expiry scope' => ['expiry', 'expired', 1],
        ];
    }

    #[DataProvider('scopedReportTypes')]
    public function test_date_filtering_keeps_branch_managers_scoped_in_reports_and_exports(string $type, string $viewKey, int $productColumn): void
    {
        ['user' => $user, 'branch' => $branch] = $this->context('branch_manager');
        $other = Branch::create(['branch_name' => 'Baguio', 'branch_type' => 'franchise', 'is_active' => true]);
        $this->batch($this->item('Own Product'), $branch, '2026-10-15 12:00:00', '2026-10-15', 3);
        $this->batch($this->item('Other Product'), $other, '2026-10-15 12:00:00', '2026-10-15', 4);
        $query = http_build_query(['tab' => $type, 'type' => $type, 'branch' => $other->id, 'from' => '2026-10-01', 'to' => '2026-10-25']);

        $response = $this->actingAs($user)->get('/reports?'.$query)->assertOk();

        $response->assertViewHas('branchFilter', $branch->id);
        $this->assertSame(['Own Product'], array_column($response->viewData($viewKey), 'item_name'));

        $export = $this->get('/reports/export?'.$query)->assertOk();
        $lines = preg_split('/\r\n|\r|\n/', trim($export->streamedContent()));
        $this->assertSame(['Own Product'], array_column(array_map('str_getcsv', array_slice($lines, 1)), $productColumn));
    }
}
