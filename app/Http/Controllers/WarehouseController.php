<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\ExpiredBatchArchive;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Models\User;
use App\Services\StockService;
use DateTime;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WarehouseController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $mainBranch = Branch::commissary();
        $mainBid = (int) ($mainBranch?->id ?? 0);
        if (! $user->isOwner() && ! $user->isMainBranchRole() && (int) $user->branch_id !== $mainBid) {
            abort(403, 'This module is only available to Main Branch personnel.');
        }
        $monitorAllBranches = $user->isOwner() || $user->isMainBranchManager();
        $scopeLabel = $monitorAllBranches ? 'All branches' : ($mainBranch?->branch_name ?? 'Main Branch');
        $today = now()->toDateString();
        $products = Item::where('is_active', true)->orderBy('item_name')->get(['id', 'item_name', 'unit', 'shelf_life_days']);
        $catalog = $this->catalogData();
        $categories = $catalog['categories'];
        $allItems = $catalog['allItems'];
        $items = $allItems;

        $batchQuery = DB::table('stock_batches as sb')->join('items as i', 'i.id', '=', 'sb.item_id')
            ->join('branches as b', 'b.id', '=', 'sb.branch_id')->leftJoin('categories as c', 'c.id', '=', 'i.category_id')
            ->leftJoin('users as u', 'u.id', '=', 'sb.created_by')->where('sb.quantity_left', '>', 0)
            ->when(! $monitorAllBranches, fn ($query) => $query->where('sb.branch_id', $mainBid))
            ->select('sb.id', 'sb.branch_id', 'b.branch_name', 'sb.item_id', 'i.item_name', 'i.unit', 'i.reorder_level',
                'c.name as category_name', 'c.goods_type', 'sb.quantity_in', 'sb.quantity_left', 'sb.expiry_date', 'sb.condition', 'sb.qr_code', 'sb.received_at', 'u.full_name');
        $activeBatches = (clone $batchQuery)->where(fn ($query) => $query->whereNull('sb.condition')->orWhere('sb.condition', '<>', 'damaged'))
            ->where(fn ($query) => $query->whereNull('sb.expiry_date')->orWhere('sb.expiry_date', '>=', $today))
            ->orderBy('i.item_name')->orderBy('sb.expiry_date')->get();
        $expiredBatches = (clone $batchQuery)->whereNotNull('sb.expiry_date')->where('sb.expiry_date', '<', $today)->orderByDesc('sb.expiry_date')->get();
        // Hide batches that have already been archived (reporting archive, 30-day
        // retention) so archiving removes them from the active Expired list.
        $archivedBatchIds = ExpiredBatchArchive::visible()->pluck('batch_id')->all();
        if (! empty($archivedBatchIds)) {
            $expiredBatches = $expiredBatches->whereNotIn('id', $archivedBatchIds)->values();
        }
        $pendingOrders = Order::select('orders.*', 'branches.branch_name', 'u.full_name as ordered_by_name')
            ->join('branches', 'branches.id', '=', 'orders.branch_id')->leftJoin('users as u', 'u.id', '=', 'orders.ordered_by')
            ->whereIn('orders.status', ['pending', 'approved', 'preparing', 'delivering'])
            ->when(! $monitorAllBranches, fn ($query) => $query->where('orders.branch_id', $mainBid))->orderByDesc('orders.created_at')->get();
        $orderItems = [];
        $rows = OrderItem::select('order_items.*', 'items.item_name', 'items.unit')->join('items', 'items.id', '=', 'order_items.item_id')
            ->whereIn('order_items.order_id', $pendingOrders->pluck('id'))->orderBy('order_items.id')->get();
        foreach ($rows as $row) {
            $orderItems[$row->order_id][] = $row;
        }
        $disposalRecords = DB::table('stock_transactions as st')->join('items as i', 'i.id', '=', 'st.item_id')
            ->join('branches as b', 'b.id', '=', 'st.branch_id')->leftJoin('users as u', 'u.id', '=', 'st.performed_by')
            ->leftJoin('stock_batches as sb', 'sb.id', '=', 'st.batch_id')->whereIn('st.type', ['expired', 'damaged', 'adjustment'])->where('st.quantity', '<', 0)
            ->when(! $monitorAllBranches, fn ($query) => $query->where('st.branch_id', $mainBid)->limit(50))
            ->select('st.id', 'st.branch_id', 'b.branch_name', 'st.item_id', 'i.item_name', 'i.unit', 'st.type', 'st.quantity', 'st.reason', 'st.remarks',
                'st.created_at', 'u.full_name as performed_by_name', 'sb.qr_code as batch_qr', 'sb.expiry_date as batch_expiry')->orderByDesc('st.created_at')->get();
        // The Commissary is a make-to-order production source, not a stock-holding
        // branch — exclude it from the inventory monitor so it never shows as a row.
        $inventoryBranches = Branch::query()->where('branch_type', '!=', 'commissary')
            ->when(! $monitorAllBranches, fn ($query) => $query->whereKey($mainBid))
            ->orderByRaw("CASE WHEN branch_type = 'main' THEN 0 ELSE 1 END")->orderBy('branch_name')->get();
        $branchSummaries = $inventoryBranches->map(function ($branch) use ($activeBatches, $expiredBatches, $pendingOrders, $disposalRecords) {
            $active = $activeBatches->where('branch_id', $branch->id)->values();
            $expired = $expiredBatches->where('branch_id', $branch->id)->values();
            $orders = $pendingOrders->where('branch_id', $branch->id)->values();
            $disposal = $disposalRecords->where('branch_id', $branch->id)->values();

            return ['branch' => $branch, 'active' => $active, 'expired' => $expired, 'orders' => $orders, 'disposal' => $disposal,
                'active_quantities' => $this->quantitiesByUnit($active, 'quantity_left'), 'expired_quantities' => $this->quantitiesByUnit($expired, 'quantity_left'),
                'disposal_quantities' => $this->quantitiesByUnit($disposal, 'quantity', true),
                'low_stock' => $active->groupBy('item_id')->filter(fn ($batches) => $batches->sum('quantity_left') <= $batches->first()->reorder_level)->count()];
        });
        $totalStock = $activeBatches->sum('quantity_left');
        $productCount = $products->count();
        $lowStockCount = $activeBatches->groupBy(fn ($batch) => $batch->branch_id.':'.$batch->item_id)
            ->filter(fn ($batches) => $batches->sum('quantity_left') <= $batches->first()->reorder_level)->count();
        $isWarehouseStaff = $user->isMainBranchStaff();
        $isWarehouseAdmin = $user->isMainBranchManager();
        $canManageProducts = $user->canManageCatalog();
        $canManageAvailability = $user->canManageProductAvailability();
        $canEditBatches = ! $isWarehouseStaff;
        $canFulfillOrders = $user->isOwner() || $user->isMainBranchManager();
        $canDisposeBatches = true;
        $canArchiveBatches = $user->canManageInventory();

        return view('warehouse.index', compact('products', 'categories', 'allItems', 'items', 'activeBatches', 'expiredBatches', 'pendingOrders', 'orderItems', 'disposalRecords',
            'totalStock', 'productCount', 'lowStockCount', 'mainBid', 'isWarehouseStaff', 'isWarehouseAdmin', 'canManageProducts', 'canManageAvailability', 'canEditBatches',
            'canFulfillOrders', 'canDisposeBatches', 'canArchiveBatches', 'monitorAllBranches', 'branchSummaries', 'scopeLabel'));
    }

    public function products(): View
    {
        $user = Auth::user();
        abort_unless($user->canManageCatalog() || $user->canManageProductAvailability(), 403);

        return view('products.index', array_merge($this->catalogData(), [
            'canManageProducts' => $user->canManageCatalog(),
            'canManageAvailability' => $user->canManageProductAvailability(),
        ]));
    }

    /** Shared catalog and stock breakdown across all locations. */
    private function catalogData(): array
    {
        $today = now()->toDateString();
        $categories = Category::orderBy('name')->get();
        $stockBreakdown = DB::table('stock_batches')->select('item_id')->selectRaw(
            'SUM(quantity_left) AS physical_stock,
             SUM(CASE WHEN quantity_left > 0 AND (`condition` IS NULL OR `condition` <> ?) AND (expiry_date IS NULL OR expiry_date >= ?) THEN quantity_left ELSE 0 END) AS usable_stock,
             SUM(CASE WHEN quantity_left > 0 AND expiry_date IS NOT NULL AND expiry_date < ? THEN quantity_left ELSE 0 END) AS expired_stock,
             SUM(CASE WHEN quantity_left > 0 AND `condition` = ? THEN quantity_left ELSE 0 END) AS damaged_stock',
            ['damaged', $today, $today, 'damaged']
        )->groupBy('item_id');
        $allItems = DB::table('items as i')->leftJoin('categories as c', 'c.id', '=', 'i.category_id')
            ->leftJoinSub($stockBreakdown, 's', 's.item_id', '=', 'i.id')
            ->select('i.*', 'c.name as category_name', 'c.goods_type',
                DB::raw('COALESCE(s.physical_stock,0) AS physical_stock'), DB::raw('COALESCE(s.physical_stock,0) AS total_stock'),
                DB::raw('COALESCE(s.usable_stock,0) AS usable_stock'), DB::raw('COALESCE(s.expired_stock,0) AS expired_stock'), DB::raw('COALESCE(s.damaged_stock,0) AS damaged_stock'))
            ->orderByDesc('i.is_active')->orderBy('c.name')->orderBy('i.item_name')->get();

        return compact('categories', 'allItems');
    }

    private function quantitiesByUnit(Collection $rows, string $field, bool $absolute = false): string
    {
        return $rows->groupBy('unit')->map(function ($records, $unit) use ($field, $absolute) {
            $quantity = $records->sum(fn ($record) => $absolute ? abs((int) $record->{$field}) : (int) $record->{$field});

            return number_format($quantity).' '.$unit;
        })->implode(', ') ?: '0';
    }

    public function api(Request $request): JsonResponse
    {
        $user = Auth::user();
        $action = $request->input('action');

        $mainBranch = Branch::commissary();
        $mainBid = $mainBranch ? $mainBranch->id : 0;

        if (! $user->isOwner() && ! $user->isWarehouseRole() && $user->branch_id !== $mainBid) {
            return response()->json(['ok' => false, 'error' => 'Access denied.'], 403);
        }

        // Warehouse staff can only: create_stock (receive), dispose_batch
        if ($user->isWarehouseStaff()) {
            if (! in_array($action, ['create_stock', 'dispose_batch'])) {
                return response()->json(['ok' => false, 'error' => 'You do not have permission for this action.'], 403);
            }
        }

        if ($action === 'create_stock') {
            return $this->createStock($request, $mainBid, $user);
        }

        if ($action === 'adjust_batch') {
            return $this->adjustBatch($request, $mainBid, $user);
        }

        if ($action === 'void_batch') {
            return $this->voidBatch($request, $mainBid, $user);
        }

        // Legacy aliases
        if ($action === 'edit_batch') {
            return $this->adjustBatch($request, $mainBid, $user);
        }

        if ($action === 'delete_batch') {
            return $this->voidBatch($request, $mainBid, $user);
        }

        if ($action === 'fulfill_order') {
            $request->merge(['action' => 'update_status', 'id' => $request->input('order_id')]);

            return app(OrderController::class)->api($request);
        }

        if ($action === 'save_product') {
            if (! $user->isOwner()) {
                return response()->json(['ok' => false, 'error' => 'Only owners can manage products.'], 403);
            }

            return $this->saveProduct($request, $user);
        }

        if ($action === 'toggle_product') {
            return $this->toggleProduct($request, $user);
        }

        if ($action === 'dispose_batch') {
            return $this->disposeBatch($request, $mainBid, $user);
        }

        return response()->json(['ok' => false, 'error' => 'Unknown action.'], 400);
    }

    protected function createStock(Request $request, int $mainBid, $user): JsonResponse
    {
        $itemId = (int) $request->input('item_id', 0);
        $quantity = (int) $request->input('quantity', 0);
        $expiryDate = trim($request->input('expiry_date', ''));
        $remarks = trim($request->input('remarks', ''));

        if ($itemId <= 0) {
            return response()->json(['ok' => false, 'error' => 'Please select a product.'], 422);
        }
        if ($quantity <= 0) {
            return response()->json(['ok' => false, 'error' => 'Quantity must be at least 1.'], 422);
        }

        $item = Item::where('id', $itemId)->where('is_active', true)->first();
        if (! $item) {
            return response()->json(['ok' => false, 'error' => 'Product not found.'], 422);
        }

        $parsedExpiry = null;
        if ($expiryDate !== '') {
            $parsed = DateTime::createFromFormat('Y-m-d', $expiryDate);
            if (! $parsed) {
                return response()->json(['ok' => false, 'error' => 'Invalid expiry date.'], 422);
            }
            $parsedExpiry = $parsed->format('Y-m-d');
        } elseif ($item->shelf_life_days) {
            $d = new DateTime;
            $d->modify('+'.(int) $item->shelf_life_days.' days');
            $parsedExpiry = $d->format('Y-m-d');
        }

        $stockService = new StockService;
        $result = $stockService->stockIn(
            $itemId, $mainBid, $quantity, $user->id,
            $parsedExpiry, $remarks ?: 'Stock received (warehouse)', null
        );

        return response()->json($result);
    }

    protected function adjustBatch(Request $request, int $mainBid, $user): JsonResponse
    {
        if ($user->isWarehouseStaff()) {
            return response()->json(['ok' => false, 'error' => 'You do not have permission to adjust batches.'], 403);
        }

        $batchId = (int) $request->input('batch_id', 0);
        $newQty = (int) $request->input('quantity_left', 0);
        $reason = trim($request->input('reason', 'Physical Count Correction'));
        $remarks = trim($request->input('remarks', ''));

        $batch = StockBatch::where('id', $batchId)->where('branch_id', $mainBid)->first();
        if (! $batch) {
            return response()->json(['ok' => false, 'error' => 'Batch not found.'], 422);
        }

        if ($newQty < 0) {
            return response()->json(['ok' => false, 'error' => 'Quantity cannot be negative.'], 422);
        }

        $oldQty = (int) $batch->quantity_left;
        $diff = $newQty - $oldQty;

        if ($diff === 0) {
            return response()->json(['ok' => true, 'message' => 'No quantity change.']);
        }

        $validReasons = [
            'Physical Count Correction', 'Damaged', 'Spoilage',
            'Expired', 'Missing', 'Encoding Error', 'Other',
        ];
        if (! in_array($reason, $validReasons, true)) {
            $reason = 'Other';
        }

        $item = Item::find($batch->item_id);
        $itemName = $item ? $item->item_name : 'Unknown';

        DB::beginTransaction();
        try {
            StockTransaction::create([
                'item_id' => $batch->item_id,
                'branch_id' => $mainBid,
                'batch_id' => $batch->id,
                'type' => 'adjustment',
                'quantity' => $diff,
                'reason' => $reason,
                'remarks' => $remarks ?: "Adjusted by {$user->full_name}: {$oldQty} → {$newQty}",
                'performed_by' => $user->id,
            ]);

            $batch->update(['quantity_left' => $newQty]);

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'stock adjustment',
                'table_name' => 'stock_batches',
                'record_id' => $batchId,
                'details' => "Item: {$itemName}, Qty: {$oldQty} → {$newQty} (diff: {$diff}), Reason: {$reason}",
            ]);

            DB::commit();

            $direction = $diff > 0 ? 'increased' : 'decreased';

            return response()->json([
                'ok' => true,
                'message' => "Batch adjusted: {$itemName} {$direction} by ".abs($diff).'. Transaction recorded.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['ok' => false, 'error' => 'Failed to process adjustment. Please try again.'], 500);
        }
    }

    protected function voidBatch(Request $request, int $mainBid, $user): JsonResponse
    {
        if (! $user->isOwner()) {
            return response()->json(['ok' => false, 'error' => 'Only owners can void batches.'], 403);
        }

        $batchId = (int) $request->input('batch_id', 0);
        $remarks = trim($request->input('remarks', ''));

        $batch = StockBatch::where('id', $batchId)->where('branch_id', $mainBid)->first();
        if (! $batch) {
            return response()->json(['ok' => false, 'error' => 'Batch not found.'], 422);
        }

        $item = Item::find($batch->item_id);
        $itemName = $item ? $item->item_name : 'Unknown';
        $remainingQty = (int) $batch->quantity_left;

        DB::beginTransaction();
        try {
            if ($remainingQty > 0) {
                StockTransaction::create([
                    'item_id' => $batch->item_id,
                    'branch_id' => $mainBid,
                    'batch_id' => $batch->id,
                    'type' => 'adjustment',
                    'quantity' => -$remainingQty,
                    'reason' => 'Batch voided',
                    'remarks' => $remarks ?: "Voided by {$user->full_name}",
                    'performed_by' => $user->id,
                ]);

                $batch->update(['quantity_left' => 0]);
            }

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'voided batch',
                'table_name' => 'stock_batches',
                'record_id' => $batchId,
                'details' => "Item: {$itemName}, Qty zeroed: {$remainingQty}. Batch and history preserved.",
            ]);

            DB::commit();

            return response()->json([
                'ok' => true,
                'message' => "Batch voided. {$remainingQty} {$item->unit}(s) removed from usable stock. History preserved.",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['ok' => false, 'error' => 'Failed to void batch. Please try again.'], 500);
        }
    }

    /**
     * Dispose of an expired batch.
     * Both main_branch_manager and main_branch_staff can initiate, but it records a transaction.
     */
    protected function disposeBatch(Request $request, int $mainBid, $user): JsonResponse
    {
        $batchId = (int) $request->input('batch_id', 0);
        $remarks = trim($request->input('remarks', ''));

        $batch = StockBatch::find($batchId);
        if (! $batch) {
            return response()->json(['ok' => false, 'error' => 'Batch not found.'], 422);
        }

        // Dispose the batch against its OWN branch. Owner/Main Branch roles may
        // dispose any branch's batch from the monitor; anyone else only their own.
        if (! $user->isOwner() && ! $user->isMainBranchRole() && (int) $batch->branch_id !== (int) $user->branch_id) {
            return response()->json(['ok' => false, 'error' => 'You are not authorized to dispose this branch\'s stock.'], 403);
        }

        if ($batch->quantity_left <= 0) {
            return response()->json(['ok' => false, 'error' => 'Batch has no remaining stock to dispose.'], 422);
        }

        $item = Item::find($batch->item_id);
        $itemName = $item ? $item->item_name : 'Unknown';
        $qty = $batch->quantity_left;

        // Check if batch is actually expired (expired only once past its date)
        $isExpired = StockService::isExpired(
            $batch->expiry_date ? $batch->expiry_date->format('Y-m-d') : null
        );
        $reason = $isExpired ? 'Expired stock disposal' : 'Stock disposal (manual)';

        DB::beginTransaction();
        try {
            // Record the disposal transaction
            StockTransaction::create([
                'item_id' => $batch->item_id,
                'branch_id' => $batch->branch_id,
                'batch_id' => $batch->id,
                'type' => $isExpired ? 'expired' : 'adjustment',
                'quantity' => -$qty,
                'reason' => $reason,
                'remarks' => $remarks ?: 'Disposed by '.$user->full_name,
                'performed_by' => $user->id,
            ]);

            // Zero out the batch
            $batch->update(['quantity_left' => 0]);

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'disposed batch',
                'table_name' => 'stock_batches',
                'record_id' => $batchId,
                'details' => "Item: {$itemName}, Qty disposed: {$qty}, Reason: {$reason}",
            ]);

            DB::commit();

            return response()->json([
                'ok' => true,
                'message' => "Disposed {$qty} {$item->unit}(s) of {$itemName}. Transaction recorded.",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['ok' => false, 'error' => 'Failed to process disposal. Please try again.'], 500);
        }
    }

    protected function saveProduct(Request $request, $user): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        $itemName = trim($request->input('item_name', ''));
        $categoryId = (int) $request->input('category_id', 0);
        $unit = trim($request->input('unit', 'pack'));
        $packDesc = trim($request->input('pack_description', ''));
        $price = (float) $request->input('price', 0);
        $reorderLevel = (int) $request->input('reorder_level', 10);
        $shelfLifeDays = $request->input('shelf_life_days', '');
        $remarks = trim($request->input('remarks', ''));

        if ($itemName === '') {
            return response()->json(['ok' => false, 'error' => 'Product name is required.'], 422);
        }
        if (strlen($itemName) > 150) {
            return response()->json(['ok' => false, 'error' => 'Product name must be 150 characters or less.'], 422);
        }
        if ($categoryId <= 0) {
            return response()->json(['ok' => false, 'error' => 'Please select a category.'], 422);
        }
        if ($unit === '') {
            return response()->json(['ok' => false, 'error' => 'Unit is required.'], 422);
        }
        if ($price < 0) {
            return response()->json(['ok' => false, 'error' => 'Price cannot be negative.'], 422);
        }
        if ($reorderLevel < 0) {
            $reorderLevel = 10;
        }

        $shelfLifeInt = ($shelfLifeDays !== '' && (int) $shelfLifeDays > 0) ? (int) $shelfLifeDays : null;

        if (! Category::find($categoryId)) {
            return response()->json(['ok' => false, 'error' => 'Invalid category selected.'], 422);
        }

        $dupQuery = Item::where('item_name', $itemName);
        if ($id > 0) {
            $dupQuery->where('id', '!=', $id);
        }
        if ($dupQuery->exists()) {
            return response()->json(['ok' => false, 'error' => 'A product with that name already exists.'], 422);
        }

        $imagePath = $this->handleImageUpload($request);

        try {
            if ($id > 0) {
                $item = Item::findOrFail($id);
                $data = [
                    'item_name' => $itemName,
                    'category_id' => $categoryId,
                    'unit' => $unit,
                    'pack_description' => $packDesc ?: null,
                    'price' => $price,
                    'reorder_level' => $reorderLevel,
                    'shelf_life_days' => $shelfLifeInt,
                    'remarks' => $remarks ?: null,
                ];
                if ($imagePath !== null) {
                    $data['image_path'] = $imagePath;
                }
                $item->update($data);

                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'updated product',
                    'table_name' => 'items',
                    'record_id' => $id,
                    'details' => "Name: {$itemName}, Price: {$price}, Shelf life: ".($shelfLifeInt ?? 'none'),
                ]);

                return response()->json(['ok' => true, 'message' => 'Product updated successfully.']);
            } else {
                $item = Item::create([
                    'item_name' => $itemName,
                    'category_id' => $categoryId,
                    'unit' => $unit,
                    'pack_description' => $packDesc ?: null,
                    'price' => $price,
                    'image_path' => $imagePath,
                    'reorder_level' => $reorderLevel,
                    'shelf_life_days' => $shelfLifeInt,
                    'remarks' => $remarks ?: null,
                    'is_active' => true,
                ]);

                $qr = 'HV-'.strtoupper(preg_replace('/[^A-Za-z0-9]/', '', substr($itemName, 0, 10))).'-'.str_pad($item->id, 4, '0', STR_PAD_LEFT);
                $item->update(['qr_code' => $qr]);

                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'created product',
                    'table_name' => 'items',
                    'record_id' => $item->id,
                    'details' => "Name: {$itemName}, Price: {$price}, Shelf life: ".($shelfLifeInt ?? 'none').", QR: {$qr}",
                ]);

                return response()->json(['ok' => true, 'message' => 'Product created successfully.', 'id' => $item->id]);
            }
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'error' => 'Database error saving product. Please try again.'], 500);
        }
    }

    protected function toggleProduct(Request $request, User $user): JsonResponse
    {
        if (! $user->canManageProductAvailability()) {
            return response()->json(['ok' => false, 'error' => 'Only the Owner or Main Branch Manager can change product availability.'], 403);
        }

        $validator = Validator::make($request->only(['id', 'is_active']), [
            'id' => ['required', 'integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'error' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();
        $isActive = (bool) $data['is_active'];

        return DB::transaction(function () use ($data, $isActive, $user) {
            $item = Item::whereKey($data['id'])->lockForUpdate()->first();

            if (! $item) {
                return response()->json(['ok' => false, 'error' => 'Product not found.'], 404);
            }

            if ($item->is_active !== $isActive) {
                $item->update(['is_active' => $isActive]);

                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => $isActive ? 'activated product' : 'deactivated product',
                    'table_name' => 'items',
                    'record_id' => $item->id,
                    'details' => $isActive ? 'Made available for commissary orders.' : 'Marked unavailable for commissary orders.',
                ]);
            }

            return response()->json([
                'ok' => true,
                'message' => $isActive ? 'Product is now available for orders.' : 'Product marked unavailable for orders.',
            ]);
        });
    }

    protected function handleImageUpload(Request $request): ?string
    {
        if (! $request->hasFile('image') || ! $request->file('image')->isValid()) {
            return null;
        }

        $file = $request->file('image');

        if ($file->getSize() > 2 * 1024 * 1024) {
            abort(response()->json(['ok' => false, 'error' => 'Image must be 2MB or smaller.'], 422));
        }

        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (! in_array($file->getMimeType(), $allowed, true)) {
            abort(response()->json(['ok' => false, 'error' => 'Only JPG, PNG, and WebP images are allowed.'], 422));
        }

        $ext = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $filename = 'item_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
        $file->move(public_path('uploads/items'), $filename);

        return 'uploads/items/'.$filename;
    }

    protected function fulfillOrder(Request $request, $user): JsonResponse
    {
        $orderId = (int) $request->input('order_id', 0);
        $newStatus = trim($request->input('status', ''));

        $order = Order::select('orders.*', 'branches.branch_name')
            ->join('branches', 'branches.id', '=', 'orders.branch_id')
            ->where('orders.id', $orderId)
            ->first();

        if (! $order) {
            return response()->json(['ok' => false, 'error' => 'Order not found.'], 422);
        }

        $allowed = [
            'pending' => ['approved', 'cancelled'],
            'approved' => ['delivering', 'cancelled'],
            'delivering' => ['delivered'],
        ];

        if (! in_array($newStatus, $allowed[$order->status] ?? [])) {
            return response()->json(['ok' => false, 'error' => "Cannot change from '{$order->status}' to '{$newStatus}'."], 422);
        }

        $mainBranch = Branch::commissary();
        $mainBid = $mainBranch ? $mainBranch->id : 0;

        // Load order items for stock validation
        $orderItems = OrderItem::select('order_items.*', 'items.item_name', 'items.unit', 'items.shelf_life_days')
            ->join('items', 'items.id', '=', 'order_items.item_id')
            ->where('order_items.order_id', $orderId)
            ->get();

        DB::beginTransaction();
        try {
            $updateData = ['status' => $newStatus];

            if ($newStatus === 'approved') {
                $updateData['approved_by'] = $user->id;
                $updateData['approved_at'] = now();
            } elseif ($newStatus === 'delivered') {
                $updateData['delivered_at'] = now();
            }

            Order::where('id', $orderId)->update($updateData);

            // On cancelled from approved: restore warehouse stock
            if ($newStatus === 'cancelled' && $order->status === 'approved') {
                $stockService = new StockService;
                $stockService->restoreOrderStock($orderId, $order->order_number, $mainBid, $user->id);
            }

            // On approved: validate and deduct warehouse stock using FIFO
            if ($newStatus === 'approved') {
                $stockService = new StockService;
                foreach ($orderItems as $oi) {
                    $result = $stockService->deductStockFifo(
                        $oi->item_id, $mainBid, $oi->quantity, $user->id,
                        "Order {$order->order_number} approved",
                        "Allocated for {$order->branch_name}"
                    );
                    if (! $result['ok']) {
                        DB::rollBack();

                        return response()->json($result, 422);
                    }
                }
            }

            // On delivered: auto stock-in at receiving branch preserving source expiry
            if ($newStatus === 'delivered') {
                foreach ($orderItems as $oi) {
                    $sourceTxs = StockTransaction::where('type', 'out')
                        ->where('item_id', $oi->item_id)
                        ->where('branch_id', $mainBid)
                        ->where('reason', "Order {$order->order_number} approved")
                        ->whereNotNull('batch_id')
                        ->get();

                    if ($sourceTxs->isEmpty()) {
                        $expiryDate = $oi->shelf_life_days
                            ? now()->addDays((int) $oi->shelf_life_days)->toDateString()
                            : null;
                        $sourceTxs = collect([['qty' => $oi->quantity, 'expiry' => $expiryDate]]);
                    } else {
                        $sourceTxs = $sourceTxs->map(function ($tx) {
                            $sourceBatch = StockBatch::find($tx->batch_id);

                            return [
                                'qty' => abs($tx->quantity),
                                'expiry' => $sourceBatch ? $sourceBatch->expiry_date : null,
                            ];
                        });
                    }

                    foreach ($sourceTxs as $src) {
                        $batch = StockBatch::create([
                            'item_id' => $oi->item_id,
                            'branch_id' => $order->branch_id,
                            'quantity_in' => $src['qty'],
                            'quantity_left' => $src['qty'],
                            'expiry_date' => $src['expiry'],
                            'received_at' => now(),
                            'created_by' => $user->id,
                        ]);

                        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', substr($oi->item_name, 0, 8)));
                        $expTag = $src['expiry'] ? str_replace('-', '', substr($src['expiry'], 5)) : 'NOEXP';
                        $batchQr = 'HV-'.$clean.'-'.$expTag.'-B'.str_pad($batch->id, 3, '0', STR_PAD_LEFT);
                        $batch->update(['qr_code' => $batchQr]);

                        StockTransaction::create([
                            'item_id' => $oi->item_id,
                            'branch_id' => $order->branch_id,
                            'batch_id' => $batch->id,
                            'type' => 'in',
                            'quantity' => $src['qty'],
                            'reason' => "Order {$order->order_number} delivered",
                            'remarks' => 'Fulfilled from warehouse',
                            'performed_by' => $user->id,
                        ]);
                    }
                }
            }

            DB::commit();

            AuditLog::create([
                'user_id' => $user->id,
                'action' => "order {$newStatus} (warehouse)",
                'table_name' => 'orders',
                'record_id' => $orderId,
                'details' => "Order {$order->order_number} → {$newStatus}, Branch: {$order->branch_name}",
            ]);

            $labels = [
                'approved' => 'approved',
                'delivering' => 'marked as delivering',
                'delivered' => 'delivered & stock added to branch',
                'cancelled' => 'cancelled',
            ];

            return response()->json([
                'ok' => true,
                'message' => "Order {$order->order_number} {$labels[$newStatus]}.",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['ok' => false, 'error' => 'Failed to process order. Please try again.'], 500);
        }
    }
}
