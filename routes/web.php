<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\ArticleCategoryController;
use App\Http\Controllers\Admin\ArticleController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\LoginController;
use App\Http\Controllers\Admin\MaintenanceController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PasswordController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\RepairCaseController;
use App\Http\Controllers\Admin\RepairOverviewController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\TestCustomerAccountController;
use App\Http\Controllers\ArticleController as PublicArticleController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\CustomerAccountController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\Payments\PaymentController;
use App\Http\Controllers\Payments\ReceiptController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RepairRequestController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SupportChatController;
use App\Http\Middleware\EnsureAdminSession;
use App\Http\Middleware\EnsureCustomerSession;
use App\Http\Middleware\PreviewAwareSession;
use App\Http\Middleware\PrivateAdminResponse;
use App\Http\Middleware\PrivateRepairResponse;
use App\Http\Middleware\PublicArticleResponse;
use App\Services\CustomerAccountLink;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Public chat files contain no user/session data. Keep API and operator paths
// on the stateful route below; concurrent module loads must not write sessions.
Route::get('/support-chat/{path}', SupportChatController::class)
    ->where('path', '(?:support-widget\.js|(?:support-client/|assets/fonts/|assets/icons/)[a-zA-Z0-9_./-]+\.(?:js|css|html|json|woff2|png|svg))')
    ->withoutMiddleware([
        PreviewAwareSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ])->name('support.chat.asset');

Route::get('/support', [SupportChatController::class, 'operatorPanel'])
    ->withoutMiddleware([PreviewAwareSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->middleware('throttle:600,1')->name('support.panel');
Route::get('/support/sw.js', [SupportChatController::class, 'operatorWorker'])->middleware('throttle:600,1')->name('support.panel.worker');

Route::get('/support-chat/operator/{asset}', [SupportChatController::class, 'operatorAsset'])
    ->where('asset', '[a-zA-Z0-9_./-]+\.(?:js|css|json|woff2|png|svg)')
    ->middleware('throttle:600,1')
    ->withoutMiddleware([PreviewAwareSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->name('support.chat.operator.asset');

// Operator API owns its native secure cookie, CSRF and session lifecycle.
foreach (['operator-api.php', 'support-content-admin.php'] as $operatorEndpoint) {
    Route::match(['GET', 'POST'], '/support-chat/'.$operatorEndpoint, SupportChatController::class)
        ->defaults('path', $operatorEndpoint)
        ->withoutMiddleware([PreviewAwareSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
        ->middleware('throttle:600,1')->name('support.chat.operator.'.str_replace('.php', '', $operatorEndpoint));
}

Route::match(['GET', 'POST'], '/support-chat/{path?}', SupportChatController::class)
    ->where('path', '[a-zA-Z0-9_./-]*')
    ->withoutMiddleware(PreventRequestForgery::class)
    ->middleware('throttle:600,1')->name('support.chat');

Route::get('/login', [CustomerAccountController::class, 'login'])->block(20, 15)->middleware(PrivateRepairResponse::class)->name('account.login');

Route::middleware(PrivateRepairResponse::class)->prefix('account')->name('account.')->group(function (): void {
    $controller = CustomerAccountController::class;
    Route::get('login', fn () => redirect()->route('account.login'))->name('login.legacy');
    Route::post('start', [$controller, 'start'])->block(20, 15)->middleware('throttle:customer-otp')->name('start');
    Route::post('restart', [$controller, 'restart'])->block(20, 15)->name('restart');
    Route::post('otp', [$controller, 'send'])->block(20, 15)->middleware('throttle:customer-otp')->name('otp');
    Route::post('verify', [$controller, 'verify'])->block(20, 15)->middleware('throttle:customer-verify')->name('verify');
    Route::post('password-login', [$controller, 'passwordLogin'])->block(20, 15)->middleware('throttle:customer-password')->name('password.login');
    Route::middleware(EnsureCustomerSession::class)->group(function () use ($controller): void {
        Route::get('/', [$controller, 'index'])->name('index');
        Route::get('/repairs/requests/{record}', [$controller, 'repairRequest'])->where('record', '[A-Za-z0-9-]{1,35}')->name('repairs.request');
        Route::get('/repairs/{record}', [$controller, 'repair'])->whereUuid('record')->name('repairs.show');
        Route::get('/orders/{record}', [$controller, 'order'])->whereUuid('record')->name('orders.show');
        Route::post('password', [$controller, 'password'])->middleware('throttle:10,1')->name('password.update');
        Route::post('password-skip', [$controller, 'skipPassword'])->name('password.skip');
        Route::post('logout', [$controller, 'logout'])->block(20, 15)->name('logout');
        Route::post('profile', [$controller, 'profile'])->middleware('throttle:20,1')->name('profile');
        Route::post('addresses', [$controller, 'address'])->middleware('throttle:10,1')->name('address');
        Route::post('addresses/{addressId}', [$controller, 'address'])->middleware('throttle:10,1')->name('address.update');
        Route::post('addresses/{addressId}/remove', [$controller, 'removeAddress'])->middleware('throttle:10,1')->name('address.remove');
    });
});

Route::get('/', [HomeController::class, 'index'])->name('home');
// Public crawler documents contain no session/identity-dependent content.
Route::withoutMiddleware([PreviewAwareSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])->group(function (): void {
    Route::get('/llms.txt', [PageController::class, 'discovery'])->name('public.discovery');
    Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap.index');
    Route::get('/sitemap-{type}-{page}.xml', [SitemapController::class, 'part'])
        ->whereIn('type', ['pages', 'products', 'articles'])->where('page', '[1-9][0-9]{0,5}')->name('sitemap.part');
});

Route::middleware(PrivateRepairResponse::class)->group(function (): void {
    $payment = PaymentController::class;
    Route::get('/pay/{page}.html', [$payment, 'page'])->whereIn('page', ['index', 'canceled', 'error', 'failed', 'paid', 'processing', 'success'])->name('payments.page');
    Route::get('/bot/payment_verify.php', [$payment, 'callback'])->middleware('throttle:120,1')->name('payments.callback');
    Route::get('/bot/check_payment_status.php', [$payment, 'status'])->middleware('throttle:60,1')->name('payments.status');
    Route::get('/bot/receipt_info.php', [$payment, 'receipt'])->middleware('throttle:30,1')->name('payments.receipt');
    Route::post('/account/documents/{document}/zibal', [$payment, 'start'])->middleware([EnsureCustomerSession::class, 'throttle:10,1'])->name('payments.start');
    $receipt = ReceiptController::class;
    Route::get('/account/documents/{document}', [$receipt, 'show'])->middleware(EnsureCustomerSession::class)->name('payments.document');
    Route::post('/account/documents/{document}/receipts', [$receipt, 'store'])->middleware([EnsureCustomerSession::class, 'throttle:10,1'])->name('payments.upload');
    Route::get('/payment-receipts/{receipt}', [$receipt, 'image'])->middleware('throttle:60,1')->name('payments.image');
    Route::get('/account/documents/{document}/delivery', [DeliveryController::class, 'show'])->middleware(EnsureCustomerSession::class)->name('delivery.show');
    Route::post('/account/documents/{document}/delivery', [DeliveryController::class, 'request'])->middleware([EnsureCustomerSession::class, 'throttle:10,1'])->name('delivery.request');
});

Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');

Route::middleware(PrivateRepairResponse::class)->group(function (): void {
    $cart = CartController::class;
    Route::get('/cart', [$cart, 'index'])->name('cart.index');
    Route::post('/cart/products/{product}', [$cart, 'add'])->middleware('throttle:30,1')->block(20, 15)->name('cart.add');
    Route::post('/cart/remove/{slug}', [$cart, 'remove'])->middleware('throttle:30,1')->block(20, 15)->name('cart.remove');
    Route::post('/cart/checkout', [$cart, 'checkout'])->middleware([EnsureCustomerSession::class, 'throttle:10,1'])->block(20, 15)->name('cart.checkout');
});

Route::middleware(PublicArticleResponse::class)->prefix('articles')->name('articles.')->group(function (): void {
    Route::get('/', [PublicArticleController::class, 'index'])->name('index');
    Route::get('category/{slug}', [PublicArticleController::class, 'category'])->name('category');
    Route::get('{slug}', [PublicArticleController::class, 'show'])->name('show');
});

Route::middleware(PrivateRepairResponse::class)->group(function (): void {
    Route::get('/repair', [RepairRequestController::class, 'create'])->name('repair');
    Route::post('/repair', [RepairRequestController::class, 'store'])->middleware([EnsureCustomerSession::class, 'throttle:repair-submit'])->name('repair.store');
    Route::get('/repair/confirmation', [RepairRequestController::class, 'confirmation'])->name('repair.confirmation');
    Route::get('/repair/track', [RepairRequestController::class, 'tracking'])->name('repair.track');
    Route::post('/repair/track', [RepairRequestController::class, 'track'])->middleware('throttle:repair-track')->name('repair.track.store');
});
Route::get('/contact', [PageController::class, 'contact'])->name('contact');
Route::middleware(PrivateRepairResponse::class)->group(function (): void {
    Route::get('/consultation', [ConsultationController::class, 'create'])->name('consultation');
    Route::post('/consultation', [ConsultationController::class, 'store'])->middleware('throttle:consultation-submit')->name('consultation.store');
    Route::get('/consultation/confirmation', [ConsultationController::class, 'confirmation'])->name('consultation.confirmation');
});
Route::get('/terms', [PageController::class, 'terms'])->name('terms');
Route::get('/privacy', [PageController::class, 'privacy'])->name('privacy');

Route::middleware(['guest:web', PrivateAdminResponse::class])->group(function (): void {
    Route::get('/admin/login', [LoginController::class, 'create'])->name('admin.login');
    Route::post('/admin/login', [LoginController::class, 'store'])->name('admin.login.store');
});

Route::middleware([PrivateAdminResponse::class, 'auth:web'])->prefix('admin')->name('admin.')->group(function (): void {
    // Keep logout available even if administrator authorization is revoked.
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::middleware([EnsureAdminSession::class, 'can:access-admin'])->group(function (): void {
        Route::middleware('can:staff.manage')->prefix('test-customers')->name('test-customers.')->group(function (): void {
            $controller = TestCustomerAccountController::class;
            Route::get('/', [$controller, 'index'])->name('index');
            Route::post('{key}/code', [$controller, 'reveal'])->where('key', '[a-f0-9]{64}')->middleware('throttle:10,1')->name('code');
            Route::post('{record}/reset', [$controller, 'reset'])->whereUuid('record')->middleware('throttle:10,1')->name('reset');
        });
        Route::get('support', fn () => redirect('/support'))->middleware('can:support.view')->name('support.index');
        Route::get('support/confirm', fn () => redirect('/support'))->middleware('can:support.view')->name('support.confirm');
        Route::get('deliveries', [DeliveryController::class, 'admin'])->middleware(['can:finance.view', 'can:finance.details'])->name('deliveries.index');
        Route::post('deliveries/{plan}/confirm', [DeliveryController::class, 'confirm'])->middleware(['can:finance.correct', 'can:finance.details', 'throttle:15,1'])->name('deliveries.confirm');
        Route::post('deliveries/{plan}/dispatch', [DeliveryController::class, 'dispatch'])->middleware(['can:finance.receive', 'can:finance.details', 'throttle:15,1'])->name('deliveries.dispatch');
        Route::get('payments', [App\Http\Controllers\Admin\PaymentController::class, 'index'])->middleware('can:finance.view')->name('payments.index');
        Route::get('payments/receipts/{receipt}/image', [ReceiptController::class, 'image'])->middleware(['can:finance.receive', 'can:finance.details'])->name('payments.image');
        Route::post('payments/{receipt}/review', [App\Http\Controllers\Admin\PaymentController::class, 'review'])->middleware(['can:finance.receive', 'can:finance.details', 'throttle:15,1'])->name('payments.review');
        Route::post('payments/attempts/{attempt}/resolve', [App\Http\Controllers\Admin\PaymentController::class, 'resolveAttempt'])->middleware(['can:finance.correct', 'can:finance.details', 'throttle:10,1'])->name('payments.attempt.resolve');
        Route::post('payments/receive', [App\Http\Controllers\Admin\PaymentController::class, 'store'])->middleware(['can:finance.receive', 'can:finance.details', 'throttle:15,1'])->name('payments.store');
        Route::post('payments/refund', [App\Http\Controllers\Admin\PaymentController::class, 'refund'])->middleware(['can:finance.correct', 'can:finance.details', 'throttle:15,1'])->name('payments.refund');
        Route::post('customer-accounts/link', function (Request $request, CustomerAccountLink $service) {
            $data = $request->validate(['account' => ['required', 'uuid'], 'customer' => ['required', 'uuid'], 'password' => ['required', 'string', 'max:255'], 'identity_confirmed' => ['accepted']]);
            $service->link($request->user(), $data['account'], $data['customer'], $data['password']);

            return back()->with('success', 'اتصال حساب تأیید شد؛ مشتری دوباره وارد شود.');
        })->middleware(['can:customers.link', 'can:customers.contact', 'can:customers.view', 'throttle:10,1'])->name('customer-accounts.link');
        Route::middleware('can:maintenance.view')->prefix('maintenance')->name('maintenance.')->group(function (): void {
            Route::get('/', [MaintenanceController::class, 'index'])->name('index');
            Route::get('preview', [MaintenanceController::class, 'preview'])->name('preview');
            Route::get('preview/page', [MaintenanceController::class, 'previewPage'])->name('preview.page');
            foreach (['enable', 'disable'] as $action) {
                Route::post($action, [MaintenanceController::class, 'update'])->middleware(['can:maintenance.manage', 'throttle:10,1'])->name($action);
            }
        });
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
            Route::post('repairs/{repairCase}/record', [RepairCaseController::class, 'saveSimple'])->middleware('throttle:20,1')->name('repair-cases.record');
            foreach (['assign', 'transition', 'inspect', 'estimate', 'diagnose', 'decision', 'contact', 'work', 'delivery'] as $operation) {
                Route::post('repairs/{repairCase}/'.$operation, [RepairCaseController::class, 'update'])->defaults('operation', $operation)->middleware('throttle:30,1')->name('repair-cases.'.$operation);
            }
            Route::get('repairs/{repairCase}/documents/{document}', [RepairCaseController::class, 'document'])->name('repair-cases.document');
        });
    });
});
