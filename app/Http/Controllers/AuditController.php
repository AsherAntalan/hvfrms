<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditController extends Controller
{
    /**
     * Show the audit log page with filters and pagination.
     */
    public function index(Request $request)
    {
        $filterUser   = (int) $request->input('user', 0);
        $filterAction = trim($request->input('action_type', ''));
        $filterFrom   = $request->input('from', now()->subDays(7)->format('Y-m-d'));
        $filterTo     = $request->input('to', now()->format('Y-m-d'));
        $perPage      = 50;

        // Build query
        $query = AuditLog::select('audit_log.*', 'users.full_name', 'users.email as user_email')
            ->leftJoin('users', 'users.id', '=', 'audit_log.user_id')
            ->whereBetween(DB::raw('DATE(audit_log.created_at)'), [$filterFrom, $filterTo]);

        if ($filterUser > 0) {
            $query->where('audit_log.user_id', $filterUser);
        }
        if ($filterAction !== '') {
            $query->where('audit_log.action', 'like', "%{$filterAction}%");
        }

        $totalRows = (clone $query)->count();

        $logs = $query->orderBy('audit_log.created_at', 'desc')
            ->paginate($perPage)
            ->appends($request->query());

        // Users for filter dropdown
        $users = User::select('id', 'full_name', 'email')
            ->orderBy('full_name')
            ->get();

        // Distinct actions for filter
        $actions = AuditLog::select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        return view('audit.index', compact(
            'logs',
            'users',
            'actions',
            'filterUser',
            'filterAction',
            'filterFrom',
            'filterTo',
            'totalRows'
        ));
    }
}
