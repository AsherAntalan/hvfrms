<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Hidden Vietnam Franchise Restaurant Management System (HVFRMS)
|
| Role constants used in middleware:
|   super_admin, owner, branch_manager, it_staff
|
*/

// -------------------------------------------------------------------------
// Guest routes — redirect to dashboard if already authenticated
// -------------------------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('login', [\App\Http\Controllers\Auth\LoginController::class, 'showLoginForm'])
        ->name('login');
    Route::post('login', [\App\Http\Controllers\Auth\LoginController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login.submit');

    Route::get('forgot-password', [\App\Http\Controllers\Auth\ForgotPasswordController::class, 'showForm'])
        ->name('password.request');
    Route::post('forgot-password', [\App\Http\Controllers\Auth\ForgotPasswordController::class, 'sendResetLink'])
        ->middleware('throttle:password-reset')
        ->name('password.email');

    Route::get('reset-password/{token}', [\App\Http\Controllers\Auth\ResetPasswordController::class, 'showForm'])
        ->name('password.reset');
    Route::post('reset-password', [\App\Http\Controllers\Auth\ResetPasswordController::class, 'reset'])
        ->middleware('throttle:password-reset')
        ->name('password.update');
});

// -------------------------------------------------------------------------
// Authenticated routes — require auth + idle timeout + branch scoping
// -------------------------------------------------------------------------
Route::middleware(['auth', 'idle.timeout', 'branch.scope'])->group(function () {

    // Logout (POST only)
    Route::post('logout', [\App\Http\Controllers\Auth\LoginController::class, 'logout'])
        ->name('logout');

    // Root redirect
    Route::redirect('/', '/dashboard');

    // ---------------------------------------------------------------
    // Inventory-management roles (super_admin, owner, branch_manager)
    // ---------------------------------------------------------------
    Route::middleware('role:super_admin,owner,branch_manager')->group(function () {

        // Dashboard
        Route::get('dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])
            ->name('dashboard');

        // Inventory (branch managers view their own stock)
        Route::get('inventory', [\App\Http\Controllers\InventoryController::class, 'index'])
            ->name('inventory');
        Route::post('inventory/api', [\App\Http\Controllers\InventoryController::class, 'api'])
            ->name('inventory.api');

        // Stock
        Route::get('stock', [\App\Http\Controllers\StockController::class, 'index'])
            ->name('stock');
        Route::post('stock/api', [\App\Http\Controllers\StockController::class, 'api'])
            ->name('stock.api');

        // Warehouse (main branch only — controller enforces branch_type check)
        Route::get('warehouse', [\App\Http\Controllers\WarehouseController::class, 'index'])
            ->name('warehouse');
        Route::post('warehouse/api', [\App\Http\Controllers\WarehouseController::class, 'api'])
            ->name('warehouse.api');

        // Orders
        Route::get('orders', [\App\Http\Controllers\OrderController::class, 'index'])
            ->name('orders');
        Route::post('orders/api', [\App\Http\Controllers\OrderController::class, 'api'])
            ->name('orders.api');

        // Ordering
        Route::get('ordering', [\App\Http\Controllers\OrderingController::class, 'index'])
            ->name('ordering');
        Route::post('ordering/api', [\App\Http\Controllers\OrderingController::class, 'api'])
            ->name('ordering.api');

        // Forecast
        Route::get('forecast', [\App\Http\Controllers\ForecastController::class, 'index'])
            ->name('forecast');
        Route::post('forecast', [\App\Http\Controllers\ForecastController::class, 'index'])
            ->name('forecast.save');

        // Alerts
        Route::get('alerts', [\App\Http\Controllers\AlertController::class, 'index'])
            ->name('alerts');
        Route::post('alerts', [\App\Http\Controllers\AlertController::class, 'index'])
            ->name('alerts.action');
        Route::get('alerts/count', [\App\Http\Controllers\AlertController::class, 'count'])
            ->name('alerts.count');
        Route::get('notifications', [\App\Http\Controllers\AlertController::class, 'notifications'])
            ->name('notifications');

        // Reports
        Route::get('reports', [\App\Http\Controllers\ReportController::class, 'index'])
            ->name('reports');

        // Delivery
        Route::get('delivery', [\App\Http\Controllers\DeliveryController::class, 'index'])
            ->name('delivery');
        Route::get('delivery/history', [\App\Http\Controllers\DeliveryController::class, 'history'])
            ->name('delivery.history');
    });

    // ---------------------------------------------------------------
    // Owner-only routes (super_admin, owner)
    // ---------------------------------------------------------------
    Route::middleware('role:super_admin,owner')->group(function () {

        // User management
        Route::get('users', [\App\Http\Controllers\UserController::class, 'index'])
            ->name('users');
        Route::post('users/api', [\App\Http\Controllers\UserController::class, 'api'])
            ->name('users.api');

        // Branch management
        Route::get('branches', [\App\Http\Controllers\BranchController::class, 'index'])
            ->name('branches');
        Route::post('branches/api', [\App\Http\Controllers\BranchController::class, 'api'])
            ->name('branches.api');

        // Settings
        Route::get('settings', [\App\Http\Controllers\SettingsController::class, 'index'])
            ->name('settings');
        Route::post('settings', [\App\Http\Controllers\SettingsController::class, 'update'])
            ->name('settings.update');

        // Audit log
        Route::get('audit', [\App\Http\Controllers\AuditController::class, 'index'])
            ->name('audit');
    });

    // ---------------------------------------------------------------
    // Profile (all authenticated users)
    // ---------------------------------------------------------------
    Route::get('profile', [\App\Http\Controllers\ProfileController::class, 'index'])
        ->name('profile');
    Route::post('profile/photo', [\App\Http\Controllers\ProfileController::class, 'updatePhoto'])
        ->name('profile.photo');
    Route::post('profile/password', [\App\Http\Controllers\ProfileController::class, 'changePassword'])
        ->name('profile.password');

    // ---------------------------------------------------------------
    // QR Scanner (it_staff, branch_manager)
    // ---------------------------------------------------------------
    Route::middleware('role:super_admin,owner,branch_manager,it_staff')->group(function () {
        Route::get('qr', [\App\Http\Controllers\QrController::class, 'index'])
            ->name('qr');
        Route::post('qr/api', [\App\Http\Controllers\QrController::class, 'api'])
            ->name('qr.api');
    });
});
