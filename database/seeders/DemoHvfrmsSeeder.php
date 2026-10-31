<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\ExpiredBatchArchive;
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
use App\Services\StockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * DEMO-HVFRMS seeder — a realistic, clearly-labelled SIMULATED operational
 * dataset for exercising the implemented system end to end.
 *
 * Upgrade-safe and idempotent at the record level (no blanket sentinel):
 *   - static entities use firstOrCreate;
 *   - scenario stock lots use deterministic demo QR codes;
 *   - demo orders carry a stable DEMO-HVFRMS#<branch>#<scenario> key and are
 *     advanced through the REAL workflow services (review → prepare → dispatch →
 *     receive), whose own idempotency makes re-runs safe and completes missing
 *     steps without duplicating users, orders, labels, batches or movements.
 *
 * Safety: runs only against an isolated demo database (name containing "demo")
 * and never in production. There is no override bypass.
 *
 * It does NOT fabricate verified sales or add a payment-verification module.
 * Payment references/screenshots are synthetic submitted evidence, never
 * verified payment. Commissary Sales stays unavailable.
 */
class DemoHvfrmsSeeder extends Seeder
{
    private const MARKER = 'DEMO-HVFRMS';

    private const DEMO_PASSWORD = 'demo1234';

    /** Operational database that must never be seeded. */
    private const OPERATIONAL_DB = 'hidden_vietnam';

    private StockService $stock;

    private OrderLabelService $labels;

    private OrderGroupLabelService $groups;

    private OrderPaymentService $payments;

    /** @var array<string,int> role_name => id */
    private array $roleIds = [];

    /** @var array<string,Branch> branch_name => Branch */
    private array $branches = [];

    /** @var array<string,Item> code => Item */
    private array $products = [];

    /** @var array<string,User> key => User */
    private array $users = [];

    private int $orderSeq = 0;

    public function run(): void
    {
        $this->guardTarget();

        $this->stock = new StockService;
        $this->labels = new OrderLabelService;
        $this->groups = new OrderGroupLabelService;
        $this->payments = new OrderPaymentService;

        $this->ensureRoles();
        $commissary = $this->ensureBranches();
        $this->ensureSettings($commissary->id);
        $this->ensureCategories();
        $this->ensureProducts();
        $this->ensureCoreUsers($commissary);
        $this->ensureBranchUsers();

        $this->seedPerBranchScenarios();
        $this->seedCommissaryOperationalExamples();
        $this->seedCrossDatasetScenarios();
        $this->seedForecastHistory();
        $this->seedDisposalAndArchives();

        Setting::set('demo_hvfrms_version', '2');

        $this->report();
    }

    /** Hard safety gate: isolated demo database only, never production. No bypass. */
    private function guardTarget(): void
    {
        $dbName = (string) DB::connection()->getDatabaseName();

        if (app()->environment('production')) {
            throw new \RuntimeException('Refusing to run DemoHvfrmsSeeder in production. Run it in a local/testing environment against the demo database.');
        }

        if (strcasecmp($dbName, self::OPERATIONAL_DB) === 0 || ! str_contains(strtolower($dbName), 'demo')) {
            throw new \RuntimeException(
                "Refusing to run DemoHvfrmsSeeder against database '{$dbName}'. ".
                'Target the isolated demo database (name containing "demo"), e.g. '.
                'php artisan db:seed --class=DemoHvfrmsSeeder --database=demo.'
            );
        }
    }

    // ───────────────────────── static entities ─────────────────────────

    private function ensureRoles(): void
    {
        $roles = [
            'super_admin' => 'Researcher/developer — full system access, audit logs, user management',
            'owner' => 'Hidden Vietnam main owner — all branches, users, branches, settings, order approval',
            'branch_manager' => 'Branch manager — dashboard, stock, orders, reports, forecast, alerts for OWN branch only',
            'branch_staff' => 'Branch staff — QR scanning, usage/spoilage recording, branch inventory tasks',
            'commissary_manager' => 'Commissary manager — reviews/prepares requests, sets expiry, prints labels, manages deliveries, monitors branches',
            'commissary_staff' => 'Commissary staff — assists preparation and scans prepared goods out for delivery',
            'admin' => 'Administrator — same application functions and all-branch access as Owner, with a distinct, traceable account',
        ];

        foreach ($roles as $name => $description) {
            $role = Role::firstOrCreate(['role_name' => $name], ['description' => $description, 'created_at' => now()]);
            $this->roleIds[$name] = $role->id;
        }
    }

    /** Ensure canonical branches plus a distinct demo Commissary source. */
    private function ensureBranches(): Branch
    {
        $canonical = [
            ['Tanza', 'main', 'Tanza, Cavite'],
            ['Silang', 'franchise', 'Silang, Cavite'],
            ["Dasma - Governor's Drive", 'franchise', "Governor's Drive, Dasmariñas, Cavite"],
            ['La Salle - Dasma', 'franchise', 'DBB-B, Dasmariñas, Cavite'],
            ['Molino 3', 'franchise', 'Bacoor, Cavite'],
            ['Mendez', 'franchise', 'Mendez, Cavite'],
            ['Lipa', 'franchise', 'Lipa, Batangas'],
            ['Batangas', 'franchise', 'Batangas City'],
            ['Rosario', 'franchise', 'Rosario, Batangas'],
            ['Muntinlupa', 'franchise', 'Katarungan Village, Muntinlupa'],
            ['Trece Martires', 'franchise', 'Trece Martires, Cavite'],
            ['Marikina', 'franchise', 'Marikina Heights, Marikina City'],
            ['Quezon City', 'franchise', 'Quezon City'],
            ['Baguio', 'franchise', 'Baguio City'],
            ['Indang', 'franchise', 'Indang, Cavite'],
        ];

        foreach ($canonical as [$name, $type, $address]) {
            $branch = Branch::firstOrCreate(['branch_name' => $name], ['branch_type' => $type, 'address' => $address, 'is_active' => true]);
            $this->branches[$name] = $branch;
        }

        $commissary = Branch::firstOrCreate(
            ['branch_name' => 'DEMO Commissary'],
            ['branch_type' => 'commissary', 'address' => self::MARKER.' production & dispatch source — Tanza, Cavite', 'is_active' => true]
        );
        $this->branches['DEMO Commissary'] = $commissary;

        return $commissary;
    }

    private function ensureSettings(int $commissaryId): void
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

        Setting::set('commissary_branch_id', (string) $commissaryId);
    }

    private function ensureCategories(): void
    {
        foreach ([['Sauces', 'wet'], ['Sauces (Big Sizes)', 'wet'], ['Frozen Products', 'wet'], ['Bread', 'dry'], ['Juices/Others', 'wet']] as [$name, $goodsType]) {
            Category::firstOrCreate(['name' => $name], ['goods_type' => $goodsType, 'created_at' => now()]);
        }
    }

    private function categoryId(string $name): int
    {
        return (int) Category::where('name', $name)->value('id');
    }

    private function ensureProducts(): void
    {
        // code, name, category, unit, price, reorder_level, shelf_life_days, is_active
        $catalog = [
            ['HOISIN', 'DEMO Hoisin Sauce 500ml', 'Sauces', 'bottle', 120.00, 20, 180, true],
            ['FISHSAUCE', 'DEMO Fish Sauce 750ml', 'Sauces', 'bottle', 95.00, 20, 365, true],
            ['CHILIOIL', 'DEMO Chili Garlic Oil 500ml', 'Sauces', 'bottle', 110.00, 15, 150, true],
            ['HOISIN5L', 'DEMO Hoisin Sauce 5L', 'Sauces (Big Sizes)', 'container', 780.00, 8, 180, true],
            ['SOY5L', 'DEMO Soy Sauce 5L', 'Sauces (Big Sizes)', 'container', 520.00, 8, 365, true],
            ['SPRINGROLL', 'DEMO Frozen Spring Rolls 50pc', 'Frozen Products', 'pack', 260.00, 25, 90, true],
            ['DUMPLING', 'DEMO Frozen Dumplings 40pc', 'Frozen Products', 'pack', 240.00, 25, 90, true],
            ['BEEFSLICE', 'DEMO Frozen Beef Slices 1kg', 'Frozen Products', 'pack', 430.00, 15, 60, true],
            ['BANHMI', 'DEMO Banh Mi Baguette', 'Bread', 'piece', 85.00, 30, 7, true],
            ['RICEPAPER', 'DEMO Rice Paper 400g', 'Bread', 'pack', 70.00, 20, 365, true],
            ['CALAMANSI', 'DEMO Calamansi Juice Concentrate 1L', 'Juices/Others', 'bottle', 130.00, 15, 120, true],
            ['CHILIPASTE', 'DEMO Seasonal Chili Paste 250ml', 'Sauces', 'jar', 90.00, 10, 120, false],
        ];

        foreach ($catalog as [$code, $name, $category, $unit, $price, $reorder, $shelf, $active]) {
            $item = Item::firstOrCreate(
                ['item_name' => $name],
                [
                    'category_id' => $this->categoryId($category),
                    'unit' => $unit,
                    'price' => $price,
                    'reorder_level' => $reorder,
                    'shelf_life_days' => $shelf,
                    'is_active' => $active,
                    'remarks' => self::MARKER.' simulated catalog product',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $this->products[$code] = $item;
        }
    }

    private function ensureCoreUsers(Branch $commissary): void
    {
        $tanza = $this->branches['Tanza'];

        $core = [
            ['owner', 'owner', 'DEMO Owner', 'owner.demo@hvfrms.test', 'demo_owner', $tanza->id],
            ['admin', 'admin', 'DEMO Administrator', 'admin.demo@hvfrms.test', 'demo_admin', $tanza->id],
            ['commissary_manager', 'commissary_manager', 'DEMO Commissary Manager', 'commissary.manager.demo@hvfrms.test', 'demo_comm_mgr', $commissary->id],
            ['commissary_staff', 'commissary_staff', 'DEMO Commissary Staff', 'commissary.staff.demo@hvfrms.test', 'demo_comm_staff', $commissary->id],
        ];

        foreach ($core as [$key, $roleName, $fullName, $email, $username, $branchId]) {
            $this->users[$key] = $this->ensureUser($key, $roleName, $fullName, $email, $username, $branchId);
        }
    }

    /** A Branch Manager and a Branch Staff for every active operating branch. */
    private function ensureBranchUsers(): void
    {
        foreach ($this->operatingBranches() as $branch) {
            $slug = $this->slug($branch->branch_name);
            $this->users['bm_'.$slug] = $this->ensureUser(
                'bm_'.$slug, 'branch_manager', "DEMO {$branch->branch_name} Manager",
                "bm.{$slug}.demo@hvfrms.test", "demo_bm_{$slug}", $branch->id
            );
            $this->users['bs_'.$slug] = $this->ensureUser(
                'bs_'.$slug, 'branch_staff', "DEMO {$branch->branch_name} Staff",
                "bs.{$slug}.demo@hvfrms.test", "demo_bs_{$slug}", $branch->id
            );
        }
    }

    private function ensureUser(string $key, string $roleName, string $fullName, string $email, string $username, int $branchId): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'full_name' => $fullName,
                'username' => $username,
                'password_hash' => Hash::make(self::DEMO_PASSWORD),
                'role_id' => $this->roleIds[$roleName],
                'branch_id' => $branchId,
                'status' => 'active',
                'failed_attempts' => 0,
            ]
        );
    }

    /** @return array<int,Branch> active, non-commissary branches */
    private function operatingBranches(): array
    {
        return array_values(array_filter(
            $this->branches,
            fn (Branch $b) => $b->is_active && $b->branch_type !== 'commissary'
        ));
    }

    private function slug(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));

        return trim($slug, '_');
    }

    // ───────────────────────── per-branch scenarios ─────────────────────────

    private function seedPerBranchScenarios(): void
    {
        $commMgr = $this->users['commissary_manager']->id;
        $today = Carbon::today();

        foreach ($this->operatingBranches() as $branch) {
            $bid = $branch->id;

            // Healthy usable stock — two staples, FISHSAUCE in two expiry lots.
            $this->ensureBatch('HLT1', 'FISHSAUCE', $bid, 80, $today->copy()->addDays(300), 'good', $commMgr, $today->copy()->subDays(10));
            $this->ensureBatch('HLT2', 'FISHSAUCE', $bid, 40, $today->copy()->addDays(260), 'good', $commMgr, $today->copy()->subDays(4));
            $this->ensureBatch('HLT3', 'RICEPAPER', $bid, 60, $today->copy()->addDays(320), 'good', $commMgr, $today->copy()->subDays(8));

            // Below reorder level (HOISIN reorder 20 → only 6 on hand).
            $this->ensureBatch('LOW1', 'HOISIN', $bid, 6, $today->copy()->addDays(150), 'good', $commMgr, $today->copy()->subDays(6));

            // Expiring soon: today and within 7 days.
            $this->ensureBatch('EXPSOON1', 'BANHMI', $bid, 10, $today->copy(), 'good', $commMgr, $today->copy()->subDays(1));
            $this->ensureBatch('EXPSOON2', 'DUMPLING', $bid, 18, $today->copy()->addDays(4), 'good', $commMgr, $today->copy()->subDays(3));

            // Expired batch with remaining quantity.
            $this->ensureBatch('EXPIRED1', 'CHILIOIL', $bid, 10, $today->copy()->subDays(6), 'good', $commMgr, $today->copy()->subDays(16));

            // Damaged stock (blocked from normal in/out).
            $this->ensureBatch('DAMAGED1', 'BEEFSLICE', $bid, 8, $today->copy()->addDays(40), 'damaged', $commMgr, $today->copy()->subDays(2));

            // Manually-unavailable catalog product that still has stock.
            $this->ensureBatch('UNAVAIL1', 'CHILIPASTE', $bid, 15, $today->copy()->addDays(100), 'good', $commMgr, $today->copy()->subDays(5));

            // Genuine zero stock: SOY5L is never stocked, ordered, or delivered.

            // At least one pending supply request (awaiting review).
            $this->getOrCreateOrder($branch, 'pending', [['CALAMANSI', 6], ['HOISIN', 5]]);

            // At least one completed receiving scenario (settled in full).
            $order = $this->getOrCreateOrder($branch, 'received', [['SPRINGROLL', 8]]);
            $this->advanceOrder($order, 'received');
        }
    }

    /**
     * Create (or reuse) a scenario stock lot with a deterministic demo QR, with a
     * matching stock-in transaction for traceability. Idempotent by qr_code.
     */
    private function ensureBatch(string $scenario, string $code, int $branchId, int $qty, Carbon $expiry, string $condition, int $userId, Carbon $receivedAt): void
    {
        $item = $this->products[$code];
        $qr = 'HV-DEMO-S'.$branchId.'-'.$code.'-'.$scenario;

        if (StockBatch::where('qr_code', $qr)->exists()) {
            return;
        }

        DB::transaction(function () use ($item, $branchId, $qty, $expiry, $condition, $userId, $receivedAt, $qr) {
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
                'reason' => self::MARKER.' opening stock ('.$condition.')',
                'remarks' => self::MARKER,
                'performed_by' => $userId,
                'created_at' => $receivedAt,
            ]);
        });
    }

    // ───────────────────────── order lifecycle ─────────────────────────

    /**
     * Find or create a demo order for a branch/scenario, stamped with a stable
     * DEMO-HVFRMS#<branch>#<scenario> key so re-runs never duplicate it.
     *
     * @param  array<int,array{0:string,1:int}>  $lines
     */
    private function getOrCreateOrder(Branch $branch, string $scenario, array $lines): Order
    {
        $key = self::MARKER.'#'.$branch->id.'#'.$scenario;
        $existing = Order::where('remarks', $key)->first();
        if ($existing) {
            return $existing;
        }

        $bm = $this->branchManagerFor($branch->id);
        $daysAgo = random_int(4, 20);

        return DB::transaction(function () use ($branch, $scenario, $lines, $key, $bm, $daysAgo) {
            $total = 0.0;
            foreach ($lines as [$code, $qty]) {
                $total += (float) $this->products[$code]->price * $qty;
            }

            $order = Order::create([
                'order_number' => $this->nextOrderNumber(),
                'branch_id' => $branch->id,
                'status' => 'pending',
                'ordered_by' => $bm?->id,
                'remarks' => $key,
                'total_amount' => $total,
                'created_at' => now()->subDays($daysAgo),
                'updated_at' => now()->subDays($daysAgo),
            ]);

            foreach ($lines as [$code, $qty]) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'item_id' => $this->products[$code]->id,
                    'quantity' => $qty,
                    'unit_price' => $this->products[$code]->price,
                ]);
            }

            $this->attachSyntheticPayment($order, $scenario);

            AuditLog::create([
                'user_id' => $bm?->id,
                'action' => 'order created',
                'table_name' => 'orders',
                'record_id' => $order->id,
                'details' => self::MARKER.' simulated request '.$order->order_number.' ('.$scenario.')',
            ]);

            return $order;
        });
    }

    /**
     * Advance an order to a target stage through the REAL workflow services.
     * Each step is idempotent, so calling it again completes missing steps.
     *
     * Stages: pending | preparing | delivering | received | received_exception | partial
     */
    private function advanceOrder(Order $order, string $stage, ?array $producedOverride = null, array $receiveQuantities = [], ?string $receiveReason = null): void
    {
        if ($stage === 'pending') {
            return;
        }

        $commMgr = $this->users['commissary_manager'];
        $commStaff = $this->users['commissary_staff'];
        $bm = $this->branchManagerFor($order->branch_id) ?? $this->users['owner'];
        $workflow = new OrderWorkflowService;

        $order->refresh();
        if (in_array($order->status, ['cancelled', 'declined'], true)) {
            return;
        }

        // Review (pending → approved). Only while still pending.
        if ($order->status === 'pending') {
            $this->expectOk($this->labels->review($order->fresh(), $commMgr), $order, 'review');
            $order->refresh();
        }

        // Prepare ONCE: real production output, source batches, reservations,
        // labels. Skipped if already prepared or past preparation.
        if (! $this->labels->hasUnits($order->id) && in_array($order->status, ['pending', 'approved', 'preparing'], true)) {
            $expiry = [];
            $fulfillment = [];
            foreach ($order->orderItems as $oi) {
                $code = $this->codeForItem($oi->item_id);
                $shelf = (int) ($this->products[$code]->shelf_life_days ?? 0);
                $qty = $producedOverride[$code] ?? (int) $oi->quantity;
                $reason = $qty < (int) $oi->quantity ? self::MARKER.' short production run' : '';
                $expiry[$oi->id] = $shelf > 0 ? Carbon::today()->addDays(max(14, $shelf))->toDateString() : '';
                $fulfillment[$oi->id] = ['quantity' => $qty, 'reason' => $reason, 'source' => 'produced'];
            }
            $this->expectOk($this->labels->prepare($order->fresh(), $expiry, $commMgr, $fulfillment), $order, 'prepare');
            $order->refresh();
        }

        if ($stage === 'preparing') {
            return; // prepared / goods awaiting dispatch
        }

        // Dispatch every prepared production group, unless already in transit.
        if (in_array($order->status, ['approved', 'preparing', 'delivering'], true) && ! $workflow->isFullyDispatched($order)) {
            foreach (OrderProduction::where('order_id', $order->id)->get() as $prod) {
                if ((int) $prod->produced_qty > 0 && $prod->undispatched() > 0) {
                    $this->expectOk($this->groups->dispatch($prod->qr_code, $commStaff), $order, 'dispatch');
                }
            }
            $order->refresh();
        }

        if ($stage === 'delivering') {
            return; // released / in transit — awaiting destination receipt
        }

        // Receive at destination only while in transit. Empty quantities = full.
        if ($order->status === 'delivering') {
            $quantities = [];
            $reasons = [];
            if ($stage === 'received_exception') {
                $first = $order->orderItems->first();
                $prod = OrderProduction::where('order_item_id', $first->id)->first();
                $shipped = $prod ? (int) $prod->dispatched_qty : (int) $first->quantity;
                $quantities[$first->id] = max(0, $shipped - 1);
                $reasons[$first->id] = $receiveReason ?? 'Missing Quantity';
            }
            $this->expectOk($this->groups->receiveOrder($order->fresh(), $bm, $quantities, $reasons), $order, 'receive');
        }
    }

    private function expectOk(array $result, Order $order, string $step): void
    {
        if (! ($result['ok'] ?? false)) {
            throw new \RuntimeException("DEMO-HVFRMS: {$step} failed for order {$order->order_number}: ".($result['error'] ?? json_encode($result)));
        }
    }

    /** Store a clearly-synthetic GCash proof image through protected local storage. */
    private function attachSyntheticPayment(Order $order, string $scenario): void
    {
        if ($order->payment_reference) {
            return;
        }

        // Deterministic, unique synthetic reference derived from the order number.
        $reference = 'DEMO'.preg_replace('/[^A-Z0-9]/', '', strtoupper($order->order_number));
        $path = 'order-payments/'.self::MARKER.'-'.$order->order_number.'.png';

        // 1x1 PNG placeholder — clearly synthetic proof, not a real receipt.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        Storage::disk('local')->put($path, $png);

        $order->update([
            'payment_reference' => $reference,
            'payment_screenshot_path' => $path,
            'payment_submitted_at' => $order->created_at ?? now(),
            'proforma_snapshot' => $this->payments->snapshot($order),
        ]);
    }

    private function codeForItem(int $itemId): string
    {
        foreach ($this->products as $code => $item) {
            if ($item->id === $itemId) {
                return $code;
            }
        }

        return '';
    }

    private function branchManagerFor(int $branchId): ?User
    {
        foreach ($this->users as $user) {
            if ($user->branch_id === $branchId && $user->role_id === $this->roleIds['branch_manager']) {
                return $user;
            }
        }

        return $this->users['owner'] ?? null;
    }

    private function nextOrderNumber(): string
    {
        $year = date('Y');
        if ($this->orderSeq === 0) {
            $this->orderSeq = Order::where('order_number', 'like', "ORD-{$year}-%")->count();
        }
        $this->orderSeq++;

        return sprintf('ORD-%s-%04d', $year, $this->orderSeq);
    }

    // ───────────────────────── commissary operational examples ─────────────────────────

    private function seedCommissaryOperationalExamples(): void
    {
        // Awaiting review is covered by every branch's pending request.
        // Preparing / goods awaiting dispatch (reserved source batches at the Commissary):
        $prep = $this->getOrCreateOrder($this->branches["Dasma - Governor's Drive"], 'comm_preparing', [['FISHSAUCE', 12], ['SPRINGROLL', 8]]);
        $this->advanceOrder($prep, 'preparing');

        // Released / in transit — awaiting destination receipt:
        $transit = $this->getOrCreateOrder($this->branches['La Salle - Dasma'], 'comm_delivering', [['RICEPAPER', 20], ['HOISIN', 8]]);
        $this->advanceOrder($transit, 'delivering');

        // Order-linked prepared goods approaching expiry (near-expiry source lot, left reserved).
        $nearExpiry = $this->getOrCreateOrder($this->branches['Molino 3'], 'comm_prep_near_expiry', [['DUMPLING', 6]]);
        $this->advanceNearExpiryPrepared($nearExpiry);
    }

    /** Prepare an order whose produced source lot expires in 3 days, then stop. */
    private function advanceNearExpiryPrepared(Order $order): void
    {
        $order->refresh();
        if (OrderProduction::where('order_id', $order->id)->whereNotNull('stock_batch_id')->exists()) {
            return;
        }
        $commMgr = $this->users['commissary_manager'];
        if ($order->status === 'pending') {
            $this->expectOk($this->labels->review($order->fresh(), $commMgr), $order, 'review');
        }
        $expiry = [];
        $fulfillment = [];
        foreach ($order->orderItems as $oi) {
            $expiry[$oi->id] = Carbon::today()->addDays(3)->toDateString();
            $fulfillment[$oi->id] = ['quantity' => (int) $oi->quantity, 'reason' => '', 'source' => 'produced'];
        }
        $this->expectOk($this->labels->prepare($order->fresh(), $expiry, $commMgr, $fulfillment), $order, 'prepare(near-expiry)');
    }

    // ───────────────────────── cross-dataset scenarios ─────────────────────────

    private function seedCrossDatasetScenarios(): void
    {
        // Partial fulfilment with a shortage reason (produced below requested), received in full.
        $partial = $this->getOrCreateOrder($this->branches['Silang'], 'partial_shortage', [['CALAMANSI', 12]]);
        $this->advanceOrder($partial, 'received', ['CALAMANSI' => 7]);

        // Receiving discrepancy (released in full, one unit short at receipt).
        $discrepancy = $this->getOrCreateOrder($this->branches['Molino 3'], 'receiving_discrepancy', [['SPRINGROLL', 10]]);
        $this->advanceOrder($discrepancy, 'received_exception', null, [], 'Missing Quantity');

        // Cancelled and declined history.
        $this->getOrCreateOrder($this->branches["Dasma - Governor's Drive"], 'cancelled', [['HOISIN', 5]]);
        $this->setTerminal($this->branches["Dasma - Governor's Drive"], 'cancelled');

        $this->getOrCreateOrder($this->branches['Silang'], 'declined', [['BEEFSLICE', 4]]);
        $this->setTerminal($this->branches['Silang'], 'declined');

        // Archived order history (a fully received order moved to the archive).
        $archived = $this->getOrCreateOrder($this->branches['La Salle - Dasma'], 'archived', [['RICEPAPER', 10]]);
        $this->advanceOrder($archived, 'received');
        $archived->refresh();
        if ($archived->archived_at === null && in_array($archived->status, ['settled', 'delivered'], true)) {
            $archived->update(['archived_at' => now(), 'archived_by' => $this->users['commissary_manager']->id]);
        }
    }

    private function setTerminal(Branch $branch, string $stage): void
    {
        $order = Order::where('remarks', self::MARKER.'#'.$branch->id.'#'.$stage)->first();
        if (! $order || in_array($order->status, ['cancelled', 'declined'], true)) {
            return;
        }
        $commMgr = $this->users['commissary_manager']->id;
        if ($stage === 'cancelled') {
            $order->update(['status' => 'cancelled', 'cancelled_by' => $commMgr, 'cancelled_at' => now()->subDays(1), 'cancel_reason' => self::MARKER.' requester cancelled before approval']);
        } else {
            $order->update(['status' => 'declined', 'declined_by' => $commMgr, 'declined_at' => now()->subDays(1), 'decline_reason' => self::MARKER.' declined — item temporarily unavailable']);
        }
    }

    // ───────────────────────── forecast history ─────────────────────────

    private function seedForecastHistory(): void
    {
        $commMgr = $this->users['commissary_manager']->id;

        $sufficient = [
            ['FISHSAUCE', 'Silang', [8, 10, 9, 12, 11, 13, 10, 14], 30],
            ['RICEPAPER', 'Silang', [6, 7, 5, 8, 9, 7, 10, 8], 25],
            ['FISHSAUCE', "Dasma - Governor's Drive", [9, 8, 11, 10, 12, 9, 13, 11], 20],
            ['RICEPAPER', 'Molino 3', [7, 6, 8, 7, 9, 8, 10, 9], 22],
        ];

        foreach ($sufficient as [$code, $branchName, $weekly, $remaining]) {
            $this->seedHistoryLot($code, $this->branches[$branchName]->id, $weekly, $remaining, $commMgr);
        }

        // Insufficient history (a single completed week) for an explicit example.
        $this->seedHistoryLot('CHILIOIL', $this->branches['Molino 3']->id, [7], 10, $commMgr);
    }

    /** @param array<int,int> $weekly oldest → newest weekly issued quantities */
    private function seedHistoryLot(string $code, int $branchId, array $weekly, int $remaining, int $userId): void
    {
        $item = $this->products[$code];
        $qr = 'HV-DEMO-HIST-'.$branchId.'-'.$code;
        if (StockBatch::where('qr_code', $qr)->exists()) {
            return;
        }

        $weeks = count($weekly);
        $totalIn = array_sum($weekly) + $remaining;
        $receivedAt = Carbon::today()->startOfWeek()->subWeeks($weeks + 1)->addDay();

        DB::transaction(function () use ($item, $branchId, $weekly, $userId, $qr, $weeks, $totalIn, $receivedAt) {
            $batch = StockBatch::create([
                'item_id' => $item->id,
                'branch_id' => $branchId,
                'quantity_in' => $totalIn,
                'quantity_left' => $totalIn,
                'expiry_date' => Carbon::today()->addDays(240)->toDateString(),
                'condition' => 'good',
                'received_at' => $receivedAt,
                'created_by' => $userId,
                'qr_code' => $qr,
            ]);

            StockTransaction::create([
                'item_id' => $item->id, 'branch_id' => $branchId, 'batch_id' => $batch->id, 'type' => 'in', 'quantity' => $totalIn,
                'reason' => self::MARKER.' history opening lot', 'remarks' => self::MARKER, 'performed_by' => $userId, 'created_at' => $receivedAt,
            ]);

            foreach ($weekly as $i => $qty) {
                $when = Carbon::today()->startOfWeek()->subWeeks($weeks - $i)->addDays(2);
                StockTransaction::create([
                    'item_id' => $item->id, 'branch_id' => $branchId, 'batch_id' => $batch->id, 'type' => 'out', 'quantity' => -$qty,
                    'reason' => self::MARKER.' weekly usage', 'remarks' => self::MARKER.' simulated movement history', 'performed_by' => $userId, 'created_at' => $when,
                ]);
                $batch->decrement('quantity_left', $qty);
            }
        });
    }

    // ───────────────────────── disposal & archives ─────────────────────────

    private function seedDisposalAndArchives(): void
    {
        $commMgr = $this->users['commissary_manager']->id;
        $today = Carbon::today();

        // Disposal with matching stock movements: dispose part of the expired
        // CHILIOIL lot at representative branches (idempotent — skip if already short).
        foreach (['Silang', "Dasma - Governor's Drive", 'La Salle - Dasma', 'Molino 3'] as $branchName) {
            $bid = $this->branches[$branchName]->id;
            $qr = 'HV-DEMO-S'.$bid.'-CHILIOIL-EXPIRED1';
            $batch = StockBatch::where('qr_code', $qr)->first();
            if ($batch && (int) $batch->quantity_left === 10) {
                $this->expectDisposal($this->stock->adjustmentBatch($batch->id, $bid, 4, $commMgr, 'Expired goods disposal', self::MARKER.' disposal'));
            }
        }

        // Archive scenarios at Silang: one recent (visible), one past the 30-day
        // retention window (excluded from the visible list).
        $silang = $this->branches['Silang']->id;
        $this->ensureArchivedLot('ARCH_RECENT', $silang, $today->copy()->subDays(10), now()->subDays(5), $commMgr);
        $this->ensureArchivedLot('ARCH_OLD', $silang, $today->copy()->subDays(50), now()->subDays(40), $commMgr);
    }

    private function expectDisposal(array $result): void
    {
        if (! ($result['ok'] ?? false)) {
            throw new \RuntimeException('DEMO-HVFRMS: disposal failed: '.($result['error'] ?? json_encode($result)));
        }
    }

    private function ensureArchivedLot(string $scenario, int $branchId, Carbon $expiry, Carbon $archivedAt, int $userId): void
    {
        $qr = 'HV-DEMO-S'.$branchId.'-HOISIN-'.$scenario;
        $batch = StockBatch::where('qr_code', $qr)->first();
        if (! $batch) {
            $this->ensureBatch($scenario, 'HOISIN', $branchId, 5, $expiry, 'good', $userId, $expiry->copy()->subDays(2));
            $batch = StockBatch::where('qr_code', $qr)->first();
        }
        if ($batch) {
            ExpiredBatchArchive::firstOrCreate(
                ['batch_id' => $batch->id],
                ['branch_id' => $branchId, 'archived_by' => $userId, 'archived_at' => $archivedAt]
            );
        }
    }

    // ───────────────────────── reporting ─────────────────────────

    private function report(): void
    {
        $c = $this->command;
        if (! $c) {
            return;
        }

        $c->info('── DEMO-HVFRMS seeding summary (SIMULATED TEST DATA) ──');
        $c->info('Database : '.DB::connection()->getDatabaseName());
        $c->info('Branches : '.Branch::count().' (incl. DEMO Commissary production source)');
        $c->info('Demo users : '.User::where('email', 'like', '%.demo@hvfrms.test')->count().' (password: '.self::DEMO_PASSWORD.')');
        $c->info('Products : '.Item::where('remarks', 'like', self::MARKER.'%')->count().' demo catalog products');
        $c->info('Batches  : '.StockBatch::count().' | Stock tx: '.StockTransaction::count());
        $c->info('Demo orders : '.Order::where('remarks', 'like', self::MARKER.'#%')->count().' | Productions: '.OrderProduction::count());
        $c->info('Archives : '.ExpiredBatchArchive::count());
        $c->warn('Simulated test data — not evidence of business performance, verified sales, or evaluation results.');
    }
}
