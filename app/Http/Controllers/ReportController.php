<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ExpiredBatchArchive;
use App\Models\StockBatch;
use App\Services\CommissarySalesService;
use App\Services\DemoSalesService;
use App\Services\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Show the reports page.
     * Four report tabs: Stock Summary, Stock Movements, Commissary Sales, Expiry Report.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $salesAuthorized = $user->isOwner() || $user->isCommissaryManager();
        if (in_array($request->input('tab'), ['sales', 'orders'], true)) {
            abort_unless($salesAuthorized, 403);
        }

        [$dateFrom, $dateTo, $dateNotice, $datePreset] = $this->dateRange($request);
        $branchFilter = $this->resolveBranchFilter($request, $user);

        $branches = $this->authorizedBranches($user);
        $mainBranch = $this->mainWarehouse();

        $data = $this->buildReportData($branchFilter, $dateFrom, $dateTo);

        $scopeText = $branchFilter === 0
            ? 'All operating branches'
            : (optional($branches->firstWhere('id', $branchFilter))->branch_name ?? 'Selected branch');

        $demoPreview = $salesAuthorized && app(DemoSalesService::class)->enabled();
        $demoSales = null;
        $demoSalesScopeText = 'All operating branches';
        $salesSummary = null;
        $salesScopeText = 'All branches';
        $canSelectBranches = ! $user->isBranchScoped();
        if ($salesAuthorized) {
            $salesBranches = Branch::where('branch_type', '<>', 'commissary')->orderBy('branch_name')->get();
            $salesBranch = $this->resolveSalesBranchFilter($request);
            $salesScopeText = $salesBranch === 0 ? 'All branches' : $salesBranches->firstWhere('id', $salesBranch)->branch_name;
            if ($demoPreview) {
                $demoSales = app(DemoSalesService::class)->summary($dateFrom, $dateTo, $salesBranch);
                $demoSalesScopeText = $salesScopeText;
            } else {
                $salesSummary = app(CommissarySalesService::class)->summary($dateFrom, $dateTo, $salesBranch);
            }
            if (in_array($request->input('tab'), ['sales', 'orders'], true)) {
                $branches = $salesBranches;
                $branchFilter = $salesBranch;
                $canSelectBranches = true;
            }
        }

        // The Commissary Manager's Product Summary / Product Movement tabs report
        // the order-bound production workflow (requested → prepared → released),
        // not branch batch stock.
        $commissaryReports = $user->isCommissaryManager();
        $commissaryProductSummary = collect();
        $commissaryMovements = collect();
        $commissaryBranches = collect();
        $commissaryBranchFilter = 0;
        if ($commissaryReports) {
            // Scope by DESTINATION branch (a franchise), not the Commissary the
            // manager is pinned to; default 0 = all destinations.
            $commissaryBranchFilter = $this->resolveSalesBranchFilter($request);
            $commissaryBranches = Branch::where('branch_type', '<>', 'commissary')->where('is_active', true)->orderBy('branch_name')->get();
            [$commissaryProductSummary, $commissaryMovements] = $this->buildCommissaryReportData($commissaryBranchFilter, $dateFrom, $dateTo);
        }

        return view('reports.index', array_merge($data, [
            'commissaryReports' => $commissaryReports,
            'commissaryProductSummary' => $commissaryProductSummary,
            'commissaryMovements' => $commissaryMovements,
            'commissaryBranches' => $commissaryBranches,
            'commissaryBranchFilter' => $commissaryBranchFilter,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'dateNotice' => $dateNotice,
            'datePreset' => $datePreset,
            'periodText' => $this->formatPeriod($dateFrom, $dateTo),
            'scopeText' => $scopeText,
            'branchFilter' => $branchFilter,
            'branches' => $branches,
            'mainBranch' => $mainBranch,
            'canArchive' => $user->canHandleInventory(),
            'allowAllBranches' => ! $user->isBranchScoped(),
            'salesAuthorized' => $salesAuthorized,
            'demoPreview' => $demoPreview,
            'demoSales' => $demoSales,
            'demoSalesScopeText' => $demoSalesScopeText,
            'salesSummary' => $salesSummary,
            'salesScopeText' => $salesScopeText,
            'canSelectBranches' => $canSelectBranches,
        ]));
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * Validated, inclusive date range in Asia/Manila business dates. With no valid
     * dates supplied the window defaults to "This Month" (first of the month
     * through today); explicit valid dates are preserved. A reversed range (From
     * after To) is swapped and flagged. Returns [from, to, notice, preset] where
     * preset is one of today|last7|month|custom for the quick-choice buttons.
     */
    protected function dateRange(Request $request): array
    {
        $tz = 'Asia/Manila';
        $monthStart = now($tz)->startOfMonth()->format('Y-m-d');
        $today = now($tz)->format('Y-m-d');

        $from = $this->validDate($request->input('from'), $monthStart);
        $to = $this->validDate($request->input('to'), $today);
        $notice = null;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
            $notice = 'Date From was after Date To — the dates were swapped so results still show.';
        }

        return [$from, $to, $notice, $this->detectPreset($from, $to, $tz)];
    }

    /** Which quick-choice preset the applied range matches, else custom. */
    private function detectPreset(string $from, string $to, string $tz): string
    {
        $today = now($tz)->format('Y-m-d');
        if ($from === $today && $to === $today) {
            return 'today';
        }
        if ($from === now($tz)->subDays(6)->format('Y-m-d') && $to === $today) {
            return 'last7';
        }
        if ($from === now($tz)->startOfMonth()->format('Y-m-d') && $to === $today) {
            return 'month';
        }

        return 'custom';
    }

    /** Human-readable applied period, e.g. "October 1–25, 2026". */
    private function formatPeriod(string $from, string $to): string
    {
        $f = Carbon::parse($from);
        $t = Carbon::parse($to);
        if ($f->year === $t->year && $f->month === $t->month) {
            return $f->format('F j').'–'.$t->format('j, Y');
        }
        if ($f->year === $t->year) {
            return $f->format('M j').' – '.$t->format('M j, Y');
        }

        return $f->format('M j, Y').' – '.$t->format('M j, Y');
    }

    /** Return a valid Y-m-d string, else the given default. */
    private function validDate($value, string $default): string
    {
        $value = trim((string) $value);
        $d = \DateTime::createFromFormat('Y-m-d', $value);

        return ($d && $d->format('Y-m-d') === $value) ? $value : $default;
    }

    /**
     * Resolve the requested branch, honouring branch scoping. A non-scoped user's
     * ?branch=… is validated against the authorized, non-Commissary, active branch
     * set, so a manually edited URL cannot slip an excluded or unknown branch past
     * the filter (falls back to 0 = All Branches). A branch-scoped user is always
     * pinned to their own branch — a Commissary-scoped account therefore resolves
     * to the Commissary (which the branch reports exclude, yielding an empty
     * access-appropriate result) and never silently gains all-branch access.
     */
    private function resolveSalesBranchFilter(Request $request): int
    {
        $branch = (int) $request->input('branch', 0);

        return $branch > 0 && Branch::whereKey($branch)->where('branch_type', '<>', 'commissary')->exists() ? $branch : 0;
    }

    protected function resolveBranchFilter(Request $request, $user): int
    {
        if ($user->isBranchScoped()) {
            return (int) $user->branch_id;
        }

        $branch = (int) $request->input('branch', 0);
        if ($branch > 0) {
            $valid = Branch::where('id', $branch)
                ->where('is_active', true)
                ->where('branch_type', '<>', 'commissary')
                ->exists();
            if (! $valid) {
                $branch = 0;
            }
        }

        return $branch;
    }

    protected function mainWarehouse(): ?Branch
    {
        return Branch::where('branch_type', 'main')->where('is_active', true)->orderBy('id')->first();
    }

    /**
     * Branches selectable in these branch inventory reports (own branch only when
     * scoped). The Commissary is the production/dispatch source, not a reportable
     * branch, so it is excluded from the options everywhere.
     */
    protected function authorizedBranches($user)
    {
        $q = Branch::where('is_active', true)
            ->where('branch_type', '<>', 'commissary')
            ->orderBy('branch_name');
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
        // Current balances, restricted to batches received during the selected period.
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
                AND DATE(sb.received_at) BETWEEN ? AND ?
            WHERE i.is_active = 1 AND b.is_active = 1 AND b.branch_type <> \'commissary\'
        ';
        $stockParams = [$dateFrom, $dateTo];
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
                   SUM(CASE WHEN st.type IN ('out','adjustment','expired','damaged') THEN ABS(st.quantity) ELSE 0 END) AS total_out
            FROM stock_transactions st
            JOIN items i ON i.id = st.item_id
            LEFT JOIN categories c ON c.id = i.category_id
            JOIN branches b ON b.id = st.branch_id
            WHERE DATE(st.created_at) BETWEEN ? AND ? AND b.branch_type <> 'commissary'
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
            WHERE DATE(o.created_at) BETWEEN ? AND ? AND b.branch_type <> 'commissary'
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

        // Current batch balances and statuses, restricted to the selected expiry dates.
        // days_left is computed in PHP (Asia/Manila) rather than via MySQL-only
        // DATEDIFF/CURDATE, so the report is portable and testable.
        $expirySql = '
            SELECT sb.id AS batch_id, i.item_name, i.unit, b.branch_name, sb.branch_id,
                   sb.quantity_left, sb.expiry_date, sb.received_at
            FROM stock_batches sb
            JOIN items i ON i.id = sb.item_id
            JOIN branches b ON b.id = sb.branch_id
            WHERE sb.quantity_left > 0 AND sb.expiry_date IS NOT NULL AND b.branch_type <> \'commissary\'
                AND DATE(sb.expiry_date) BETWEEN ? AND ?
        ';
        $expiryParams = [$dateFrom, $dateTo];
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

    /**
     * Commissary production/dispatch reporting datasets.
     *
     * Product Summary: per product, the requested / prepared / released and
     * remaining-for-release quantities from the order-bound production workflow,
     * dated by preparation and optionally scoped to one destination branch.
     *
     * Product Movement: preparation/picking and release history rows, each with
     * the destination branch and event date, within the selected period.
     *
     * Cancelled and declined orders are excluded. Quantities are in each product's
     * own unit and are never combined across units.
     *
     * @return array{0:Collection,1:Collection}
     */
    protected function buildCommissaryReportData(int $branchFilter, string $dateFrom, string $dateTo): array
    {
        $summary = DB::table('order_productions as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->join('order_items as oi', 'oi.id', '=', 'p.order_item_id')
            ->join('items as i', 'i.id', '=', 'p.item_id')
            ->whereNotIn('o.status', ['cancelled', 'declined'])
            ->whereNotNull('p.production_at')
            ->whereBetween(DB::raw('DATE(p.production_at)'), [$dateFrom, $dateTo])
            ->when($branchFilter > 0, fn ($q) => $q->where('p.destination_branch_id', $branchFilter))
            ->groupBy('i.id', 'i.item_name', 'i.unit')
            ->orderBy('i.item_name')
            ->select(
                'i.item_name',
                'i.unit',
                DB::raw('SUM(oi.quantity) as requested'),
                DB::raw('SUM(p.produced_qty) as prepared'),
                DB::raw('SUM(p.dispatched_qty) as released'),
                DB::raw('SUM(p.produced_qty - p.dispatched_qty) as remaining')
            )
            ->get();

        // Preparation / picking events (dated by production_at).
        $preparation = DB::table('order_productions as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->join('items as i', 'i.id', '=', 'p.item_id')
            ->join('branches as b', 'b.id', '=', 'p.destination_branch_id')
            ->whereNotIn('o.status', ['cancelled', 'declined'])
            ->whereNotNull('p.production_at')
            ->whereBetween(DB::raw('DATE(p.production_at)'), [$dateFrom, $dateTo])
            ->when($branchFilter > 0, fn ($q) => $q->where('p.destination_branch_id', $branchFilter))
            ->select(
                DB::raw('p.production_at as moved_at'),
                DB::raw("CASE WHEN p.source_type = 'picked' THEN 'Picked' ELSE 'Prepared' END as action"),
                'o.order_number',
                'i.item_name',
                'i.unit',
                'b.branch_name as destination',
                DB::raw('p.produced_qty as quantity')
            );

        // Release events (dated by each unit's dispatch, grouped per day/product/destination).
        $release = DB::table('order_production_units as u')
            ->join('order_productions as p', 'p.id', '=', 'u.production_id')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->join('items as i', 'i.id', '=', 'p.item_id')
            ->join('branches as b', 'b.id', '=', 'p.destination_branch_id')
            ->whereNotIn('o.status', ['cancelled', 'declined'])
            ->whereNotNull('u.dispatched_at')
            ->whereBetween(DB::raw('DATE(u.dispatched_at)'), [$dateFrom, $dateTo])
            ->when($branchFilter > 0, fn ($q) => $q->where('p.destination_branch_id', $branchFilter))
            ->groupBy(DB::raw('DATE(u.dispatched_at)'), 'o.order_number', 'i.item_name', 'i.unit', 'b.branch_name')
            ->select(
                DB::raw('MAX(u.dispatched_at) as moved_at'),
                DB::raw("'Released' as action"),
                'o.order_number',
                'i.item_name',
                'i.unit',
                'b.branch_name as destination',
                DB::raw('COUNT(*) as quantity')
            );

        $movements = $preparation->get()
            ->concat($release->get())
            ->sortByDesc(fn ($row) => $row->moved_at)
            ->values();

        return [$summary, $movements];
    }

    /**
     * Visible archive entries scoped to a branch (0 = all authorized). The
     * Commissary is excluded to match the branch inventory reports it sits beside.
     */
    protected function archiveQuery(int $branchFilter)
    {
        $q = ExpiredBatchArchive::visible()
            ->whereNotIn('expired_batch_archives.branch_id', $this->commissaryBranchIds());
        if ($branchFilter > 0) {
            $q->where('branch_id', $branchFilter);
        }

        return $q;
    }

    /** Branch ids classified as the Commissary production/dispatch source. */
    protected function commissaryBranchIds(): array
    {
        return Branch::where('branch_type', 'commissary')->pluck('id')->all();
    }

    // ───────────────────── archive actions ─────────────────────

    /**
     * Archive ONE expired batch (reporting lifecycle — no stock movement).
     */
    public function archive(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (! $user->canHandleInventory()) {
            return response()->json(['ok' => false, 'error' => 'Inventory monitoring only. Inventory archive actions require an authorized inventory handler.'], 403);
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
            ->leftJoin('users as u', 'u.id', '=', 'expired_batch_archives.archived_by')
            ->where('b.branch_type', '<>', 'commissary');

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
        if (! $user->canHandleInventory()) {
            return response()->json(['ok' => false, 'error' => 'Inventory monitoring only. Inventory archive actions require an authorized inventory handler.'], 403);
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

    private function salesCsvText(string $value): string
    {
        return preg_match('/^\s*[=+\-@]/u', $value) === 1 ? "'".$value : $value;
    }

    /**
     * Export the selected report tab as a CSV download.
     */
    public function export(Request $request)
    {
        $user = Auth::user();
        $type = $request->input('type', 'stock');
        if (in_array($type, ['sales', 'orders'], true)) {
            abort_unless($user->isOwner() || $user->isCommissaryManager(), 403);
            if (app(DemoSalesService::class)->enabled()) {
                return response('Simulated Sales are a demo preview and cannot be exported as actual Commissary Sales.', 409);
            }
            [$dateFrom, $dateTo] = $this->dateRange($request);
            $summary = app(CommissarySalesService::class)->summary($dateFrom, $dateTo, $this->resolveSalesBranchFilter($request));

            return response()->streamDownload(function () use ($summary): void {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['Date', 'Order #', 'Branch', 'Product', 'Released / Completed Quantity', 'Unit', 'Unit Price', 'Amount', 'Record Basis']);
                foreach ($summary['rows'] as $row) {
                    fputcsv($handle, [$row->released_on, $this->salesCsvText($row->order_number), $this->salesCsvText($row->branch_name), $this->salesCsvText($row->item_name), $row->released_quantity, $this->salesCsvText($row->unit), number_format($row->unit_price, 2, '.', ''), number_format($row->amount, 2, '.', ''), $row->record_basis]);
                }
                fclose($handle);
            }, "report_sales_{$dateFrom}_to_{$dateTo}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

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
                            AND DATE(sb.received_at) BETWEEN ? AND ?
                        WHERE i.is_active = 1 AND b.is_active = 1 AND b.branch_type <> \'commissary\'';
                $params = [$dateFrom, $dateTo];
                if ($branchFilter > 0) {
                    $sql .= ' AND b.id = ?';
                    $params[] = $branchFilter;
                }
                $sql .= ' GROUP BY i.id, b.id, i.item_name, i.unit, i.reorder_level, c.name, c.goods_type, b.branch_name HAVING total_qty > 0 ORDER BY c.name, i.item_name';
                foreach (DB::select($sql, $params) as $r) {
                    fputcsv($handle, [$r->item_name, $r->category_name, $r->goods_type, $r->branch_name, $r->total_qty, $r->unit, $r->reorder_level, $r->active_batches, $r->nearest_expiry]);
                }
            } elseif ($type === 'movements') {
                fputcsv($handle, ['Product', 'Category', 'Branch', 'Stock In', 'Stock Out', 'Net']);
                $sql = "SELECT i.item_name, c.name AS category_name, b.branch_name,
                               SUM(CASE WHEN st.type='in' THEN st.quantity ELSE 0 END) AS total_in,
                               SUM(CASE WHEN st.type IN ('out','adjustment','expired','damaged') THEN ABS(st.quantity) ELSE 0 END) AS total_out
                        FROM stock_transactions st JOIN items i ON i.id = st.item_id LEFT JOIN categories c ON c.id = i.category_id
                        JOIN branches b ON b.id = st.branch_id WHERE DATE(st.created_at) BETWEEN ? AND ? AND b.branch_type <> 'commissary'";
                $params = [$dateFrom, $dateTo];
                if ($branchFilter > 0) {
                    $sql .= ' AND st.branch_id = ?';
                    $params[] = $branchFilter;
                }
                $sql .= ' GROUP BY i.id, b.id, i.item_name, c.name, b.branch_name ORDER BY c.name, i.item_name';
                foreach (DB::select($sql, $params) as $r) {
                    $net = $r->total_in - $r->total_out;
                    fputcsv($handle, [$r->item_name, $r->category_name, $r->branch_name, $r->total_in, $r->total_out, $net]);
                }
            } elseif ($type === 'expiry') {
                fputcsv($handle, ['Batch #', 'Product', 'Branch', 'Qty Left', 'Unit', 'Expiry Date', 'Days Left']);
                $sql = 'SELECT sb.id AS batch_id, i.item_name, i.unit, b.branch_name, sb.quantity_left, sb.expiry_date
                        FROM stock_batches sb JOIN items i ON i.id = sb.item_id JOIN branches b ON b.id = sb.branch_id
                        WHERE sb.quantity_left > 0 AND sb.expiry_date IS NOT NULL AND b.branch_type <> \'commissary\'
                            AND DATE(sb.expiry_date) BETWEEN ? AND ?';
                $params = [$dateFrom, $dateTo];
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
