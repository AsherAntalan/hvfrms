<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\Request;
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

        // Date range defaults: last 30 days
        $dateFrom = $request->input('from', date('Y-m-d', strtotime('-30 days')));
        $dateTo   = $request->input('to', date('Y-m-d'));

        // Branch managers are always locked to their own branch
        $branchFilter = 0;
        if ($user->isBranchScoped()) {
            $branchFilter = $user->branch_id;
        } else {
            $branchFilter = (int)$request->input('branch', 0);
        }

        $branches = Branch::where('is_active', true)
            ->orderBy('branch_name')
            ->get(['id', 'branch_name']);

        // ── REPORT 1: CURRENT STOCK SUMMARY ──
        $stockSql = "
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
        ";
        $stockParams = [];
        if ($branchFilter > 0) {
            $stockSql .= " AND b.id = ?";
            $stockParams[] = $branchFilter;
        }
        $stockSql .= " GROUP BY i.id, b.id, i.item_name, i.unit, i.reorder_level, c.name, c.goods_type, b.branch_name
                        HAVING total_qty > 0
                        ORDER BY c.name, i.item_name, b.branch_name";
        $stockRows = DB::select($stockSql, $stockParams);

        // ── REPORT 2: STOCK MOVEMENT SUMMARY ──
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
            $moveSql .= " AND st.branch_id = ?";
            $moveParams[] = $branchFilter;
        }
        $moveSql .= " GROUP BY i.id, b.id, i.item_name, i.unit, c.name, b.branch_name
                       ORDER BY c.name, i.item_name, b.branch_name";
        $moveRows = DB::select($moveSql, $moveParams);

        // ── REPORT 3: ORDER SUMMARY ──
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
                   SUM(CASE WHEN o.status NOT IN ('cancelled') THEN o.total_amount ELSE 0 END) AS total_value
            FROM orders o
            JOIN branches b ON b.id = o.branch_id
            WHERE DATE(o.created_at) BETWEEN ? AND ?
        ";
        $orderParams = [$dateFrom, $dateTo];
        if ($branchFilter > 0) {
            $orderSql .= " AND o.branch_id = ?";
            $orderParams[] = $branchFilter;
        }
        $orderSql .= " GROUP BY b.id, b.branch_name ORDER BY b.branch_name";
        $orderRows = DB::select($orderSql, $orderParams);

        // Order totals
        $orderGrand = ['orders' => 0, 'pending' => 0, 'settled' => 0, 'cancelled' => 0, 'value' => 0];
        foreach ($orderRows as $r) {
            $orderGrand['orders']    += $r->total_orders;
            $orderGrand['pending']   += $r->pending_count;
            $orderGrand['settled']   += $r->settled_count;
            $orderGrand['cancelled'] += $r->cancelled_count;
            $orderGrand['value']     += (float)$r->total_value;
        }

        // ── REPORT 4: EXPIRY REPORT ──
        $expirySql = "
            SELECT sb.id AS batch_id, i.item_name, i.unit, b.branch_name,
                   sb.quantity_left, sb.expiry_date, sb.received_at,
                   DATEDIFF(sb.expiry_date, CURDATE()) AS days_left
            FROM stock_batches sb
            JOIN items i ON i.id = sb.item_id
            JOIN branches b ON b.id = sb.branch_id
            WHERE sb.quantity_left > 0 AND sb.expiry_date IS NOT NULL
        ";
        $expiryParams = [];
        if ($branchFilter > 0) {
            $expirySql .= " AND sb.branch_id = ?";
            $expiryParams[] = $branchFilter;
        }
        $expirySql .= " ORDER BY sb.expiry_date ASC";
        $expiryRows = DB::select($expirySql, $expiryParams);

        // Split into expired vs expiring soon vs ok
        $expired = $expiringSoon = $expiryOk = [];
        foreach ($expiryRows as $er) {
            $dl = (int)$er->days_left;
            if ($dl < 0) $expired[] = $er;
            elseif ($dl <= 7) $expiringSoon[] = $er;
            else $expiryOk[] = $er;
        }

        return view('reports.index', compact(
            'dateFrom',
            'dateTo',
            'branchFilter',
            'branches',
            'stockRows',
            'moveRows',
            'orderRows',
            'orderGrand',
            'expired',
            'expiringSoon',
            'expiryOk'
        ));
    }

    /**
     * Export the selected report tab as a CSV download.
     */
    public function export(Request $request)
    {
        $user = Auth::user();
        $type = $request->input('type', 'stock');

        $dateFrom = $request->input('from', date('Y-m-d', strtotime('-30 days')));
        $dateTo   = $request->input('to', date('Y-m-d'));

        $branchFilter = 0;
        if ($user->isBranchScoped()) {
            $branchFilter = $user->branch_id;
        } else {
            $branchFilter = (int)$request->input('branch', 0);
        }

        $filename = "report_{$type}_{$dateFrom}_to_{$dateTo}.csv";

        return response()->streamDownload(function () use ($type, $dateFrom, $dateTo, $branchFilter) {
            $handle = fopen('php://output', 'w');

            if ($type === 'stock') {
                fputcsv($handle, ['Product', 'Category', 'Type', 'Branch', 'Qty on Hand', 'Unit', 'Reorder Level', 'Active Batches', 'Nearest Expiry']);
                $sql = "SELECT i.item_name, c.name AS category_name, c.goods_type, b.branch_name, i.unit, i.reorder_level,
                               COALESCE(SUM(sb.quantity_left),0) AS total_qty,
                               COUNT(CASE WHEN sb.quantity_left > 0 THEN 1 END) AS active_batches,
                               MIN(CASE WHEN sb.quantity_left > 0 THEN sb.expiry_date END) AS nearest_expiry
                        FROM items i LEFT JOIN categories c ON c.id = i.category_id
                        CROSS JOIN branches b LEFT JOIN stock_batches sb ON sb.item_id = i.id AND sb.branch_id = b.id AND sb.quantity_left > 0
                        WHERE i.is_active = 1 AND b.is_active = 1";
                $params = [];
                if ($branchFilter > 0) { $sql .= " AND b.id = ?"; $params[] = $branchFilter; }
                $sql .= " GROUP BY i.id, b.id, i.item_name, i.unit, i.reorder_level, c.name, c.goods_type, b.branch_name HAVING total_qty > 0 ORDER BY c.name, i.item_name";
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
                if ($branchFilter > 0) { $sql .= " AND st.branch_id = ?"; $params[] = $branchFilter; }
                $sql .= " GROUP BY i.id, b.id, i.item_name, c.name, b.branch_name ORDER BY c.name, i.item_name";
                foreach (DB::select($sql, $params) as $r) {
                    $net = $r->total_in - $r->total_out - $r->total_adj;
                    fputcsv($handle, [$r->item_name, $r->category_name, $r->branch_name, $r->total_in, $r->total_out, $r->total_adj, $net]);
                }
            } elseif ($type === 'orders') {
                fputcsv($handle, ['Branch', 'Total', 'Pending', 'Approved', 'Preparing', 'Delivering', 'Delivered', 'Settled', 'Cancelled', 'Value']);
                $sql = "SELECT b.branch_name, COUNT(*) AS total_orders,
                               SUM(CASE WHEN o.status='pending' THEN 1 ELSE 0 END) AS pending_count,
                               SUM(CASE WHEN o.status='approved' THEN 1 ELSE 0 END) AS approved_count,
                               SUM(CASE WHEN o.status='preparing' THEN 1 ELSE 0 END) AS preparing_count,
                               SUM(CASE WHEN o.status='delivering' THEN 1 ELSE 0 END) AS delivering_count,
                               SUM(CASE WHEN o.status='delivered' THEN 1 ELSE 0 END) AS delivered_count,
                               SUM(CASE WHEN o.status='settled' THEN 1 ELSE 0 END) AS settled_count,
                               SUM(CASE WHEN o.status='cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
                               SUM(CASE WHEN o.status NOT IN ('cancelled') THEN o.total_amount ELSE 0 END) AS total_value
                        FROM orders o JOIN branches b ON b.id = o.branch_id WHERE DATE(o.created_at) BETWEEN ? AND ?";
                $params = [$dateFrom, $dateTo];
                if ($branchFilter > 0) { $sql .= " AND o.branch_id = ?"; $params[] = $branchFilter; }
                $sql .= " GROUP BY b.id, b.branch_name ORDER BY b.branch_name";
                foreach (DB::select($sql, $params) as $r) {
                    fputcsv($handle, [$r->branch_name, $r->total_orders, $r->pending_count, $r->approved_count, $r->preparing_count, $r->delivering_count, $r->delivered_count, $r->settled_count, $r->cancelled_count, $r->total_value]);
                }
            } elseif ($type === 'expiry') {
                fputcsv($handle, ['Batch #', 'Product', 'Branch', 'Qty Left', 'Unit', 'Expiry Date', 'Days Left']);
                $sql = "SELECT sb.id AS batch_id, i.item_name, i.unit, b.branch_name, sb.quantity_left, sb.expiry_date,
                               DATEDIFF(sb.expiry_date, CURDATE()) AS days_left
                        FROM stock_batches sb JOIN items i ON i.id = sb.item_id JOIN branches b ON b.id = sb.branch_id
                        WHERE sb.quantity_left > 0 AND sb.expiry_date IS NOT NULL";
                $params = [];
                if ($branchFilter > 0) { $sql .= " AND sb.branch_id = ?"; $params[] = $branchFilter; }
                $sql .= " ORDER BY sb.expiry_date ASC";
                foreach (DB::select($sql, $params) as $r) {
                    fputcsv($handle, [$r->batch_id, $r->item_name, $r->branch_name, $r->quantity_left, $r->unit, $r->expiry_date, $r->days_left]);
                }
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
