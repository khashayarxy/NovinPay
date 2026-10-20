<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LoginController;
use App\Http\Controllers\Admin\PasswordController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\RepairCaseController;
use App\Http\Controllers\Admin\RepairOverviewController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RepairRequestController;
use App\Http\Middleware\EnsureAdminSession;
use App\Http\Middleware\PrivateAdminResponse;
use App\Http\Middleware\PrivateRepairResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');

Route::get('/services', [PageController::class, 'services'])->name('services');
Route::middleware(PrivateRepairResponse::class)->group(function (): void {
    Route::get('/repair', [RepairRequestController::class, 'create'])->name('repair');
    Route::post('/repair', [RepairRequestController::class, 'store'])->middleware('throttle:repair-submit')->name('repair.store');
    Route::get('/repair/confirmation', [RepairRequestController::class, 'confirmation'])->name('repair.confirmation');
    Route::get('/repair/track', [RepairRequestController::class, 'tracking'])->name('repair.track');
    Route::post('/repair/track', [RepairRequestController::class, 'track'])->middleware('throttle:repair-track')->name('repair.track.store');
});
Route::get('/about', [PageController::class, 'about'])->name('about');
Route::get('/contact', [PageController::class, 'contact'])->name('contact');
Route::get('/terms', [PageController::class, 'terms'])->name('terms');
Route::get('/privacy', [PageController::class, 'privacy'])->name('privacy');

Route::middleware(['guest', PrivateAdminResponse::class])->group(function (): void {
    Route::get('/admin/login', [LoginController::class, 'create'])->name('admin.login');
    Route::post('/admin/login', [LoginController::class, 'store'])->name('admin.login.store');
});

Route::middleware([PrivateAdminResponse::class, 'auth'])->prefix('admin')->name('admin.')->group(function (): void {
    // Keep logout available even if administrator authorization is revoked.
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::middleware([EnsureAdminSession::class, 'can:access-admin'])->group(function (): void {
        Route::get('/', DashboardController::class)->middleware('can:dashboard.view')->name('dashboard');
        Route::get('account/password', [PasswordController::class, 'edit'])->name('password.edit');
        Route::put('account/password', [PasswordController::class, 'update'])->middleware('throttle:10,1')->name('password.update');

        Route::get('products', [AdminProductController::class, 'index'])->middleware('can:products.view')->name('products.index');
        Route::middleware('can:products.manage')->group(function (): void {
            Route::get('products/create', [AdminProductController::class, 'create'])->name('products.create');
            Route::post('products', [AdminProductController::class, 'store'])->name('products.store');
            Route::get('products/{product}/edit', [AdminProductController::class, 'edit'])->name('products.edit');
            Route::match(['put', 'patch'], 'products/{product}', [AdminProductController::class, 'update'])->name('products.update');
            Route::delete('products/{product}', [AdminProductController::class, 'destroy'])->name('products.destroy');
        });
        Route::resource('staff', StaffController::class)->only(['index', 'create', 'edit'])->middleware('can:staff.manage');
        Route::post('staff', [StaffController::class, 'store'])->middleware(['can:staff.manage', 'throttle:10,1'])->name('staff.store');
        Route::match(['put', 'patch'], 'staff/{staff}', [StaffController::class, 'update'])->middleware(['can:staff.manage', 'throttle:10,1'])->name('staff.update');
        Route::get('activities', [ActivityController::class, 'index'])->middleware('can:activity.view')->name('activities.index');
        Route::get('repair-requests', [RepairOverviewController::class, 'index'])->middleware('can:repairs.view')->name('repairs.index');
        Route::get('repair-requests/{repairRequest}', [RepairOverviewController::class, 'show'])->middleware('can:view,repairRequest')->name('repairs.show');
        Route::middleware('can:repairs.view')->group(function (): void {
            Route::get('repairs', [RepairCaseController::class, 'index'])->name('repair-cases.index');
            Route::post('repairs/search', [RepairCaseController::class, 'search'])->defaults('operation', 'search')->name('repair-cases.search');
            Route::post('repairs/search/reset', [RepairCaseController::class, 'resetSearch'])->name('repair-cases.reset');
            Route::get('repairs/intake', [RepairCaseController::class, 'create'])->name('repair-cases.create');
            Route::post('repairs/intake', [RepairCaseController::class, 'store'])->defaults('operation', 'intake')->middleware('throttle:20,1')->name('repair-cases.store');
            Route::get('repairs/{repairCase}', [RepairCaseController::class, 'show'])->name('repair-cases.show');
            foreach (['assign', 'transition', 'inspect', 'estimate', 'decision', 'work', 'delivery'] as $operation) {
                Route::post('repairs/{repairCase}/'.$operation, [RepairCaseController::class, 'update'])->defaults('operation', $operation)->middleware('throttle:30,1')->name('repair-cases.'.$operation);
            }
            Route::get('repairs/{repairCase}/documents/{document}', [RepairCaseController::class, 'document'])->name('repair-cases.document');
        });
    });
});
