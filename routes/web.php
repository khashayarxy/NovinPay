<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\ArticleCategoryController;
use App\Http\Controllers\Admin\ArticleController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\LoginController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PasswordController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\RepairCaseController;
use App\Http\Controllers\Admin\RepairOverviewController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RepairRequestController;
use App\Http\Middleware\EnsureAdminSession;
use App\Http\Middleware\PrivateAdminResponse;
use App\Http\Middleware\PrivateRepairResponse;
use Illuminate\Http\Request;
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
Route::middleware(PrivateRepairResponse::class)->group(function (): void {
    Route::get('/consultation', [ConsultationController::class, 'create'])->name('consultation');
    Route::post('/consultation', [ConsultationController::class, 'store'])->middleware('throttle:consultation-submit')->name('consultation.store');
    Route::get('/consultation/confirmation', [ConsultationController::class, 'confirmation'])->name('consultation.confirmation');
});
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
        Route::middleware('can:articles.view')->prefix('articles')->name('articles.')->group(function (): void {
            $controller = ArticleController::class;
            Route::get('/', [$controller, 'index'])->name('index');
            Route::get('create', [$controller, 'create'])->middleware('can:articles.manage')->name('create');
            Route::post('/', [$controller, 'store'])->defaults('operation', 'create')->middleware(['can:articles.manage', 'throttle:30,1'])->name('store');
            Route::get('{article}/edit', [$controller, 'edit'])->middleware('can:articles.manage')->name('edit');
            Route::put('{article}', [$controller, 'store'])->defaults('operation', 'save')->middleware(['can:articles.manage', 'throttle:30,1'])->name('update');
            Route::get('{article}/preview', [$controller, 'preview'])->name('preview');
            foreach (['publish', 'unpublish'] as $operation) {
                Route::post('{article}/'.$operation, [$controller, 'store'])->defaults('operation', $operation)->middleware(['can:articles.publish', 'throttle:30,1'])->name($operation);
            }
        });
        Route::middleware('can:article_categories.manage')->prefix('article-categories')->name('article-categories.')->group(function (): void {
            $controller = ArticleCategoryController::class;
            Route::get('/', [$controller, 'index'])->name('index');
            Route::get('create', [$controller, 'create'])->name('create');
            Route::get('{category}/edit', [$controller, 'edit'])->name('edit');
            Route::post('/', [$controller, 'store'])->defaults('operation', 'category.create')->middleware('throttle:30,1')->name('store');
            Route::put('{category}', [$controller, 'store'])->defaults('operation', 'category.update')->middleware('throttle:30,1')->name('update');
        });
        Route::middleware('can:finance.view')->prefix('finance')->name('finance.')->group(function (): void {
            $controller = FinanceController::class;
            Route::get('/', [$controller, 'index'])->name('index');
            Route::get('reports', [$controller, 'index'])->name('reports');
            Route::get('documents/{document}', [$controller, 'document'])->name('documents.show');
            Route::get('entries/{entry}', [$controller, 'entry'])->middleware('can:finance.details')->name('entries.show');
            Route::get('customers/{customer}', [$controller, 'customer'])->middleware(['can:finance.balances', 'can:customers.view'])->name('customers.show');
            Route::get('sources/{type}/{reference}', [$controller, 'source'])->name('sources.show');
            foreach (['issue', 'receipt', 'expense', 'credit', 'debit', 'reverse'] as $action) {
                Route::get('new/'.$action, fn (Request $request, FinanceController $controller) => $controller->create($request, $action))->name($action.'.create');
                Route::post($action, [$controller, 'store'])->defaults('operation', $action)->middleware('throttle:15,1')->name($action.'.store');
            }
        });
        Route::middleware('can:inventory.view')->group(function (): void {
            $controller = InventoryController::class;
            Route::get('inventory', [$controller, 'index'])->name('inventory.index');
            Route::get('inventory/create', [$controller, 'create'])->name('inventory.create');
            Route::get('inventory/movements', [$controller, 'movements'])->name('inventory.movements');
            Route::post('inventory', [$controller, 'store'])->defaults('operation', 'item.create')->middleware('throttle:30,1')->name('inventory.store');
            Route::get('inventory/{item}', [$controller, 'show'])->name('inventory.show');
            foreach (['receive', 'adjust', 'configure'] as $action) {
                Route::post('inventory/{item}/'.$action, [$controller, 'update'])->defaults('operation', 'item.'.$action)->middleware('throttle:30,1')->name('inventory.'.$action);
            }
        });
        Route::middleware('can:orders.view')->group(function (): void {
            $controller = OrderController::class;
            Route::get('orders', [$controller, 'index'])->name('orders.index');
            Route::get('orders/create', [$controller, 'create'])->name('orders.create');
            Route::post('orders', [$controller, 'store'])->defaults('operation', 'order.save')->middleware('throttle:30,1')->name('orders.store');
            Route::get('orders/{order}', [$controller, 'show'])->name('orders.show');
            Route::get('orders/{order}/edit', [$controller, 'edit'])->name('orders.edit');
            Route::put('orders/{order}', [$controller, 'update'])->defaults('operation', 'order.save')->middleware('throttle:30,1')->name('orders.update');
            foreach (['reserve', 'release', 'cancel', 'confirm', 'fulfill'] as $action) {
                Route::post('orders/{order}/'.$action, [$controller, 'update'])->defaults('operation', 'order.'.$action)->middleware('throttle:30,1')->name('orders.'.$action);
            }
        });
        Route::middleware('can:customers.view')->group(function (): void {
            $controller = CustomerController::class;
            Route::get('customers', [$controller, 'index'])->name('customers.index');
            Route::post('customers/search', [$controller, 'search'])->name('customers.search');
            Route::get('customers/create', [$controller, 'create'])->name('customers.create');
            Route::post('customers', [$controller, 'store'])->defaults('operation', 'customer.save')->middleware('throttle:20,1')->name('customers.store');
            Route::get('customers/{customer}', [$controller, 'show'])->name('customers.show');
            Route::get('customers/{customer}/edit', [$controller, 'edit'])->name('customers.edit');
            Route::put('customers/{customer}', [$controller, 'update'])->defaults('operation', 'customer.save')->middleware('throttle:20,1')->name('customers.update');
            foreach (['link', 'note', 'reconcile'] as $operation) {
                Route::post('customers/{customer}/'.$operation, [$controller, 'operation'])->defaults('operation', 'customer.'.$operation)->middleware('throttle:20,1')->name('customers.'.$operation);
            }
        });
        Route::middleware('can:consultations.view')->group(function (): void {
            $controller = App\Http\Controllers\Admin\ConsultationController::class;
            Route::get('consultations', [$controller, 'index'])->name('consultations.index');
            Route::post('consultations/search', [$controller, 'search'])->name('consultations.search');
            Route::get('consultations/{consultation}', [$controller, 'show'])->name('consultations.show');
            foreach (['assign', 'transition', 'entry'] as $operation) {
                Route::post('consultations/{consultation}/'.$operation, [$controller, 'update'])->defaults('operation', 'consultation.'.$operation)->middleware('throttle:30,1')->name('consultations.'.$operation);
            }
        });
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
