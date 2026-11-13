<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderProduction;
use App\Models\Role;
use App\Models\Setting;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Models\User;
use App\Services\OrderGroupLabelService;
use App\Services\OrderLabelService;
use App\Services\OrderPaymentService;
use App\Services\OrderWorkflowService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * PRESENTATION dataset seeder — a realistic operational history for a research
 * presentation, spanning a configurable window (default 2026-11-01 → 2027-01-31).
 *
 * Unlike DemoHvfrmsSeeder (which uses clearly-labelled DEMO catalog products),
 * this seeder builds its dataset on the REAL Hidden Vietnam product catalog,
 * branches and categories so the system presents as a genuine operating store.
 * Every order, QR label and proforma is produced by the REAL workflow services
 * (review → prepare → dispatch → receive) across the whole window, week by week.
 *
 * It still does NOT fabricate verified sales or payment verification. Payment
 * references/screenshots are synthetic submitted evidence, never verified payment.
 *
 * Safety: refuses the operational database and production environment. Target an
 * isolated presentation database (name containing "present"), reusing the demo
 * connection with an overridden name:
 *   DB_DEMO_DATABASE=hvfrms_present php artisan migrate:fresh --database=demo
 *   DB_DEMO_DATABASE=hvfrms_present php artisan db:seed --class=PresentationSeeder --database=demo
 */
class PresentationSeeder extends Seeder
{
    private const PASSWORD = 'present123';

    private const OPERATIONAL_DB = 'hidden_vietnam';

    /** Window: first and last Monday-anchored weeks (inclusive). */
    private const WINDOW_START = '2026-10-01';

    private const WINDOW_END = '2026-11-12';

    private OrderLabelService $labels;

    private OrderGroupLabelService $groups;

    private OrderPaymentService $payments;

    /** @var array<string,int> */
    private array $roleIds = [];

    /** @var array<int,Branch> operating (non-commissary) branches */
    private array $operating = [];

    private Branch $commissary;

    /** @var array<int,Item> real sellable catalog items */
    private array $catalog = [];

    /** @var array<string,User> */
    private array $users = [];

    private int $orderSeq = 0;

    private int $qrSeq = 0;

    private int $ordersMade = 0;

    /** Real wall-clock time captured before any Carbon::setTestNow — nothing is dated past this. */
    private Carbon $cutoff;

    public function run(): void
    {
        $this->guardTarget();
        $this->cutoff = Carbon::now();

        $this->labels = new OrderLabelService;
        $this->groups = new OrderGroupLabelService;
        $this->payments = new OrderPaymentService;

        $this->ensureRoles();
        $this->loadBranchesAndCommissary();
        $this->ensureSettings();
        $this->loadCatalog();
        $this->ensureUsers();

        $this->seedOpeningStock();
        $this->seedWeeklyHistory();

        Carbon::setTestNow();
        Setting::set('presentation_dataset_version', '1');

        $this->report();
    }

    private function guardTarget(): void
    {
        $db = (string) DB::connection()->getDatabaseName();

        if (app()->environment('production')) {
            throw new \RuntimeException('Refusing to run PresentationSeeder in production. Run it locally against the presentation database.');
        }
        if (strcasecmp($db, self::OPERATIONAL_DB) === 0 || ! str_contains(strtolower($db), 'present')) {
            throw new \RuntimeException(
                "Refusing to run PresentationSeeder against database '{$db}'. ".
                'Target an isolated presentation database (name containing "present"), e.g. '.
                'DB_DEMO_DATABASE=hvfrms_present php artisan db:seed --class=PresentationSeeder --database=demo.'
            );
        }
    }

    // ───────────────────────── reference ─────────────────────────

    private function ensureRoles(): void
    {
        $roles = [
            'super_admin' => 'Researcher/developer — full system access, audit logs, user management',
            'owner' => 'Hidden Vietnam main owner — all branches, users, branches, settings, order approval',
            'branch_manager' => 'Branch manager — dashboard, stock, orders, reports, forecast, alerts for OWN branch only',
            'branch_staff' => 'Branch staff — QR scanning, usage/spoilage recording, branch inventory tasks',
            'commissary_manager' => 'Commissary manager — reviews/prepares requests, sets expiry, prints labels, manages deliveries',
            'commissary_staff' => 'Commissary staff — assists preparation and scans prepared goods out for delivery',
            'admin' => 'Administrator — same application functions and all-branch access as Owner, with a distinct account',
        ];
        foreach ($roles as $name => $description) {
            $this->roleIds[$name] = Role::firstOrCreate(['role_name' => $name], ['description' => $description, 'created_at' => now()])->id;
        }
    }

    private function loadBranchesAndCommissary(): void
    {
        $commissary = Branch::where('branch_type', 'commissary')->where('is_active', true)->orderBy('id')->first();
        if ($commissary === null) {
            $commissary = Branch::firstOrCreate(
                ['branch_name' => 'Commissary'],
                ['branch_type' => 'commissary', 'address' => 'Production & dispatch source — Tanza, Cavite', 'is_active' => true]
            );
        }
        $this->commissary = $commissary;

        $this->operating = Branch::where('is_active', true)
            ->where('id', '!=', $commissary->id)
            ->where('branch_type', '!=', 'commissary')
            ->orderBy('id')
            ->get()
            ->all();

        if ($this->operating === []) {
            throw new \RuntimeException('No operating branches found. Import reference data (branches) before seeding.');
        }
    }

    private function ensureSettings(): void
    {
        $defaults = [
            'forecast_weeks' => '8',
            'safety_stock_factor' => '1.5',
            'unusual_sales_sigma' => '2',
            'idle_branch_days' => '3',
            'expiry_warning_days' => '7',
            'app_name' => 'Hidden Vietnam FRMS',
            'order_max_qty_per_line' => '0',
        ];
        foreach ($defaults as $key => $value) {
            if (Setting::find($key) === null) {
                Setting::set($key, $value);
            }
        }
        Setting::set('commissary_branch_id', (string) $this->commissary->id);
    }

    private function loadCatalog(): void
    {
        $items = Item::where('is_active', true)->whereNull('archived_at')->orderBy('id')->get();
        if ($items->isEmpty()) {
            throw new \RuntimeException('No active items found. Import the real product catalog (items) before seeding.');
        }
        $this->catalog = $items->all();
    }

    private function ensureUsers(): void
    {
        $tanza = collect($this->operating)->firstWhere('branch_type', 'main') ?? $this->operating[0];

        $this->users['owner'] = $this->ensureUser('owner', 'Hidden Vietnam Owner', 'owner@hvfrms.test', 'owner', $tanza->id);
        $this->users['admin'] = $this->ensureUser('admin', 'System Administrator', 'vietnamhidden2026@gmail.com', 'admin', $tanza->id);
        $this->users['commissary_manager'] = $this->ensureUser('commissary_manager', 'Commissary Manager', 'commissary.manager@hvfrms.test', 'commissary_manager', $this->commissary->id);
        $this->users['commissary_staff'] = $this->ensureUser('commissary_staff', 'Commissary Staff', 'commissary.staff@hvfrms.test', 'commissary_staff', $this->commissary->id);

        foreach ($this->operating as $branch) {
            $slug = $this->slug($branch->branch_name);
            $this->users['bm_'.$branch->id] = $this->ensureUser('branch_manager', $branch->branch_name.' Manager', "manager.{$slug}@hvfrms.test", "manager{$slug}", $branch->id);
            $this->users['bs_'.$branch->id] = $this->ensureUser('branch_staff', $branch->branch_name.' Staff', "staff.{$slug}@hvfrms.test", "staff{$slug}", $branch->id);
        }
    }

    private function ensureUser(string $roleName, string $fullName, string $email, string $username, int $branchId): User
    {
        return User::firstOrCreate(
            ['username' => $username],
            [
                'full_name' => $fullName,
                'email' => $email,
                'password_hash' => Hash::make(self::PASSWORD),
                'role_id' => $this->roleIds[$roleName],
                'branch_id' => $branchId,
                'status' => 'active',
                'failed_attempts' => 0,
            ]
        );
    }

    private function slug(string $name): string
    {
        return trim(strtolower(preg_replace('/[^a-z0-9]+/i', '', $name)), '');
    }

    // ───────────────────────── stock + operations ─────────────────────────

    /** Opening stock at the start of the window for every operating branch. */
    private function seedOpeningStock(): void
    {
        $start = Carbon::parse(self::WINDOW_START);
        $commMgr = $this->users['commissary_manager']->id;

        foreach ($this->operating as $branch) {
            foreach ($this->catalog as $item) {
                // A healthy opening lot per item, received just before the window.
                $qty = $this->openingQtyFor($item);
                $expiry = $this->expiryFor($item, $start);
                $this->makeBatch($branch->id, $item, $qty, $expiry, 'good', $commMgr, $start->copy()->subDays(random_int(2, 6)));
            }
        }
    }

    private function openingQtyFor(Item $item): int
    {
        $base = max((int) $item->reorder_level, 5);

        return random_int($base * 2, $base * 4);
    }

    private function expiryFor(Item $item, Carbon $from): Carbon
    {
        $shelf = (int) ($item->shelf_life_days ?? 0);
        $days = $shelf > 0 ? max(21, $shelf) : random_int(120, 300);

        return $from->copy()->addDays($days);
    }

    /** Weekly order + consumption history across the whole window. */
    private function seedWeeklyHistory(): void
    {
        $weekStart = Carbon::parse(self::WINDOW_START)->startOfWeek();
        $end = Carbon::parse(self::WINDOW_END)->endOfWeek();
        if ($end->gt($this->cutoff)) {
            $end = $this->cutoff->copy();
        }
        $weekIndex = 0;

        for ($monday = $weekStart->copy(); $monday->lte($end); $monday->addWeek()) {
            $weekIndex++;
            $isLastWeeks = $monday->copy()->addWeeks(2)->gte($end);

            foreach ($this->operating as $branch) {
                // 1–2 replenishment orders this week for the branch.
                $orderCount = random_int(1, 2);
                for ($o = 0; $o < $orderCount; $o++) {
                    $orderDay = $monday->copy()->addDays(random_int(0, 3))->setTime(random_int(8, 15), random_int(0, 59));
                    // Never place an order in the future relative to real "now".
                    if ($orderDay->gt($this->cutoff)) {
                        continue;
                    }
                    Carbon::setTestNow($orderDay);

                    $lines = $this->orderLinesFor();
                    $order = $this->createOrder($branch, $weekIndex, $o, $lines, $orderDay);

                    // Most orders are received within the week; a few in the last
                    // weeks stay in-flight (preparing / delivering) to show live queues.
                    $stage = 'received';
                    if ($isLastWeeks && random_int(1, 100) <= 40) {
                        $stage = random_int(0, 1) === 0 ? 'preparing' : 'delivering';
                    } elseif (random_int(1, 100) <= 8) {
                        $stage = 'received_exception';
                    }

                    // Clamp the progression timestamp so no record is dated ahead of now.
                    $advanceDay = $orderDay->copy()->addDays(random_int(1, 3));
                    if ($advanceDay->gt($this->cutoff)) {
                        $advanceDay = $this->cutoff->copy();
                    }
                    Carbon::setTestNow($advanceDay);
                    $this->advanceOrder($order, $stage);
                    $this->ordersMade++;
                }

                // Branch consumption through the week (drives the forecast).
                $consumptionDay = $monday->copy()->addDays(5)->setTime(18, 0);
                if ($consumptionDay->gt($this->cutoff)) {
                    $consumptionDay = $this->cutoff->copy();
                }
                Carbon::setTestNow($consumptionDay);
                $this->recordConsumption($branch, $monday);
            }
        }
        Carbon::setTestNow();
    }

    /** @return array<int,array{0:Item,1:int}> */
    private function orderLinesFor(): array
    {
        $pick = collect($this->catalog)->shuffle()->take(random_int(2, 4));
        $lines = [];
        foreach ($pick as $item) {
            $base = max((int) $item->reorder_level, 4);
            $lines[] = [$item, random_int($base, $base * 3)];
        }

        return $lines;
    }

    /** @param  array<int,array{0:Item,1:int}>  $lines */
    private function createOrder(Branch $branch, int $week, int $seq, array $lines, Carbon $when): Order
    {
        $bm = $this->users['bm_'.$branch->id] ?? $this->users['owner'];
        $key = 'PRESENT#'.$branch->id.'#'.$week.'#'.$seq;

        return DB::transaction(function () use ($branch, $lines, $key, $bm, $when) {
            $total = 0.0;
            foreach ($lines as [$item, $qty]) {
                $total += (float) $item->price * $qty;
            }

            $order = Order::create([
                'order_number' => $this->nextOrderNumber($when),
                'branch_id' => $branch->id,
                'status' => 'pending',
                'ordered_by' => $bm?->id,
                'remarks' => $key,
                'total_amount' => $total,
                'created_at' => $when,
                'updated_at' => $when,
            ]);

            foreach ($lines as [$item, $qty]) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'item_id' => $item->id,
                    'quantity' => $qty,
                    'unit_price' => $item->price,
                ]);
            }

            $this->attachSyntheticPayment($order);

            AuditLog::create([
                'user_id' => $bm?->id,
                'action' => 'order created',
                'table_name' => 'orders',
                'record_id' => $order->id,
                'details' => 'Presentation dataset — branch request '.$order->order_number,
            ]);

            return $order;
        });
    }

    /** Advance through the REAL workflow services (idempotent per step). */
    private function advanceOrder(Order $order, string $stage): void
    {
        if ($stage === 'pending') {
            return;
        }

        $commMgr = $this->users['commissary_manager'];
        $commStaff = $this->users['commissary_staff'];
        $bm = $this->users['bm_'.$order->branch_id] ?? $this->users['owner'];
        $workflow = new OrderWorkflowService;

        $order->refresh();
        if (in_array($order->status, ['cancelled', 'declined'], true)) {
            return;
        }

        if ($order->status === 'pending') {
            $this->expectOk($this->labels->review($order->fresh(), $commMgr), $order, 'review');
            $order->refresh();
        }

        if (! $this->labels->hasUnits($order->id) && in_array($order->status, ['pending', 'approved', 'preparing'], true)) {
            $expiry = [];
            $fulfillment = [];
            foreach ($order->orderItems as $oi) {
                $item = $oi->item ?? Item::find($oi->item_id);
                $shelf = (int) ($item->shelf_life_days ?? 0);
                $qty = (int) $oi->quantity;
                // Occasional short production run for realism.
                if (random_int(1, 100) <= 10) {
                    $qty = max(1, $qty - random_int(1, max(1, (int) floor($qty * 0.2))));
                }
                $reason = $qty < (int) $oi->quantity ? 'Short production run' : '';
                $expiry[$oi->id] = $shelf > 0 ? Carbon::today()->addDays(max(14, $shelf))->toDateString() : '';
                $fulfillment[$oi->id] = ['quantity' => $qty, 'reason' => $reason, 'source' => 'produced'];
            }
            $this->expectOk($this->labels->prepare($order->fresh(), $expiry, $commMgr, $fulfillment), $order, 'prepare');
            $order->refresh();
        }

        if ($stage === 'preparing') {
            return;
        }

        if (in_array($order->status, ['approved', 'preparing', 'delivering'], true) && ! $workflow->isFullyDispatched($order)) {
            foreach (OrderProduction::where('order_id', $order->id)->get() as $prod) {
                if ((int) $prod->produced_qty > 0 && $prod->undispatched() > 0) {
                    $this->expectOk($this->groups->dispatch($prod->qr_code, $commStaff), $order, 'dispatch');
                }
            }
            $order->refresh();
        }

        if ($stage === 'delivering') {
            return;
        }

        if ($order->status === 'delivering') {
            $quantities = [];
            $reasons = [];
            if ($stage === 'received_exception') {
                $first = $order->orderItems->first();
                $prod = OrderProduction::where('order_item_id', $first->id)->first();
                $shipped = $prod ? (int) $prod->dispatched_qty : (int) $first->quantity;
                $quantities[$first->id] = max(0, $shipped - 1);
                $reasons[$first->id] = 'Missing Quantity';
            }
            $this->expectOk($this->groups->receiveOrder($order->fresh(), $bm, $quantities, $reasons), $order, 'receive');
        }
    }

    /** Branch consumption: draw down on-hand stock as weekly usage/spoilage. */
    private function recordConsumption(Branch $branch, Carbon $weekMonday): void
    {
        $staff = $this->users['bs_'.$branch->id] ?? $this->users['owner'];

        $batches = StockBatch::where('branch_id', $branch->id)
            ->where('quantity_left', '>', 0)
            ->where('condition', 'good')
            ->orderBy('expiry_date')
            ->get();

        foreach ($batches as $batch) {
            if (random_int(1, 100) > 70) {
                continue; // not every batch moves every week
            }
            $take = random_int(1, max(1, (int) ceil($batch->quantity_left * 0.35)));
            $take = min($take, (int) $batch->quantity_left);
            if ($take <= 0) {
                continue;
            }

            $spoilage = random_int(1, 100) <= 12;
            $when = $weekMonday->copy()->addDays(random_int(1, 5))->setTime(random_int(9, 19), random_int(0, 59));

            StockTransaction::create([
                'item_id' => $batch->item_id,
                'branch_id' => $branch->id,
                'batch_id' => $batch->id,
                'type' => 'out',
                'quantity' => -$take,
                'reason' => $spoilage ? 'Spoilage' : 'Usage',
                'remarks' => 'Presentation dataset',
                'performed_by' => $staff->id,
                'created_at' => $when,
            ]);

            $batch->decrement('quantity_left', $take);
        }
    }

    private function makeBatch(int $branchId, Item $item, int $qty, Carbon $expiry, string $condition, int $userId, Carbon $receivedAt): void
    {
        $qr = 'HV-P-'.$branchId.'-'.$item->id.'-'.(++$this->qrSeq);

        DB::transaction(function () use ($branchId, $item, $qty, $expiry, $condition, $userId, $receivedAt, $qr): void {
            $batch = StockBatch::create([
                'item_id' => $item->id,
                'branch_id' => $branchId,
                'quantity_in' => $qty,
                'quantity_left' => $qty,
                'expiry_date' => $expiry->toDateString(),
                'condition' => $condition,
                'received_at' => $receivedAt,
                'created_by' => $userId,
                'qr_code' => $qr,
            ]);

            StockTransaction::create([
                'item_id' => $item->id,
                'branch_id' => $branchId,
                'batch_id' => $batch->id,
                'type' => 'in',
                'quantity' => $qty,
                'reason' => 'Opening stock',
                'remarks' => 'Presentation dataset',
                'performed_by' => $userId,
                'created_at' => $receivedAt,
            ]);
        });
    }

    private function attachSyntheticPayment(Order $order): void
    {
        if ($order->payment_reference) {
            return;
        }
        $reference = 'GC'.preg_replace('/[^A-Z0-9]/', '', strtoupper($order->order_number));
        $path = 'order-payments/present-'.$order->order_number.'.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        Storage::disk('local')->put($path, $png);

        $order->update([
            'payment_reference' => $reference,
            'payment_screenshot_path' => $path,
            'payment_submitted_at' => $order->created_at ?? now(),
            'proforma_snapshot' => $this->payments->snapshot($order),
        ]);
    }

    private function expectOk(array $result, Order $order, string $step): void
    {
        if (! ($result['ok'] ?? false)) {
            throw new \RuntimeException("Presentation: {$step} failed for order {$order->order_number}: ".($result['error'] ?? json_encode($result)));
        }
    }

    private function nextOrderNumber(Carbon $when): string
    {
        $year = $when->format('Y');
        if ($this->orderSeq === 0) {
            $this->orderSeq = Order::count();
        }
        $this->orderSeq++;

        return sprintf('ORD-%s-%05d', $year, $this->orderSeq);
    }

    private function report(): void
    {
        $this->command?->getOutput()->writeln('<info>── Presentation dataset summary ──</info>');
        $this->command?->getOutput()->writeln('<info>Database   : '.DB::connection()->getDatabaseName().'</info>');
        $this->command?->getOutput()->writeln('<info>Window     : '.self::WINDOW_START.' → '.self::WINDOW_END.'</info>');
        $this->command?->getOutput()->writeln('<info>Branches   : '.count($this->operating).' operating + 1 commissary</info>');
        $this->command?->getOutput()->writeln('<info>Catalog    : '.count($this->catalog).' real products</info>');
        $this->command?->getOutput()->writeln('<info>Users      : '.User::count().' (password: '.self::PASSWORD.')</info>');
        $this->command?->getOutput()->writeln('<info>Orders     : '.Order::count().' | Productions: '.OrderProduction::count().'</info>');
        $this->command?->getOutput()->writeln('<info>Batches    : '.StockBatch::count().' | Stock tx: '.StockTransaction::count().'</info>');
        $this->command?->getOutput()->writeln('<comment>Simulated operational data — not evidence of verified sales or business performance.</comment>');
    }
}
