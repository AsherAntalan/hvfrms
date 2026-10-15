<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ExpiredBatchArchive;
use App\Models\StockBatch;
use App\Services\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Show the reports page.
     * Four report tabs: Stock Summary, Stock Movements, Order Summary, Expiry Report.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        [$dateFrom, $dateTo] = $this->dateRange($request);
        $branchFilter = $this->resolveBranchFilter($request, $user);

        $branches = $this->authorizedBranches($user);
        $mainBranch = $this->mainWarehouse();

        $data = $this->buildReportData($branchFilter, $dateFrom, $dateTo);

        return view('reports.index', array_merge($data, [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'branchFilter' => $branchFilter,
            'branches' => $branches,
            'mainBranch' => $mainBranch,
            'canArchive' => $user->canManageInventory(),
            'allowAllBranches' => ! $user->isBranchScoped(),
        ]));
    }

    // ───────────────────────── helpers ─────────────────────────

    protected function dateRange(Request $request): array
    {
        return [
            $request->input('from', date('Y-m-d', strtotime('-30 days'))),
            $request->input('to', date('Y-m-d')),
        ];
    }

    /** Resolve the requested branch, honouring branch scoping. */
    protected function resolveBranchFilter(Request $request, $user): int
    {
        if ($user->isBranchScoped()) {
            return (int) $user->branch_id;
        }

        return (int) $request->input('branch', 0);
    }

    protected function mainWarehouse(): ?Branch
    {
        return Branch::where('branch_type', 'main')->where('is_active', true)->orderBy('id')->first();
    }

    /** Branches this user may view (own branch only when scoped). */
    protected function authorizedBranches($user)
    {
        $q = Branch::where('is_active', true)->orderBy('branch_name');
        if ($user->isBranchScoped()) {
            $q->where('id', $user->branch_id);
        }

        return $q->get(['id', 'branch_name', 'branch_type']);
    }

    /**
     * Build every report section for a branch scope + date range. Single source
     * of truth reused by the page, the branch modal, and (subsets of) exports.
     */
    protected function buildReportData(int $branchFilter, string $dateFrom, string $dateTo): array
    {
        // ── REPORT 1: CURRENT STOCK SUMMARY (snapshot) ──
        $stockSql = '
            SELECT i.id, i.item_name, i.unit, i.reorder_level,
                   c.name AS category_name, c.goods_type,
                   b.branch_name, b.id AS branch_id,
                   COALESCE(SUM(sb.quantity_left), 0) AS total_qty,
                   COUNT(CASE WHEN sb.quantity_left > 0 THEN 1 END) AS active_batches,
                   MIN(CASE WHEN sb.quantity_left > 0 THEN sb.expiry_date END) AS nearest_expiry
            FROM items i
            LEFT JOIN categories c ON c.id = i.category_id
            CROSS JOIN branches b
            LEFT JOIN stock_batches sb ON sb.item_id = i.id AND sb.branch_id = b.id AND sb.quantity_left > 0
            WHERE i.is_active = 1 AND b.is_active = 1
        ';
        $stockParams = [];
        if ($branchFilter > 0) {
            $stockSql .= ' AND b.id = ?';
            $stockParams[] = $branchFilter;
        }
        $stockSql .= ' GROUP BY i.id, b.id, i.item_name, i.unit, i.reorder_level, c.name, c.goods_type, b.branch_name
                        HAVING total_qty > 0
                        ORDER BY c.name, i.item_name, b.branch_name';
        $stockRows = DB::select($stockSql, $stockParams);

        // ── REPORT 2: STOCK MOVEMENT SUMMARY (date-ranged) ──
        $moveSql = "
            SELECT i.item_name, i.unit, c.name AS category_name, b.branch_name,
                   SUM(CASE WHEN st.type = 'in' THEN st.quantity ELSE 0 END) AS total_in,
                   SUM(CASE WHEN st.type = 'out' THEN ABS(st.quantity) ELSE 0 END) AS total_out,
                   SUM(CASE WHEN st.type IN ('adjustment','expired','damaged') THEN ABS(st.quantity) ELSE 0 END) AS total_adj
            FROM stock_transactions st
            JOIN items i ON i.id = st.item_id
            LEFT JOIN categories c ON c.id = i.category_id
            JOIN branches b ON b.id = st.branch_id
            WHERE DATE(st.created_at) BETWEEN ? AND ?
        ";
        $moveParams = [$dateFrom, $dateTo];
        if ($branchFilter > 0) {
            $moveSql .= ' AND st.branch_id = ?';
            $moveParams[] = $branchFilter;
        }
        $moveSql .= ' GROUP BY i.id, b.id, i.item_name, i.unit, c.name, b.branch_name
                       ORDER BY c.name, i.item_name, b.branch_name';
        $moveRows = DB::select($moveSql, $moveParams);

        // ── REPORT 3: ORDER SUMMARY (date-ranged) ──
        $orderSql = "
            SELECT b.branch_name,
                   COUNT(*) AS total_orders,
                   SUM(CASE WHEN o.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                   SUM(CASE WHEN o.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                   SUM(CASE WHEN o.status = 'preparing' THEN 1 ELSE 0 END) AS preparing_count,
                   SUM(CASE WHEN o.status = 'delivering' THEN 1 ELSE 0 END) AS delivering_count,
                   SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) AS delivered_count,
                   SUM(CASE WHEN o.status = 'settled' THEN 1 ELSE 0 END) AS settled_count,
                   SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
                   SUM(CASE WHEN o.status = 'declined' THEN 1 ELSE 0 END) AS declined_count,
                   SUM(CASE WHEN o.status NOT IN ('cancelled','declined') THEN COALESCE(o.fulfilled_amount, o.total_amount) ELSE 0 END) AS total_value
            FROM orders o
            JOIN branches b ON b.id = o.branch_id
            WHERE DATE(o.created_at) BETWEEN ? AND ?
        ";
        $orderParams = [$dateFrom, $dateTo];
        if ($branchFilter > 0) {
            $orderSql .= ' AND o.branch_id = ?';
            $orderParams[] = $branchFilter;
        }
        $orderSql .= ' GROUP BY b.id, b.branch_name ORDER BY b.branch_name';
        $orderRows = DB::select($orderSql, $orderParams);

        $orderGrand = ['orders' => 0, 'pending' => 0, 'settled' => 0, 'cancelled' => 0, 'value' => 0];
        foreach ($orderRows as $r) {
            $orderGrand['orders'] += $r->total_orders;
            $orderGrand['pending'] += $r->pending_count;
            $orderGrand['settled'] += $r->settled_count;
            $orderGrand['cancelled'] += $r->cancelled_count;
            $orderGrand['value'] += (float) $r->total_value;
        }

        // ── REPORT 4: EXPIRY REPORT (snapshot) ──
        // days_left is computed in PHP (Asia/Manila) rather than via MySQL-only
        // DATEDIFF/CURDATE, so the report is portable and testable.
        $expirySql = '
            SELECT sb.id AS batch_id, i.item_name, i.unit, b.branch_name, sb.branch_id,
                   sb.quantity_left, sb.expiry_date, sb.received_at
            FROM stock_batches sb
            JOIN items i ON i.id = sb.item_id
            JOIN branches b ON b.id = sb.branch_id
            WHERE sb.quantity_left > 0 AND sb.expiry_date IS NOT NULL
        ';
        $expiryParams = [];
        if ($branchFilter > 0) {
            $expirySql .= ' AND sb.branch_id = ?';
            $expiryParams[] = $branchFilter;
        }
        $expirySql .= ' ORDER BY sb.expiry_date ASC';
        $expiryRows = DB::select($expirySql, $expiryParams);

        $today = Carbon::today();
        foreach ($expiryRows as $er) {
            // Positive = days until expiry; negative = days overdue. The expiry
            // day itself is day 0 (still usable through that date, per policy).
            $er->days_left = (int) $today->diffInDays(Carbon::parse($er->expiry_date)->startOfDay(), false);
        }

        // Batch ids that currently hold a visible archive (hidden from the
        // active "pending review" expired list, but still physically expired).
        $archivedBatchIds = ExpiredBatchArchive::visible()->pluck('batch_id')->all();
        $archivedSet = array_flip($archivedBatchIds);

        $expired = $expiringSoon = $expiryOk = [];
        $expiredPhysicalQty = 0;          // truthful physical expired stock (all)
        $expiredArchivedCount = 0;
        foreach ($expiryRows as $er) {
            $dl = (int) $er->days_left;
            if ($dl < 0) {
                $expiredPhysicalQty += (int) $er->quantity_left;
                if (isset($archivedSet[$er->batch_id])) {
                    $expiredArchivedCount++;

                    continue; // archived → out of the active pending-review list
                }
                $expired[] = $er;
            } elseif ($dl <= 7) {
                $expiringSoon[] = $er;
            } else {
                $expiryOk[] = $er;
            }
        }

        return [
            'stockRows' => $stockRows,
            'moveRows' => $moveRows,
            'orderRows' => $orderRows,
            'orderGrand' => $orderGrand,
            'expired' => $expired,
            'expiringSoon' => $expiringSoon,
            'expiryOk' => $expiryOk,
            'expiredPhysicalQty' => $expiredPhysicalQty,
            'expiredArchivedCount' => $expiredArchivedCount,
            'archiveCount' => $this->archiveQuery($branchFilter)->count(),
        ];
    }

    /** Visible archive entries scoped to a branch (0 = all authorized). */
    protected function archiveQuery(int $branchFilter)
    {
        $q = ExpiredBatchArchive::visible();
        if ($branchFilter > 0) {
            $q->where('branch_id', $branchFilter);
        }

        return $q;
    }

    // ───────────────────── archive actions ─────────────────────

    /**
     * Archive ONE expired batch (reporting lifecycle — no stock movement).
     */
    public function archive(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (! $user->canManageInventory()) {
            return response()->json(['ok' => false, 'error' => 'You are not authorized to archive batches.'], 403);
        }

        $batchId = (int) $request->input('batch_id', 0);
        if ($batchId <= 0) {
            return response()->json(['ok' => false, 'error' => 'A batch is required.'], 422);
        }

        $batch = StockBatch::find($batchId);
        if (! $batch) {
            return response()->json(['ok' => false, 'error' => 'Batch not found.'], 404);
        }

        // Branch ownership/scope.
        if ($user->isBranchScoped() && (int) $batch->branch_id !== (int) $user->branch_id) {
            return response()->json(['ok' => false, 'error' => 'You are not authorized to archive this branch\'s stock.'], 403);
        }

        // Authoritative expired check (Asia/Manila policy). Future/unexpired
        // batches cannot be archived through the expired-batch endpoint.
        $expiry = $batch->expiry_date ? $batch->expiry_date->format('Y-m-d') : null;
        if (! StockService::isExpired($expiry)) {
            return response()->json(['ok' => false, 'error' => 'Only expired batches can be archived here.'], 422);
        }

        try {
            $result = DB::transaction(function () use ($batch, $user) {
                $existing = ExpiredBatchArchive::where('batch_id', $batch->id)->lockForUpdate()->first();

                if ($existing && $existing->removed_at === null
                    && $existing->archived_at->gt(ExpiredBatchArchive::retentionCutoff())) {
                    // Already actively archived — idempotent, do NOT reset the clock.
                    return ['created' => false, 'archive' => $existing];
                }

                if ($existing) {
                    // Re-archive after an earlier removal: fresh window.
                    $existing->update([
                        'archived_by' => $user->id,
                        'archived_at' => now(),
                        'removed_at' => null,
                        'removed_reason' => null,
                        'branch_id' => $batch->branch_id,
                    ]);

                    return ['created' => true, 'archive' => $existing];
                }

                $archive = ExpiredBatchArchive::create([
                    'batch_id' => $batch->id,
                    'branch_id' => $batch->branch_id,
                    'archived_by' => $user->id,
                    'archived_at' => now(),
                ]);

                return ['created' => true, 'archive' => $archive];
            });
        } catch (QueryException $e) {
            // Concurrent double-submit collided on the unique batch_id — the
            // batch is already archived; treat as a safe no-op.
            return response()->json(['ok' => true, 'message' => "Batch #{$batch->id} is already archived.", 'already' => true]);
        }

        if ($result['created']) {
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'archived expired batch',
                'table_name' => 'expired_batch_archives',
                'record_id' => $batch->id,
                'details' => "Batch #{$batch->id} archived (reporting only; stock untouched). Expiry: {$expiry}.",
            ]);

            return response()->json(['ok' => true, 'message' => "Batch #{$batch->id} archived. Physical stock is unchanged and still blocked."]);
        }

        return response()->json(['ok' => true, 'message' => "Batch #{$batch->id} is already archived.", 'already' => true]);
    }

    /**
     * List visible archived expired batches (branch-scoped), with search + paging.
     */
    public function archives(Request $request): JsonResponse
    {
        $user = Auth::user();
        $branchFilter = $this->resolveBranchFilter($request, $user);
        $search = trim((string) $request->input('q', ''));
        $perPage = min(50, max(5, (int) $request->input('per_page', 15)));

        $q = ExpiredBatchArchive::visible()
            ->join('stock_batches as sb', 'sb.id', '=', 'expired_batch_archives.batch_id')
            ->join('items as i', 'i.id', '=', 'sb.item_id')
            ->join('branches as b', 'b.id', '=', 'expired_batch_archives.branch_id')
            ->leftJoin('users as u', 'u.id', '=', 'expired_batch_archives.archived_by');

        if ($user->isBranchScoped()) {
            $q->where('expired_batch_archives.branch_id', $user->branch_id);
        } elseif ($branchFilter > 0) {
            $q->where('expired_batch_archives.branch_id', $branchFilter);
        }

        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('i.item_name', 'like', "%{$search}%")
                    ->orWhere('b.branch_name', 'like', "%{$search}%")
                    ->orWhere('sb.id', $search);
            });
        }

        $rows = $q->orderByDesc('expired_batch_archives.archived_at')
            ->paginate($perPage, [
                'expired_batch_archives.id',
                'expired_batch_archives.batch_id',
                'expired_batch_archives.archived_at',
                'i.item_name', 'i.unit', 'b.branch_name',
                'sb.quantity_left', 'sb.expiry_date',
                'u.full_name as archived_by_name',
            ]);

        $retentionDays = ExpiredBatchArchive::RETENTION_DAYS;
        $items = collect($rows->items())->map(function ($r) use ($retentionDays) {
            $archivedAt = Carbon::parse($r->archived_at);
            $removalAt = $archivedAt->copy()->addDays($retentionDays);

            return [
                'id' => $r->id,
                'batch_id' => $r->batch_id,
                'item_name' => $r->item_name,
                'branch_name' => $r->branch_name,
                'quantity_left' => (int) $r->quantity_left,
                'unit' => $r->unit,
                'expiry_date' => $r->expiry_date,
                'archived_at' => $archivedAt->format('M j, Y g:i A'),
                'archived_by' => $r->archived_by_name ?? '—',
                'removal_at' => $removalAt->format('M j, Y'),
                'days_left' => max(0, (int) now()->startOfDay()->diffInDays($removalAt->copy()->startOfDay(), false)),
            ];
        });

        return response()->json([
            'ok' => true,
            'items' => $items,
            'total' => $rows->total(),
            'page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
        ]);
    }

    /**
     * Manually remove an archive entry early (soft-delete; history preserved).
     */
    public function archiveRemove(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (! $user->canManageInventory()) {
            return response()->json(['ok' => false, 'error' => 'You are not authorized.'], 403);
        }

        $id = (int) $request->input('id', 0);
        $archive = ExpiredBatchArchive::find($id);
        if (! $archive || $archive->removed_at !== null) {
            return response()->json(['ok' => false, 'error' => 'Archive entry not found.'], 404);
        }

        if ($user->isBranchScoped() && (int) $archive->branch_id !== (int) $user->branch_id) {
            return response()->json(['ok' => false, 'error' => 'You are not authorized for this branch.'], 403);
        }

        $archive->update(['removed_at' => now(), 'removed_reason' => 'manual']);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'removed archive entry',
            'table_name' => 'expired_batch_archives',
            'record_id' => $archive->id,
            'details' => "Archive entry for batch #{$archive->batch_id} removed early (soft-delete). Ledger and history preserved.",
        ]);

        return response()->json(['ok' => true, 'message' => 'Archive entry removed.']);
    }

    /**
     * Export the selected report tab as a CSV download.
     */
    public function export(Request $request)
    {
        $user = Auth::user();
        $type = $request->input('type', 'stock');

        [$dateFrom, $dateTo] = $this->dateRange($request);
        $branchFilter = $this->resolveBranchFilter($request, $user);

        $filename = "report_{$type}_{$dateFrom}_to_{$dateTo}.csv";

        return response()->streamDownload(function () use ($type, $dateFrom, $dateTo, $branchFilter) {
            $handle = fopen('php://output', 'w');

            if ($type === 'stock') {
                fputcsv($handle, ['Product', 'Category', 'Type', 'Branch', 'Qty on Hand', 'Unit', 'Reorder Level', 'Active Batches', 'Nearest Expiry']);
                $sql = 'SELECT i.item_name, c.name AS category_name, c.goods_type, b.branch_name, i.unit, i.reorder_level,
                               COALESCE(SUM(sb.quantity_left),0) AS total_qty,
                               COUNT(CASE WHEN sb.quantity_left > 0 THEN 1 END) AS active_batches,
                               MIN(CASE WHEN sb.quantity_left > 0 THEN sb.expiry_date END) AS nearest_expiry
                        FROM items i LEFT JOIN categories c ON c.id = i.category_id
                        CROSS JOIN branches b LEFT JOIN stock_batches sb ON sb.item_id = i.id AND sb.branch_id = b.id AND sb.quantity_left > 0
                        WHERE i.is_active = 1 AND b.is_active = 1';
                $params = [];
                if ($branchFilter > 0) {
                    $sql .= ' AND b.id = ?';
                    $params[] = $branchFilter;
                }
                $sql .= ' GROUP BY i.id, b.id, i.item_name, i.unit, i.reorder_level, c.name, c.goods_type, b.branch_name HAVING total_qty > 0 ORDER BY c.name, i.item_name';
                foreach (DB::select($sql, $params) as $r) {
                    fputcsv($handle, [$r->item_name, $r->category_name, $r->goods_type, $r->branch_name, $r->total_qty, $r->unit, $r->reorder_level, $r->active_batches, $r->nearest_expiry]);
                }
            } elseif ($type === 'movements') {
                fputcsv($handle, ['Product', 'Category', 'Branch', 'Stock In', 'Stock Out', 'Adjustments', 'Net']);
                $sql = "SELECT i.item_name, c.name AS category_name, b.branch_name,
                               SUM(CASE WHEN st.type='in' THEN st.quantity ELSE 0 END) AS total_in,
                               SUM(CASE WHEN st.type='out' THEN ABS(st.quantity) ELSE 0 END) AS total_out,
                               SUM(CASE WHEN st.type IN ('adjustment','expired','damaged') THEN ABS(st.quantity) ELSE 0 END) AS total_adj
                        FROM stock_transactions st JOIN items i ON i.id = st.item_id LEFT JOIN categories c ON c.id = i.category_id
                        JOIN branches b ON b.id = st.branch_id WHERE DATE(st.created_at) BETWEEN ? AND ?";
                $params = [$dateFrom, $dateTo];
                if ($branchFilter > 0) {
                    $sql .= ' AND st.branch_id = ?';
                    $params[] = $branchFilter;
                }
                $sql .= ' GROUP BY i.id, b.id, i.item_name, c.name, b.branch_name ORDER BY c.name, i.item_name';
                foreach (DB::select($sql, $params) as $r) {
                    $net = $r->total_in - $r->total_out - $r->total_adj;
                    fputcsv($handle, [$r->item_name, $r->category_name, $r->branch_name, $r->total_in, $r->total_out, $r->total_adj, $net]);
                }
            } elseif ($type === 'orders') {
                fputcsv($handle, ['Branch', 'Total', 'Pending', 'Approved', 'Preparing', 'Delivering', 'Delivered', 'Settled', 'Cancelled', 'Declined', 'Value']);
                $sql = "SELECT b.branch_name, COUNT(*) AS total_orders,
                               SUM(CASE WHEN o.status='pending' THEN 1 ELSE 0 END) AS pending_count,
                               SUM(CASE WHEN o.status='approved' THEN 1 ELSE 0 END) AS approved_count,
                               SUM(CASE WHEN o.status='preparing' THEN 1 ELSE 0 END) AS preparing_count,
                               SUM(CASE WHEN o.status='delivering' THEN 1 ELSE 0 END) AS delivering_count,
                               SUM(CASE WHEN o.status='delivered' THEN 1 ELSE 0 END) AS delivered_count,
                               SUM(CASE WHEN o.status='settled' THEN 1 ELSE 0 END) AS settled_count,
                               SUM(CASE WHEN o.status='cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
                               SUM(CASE WHEN o.status='declined' THEN 1 ELSE 0 END) AS declined_count,
                               SUM(CASE WHEN o.status NOT IN ('cancelled','declined') THEN COALESCE(o.fulfilled_amount, o.total_amount) ELSE 0 END) AS total_value
                        FROM orders o JOIN branches b ON b.id = o.branch_id WHERE DATE(o.created_at) BETWEEN ? AND ?";
                $params = [$dateFrom, $dateTo];
                if ($branchFilter > 0) {
                    $sql .= ' AND o.branch_id = ?';
                    $params[] = $branchFilter;
                }
                $sql .= ' GROUP BY b.id, b.branch_name ORDER BY b.branch_name';
                foreach (DB::select($sql, $params) as $r) {
                    fputcsv($handle, [$r->branch_name, $r->total_orders, $r->pending_count, $r->approved_count, $r->preparing_count, $r->delivering_count, $r->delivered_count, $r->settled_count, $r->cancelled_count, $r->declined_count, $r->total_value]);
                }
            } elseif ($type === 'expiry') {
                fputcsv($handle, ['Batch #', 'Product', 'Branch', 'Qty Left', 'Unit', 'Expiry Date', 'Days Left']);
                $sql = 'SELECT sb.id AS batch_id, i.item_name, i.unit, b.branch_name, sb.quantity_left, sb.expiry_date
                        FROM stock_batches sb JOIN items i ON i.id = sb.item_id JOIN branches b ON b.id = sb.branch_id
                        WHERE sb.quantity_left > 0 AND sb.expiry_date IS NOT NULL';
                $params = [];
                if ($branchFilter > 0) {
                    $sql .= ' AND sb.branch_id = ?';
                    $params[] = $branchFilter;
                }
                $sql .= ' ORDER BY sb.expiry_date ASC';
                $today = Carbon::today();
                foreach (DB::select($sql, $params) as $r) {
                    $daysLeft = (int) $today->diffInDays(Carbon::parse($r->expiry_date)->startOfDay(), false);
                    fputcsv($handle, [$r->batch_id, $r->item_name, $r->branch_name, $r->quantity_left, $r->unit, $r->expiry_date, $daysLeft]);
                }
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
