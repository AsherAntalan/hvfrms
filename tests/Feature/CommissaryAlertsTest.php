<?php

namespace Tests\Feature;

use App\Mail\AlertDigest;
use App\Models\Alert;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderProduction;
use App\Models\Role;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CommissaryAlertsTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        $this->travelTo(Carbon::parse('2026-10-25 12:00:00', 'Asia/Manila'));
        $commissary = Branch::create(['branch_name' => 'Commissary', 'branch_type' => 'commissary', 'is_active' => true]);
        $branch = Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => true]);
        $users = [];
        foreach (['owner', 'commissary_manager', 'commissary_staff', 'branch_manager'] as $name) {
            $role = Role::firstOrCreate(['role_name' => $name], ['description' => $name]);
            $users[$name] = User::create(['full_name' => $name, 'email' => $name.'@alerts.test', 'password_hash' => Hash::make('password'),
                'role_id' => $role->id, 'branch_id' => $name === 'branch_manager' ? $branch->id : $commissary->id, 'status' => 'active']);
        }
        $item = Item::create(['item_name' => 'Buns', 'unit' => 'piece', 'price' => 10, 'reorder_level' => 20, 'is_active' => true]);

        return compact('commissary', 'branch', 'users', 'item');
    }

    private function batch(array $world, Branch $branch, int $expiryDays, int $quantity = 3): StockBatch
    {
        return StockBatch::create(['item_id' => $world['item']->id, 'branch_id' => $branch->id,
            'quantity_in' => 3, 'quantity_left' => $quantity, 'condition' => 'good',
            'expiry_date' => now()->addDays($expiryDays)->toDateString(), 'received_at' => now()]);
    }

    private function production(array $world, StockBatch $batch, string $source = 'produced'): OrderProduction
    {
        $order = Order::create(['order_number' => 'ORD-PRODUCTION-'.$batch->id, 'branch_id' => $world['branch']->id,
            'ordered_by' => $world['users']['branch_manager']->id, 'status' => 'preparing', 'total_amount' => 30]);
        $line = OrderItem::create(['order_id' => $order->id, 'item_id' => $batch->item_id, 'quantity' => 3, 'unit_price' => 10]);
        $production = OrderProduction::create(['order_id' => $order->id, 'order_item_id' => $line->id, 'item_id' => $batch->item_id,
            'destination_branch_id' => $world['branch']->id, 'produced_qty' => 3, 'dispatched_qty' => 0,
            'production_at' => now(), 'expiry_date' => $batch->expiry_date, 'condition' => 'good', 'source_type' => $source,
            'stock_batch_id' => $source === 'produced' ? $batch->id : null, 'qr_code' => 'TEST-PRODUCTION-'.$batch->id]);
        if ($source === 'picked') {
            DB::table('order_production_units')->insert(['production_id' => $production->id, 'unit_number' => 1,
                'stock_batch_id' => $batch->id, 'qr_code' => 'TEST-UNIT-'.$batch->id, 'created_at' => now()]);
        }

        return $production;
    }

    public function test_commissary_hides_legacy_stock_alerts_and_shows_live_order_followups(): void
    {
        $w = $this->world();
        foreach (['low_stock', 'unusual_sales', 'operational', 'expiring_soon'] as $type) {
            Alert::create(['alert_type' => $type, 'severity' => 'warning', 'branch_id' => $w['commissary']->id, 'message' => 'LEGACY-'.$type]);
        }
        foreach (['pending', 'approved', 'preparing', 'delivering'] as $status) {
            Order::create(['order_number' => 'ORD-FOLLOWUP-'.$status, 'branch_id' => $w['branch']->id,
                'ordered_by' => $w['users']['branch_manager']->id, 'status' => $status]);
        }

        $response = $this->actingAs($w['users']['commissary_manager'])->get('/alerts')->assertOk()
            ->assertSee('Orders Notification')->assertSee('Request awaiting review')->assertSee('Preparation in progress')
            ->assertViewHas('countOpen', 0)->assertViewHas('operationalOrderCount', 4)->assertDontSee('LEGACY-');
        if ($folder = getenv('PRODUCTS_BROWSER_ARTIFACTS')) {
            if (! is_dir($folder)) {
                mkdir($folder, 0777, true);
            }
            file_put_contents($folder.'/commissary-alerts.html', $response->getContent());
        }
        $this->getJson('/alerts/count')->assertOk()->assertJsonPath('count', 0);
        $this->getJson('/notifications')->assertOk()->assertJsonPath('total', 4)->assertJsonPath('sidebar.alerts', 0)
            ->assertJsonPath('sidebar.orders', 4)->assertJsonCount(4, 'items');
        $this->post('/alerts', ['post_action' => 'resolve_all'])->assertRedirect();
        $this->assertSame(4, Alert::where('is_resolved', false)->count());
        $this->get('/alerts?resolved=1')->assertOk()->assertViewHas('operationalOrderCount', 0);
    }

    #[TestWith(['produced', -1, 'expired'])]
    #[TestWith(['picked', 1, 'expiring_soon'])]
    public function test_expiry_alerts_only_cover_remaining_order_linked_goods(string $source, int $days, string $type): void
    {
        $w = $this->world();
        $batch = $this->batch($w, $w['commissary'], $days);
        $production = $this->production($w, $batch, $source);
        $unlinked = $this->batch($w, $w['commissary'], -1);
        $depleted = $this->batch($w, $w['commissary'], -1, 0);
        $this->production($w, $depleted);

        $this->actingAs($w['users']['commissary_manager'])->get('/alerts')->assertOk()->assertViewHas('countOpen', 1);
        $this->assertDatabaseHas('alerts', ['batch_id' => $batch->id, 'alert_type' => $type]);
        $this->assertDatabaseMissing('alerts', ['batch_id' => $unlinked->id]);
        $this->assertDatabaseMissing('alerts', ['batch_id' => $depleted->id]);
        $this->get('/alerts')->assertOk()->assertViewHas('countOpen', 1);
        $this->assertDatabaseCount('alerts', 1);
        $notice = Alert::firstOrFail();
        $this->actingAs($w['users']['commissary_staff'])->getJson('/notifications')->assertOk()
            ->assertJsonPath('items.0.link', route('delivery'));
        $this->get('/delivery')->assertOk()->assertSee('View Delivery Follow-ups');
        $this->actingAs($w['users']['branch_manager'])->post('/alerts', ['post_action' => 'resolve', 'resolve_id' => $notice->id])->assertRedirect();
        $this->assertFalse($notice->fresh()->is_resolved);
        $production->update(['dispatched_qty' => 3]);
        DB::table('order_production_units')->where('production_id', $production->id)->update(['dispatched_at' => now()]);
        if ($source === 'produced') {
            $batch->update(['quantity_left' => 0]);
        }
        $this->actingAs($w['users']['commissary_manager'])->getJson('/notifications')->assertOk()->assertJsonPath('sidebar.alerts', 0);
        $this->get('/alerts')->assertOk()->assertViewHas('countOpen', 0);
        $this->assertDatabaseCount('alerts', 1);
    }

    public function test_scheduled_generation_and_email_keep_branch_stock_out_of_commissary_notifications(): void
    {
        $w = $this->world();
        Mail::fake();
        $prepared = $this->batch($w, $w['commissary'], 1);
        $this->production($w, $prepared);
        $this->batch($w, $w['branch'], 30);

        $this->artisan('alerts:generate')->assertSuccessful();

        $this->assertDatabaseHas('alerts', ['branch_id' => $w['branch']->id, 'alert_type' => 'low_stock']);
        $this->assertDatabaseMissing('alerts', ['branch_id' => $w['commissary']->id, 'alert_type' => 'low_stock']);
        Mail::assertSent(AlertDigest::class, fn (AlertDigest $mail): bool => $mail->hasTo($w['users']['owner']->email) && count($mail->alerts) === 2);
        Mail::assertSent(AlertDigest::class, fn (AlertDigest $mail): bool => $mail->hasTo($w['users']['commissary_manager']->email)
            && count($mail->alerts) === 1 && $mail->alerts[0]->alert_type === 'expiring_soon');
        $this->artisan('alerts:generate')->assertSuccessful();
        Mail::assertSentCount(2);
        $this->assertDatabaseCount('alerts', 2);
        $this->actingAs($w['users']['branch_manager'])->getJson('/notifications?branch='.$w['commissary']->id)
            ->assertOk()->assertJsonPath('sidebar.alerts', 1);
        $this->actingAs($w['users']['commissary_manager'])->getJson('/alerts/count')->assertOk()->assertJsonPath('count', 1);
    }

    public function test_resolve_all_only_resolves_relevant_commissary_alerts_without_closing_orders(): void
    {
        $w = $this->world();
        $batch = $this->batch($w, $w['commissary'], -1);
        $production = $this->production($w, $batch);
        $legacy = Alert::create(['alert_type' => 'low_stock', 'severity' => 'warning', 'message' => 'Old source warning', 'branch_id' => $w['commissary']->id]);
        $other = Alert::create(['alert_type' => 'low_stock', 'severity' => 'warning', 'message' => 'Branch warning', 'branch_id' => $w['branch']->id]);

        $this->actingAs($w['users']['commissary_manager'])->get('/alerts')->assertOk()->assertViewHas('countOpen', 1);
        $this->post('/alerts', ['post_action' => 'resolve_all'])->assertRedirect();

        $this->assertDatabaseHas('alerts', ['batch_id' => $batch->id, 'is_resolved' => true, 'resolved_by' => $w['users']['commissary_manager']->id]);
        $this->assertFalse($legacy->fresh()->is_resolved);
        $this->assertFalse($other->fresh()->is_resolved);
        $this->assertSame('preparing', $production->order->fresh()->status);
    }

    public function test_idle_and_consumption_warnings_apply_to_operating_branches_instead_of_commissary(): void
    {
        $w = $this->world();
        Mail::fake();
        $idle = Branch::create(['branch_name' => 'Idle Branch', 'branch_type' => 'franchise', 'is_active' => true]);
        $idle->forceFill(['created_at' => now()->subDays(30)])->save();
        $w['commissary']->forceFill(['created_at' => now()->subDays(30)])->save();
        foreach ([$w['commissary'], $w['branch']] as $branch) {
            foreach ([6 => 1, 5 => 1, 4 => 1, 3 => 1, 2 => 1, 1 => 100] as $weeks => $quantity) {
                StockTransaction::create(['branch_id' => $branch->id, 'item_id' => $w['item']->id, 'type' => 'out',
                    'quantity' => -$quantity, 'reason' => 'Recorded usage'])->forceFill(['created_at' => now()->subWeeks($weeks)])->save();
            }
        }

        $this->artisan('alerts:generate')->assertSuccessful();

        $this->assertDatabaseHas('alerts', ['branch_id' => $idle->id, 'alert_type' => 'operational']);
        $this->assertDatabaseHas('alerts', ['branch_id' => $w['branch']->id, 'alert_type' => 'unusual_sales']);
        $this->assertDatabaseMissing('alerts', ['branch_id' => $w['commissary']->id]);
    }
}
