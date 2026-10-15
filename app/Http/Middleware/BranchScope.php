<?php

namespace App\Http\Middleware;

use App\Models\Branch;
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
     * - branch_manager / branch_staff: restricted to their own branch_id.
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

        if (in_array($roleName, ['super_admin', 'owner', 'admin'], true)) {
            // Owner/Admin can optionally filter by branch via query parameter.
            $selected = $request->query('branch', $request->isMethod('get') ? null : $request->input('branch_id'));
            $branchId = $selected ? (int) $selected : null;
            $request->merge(['branch_id' => $branchId]);
        } elseif (in_array($roleName, ['commissary_manager', 'commissary_staff'], true)) {
            // Commissary roles operate at the Commissary location (falls back to
            // the main store only until a Commissary is configured).
            $commissary = Branch::commissary();
            $request->merge(['branch_id' => $commissary ? $commissary->id : $user->branch_id]);
        } else {
            // Non-owner roles are locked to their own branch.
            $request->merge(['branch_id' => $user->branch_id]);
        }

        return $next($request);
    }
}
