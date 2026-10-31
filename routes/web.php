<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\ForecastController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderingController;
use App\Http\Controllers\OrderPaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QrController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Hidden Vietnam Franchise Restaurant Management System (HVFRMS)
|
| Role constants used in middleware:
|   super_admin, owner, branch_manager, branch_staff, main_branch_manager, main_branch_staff
|
*/

// -------------------------------------------------------------------------
// Guest routes — redirect to dashboard if already authenticated
// -------------------------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'showLoginForm'])
        ->name('login');
    Route::post('login', [LoginController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login.submit');

    Route::get('forgot-password', [ForgotPasswordController::class, 'showForm'])
        ->name('password.request');
    Route::post('forgot-password', [ForgotPasswordController::class, 'sendResetLink'])
        ->middleware('throttle:password-reset')
        ->name('password.email');

    Route::get('reset-password/{token}', [ResetPasswordController::class, 'showForm'])
        ->name('password.reset');
    Route::post('reset-password', [ResetPasswordController::class, 'reset'])
        ->middleware('throttle:password-reset')
        ->name('password.update');
});

// -------------------------------------------------------------------------
// Authenticated routes — require auth + idle timeout + branch scoping
// -------------------------------------------------------------------------
Route::middleware(['auth', 'idle.timeout', 'branch.scope'])->group(function () {

    // Logout (POST only)
    Route::post('logout', [LoginController::class, 'logout'])
        ->name('logout');

    // Root redirect — role-aware landing page
    Route::get('/', function () {
        $user = auth()->user();
        if ($user && $user->isBranchStaff()) {
            return redirect()->route('qr');
        }
        if ($user && $user->isCommissaryStaff()) {
            return redirect()->route('stock');
        }

        return redirect()->route('dashboard');
    });

    // ---------------------------------------------------------------
    // Dashboard — all management roles + warehouse roles
    // ---------------------------------------------------------------
    Route::middleware('role:super_admin,owner,admin,branch_manager,commissary_manager,commissary_staff')->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index'])
            ->name('dashboard');
    });

    // ---------------------------------------------------------------
    // Inventory-management roles (super_admin, owner, branch_manager, main_branch_manager)
    // ---------------------------------------------------------------
    // Notification endpoints — accessible to all roles that see the bell
    Route::middleware('role:super_admin,owner,admin,branch_manager,commissary_manager,commissary_staff')->group(function () {
        Route::get('alerts/count', [AlertController::class, 'count'])
            ->name('alerts.count');
        Route::get('notifications', [AlertController::class, 'notifications'])
            ->name('notifications');
    });

    Route::middleware('role:super_admin,owner,admin,branch_manager,commissary_manager')->group(function () {

        // Inventory (branch managers view their own stock)
        Route::get('inventory', [InventoryController::class, 'index'])
            ->name('inventory');
        Route::post('inventory/api', [InventoryController::class, 'api'])
            ->name('inventory.api');

        Route::get('products', [WarehouseController::class, 'products'])
            ->middleware('role:super_admin,owner,admin,commissary_manager')
            ->name('products');

        // Ordering (branch managers only)
        Route::get('ordering', [OrderingController::class, 'index'])
            ->name('ordering');
        Route::post('ordering/api', [OrderingController::class, 'api'])
            ->name('ordering.api');

        // Forecast
        Route::get('forecast', [ForecastController::class, 'index'])
            ->name('forecast');
        Route::post('forecast', [ForecastController::class, 'index'])
            ->name('forecast.save');

        // Alerts
        Route::get('alerts', [AlertController::class, 'index'])
            ->name('alerts');
        Route::post('alerts', [AlertController::class, 'index'])
            ->name('alerts.action');
        // Reports
        Route::get('reports/export', [ReportController::class, 'export'])
            ->name('reports.export');
        Route::get('reports/archives', [ReportController::class, 'archives'])
            ->name('reports.archives');
        Route::post('reports/archive', [ReportController::class, 'archive'])
            ->name('reports.archive');
        Route::post('reports/archive/remove', [ReportController::class, 'archiveRemove'])
            ->name('reports.archiveRemove');
        Route::get('reports', [ReportController::class, 'index'])
            ->name('reports');

        // Stock movement history (traceability)
        Route::get('stock/history/{item}', [StockController::class, 'history'])
            ->name('stock.history');
        Route::get('stock/batch/{batch}', [StockController::class, 'batchDetail'])
            ->name('stock.batch');
    });

    Route::middleware('role:super_admin,owner,admin,branch_manager,branch_staff,commissary_manager,commissary_staff')->group(function () {
        // Stock (branch staff do stock-in/out scanning)
        Route::get('stock', [StockController::class, 'index'])
            ->name('stock');
        Route::post('stock/api', [StockController::class, 'api'])
            ->name('stock.api');

    });

    // ---------------------------------------------------------------
    // Orders & Delivery — accessible to warehouse roles too
    // ---------------------------------------------------------------
    Route::middleware('role:super_admin,owner,admin,branch_manager,commissary_manager,commissary_staff')->group(function () {
        Route::get('orders', [OrderController::class, 'index'])
            ->name('orders');
        Route::post('orders/api', [OrderController::class, 'api'])
            ->name('orders.api');
        Route::get('orders/{order}/proforma', [OrderPaymentController::class, 'proforma'])->name('orders.proforma');
        Route::get('orders/{order}/payment-screenshot', [OrderPaymentController::class, 'screenshot'])->name('orders.payment-screenshot');
        Route::get('payments/gcash-qr', [OrderPaymentController::class, 'receivingQr'])->name('payments.gcash-qr');
        Route::get('orders/{order}/labels', [OrderController::class, 'labels'])
            ->name('orders.labels');

        Route::get('delivery', [DeliveryController::class, 'index'])
            ->name('delivery');
        Route::get('delivery/history', [DeliveryController::class, 'history'])
            ->name('delivery.history');
    });

    // ---------------------------------------------------------------
    // Warehouse (main branch — super_admin, owner, main_branch_manager, main_branch_staff)
    // ---------------------------------------------------------------
    Route::middleware('role:super_admin,owner,admin,branch_manager,commissary_manager,commissary_staff')->group(function () {
        Route::get('warehouse', [WarehouseController::class, 'index'])
            ->name('warehouse');
        Route::get('warehouse/panel', [WarehouseController::class, 'panel'])
            ->name('warehouse.panel');
        Route::post('warehouse/api', [WarehouseController::class, 'api'])
            ->name('warehouse.api');
    });

    // ---------------------------------------------------------------
    // Owner-only routes (super_admin, owner)
    // ---------------------------------------------------------------
    Route::middleware('role:super_admin,owner,admin')->group(function () {

        // User management
        Route::get('users', [UserController::class, 'index'])
            ->name('users');
        Route::post('users/api', [UserController::class, 'api'])
            ->name('users.api');

        // Branch management
        Route::get('branches', [BranchController::class, 'index'])
            ->name('branches');
        Route::post('branches/api', [BranchController::class, 'api'])
            ->name('branches.api');

        // Settings
        Route::get('settings', [SettingsController::class, 'index'])
            ->name('settings');
        Route::post('settings', [SettingsController::class, 'update'])
            ->name('settings.update');

        Route::post('settings/gcash-qr', [OrderPaymentController::class, 'updateReceivingQr'])->name('settings.gcash-qr');

        // Database backup
        Route::post('backup', [SettingsController::class, 'backup'])
            ->name('backup');

        // Audit log
        Route::get('audit', [AuditController::class, 'index'])
            ->name('audit');
    });

    // ---------------------------------------------------------------
    // Profile (all authenticated users)
    // ---------------------------------------------------------------
    Route::get('profile', [ProfileController::class, 'index'])
        ->name('profile');
    Route::post('profile/photo', [ProfileController::class, 'updatePhoto'])
        ->name('profile.photo');
    Route::post('profile/password', [ProfileController::class, 'changePassword'])
        ->name('profile.password');

    // ---------------------------------------------------------------
    // QR Scanner (branch_staff, branch_manager, main_branch_manager, main_branch_staff)
    // ---------------------------------------------------------------
    Route::middleware('role:super_admin,owner,admin,branch_manager,branch_staff,commissary_manager,commissary_staff')->group(function () {
        Route::get('qr', [QrController::class, 'index'])
            ->name('qr');
        Route::post('qr/api', [QrController::class, 'api'])
            ->name('qr.api');
    });
});
