<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;

class DeliveryController extends Controller
{
    /**
     * Delivery Status within Orders, with prepared/in-transit shipments grouped
     * by branch. Requests awaiting review or preparation remain in All Orders.
     */
    public function index(): View
    {
        $user = Auth::user();
        $user->load('role', 'branch');
        $isBranchScoped = $user->isBranchScoped();
        $isWarehouse = $user->isWarehouseRole();

        $activeQuery = Order::select('orders.*', 'branches.branch_name',
            'u.full_name as ordered_by_name',
            'u_prep.full_name as prepared_by_name')
            ->selectRaw('(SELECT COUNT(*) FROM order_productions p WHERE p.order_id = orders.id AND (p.stock_batch_id IS NOT NULL OR EXISTS (SELECT 1 FROM order_production_units u WHERE u.production_id = p.id))) AS piece_outputs')
            ->selectRaw('(SELECT COALESCE(SUM(p.produced_qty),0) FROM order_productions p WHERE p.order_id = orders.id) AS prepared_units')
            ->selectRaw('(SELECT COALESCE(SUM(p.dispatched_qty),0) FROM order_productions p WHERE p.order_id = orders.id) AS released_units')
            ->join('branches', 'branches.id', '=', 'orders.branch_id')
            ->leftJoin('users as u', 'u.id', '=', 'orders.ordered_by')
            ->leftJoin('users as u_prep', 'u_prep.id', '=', 'orders.prepared_by')
            ->whereIn('orders.status', ['approved', 'preparing', 'delivering'])
            ->where(function (Builder $query): void {
                $query->where('orders.status', 'delivering')
                    ->orWhereExists(function (QueryBuilder $productionQuery): void {
                        $productionQuery->selectRaw('1')
                            ->from('order_productions as prepared')
                            ->whereColumn('prepared.order_id', 'orders.id')
                            ->where('prepared.produced_qty', '>', 0)
                            ->where(function (QueryBuilder $sourceQuery): void {
                                $sourceQuery->whereNotNull('prepared.stock_batch_id')
                                    ->orWhereExists(function (QueryBuilder $unitQuery): void {
                                        $unitQuery->selectRaw('1')->from('order_production_units as units')
                                            ->whereColumn('units.production_id', 'prepared.id');
                                    });
                            });
                    });
            })
            ->whereNull('orders.archived_at');

        if ($isBranchScoped && ! $isWarehouse) {
            $activeQuery->where('orders.branch_id', $user->branch_id);
        }

        $active = $activeQuery
            ->orderByRaw("CASE orders.status WHEN 'pending' THEN 1 WHEN 'approved' THEN 2 WHEN 'preparing' THEN 3 WHEN 'delivering' THEN 4 ELSE 5 END")
            ->orderByDesc('orders.updated_at')
            ->get();

        $branches = $active->groupBy('branch_id')->map(function ($orders) {
            return [
                'branch_id' => $orders->first()->branch_id,
                'branch_name' => $orders->first()->branch_name,
                'orders' => $orders->values(),
                'count' => $orders->count(),
                'ready' => $orders->filter(fn (Order $order): bool => (int) $order->prepared_units > (int) $order->released_units)->count(),
            ];
        })->sortByDesc('ready')->values();

        // Delivery history in the same page (completed / closed records).
        $historyQuery = Order::select('orders.*', 'branches.branch_name', 'u.full_name as ordered_by_name')
            ->join('branches', 'branches.id', '=', 'orders.branch_id')
            ->leftJoin('users as u', 'u.id', '=', 'orders.ordered_by')
            ->whereIn('orders.status', ['delivered', 'settled', 'cancelled', 'declined'])
            ->whereNull('orders.archived_at');

        if ($isBranchScoped && ! $isWarehouse) {
            $historyQuery->where('orders.branch_id', $user->branch_id);
        }

        $history = $historyQuery->orderByDesc('orders.updated_at')->limit(200)->get();

        // Archived orders (hidden from active + history) — restore or delete here.
        $archivedQuery = Order::select('orders.*', 'branches.branch_name', 'u.full_name as ordered_by_name')
            ->join('branches', 'branches.id', '=', 'orders.branch_id')
            ->leftJoin('users as u', 'u.id', '=', 'orders.ordered_by')
            ->whereNotNull('orders.archived_at');

        if ($isBranchScoped && ! $isWarehouse) {
            $archivedQuery->where('orders.branch_id', $user->branch_id);
        }

        $archived = $archivedQuery->orderByDesc('orders.archived_at')->limit(200)->get();

        return view('delivery.index', compact('user', 'active', 'branches', 'history', 'archived'));
    }

    /**
     * Legacy route kept for existing bookmarks/notifications. History now lives
     * inside the single Delivery page, so redirect there with the tab preselected.
     */
    public function history()
    {
        return redirect()->route('delivery', ['tab' => 'history']);
    }
}
