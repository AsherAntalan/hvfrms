<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Item;
use App\Models\Order;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    /**
     * Show the inventory / products page.
     */
    public function index()
    {
        $user = Auth::user();
        if ($user->isOwner() || $user->isMainBranchManager()) {
            return app(WarehouseController::class)->index(request());
        }
        $isMgr = $user->isBranchScoped();
        $canManageProducts = $user->canManageCatalog();
        $canManageAvailability = $user->canManageProductAvailability();

        // Business-timezone "today" (Asia/Manila); matches StockService policy:
        // usable = positive qty, condition not damaged, expiry not past.
        $today = Carbon::today()->toDateString();

        // One stock-breakdown subquery, scoped to a branch or all branches so
        // the compared figures always share the same scope.
        $scope = $isMgr ? 'WHERE branch_id = '.(int) $user->branch_id : '';
        $breakdownSub = "(
            SELECT item_id,
                   SUM(quantity_left) AS physical_stock,
                   SUM(CASE WHEN quantity_left > 0
                             AND (`condition` IS NULL OR `condition` <> 'damaged')
                             AND (expiry_date IS NULL OR expiry_date >= '{$today}')
                            THEN quantity_left ELSE 0 END) AS usable_stock,
                   SUM(CASE WHEN quantity_left > 0 AND expiry_date IS NOT NULL AND expiry_date < '{$today}'
                            THEN quantity_left ELSE 0 END) AS expired_stock,
                   SUM(CASE WHEN quantity_left > 0 AND `condition` = 'damaged'
                            THEN quantity_left ELSE 0 END) AS damaged_stock,
                   MIN(CASE WHEN quantity_left > 0
                             AND (`condition` IS NULL OR `condition` <> 'damaged')
                             AND (expiry_date IS NULL OR expiry_date >= '{$today}')
                            THEN expiry_date END) AS nearest_expiry
            FROM stock_batches
            {$scope}
            GROUP BY item_id
        ) sb";

        $items = DB::table('items as i')
            ->leftJoin('categories as c', 'c.id', '=', 'i.category_id')
            ->leftJoin(DB::raw($breakdownSub), 'sb.item_id', '=', 'i.id')
            ->select(
                'i.*',
                'c.name as category_name',
                'c.goods_type',
                DB::raw('COALESCE(sb.physical_stock, 0) AS physical_stock'),
                DB::raw('COALESCE(sb.usable_stock, 0) AS usable_stock'),
                DB::raw('COALESCE(sb.expired_stock, 0) AS expired_stock'),
                DB::raw('COALESCE(sb.damaged_stock, 0) AS damaged_stock'),
                'sb.nearest_expiry'
            )
            ->orderBy('i.is_active', 'desc')
            ->orderBy('c.name')
            ->orderBy('i.item_name')
            ->get();

        $scopeLabel = $isMgr ? ($user->branch->branch_name ?? 'Your branch') : 'All branches';

        $categories = Category::orderBy('name')->get();

        // Branch Managers get a second "Movement History" tab: all stock
        // movements for their own branch, filtered and paginated server-side.
        $showMovements = $user->isBranchManager();
        $tab = ($showMovements && request()->query('tab') === 'movements') ? 'movements' : 'inventory';
        $movements = null;
        $movementFilters = ['search' => '', 'from' => '', 'to' => '', 'type' => '', 'category' => ''];
        $movementOrderRefs = [];
        if ($tab === 'movements') {
            [$movements, $movementFilters, $movementOrderRefs] = $this->branchMovements(request(), $user);
        }

        return view('inventory.index', compact(
            'items', 'categories', 'isMgr', 'scopeLabel', 'canManageProducts', 'canManageAvailability',
            'showMovements', 'tab', 'movements', 'movementFilters', 'movementOrderRefs'
        ));
    }

    /**
     * Build the branch-scoped, filtered, paginated movement history.
     *
     * Access is hard-scoped to the authenticated Branch Manager's own branch;
     * any branch identifier in the request is ignored. Quantities keep their
     * stored sign, so no sign is recomputed here.
     *
     * @return array{0: LengthAwarePaginator, 1: array<string, string>, 2: array<int, array{number: string, id: int}>}
     */
    private function branchMovements(Request $request, User $user): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'type' => ['nullable', Rule::in(['in', 'out', 'adjustment', 'expired', 'damaged'])],
            'category' => ['nullable', 'integer', 'exists:categories,id'],
        ]);

        $timezone = config('app.timezone');

        $query = StockTransaction::query()
            ->where('stock_transactions.branch_id', $user->branch_id)
            ->with(['item:id,item_name,unit,category_id,is_active', 'performer:id,full_name']);

        if (! empty($validated['type'])) {
            $query->where('stock_transactions.type', $validated['type']);
        }
        if (! empty($validated['category'])) {
            $query->whereHas('item', fn ($item) => $item->where('category_id', (int) $validated['category']));
        }
        if (! empty($validated['from'])) {
            $query->where('stock_transactions.created_at', '>=', Carbon::parse($validated['from'], $timezone)->startOfDay());
        }
        if (! empty($validated['to'])) {
            // Include the full selected end date in the application timezone.
            $query->where('stock_transactions.created_at', '<=', Carbon::parse($validated['to'], $timezone)->endOfDay());
        }
        if (! empty($validated['search'])) {
            $term = trim($validated['search']);
            $like = '%'.$term.'%';
            $batchId = ctype_digit(ltrim($term, '#')) ? (int) ltrim($term, '#') : null;
            $query->where(function ($outer) use ($like, $batchId) {
                $outer->whereHas('item', fn ($item) => $item->where('item_name', 'like', $like))
                    ->orWhere('stock_transactions.reason', 'like', $like)
                    ->orWhereHas('batch', fn ($batch) => $batch->where('qr_code', 'like', $like));
                if ($batchId !== null) {
                    $outer->orWhere('stock_transactions.batch_id', $batchId);
                }
            });
        }

        $movements = $query
            ->orderByDesc('stock_transactions.created_at')
            ->orderByDesc('stock_transactions.id')
            ->paginate(10)
            ->withQueryString();

        $orderRefs = $this->resolveOrderReferences($movements->getCollection(), (int) $user->branch_id);

        $filters = [
            'search' => $validated['search'] ?? '',
            'from' => $validated['from'] ?? '',
            'to' => $validated['to'] ?? '',
            'type' => $validated['type'] ?? '',
            'category' => $validated['category'] ?? '',
        ];

        return [$movements, $filters, $orderRefs];
    }

    /**
     * Resolve reliable order references for the visible movements only.
     *
     * An order number embedded in a transaction reason is shown and linked only
     * when a real order with that exact number exists in the same branch, so an
     * unreliable text match is never turned into a fabricated link.
     *
     * @param  Collection<int, StockTransaction>  $transactions
     * @return array<int, array{number: string, id: int}>
     */
    private function resolveOrderReferences(Collection $transactions, int $branchId): array
    {
        $candidates = [];
        foreach ($transactions as $transaction) {
            if ($transaction->reason && preg_match('/ORD-[A-Za-z0-9-]+/', $transaction->reason, $matches)) {
                $candidates[$transaction->id] = $matches[0];
            }
        }
        if ($candidates === []) {
            return [];
        }

        $orders = Order::whereIn('order_number', array_unique(array_values($candidates)))
            ->where('branch_id', $branchId)
            ->pluck('id', 'order_number');

        $references = [];
        foreach ($candidates as $transactionId => $orderNumber) {
            if ($orders->has($orderNumber)) {
                $references[$transactionId] = ['number' => $orderNumber, 'id' => (int) $orders[$orderNumber]];
            }
        }

        return $references;
    }

    /**
     * Handle AJAX API actions: save, toggle.
     */
    public function api(Request $request): JsonResponse
    {
        $action = $request->input('action');

        if ($action === 'save') {
            if (! Auth::user()->canManageCatalog()) {
                return response()->json(['ok' => false, 'error' => 'Only the Owner or Commissary Manager can add or edit products.'], 403);
            }

            return $this->saveItem($request);
        }

        if ($action === 'toggle') {
            return $this->toggleItem($request);
        }

        return response()->json(['ok' => false, 'error' => 'Unknown action.'], 400);
    }

    /**
     * Create or update a product.
     */
    protected function saveItem(Request $request): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        if (Item::whereKey($id)->whereNotNull('archived_at')->exists()) {
            return response()->json(['ok' => false, 'error' => 'Restore the archived product before editing it.'], 422);
        }
        $itemName = trim($request->input('item_name', ''));
        $categoryId = (int) $request->input('category_id', 0);
        $unit = trim($request->input('unit', 'pack'));
        $packDesc = trim($request->input('pack_description', ''));
        $price = (float) $request->input('price', 0);
        $reorderLevel = (int) $request->input('reorder_level', 10);
        $shelfLifeDays = $request->input('shelf_life_days', '');
        $request->validate(['shelf_life_months' => ['nullable', 'integer', 'min:1', 'max:120']]);
        $remarks = trim($request->input('remarks', ''));

        // Validation
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

        // Verify category exists
        if (! Category::find($categoryId)) {
            return response()->json(['ok' => false, 'error' => 'Invalid category selected.'], 422);
        }

        // Check duplicate name
        $dupQuery = Item::where('item_name', $itemName);
        if ($id > 0) {
            $dupQuery->where('id', '!=', $id);
        }
        if ($dupQuery->exists()) {
            return response()->json(['ok' => false, 'error' => 'A product with that name already exists.'], 422);
        }

        // Handle image upload
        $imagePath = $this->handleImageUpload($request);

        try {
            if ($id > 0) {
                // Update
                $item = Item::findOrFail($id);
                $data = [
                    'item_name' => $itemName,
                    'category_id' => $categoryId,
                    'unit' => $unit,
                    'pack_description' => $packDesc ?: null,
                    'price' => $price,
                    'reorder_level' => $reorderLevel,
                    'shelf_life_days' => $shelfLifeInt,
                    'shelf_life_months' => $request->input('shelf_life_months') ?: null,
                    'remarks' => $remarks ?: null,
                ];

                if ($imagePath !== null) {
                    $data['image_path'] = $imagePath;
                }

                $item->update($data);

                AuditLog::create([
                    'user_id' => Auth::id(),
                    'action' => 'updated product',
                    'table_name' => 'items',
                    'record_id' => $id,
                    'details' => "Name: {$itemName}, Price: {$price}, Shelf life: ".($shelfLifeInt ?? 'none'),
                ]);

                return response()->json(['ok' => true, 'message' => 'Product updated successfully.']);

            } else {
                // Create
                $item = Item::create([
                    'item_name' => $itemName,
                    'category_id' => $categoryId,
                    'unit' => $unit,
                    'pack_description' => $packDesc ?: null,
                    'price' => $price,
                    'image_path' => $imagePath,
                    'reorder_level' => $reorderLevel,
                    'shelf_life_days' => $shelfLifeInt,
                    'shelf_life_months' => $request->input('shelf_life_months') ?: null,
                    'remarks' => $remarks ?: null,
                    'is_active' => true,
                ]);

                // Auto-generate QR code
                $qr = $this->generateQrCode($itemName, $item->id);
                $item->update(['qr_code' => $qr]);

                AuditLog::create([
                    'user_id' => Auth::id(),
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

    /**
     * Activate or deactivate a product.
     */
    protected function toggleItem(Request $request): JsonResponse
    {
        if (! Auth::user()->canManageProductAvailability()) {
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

        return DB::transaction(function () use ($data, $isActive) {
            $item = Item::whereKey($data['id'])->whereNull('archived_at')->lockForUpdate()->first();

            if (! $item) {
                return response()->json(['ok' => false, 'error' => 'Product not found.'], 404);
            }

            if ($item->is_active !== $isActive) {
                $item->update(['is_active' => $isActive]);

                AuditLog::create([
                    'user_id' => Auth::id(),
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

    /**
     * Handle image upload, returning the relative path or null.
     */
    protected function handleImageUpload(Request $request): ?string
    {
        if (! $request->hasFile('image') || ! $request->file('image')->isValid()) {
            return null;
        }

        $file = $request->file('image');

        // Validate size (max 2MB)
        if ($file->getSize() > 2 * 1024 * 1024) {
            abort(response()->json(['ok' => false, 'error' => 'Image must be 2MB or smaller.'], 422));
        }

        // Validate type
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

    /**
     * Generate a QR code string for an item.
     */
    protected function generateQrCode(string $itemName, int $itemId): string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', substr($itemName, 0, 10)));

        return 'HV-'.$clean.'-'.str_pad($itemId, 4, '0', STR_PAD_LEFT);
    }
}
