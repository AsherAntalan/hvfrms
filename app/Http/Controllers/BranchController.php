<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BranchController extends Controller
{
    /**
     * Show the branches management page.
     */
    public function index()
    {
        $branches = Branch::select('branches.*')
            ->selectSub(function ($query) {
                $query->from('users')
                    ->whereColumn('users.branch_id', 'branches.id')
                    ->where('users.status', 'active')
                    ->selectRaw('COUNT(*)');
            }, 'staff_count')
            ->orderBy('branch_type', 'desc')
            ->orderBy('branch_name')
            ->get();

        return view('branches.index', compact('branches'));
    }

    /**
     * Handle AJAX API actions: save, toggle.
     */
    public function api(Request $request): JsonResponse
    {
        $action = $request->input('action');

        if ($action === 'save') {
            return $this->saveBranch($request);
        }

        if ($action === 'toggle') {
            return $this->toggleBranch($request);
        }

        return response()->json(['ok' => false, 'error' => 'Unknown action.'], 400);
    }

    /**
     * Create or update a branch.
     */
    protected function saveBranch(Request $request): JsonResponse
    {
        $branchName = trim($request->input('branch_name', ''));
        $branchType = trim($request->input('branch_type', ''));
        $address    = trim($request->input('address', ''));
        $contact    = trim($request->input('contact_number', ''));
        $id         = (int) $request->input('id', 0);

        if ($branchName === '') {
            return response()->json(['ok' => false, 'error' => 'Branch name is required.'], 422);
        }
        if (!in_array($branchType, ['main', 'franchise'], true)) {
            return response()->json(['ok' => false, 'error' => 'Invalid branch type.'], 422);
        }
        if (strlen($branchName) > 100) {
            return response()->json(['ok' => false, 'error' => 'Branch name must be 100 characters or less.'], 422);
        }

        // Check duplicate name
        $dupQuery = Branch::where('branch_name', $branchName);
        if ($id > 0) {
            $dupQuery->where('id', '!=', $id);
        }
        if ($dupQuery->exists()) {
            return response()->json(['ok' => false, 'error' => 'A branch with that name already exists.'], 422);
        }

        if ($id > 0) {
            // Update existing branch
            $branch = Branch::findOrFail($id);
            $branch->update([
                'branch_name'    => $branchName,
                'branch_type'    => $branchType,
                'address'        => $address ?: null,
                'contact_number' => $contact ?: null,
            ]);

            AuditLog::create([
                'user_id'    => Auth::id(),
                'action'     => 'updated branch',
                'table_name' => 'branches',
                'record_id'  => $id,
                'details'    => "Name: {$branchName}, Type: {$branchType}",
                'ip_address' => $request->ip(),
            ]);

            return response()->json(['ok' => true, 'message' => 'Branch updated successfully.']);
        } else {
            // Create new branch
            $branch = Branch::create([
                'branch_name'    => $branchName,
                'branch_type'    => $branchType,
                'address'        => $address ?: null,
                'contact_number' => $contact ?: null,
                'is_active'      => true,
            ]);

            AuditLog::create([
                'user_id'    => Auth::id(),
                'action'     => 'created branch',
                'table_name' => 'branches',
                'record_id'  => $branch->id,
                'details'    => "Name: {$branchName}, Type: {$branchType}",
                'ip_address' => $request->ip(),
            ]);

            return response()->json(['ok' => true, 'message' => 'Branch created successfully.', 'id' => $branch->id]);
        }
    }

    /**
     * Toggle branch active/inactive.
     */
    protected function toggleBranch(Request $request): JsonResponse
    {
        $id       = (int) $request->input('id', 0);
        $isActive = (int) $request->input('is_active', 0);

        if ($id <= 0) {
            return response()->json(['ok' => false, 'error' => 'Invalid branch ID.'], 422);
        }

        // Prevent deactivating the last active main branch
        if ($isActive === 0) {
            $branch = Branch::find($id);
            if ($branch && $branch->branch_type === 'main') {
                $otherMainCount = Branch::where('branch_type', 'main')
                    ->where('is_active', 1)
                    ->where('id', '!=', $id)
                    ->count();

                if ($otherMainCount === 0) {
                    return response()->json(['ok' => false, 'error' => 'Cannot deactivate the only main branch (commissary).'], 422);
                }
            }
        }

        Branch::where('id', $id)->update(['is_active' => $isActive]);

        $statusLabel = $isActive ? 'activated' : 'deactivated';

        AuditLog::create([
            'user_id'    => Auth::id(),
            'action'     => "{$statusLabel} branch",
            'table_name' => 'branches',
            'record_id'  => $id,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['ok' => true, 'message' => "Branch {$statusLabel} successfully."]);
    }
}
