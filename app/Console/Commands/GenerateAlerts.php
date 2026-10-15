<?php

namespace App\Console\Commands;

use App\Mail\AlertDigest;
use App\Models\Alert;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class GenerateAlerts extends Command
{
    protected $signature = 'alerts:generate';

    protected $description = 'Generate automated alerts for low stock, expiry, unusual sales, and operational issues';

    public function handle(): int
    {
        $expiryWarningDays = max(1, (int) Setting::get('expiry_warning_days', '7'));
        $unusualSalesSigma = max(1.0, (float) Setting::get('unusual_sales_sigma', '2'));
        $idleBranchDays = max(1, (int) Setting::get('idle_branch_days', '14'));
        $suppressWindow = now()->subHours(24);

        $newAlerts = [];

        // 1. LOW STOCK
        $lowStock = DB::select('
            SELECT sub.* FROM (
                SELECT i.id AS item_id, i.item_name, i.unit, i.reorder_level,
                       b.id AS branch_id, b.branch_name,
                       COALESCE(SUM(sb.quantity_left), 0) AS total_qty
                FROM items i
                CROSS JOIN branches b
                LEFT JOIN stock_batches sb ON sb.item_id = i.id AND sb.branch_id = b.id AND sb.quantity_left > 0
                WHERE i.is_active = 1 AND b.is_active = 1
                GROUP BY i.id, b.id, i.item_name, i.unit, i.reorder_level, b.branch_name
            ) sub
            WHERE sub.total_qty > 0 AND sub.total_qty <= sub.reorder_level
        ');

        foreach ($lowStock as $ls) {
            $exists = Alert::where('alert_type', 'low_stock')
                ->where('item_id', $ls->item_id)
                ->where('branch_id', $ls->branch_id)
                ->where(fn ($q) => $q->where('is_resolved', false)
                    ->orWhere('resolved_at', '>=', $suppressWindow))
                ->exists();

            if (! $exists) {
                $sev = $ls->total_qty <= ($ls->reorder_level / 2) ? 'critical' : 'warning';
                $alert = Alert::create([
                    'alert_type' => 'low_stock',
                    'severity' => $sev,
                    'message' => "{$ls->item_name} is low at {$ls->branch_name}: {$ls->total_qty} {$ls->unit}(s) remaining (reorder level: {$ls->reorder_level}).",
                    'item_id' => $ls->item_id,
                    'branch_id' => $ls->branch_id,
                ]);
                $newAlerts[] = $alert;
            }
        }

        // 2. EXPIRING SOON
        $expiring = DB::select('
            SELECT sb.id AS batch_id, sb.item_id, sb.branch_id, sb.quantity_left, sb.expiry_date,
                   i.item_name, i.unit, b.branch_name,
                   DATEDIFF(sb.expiry_date, CURDATE()) AS days_left
            FROM stock_batches sb
            JOIN items i ON i.id = sb.item_id
            JOIN branches b ON b.id = sb.branch_id
            WHERE sb.quantity_left > 0
              AND sb.expiry_date IS NOT NULL
              AND sb.expiry_date > CURDATE()
              AND DATEDIFF(sb.expiry_date, CURDATE()) <= ?
        ', [$expiryWarningDays]);

        foreach ($expiring as $ex) {
            $exists = Alert::where('alert_type', 'expiring_soon')
                ->where('batch_id', $ex->batch_id)
                ->where(fn ($q) => $q->where('is_resolved', false)
                    ->orWhere('resolved_at', '>=', $suppressWindow))
                ->exists();

            if (! $exists) {
                $sev = $ex->days_left <= 2 ? 'critical' : 'warning';
                $alert = Alert::create([
                    'alert_type' => 'expiring_soon',
                    'severity' => $sev,
                    'message' => "{$ex->item_name} (Batch #{$ex->batch_id}) at {$ex->branch_name} expires in {$ex->days_left} day(s) - {$ex->quantity_left} {$ex->unit}(s) remaining.",
                    'item_id' => $ex->item_id,
                    'branch_id' => $ex->branch_id,
                    'batch_id' => $ex->batch_id,
                ]);
                $newAlerts[] = $alert;
            }
        }

        // 3. EXPIRED
        $expired = DB::select('
            SELECT sb.id AS batch_id, sb.item_id, sb.branch_id, sb.quantity_left, sb.expiry_date,
                   i.item_name, i.unit, b.branch_name,
                   ABS(DATEDIFF(sb.expiry_date, CURDATE())) AS days_overdue
            FROM stock_batches sb
            JOIN items i ON i.id = sb.item_id
            JOIN branches b ON b.id = sb.branch_id
            WHERE sb.quantity_left > 0
              AND sb.expiry_date IS NOT NULL
              AND sb.expiry_date < CURDATE()
        ');

        foreach ($expired as $eb) {
            $exists = Alert::where('alert_type', 'expired')
                ->where('batch_id', $eb->batch_id)
                ->where(fn ($q) => $q->where('is_resolved', false)
                    ->orWhere('resolved_at', '>=', $suppressWindow))
                ->exists();

            if (! $exists) {
                $alert = Alert::create([
                    'alert_type' => 'expired',
                    'severity' => 'critical',
                    'message' => "{$eb->item_name} (Batch #{$eb->batch_id}) at {$eb->branch_name} has EXPIRED ({$eb->days_overdue} day(s) ago) - {$eb->quantity_left} {$eb->unit}(s) still in stock.",
                    'item_id' => $eb->item_id,
                    'branch_id' => $eb->branch_id,
                    'batch_id' => $eb->batch_id,
                ]);
                $newAlerts[] = $alert;
            }
        }

        // 4. UNUSUAL SALES
        $weeklyUsage = DB::select("
            SELECT st.item_id, st.branch_id, i.item_name, b.branch_name,
                   YEARWEEK(st.created_at, 1) AS yw,
                   ABS(SUM(st.quantity)) AS weekly_total
            FROM stock_transactions st
            JOIN items i ON i.id = st.item_id
            JOIN branches b ON b.id = st.branch_id
            WHERE st.type IN ('out', 'adjustment', 'damaged', 'expired')
              AND st.created_at >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
            GROUP BY st.item_id, st.branch_id, i.item_name, b.branch_name, YEARWEEK(st.created_at, 1)
        ");

        $grouped = [];
        foreach ($weeklyUsage as $row) {
            $key = $row->item_id.'-'.$row->branch_id;
            $grouped[$key]['item_id'] = $row->item_id;
            $grouped[$key]['branch_id'] = $row->branch_id;
            $grouped[$key]['item_name'] = $row->item_name;
            $grouped[$key]['branch_name'] = $row->branch_name;
            $grouped[$key]['weeks'][] = (float) $row->weekly_total;
        }

        foreach ($grouped as $g) {
            $vals = $g['weeks'];
            if (count($vals) < 3) {
                continue;
            }
            $mean = array_sum($vals) / count($vals);
            $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $vals)) / count($vals);
            $stdDev = sqrt($variance);
            if ($stdDev < 1) {
                continue;
            }
            $latest = end($vals);
            if ($latest > $mean + ($unusualSalesSigma * $stdDev)) {
                $exists = Alert::where('alert_type', 'unusual_sales')
                    ->where('item_id', $g['item_id'])
                    ->where('branch_id', $g['branch_id'])
                    ->where(fn ($q) => $q->where('is_resolved', false)
                        ->orWhere('resolved_at', '>=', $suppressWindow))
                    ->exists();
                if (! $exists) {
                    $alert = Alert::create([
                        'alert_type' => 'unusual_sales',
                        'severity' => 'warning',
                        'message' => "{$g['item_name']} at {$g['branch_name']} had unusually high consumption this week: ".(int) $latest.' units (avg: '.round($mean, 1).').',
                        'item_id' => $g['item_id'],
                        'branch_id' => $g['branch_id'],
                    ]);
                    $newAlerts[] = $alert;
                }
            }
        }

        // 5. OPERATIONAL (idle branches)
        $idleBranches = DB::select("
            SELECT b.id AS branch_id, b.branch_name,
                   DATEDIFF(CURDATE(), COALESCE(MAX(st.created_at), b.created_at)) AS days_idle
            FROM branches b
            LEFT JOIN stock_transactions st ON st.branch_id = b.id
            WHERE b.is_active = 1 AND b.branch_type != 'main'
            GROUP BY b.id, b.branch_name, b.created_at
            HAVING days_idle >= ?
        ", [$idleBranchDays]);

        foreach ($idleBranches as $ib) {
            $exists = Alert::where('alert_type', 'operational')->whereNull('order_id')
                ->where('branch_id', $ib->branch_id)
                ->where(fn ($q) => $q->where('is_resolved', false)
                    ->orWhere('resolved_at', '>=', $suppressWindow))
                ->exists();
            if (! $exists) {
                $alert = Alert::create([
                    'alert_type' => 'operational',
                    'severity' => 'info',
                    'message' => "{$ib->branch_name} has had no stock activity for {$ib->days_idle} day(s).",
                    'branch_id' => $ib->branch_id,
                ]);
                $newAlerts[] = $alert;
            }
        }

        $this->info('Generated '.count($newAlerts).' new alert(s).');

        // Send email digest for critical/warning alerts
        $criticalAlerts = array_filter($newAlerts, fn ($a) => in_array($a->severity, ['critical', 'warning']));
        if (! empty($criticalAlerts)) {
            $recipients = User::whereHas('role', fn ($q) => $q->whereIn('role_name', ['super_admin', 'owner', 'admin', 'commissary_manager']))
                ->where('status', 'active')
                ->whereNotNull('email')
                ->pluck('email')
                ->toArray();

            if (! empty($recipients)) {
                try {
                    Mail::to($recipients)->send(new AlertDigest(array_values($criticalAlerts)));
                    $this->info('Alert digest email sent to '.count($recipients).' recipient(s).');
                } catch (\Exception $e) {
                    $this->warn('Email sending failed: '.$e->getMessage());
                }
            }
        }

        return self::SUCCESS;
    }
}
