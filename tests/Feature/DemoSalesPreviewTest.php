<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\DemoSalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DemoSalesPreviewTest extends TestCase
{
    use RefreshDatabase;

    private ?string $originalConnection = null;

    protected function tearDown(): void
    {
        if ($this->originalConnection !== null) {
            config(['database.default' => $this->originalConnection]);
        }
        parent::tearDown();
    }

    private function enablePreview(): void
    {
        $this->originalConnection = config('database.default');
        $pdo = DB::connection()->getPdo();
        config(['demo.preview' => true, 'database.connections.demo' => array_replace(config('database.connections.sqlite'), ['database' => 'hvfrms_demo_tests'])]);
        DB::purge('demo');
        DB::connection('demo')->setPdo($pdo);
        config(['database.default' => 'demo']);
    }

    private function world(string $roleName = 'owner'): array
    {
        $this->travelTo(Carbon::parse('2026-10-25 12:00:00', 'Asia/Manila'));
        $branch = Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => true]);
        $other = Branch::create(['branch_name' => 'Tanza', 'branch_type' => 'main', 'is_active' => true]);
        $commissary = Branch::create(['branch_name' => 'Commissary', 'branch_type' => 'commissary', 'is_active' => true]);
        $role = Role::firstOrCreate(['role_name' => $roleName], ['description' => $roleName]);
        $user = User::create(['full_name' => 'Demo User', 'email' => 'demo@example.test', 'password_hash' => Hash::make('password'),
            'role_id' => $role->id, 'branch_id' => $roleName === 'branch_manager' ? $branch->id : $commissary->id, 'status' => 'active']);

        return compact('branch', 'other', 'commissary', 'user');
    }

    private function order(Branch $branch, string $number, float $amount, ?string $closed = '2026-10-15 12:00:00', string $status = 'settled', string $marker = 'DEMO-HVFRMS#preview', ?float $fulfilled = null): void
    {
        Order::create(['order_number' => $number, 'branch_id' => $branch->id, 'status' => $status, 'total_amount' => $amount,
            'fulfilled_amount' => $fulfilled, 'settled_at' => $closed, 'remarks' => $marker]);
    }

    public function test_simulated_amount_uses_closed_demo_orders_and_inclusive_closure_dates(): void
    {
        $this->enablePreview();
        $w = $this->world();
        $this->order($w['branch'], 'LOWER', 120, '2026-10-01 00:00:00', fulfilled: 100);
        $this->order($w['branch'], 'UPPER', 200, '2026-10-25 23:59:59');
        $this->order($w['other'], 'OTHER', 50);
        $this->order($w['branch'], 'BEFORE', 9999, '2026-09-30 23:59:59');
        $this->order($w['branch'], 'AFTER', 9999, '2026-10-26 00:00:00');
        $this->order($w['branch'], 'PENDING', 9999, null, 'pending');
        $this->order($w['branch'], 'REAL', 9999, marker: 'Actual business order');
        $this->order($w['commissary'], 'SOURCE', 9999);

        $summary = app(DemoSalesService::class)->summary('2026-10-01', '2026-10-25');

        $this->assertSame(350.0, $summary['amount']);
        $this->assertSame(3, $summary['orders']);
        $this->assertCount(2, $summary['rows']);
        $this->assertSame(300.0, app(DemoSalesService::class)->summary('2026-10-01', '2026-10-25', $w['branch']->id)['amount']);
    }

    #[TestWith(['owner'])]
    #[TestWith(['commissary_manager'])]
    public function test_demo_report_shows_labelled_branch_figures_and_retains_normal_inventory_scope(string $roleName): void
    {
        $this->enablePreview();
        $w = $this->world($roleName);
        $this->order($w['branch'], 'BRANCH', 300);
        $this->order($w['other'], 'OTHER', 50);

        $response = $this->actingAs($w['user'])->get('/reports?tab=sales&from=2026-10-01&to=2026-10-25&branch='.$w['branch']->id)->assertOk();

        $response->assertViewHas('demoPreview', true)->assertViewHas('canSelectBranches', true)
            ->assertViewHas('demoSalesScopeText', 'Silang')->assertViewHas('demoSales', fn (array $data): bool => $data['amount'] === 300.0 && $data['orders'] === 1)
            ->assertSee('Simulated Sales')->assertSee('300.00')->assertSee('does not represent verified payments');
        if ($roleName === 'commissary_manager') {
            $this->get('/reports?tab=stock&branch='.$w['other']->id)->assertOk()->assertViewHas('branchFilter', $w['commissary']->id)
                ->assertViewHas('stockRows', []);
        }
        $this->get('/reports/export?type=sales')->assertStatus(409);
    }

    public function test_preview_flag_cannot_enable_simulated_sales_on_an_operational_connection(): void
    {
        config(['demo.preview' => true]);
        $w = $this->world();
        $this->order($w['branch'], 'CLOSED', 300);

        $this->assertFalse(app(DemoSalesService::class)->enabled());
        $this->actingAs($w['user'])->get('/reports?tab=sales&demo=1')->assertOk()
            ->assertViewHas('demoPreview', false)->assertViewHas('salesSummary', fn (array $summary): bool => $summary['amount'] === 0.0)->assertDontSee('300.00');
    }

    public function test_preview_requires_explicit_flag_even_on_demo_connection(): void
    {
        $this->enablePreview();
        config(['demo.preview' => false]);

        $this->assertFalse(app(DemoSalesService::class)->enabled());
    }

    public function test_preview_is_disabled_in_production(): void
    {
        $this->enablePreview();
        $this->app['env'] = 'production';

        $this->assertFalse(app(DemoSalesService::class)->enabled());
    }

    public function test_branch_manager_cannot_access_sales_even_in_demo_preview(): void
    {
        $this->enablePreview();
        $w = $this->world('branch_manager');

        $this->actingAs($w['user'])->get('/reports?tab=sales')->assertForbidden();
        $this->get('/reports')->assertOk()->assertDontSee('id="tabSales"', false);
    }
}
