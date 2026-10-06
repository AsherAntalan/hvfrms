<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Support\Facades\Auth;

class DeliveryController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $user->load('role', 'branch');
        $isBranchScoped = $user->isBranchScoped();

        $query = Order::select('orders.*', 'branches.branch_name', 'u.full_name as ordered_by_name')
            ->join('branches', 'branches.id', '=', 'orders.branch_id')
            ->leftJoin('users as u', 'u.id', '=', 'orders.ordered_by')
            ->whereIn('orders.status', ['pending', 'approved', 'delivering']);

        if ($isBranchScoped) {
            $query->where('orders.branch_id', $user->branch_id);
        }

        $active = $query->orderByRaw("FIELD(orders.status, 'pending', 'approved', 'delivering')")
            ->orderByDesc('orders.updated_at')
            ->get();

        return view('delivery.index', compact('user', 'active'));
    }

    public function history()
    {
        $user = Auth::user();
        $user->load('role', 'branch');
        $isBranchScoped = $user->isBranchScoped();

        $query = Order::select('orders.*', 'branches.branch_name', 'u.full_name as ordered_by_name')
            ->join('branches', 'branches.id', '=', 'orders.branch_id')
            ->leftJoin('users as u', 'u.id', '=', 'orders.ordered_by')
            ->whereIn('orders.status', ['delivered', 'settled', 'cancelled']);

        if ($isBranchScoped) {
            $query->where('orders.branch_id', $user->branch_id);
        }

        $history = $query->orderByDesc('orders.updated_at')->paginate(20);

        return view('delivery.history', compact('user', 'history'));
    }
}
