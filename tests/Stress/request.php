<?php

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\StockStatus;
use App\Models\CustomerAccount;
use App\Models\InventoryItem;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\ShoppingCart;
use App\Support\Chat\ChatBridge;
use App\Support\RepairIdentity;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $root, $scenario, $index] = $argv;
$index = (int) $index;
if (PHP_SAPI !== 'cli' || dirname(realpath($root) ?: '') !== realpath(sys_get_temp_dir())
    || ! str_starts_with(basename($root), 'novinpay-phase12-') || getenv('APP_ENV') !== 'testing') {
    throw new RuntimeException('Private test directory required.');
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.phase12-disposable');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
set_exception_handler(static function (Throwable $e) {
    fwrite(STDERR, $e::class.' at '.basename($e->getFile()).':'.$e->getLine().' '.substr($e->getMessage(), 0, 160).PHP_EOL);
    exit(1);
});
if (! $app->environment('testing') || $app->configurationIsCached() || DB::connection()->getDriverName() !== 'sqlite'
    || realpath(DB::connection()->getDatabaseName()) !== realpath($root.'/stress.sqlite')
    || realpath(storage_path()) !== realpath($root) || config('session.driver') !== 'file') {
    throw new RuntimeException('Unexpected test runtime.');
}
config(['support_chat.enabled' => true, 'payments.zibal.enabled' => true,
    'payments.zibal.live_requests' => true, 'payments.zibal.merchant' => 'phase12-test',
    'payments.zibal.callback_url' => 'https://novinpaypos.test/payments/zibal/callback',
    'payments.zibal.api_base_url' => 'https://gateway.zibal.ir',
    'notifications.telegram.enabled' => $scenario === 'slow',
    'notifications.sms.enabled' => $scenario === 'slow',
    'notifications.pattern_sms.provider' => 'ippanel',
    'notifications.admin.phones' => ['09000000009'],
    'notifications.sms.patterns.admin_repair_submitted' => 'syntheticpattern',
    'notifications.sms.patterns.admin_consultation_submitted' => 'syntheticpattern',
    'notifications.sms.patterns.admin_chat_first_message' => 'syntheticpattern',
    'customer.ippanel.enabled' => $scenario === 'slow',
    'customer.ippanel.api_key' => 'synthetic-key',
    'customer.ippanel.sender' => '+983000505',
    'notifications.telegram.bot_token' => '123456:synthetic-token',
    'notifications.telegram.chat_id' => '1001',
    'notifications.email.enabled' => $scenario === 'slow',
    'notifications.admin.emails' => ['qa@example.test']]);
Http::preventStrayRequests();
$delays = [];
Http::fake(function ($request) use ($scenario, &$delays) {
    if ($scenario === 'slow') {
        $provider = str_contains($request->url(), 'ippanel.com') ? 'sms' : 'telegram';
        $delays[] = $provider;
        sleep(3);

        return Http::response(['ok' => false], 503);
    }

    return Http::response(['result' => 100, 'trackId' => 123456789], 200);
});
if ($scenario === 'slow') {
    Event::listen(MessageSending::class, static function () use (&$delays) {
        $delays[] = 'mail';
        sleep(3);
    });
}
function encryptedCookie(string $name, string $value): string
{
    return encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$value, false);
}
function hit(string $path, array &$jar, ?array $body = null)
{
    global $kernel, $elapsed;
    $request = Request::create('https://novinpaypos.test'.$path, $body === null ? 'GET' : 'POST', $body ?? [], $jar);
    auth()->forgetGuards();
    $start = hrtime(true);
    $response = $kernel->handle($request);
    $elapsed = round((hrtime(true) - $start) / 1e6, 1);
    foreach ($response->headers->getCookies() as $cookie) {
        $jar[$cookie->getName()] = $cookie->getValue();
    }
    $kernel->terminate($request, $response);

    return $response;
}
if ($scenario === 'setup') {
    Artisan::call('migrate', ['--force' => true]);
    $staff = User::factory()->superAdmin()->create();
    $account = CustomerAccount::forceCreate(['public_id' => (string) Str::uuid(), 'name' => 'کاربر آزمایشی',
        'phone' => '09000000000', 'phone_digest' => RepairIdentity::digest('customer-account-phone', '09000000000'),
        'is_active' => true, 'verified_at' => now(), 'session_version' => 1]);
    $product = Product::forceCreate(['slug' => 'phase12-fixture', 'name' => 'کالای آزمایشی', 'category' => ProductCategory::PosTerminal,
        'condition' => ProductCondition::New, 'stock_status' => StockStatus::InStock, 'price' => 4500000,
        'price_changed_at' => now(), 'online_purchasing_enabled' => true, 'is_published' => true, 'is_sample' => false]);
    InventoryItem::forceCreate(['public_id' => (string) Str::uuid(), 'product_id' => $product->id, 'sku' => 'PHASE12',
        'name' => $product->name, 'condition' => 'new', 'serialized' => false, 'on_hand' => 5, 'reserved' => 0, 'version' => 1]);
    app(ChatBridge::class)->initialize();
    $session = session()->driver();
    $session->start();
    $session->put([auth('web')->getName() => $staff->id, 'admin_session_version' => 1,
        auth('customer')->getName() => $account->id, 'customer_session_version' => 1]);
    $session->save();
    $jar = [$session->getName() => encryptedCookie($session->getName(), $session->getId()), ShoppingCart::DEVICE_COOKIE => bin2hex(random_bytes(32))];
    $cart = hit('/cart', $jar)->getOriginalContent()->getData();
    hit('/cart/products/phase12-fixture', $jar, ['quantity' => 1, 'cart_context' => $cart['proof'], 'cart_revision' => $cart['revision']]);
    $checkout = hit('/cart/checkout', $jar)->getOriginalContent()->getData();
    $order = ['method' => 'pickup', 'payment_method' => 'zibal', 'operation_key' => (string) Str::uuid(),
        'quote' => $checkout['quote'], 'cart_context' => $checkout['proof'], 'cart_revision' => $checkout['revision']];
    $consult = hit('/consultation', $jar)->getOriginalContent()->getData()['token'];
    foreach (['/products', '/admin/orders', '/admin/consultations', '/admin/repairs', '/contact', '/products/phase12-fixture', '/repair/track/recovery', '/'] as $path) {
        $warm = hit($path, $jar);
        if ($warm->getStatusCode() !== 200) {
            throw new RuntimeException('Failed to warm '.$path);
        }
    }
    hit('/repair', $jar);
    $repair = session('repair_submission_token');
    $boot = hit('/support-chat/api.php?get_session=1&bootstrap=1', $jar)->getData(true);
    $jar[session_name()] = encryptedCookie(session_name(), session_id());
    file_put_contents($root.'/fixture.json', json_encode(compact('jar', 'order', 'consult', 'repair', 'boot'), JSON_THROW_ON_ERROR));
    echo 'true';
    exit;
}
if ($scenario === 'integrity') {
    $stock = InventoryItem::query()->sole();
    if (SalesOrder::count() !== 1 || PaymentAttempt::where('state', 'pending')->count() !== 1 || $stock->on_hand !== 5 || $stock->reserved !== 0) {
        throw new RuntimeException('Repeated unpaid checkout changed stock or duplicated the order/attempt.');
    }
    echo 'true';
    exit;
}
$fixture = json_decode(file_get_contents($root.'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
$jar = $fixture['jar'];
$boot = $fixture['boot'];
$poll = '/support-chat/api.php?get_conversation=1&protocol_version=2&scope=customer&user_id='.$boot['draft_user_id'];
$path = match ($scenario) {
    'checkout' => $index % 2 === 0 ? '/cart/checkout/draft' : '/cart/checkout',
    'poll' => $index % 2 === 0 ? $poll : '/products',
    'reads' => ['/products', '/admin/orders', '/admin/consultations', '/admin/repairs', '/contact', '/products/phase12-fixture', '/repair/track/recovery', '/'][$index % 8],
    'slow' => ['/consultation', '/repair', '/support-chat/api.php'][$index],
};
$payload = match ($path) {
    '/cart/checkout/draft' => ['method' => 'pickup'],
    '/cart/checkout' => $fixture['order'],
    '/consultation' => ['submission_token' => $fixture['consult'], 'customer_name' => 'کاربر آزمایشی', 'contact_phone' => '09000000000', 'topic' => 'service', 'question' => 'پیگیری آزمایشی درخواست مشاوره'],
    '/repair' => ['submission_token' => $fixture['repair'], 'customer_name' => 'کاربر آزمایشی', 'device_brand' => 'PAX', 'device_model' => 'S910',
        'issue' => 'آزمون زمان پاسخ درخواست تعمیر', 'province' => 'تهران', 'city' => 'شهریار', 'handover_method' => 'in_person'],
    '/support-chat/api.php' => ['send_message' => '1', 'user_id' => $boot['draft_user_id'], 'message' => 'آزمون زمان پاسخ',
        'protocol_version' => '2', 'client_message_id' => (string) Str::uuid(), 'csrf_token' => $boot['token']],
    default => null,
};
$queries = 0;
DB::listen(static function () use (&$queries) {
    $queries++;
});
touch($root.'/'.$scenario.'-'.$index.'.ready');
$deadline = microtime(true) + 75;
while (! is_file($root.'/'.$scenario.'.go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Barrier timed out.');
    }
    usleep(10000);
}
$response = hit($path, $jar, $payload);
$status = $response->getStatusCode();
$passed = match ($path) {
    '/cart/checkout' => $status === 303 && str_ends_with((string) $response->headers->get('Location'), '/connect'),
    '/consultation', '/repair' => $status === 302 && str_ends_with((string) $response->headers->get('Location'), '/confirmation') && in_array('sms', $delays, true) && in_array('mail', $delays, true),
    '/support-chat/api.php' => $status === 200 && $response->getData(true)['status'] === 'accepted' && in_array('sms', $delays, true) && in_array('telegram', $delays, true),
    default => $status === 200 && ($scenario !== 'reads' || $queries < 100),
};
echo json_encode(['path' => strtok($path, '?'), 'ms' => $elapsed, 'status' => $status, 'error' => $status >= 500 && isset($response->exception) ? substr($response->exception->getMessage(), 0, 180) : null, 'queries' => $queries, 'delays' => $delays, 'passed' => $passed], JSON_THROW_ON_ERROR);
