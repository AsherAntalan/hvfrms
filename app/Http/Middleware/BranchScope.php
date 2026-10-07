<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BranchScope
{
    /**
     * Handle an incoming request.
     *
     * Automatically apply branch filtering based on the user's role.
     * - Owner / super_admin: can see all branches, or filter via ?branch= parameter.
     * - branch_manager / it_staff: restricted to their own branch_id.
     *
     * Sets 'branch_id' on the request for controllers to consume.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $roleName = $user->role?->role_name;

        if (in_array($roleName, ['super_admin', 'owner'], true)) {
            // Owners can optionally filter by branch via query parameter.
            $branchId = $request->query('branch') ? (int) $request->query('branch') : null;
            $request->merge(['branch_id' => $branchId]);
        } elseif (in_array($roleName, ['warehouse_admin', 'warehouse_staff'], true)) {
            // Warehouse roles operate at the main branch.
            $mainBranch = \App\Models\Branch::where('branch_type', 'main')
                ->where('is_active', true)->first();
            $request->merge(['branch_id' => $mainBranch ? $mainBranch->id : $user->branch_id]);
        } else {
            // Non-owner roles are locked to their own branch.
            $request->merge(['branch_id' => $user->branch_id]);
        }

        return $next($request);
    }
}
