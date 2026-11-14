<?php

require dirname(__DIR__).'/Concurrency/bootstrap.php';

use App\Models\CustomerAccount;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\FinancialOperations;
use App\Services\PaymentReview;
use App\Services\StoreCheckout;
use App\Services\ZibalPayments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

$command = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
if ($command['hold']) {
    Event::listen(str_starts_with($command['action'], 'payment.') ? 'payment.after-write' : 'finance.after-write', function () use ($command): void {
        file_put_contents($command['ready'], 'ready');
        usleep(1200000);
    });
    if ($command['action'] === 'payment.zibal.start') {
        DB::listen(function ($query) use ($command): void {
            if (str_starts_with(strtolower($query->sql), 'insert into `payment_attempts`')) {
                file_put_contents($command['ready'], 'ready');
                usleep(1200000);
            }
        });
    }
    if ($command['action'] === 'store.checkout') {
        DB::listen(function ($query) use ($command): void {
            if (str_starts_with(strtolower($query->sql), 'insert into `sales_orders`')) {
                file_put_contents($command['ready'], 'ready');
                usleep(1200000);
            }
        });
    }
}
$start = microtime(true);
$status = 200;
$id = null;
try {
    $actor = User::findOrFail($command['actor']);
    $data = $command['data'];
    if ($command['action'] === 'store.checkout') {
        $id = app(StoreCheckout::class)->create(CustomerAccount::findOrFail($data['account']), $data['cart'], $data['operation_key'])->id;
    } elseif (str_starts_with($command['action'], 'payment.zibal.')) {
        config(['app.url' => 'https://novinpaypos.test', 'payments.zibal.enabled' => true, 'payments.zibal.live_requests' => true,
            'payments.zibal.merchant' => 'synthetic-'.$data['document'], 'payments.zibal.callback_url' => 'https://novinpaypos.test/bot/payment_verify.php']);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($data) {
            if (str_ends_with($request->url(), '/request')) {
                return Http::response(['result' => 100, 'trackId' => '70001']);
            }
            $attempt = PaymentAttempt::findOrFail($data['attempt']);

            return Http::response(['result' => 100, 'amount' => $attempt->amount_rial, 'orderId' => $attempt->public_id]);
        });
        $id = $command['action'] === 'payment.zibal.start'
            ? app(ZibalPayments::class)->start(CustomerAccount::findOrFail($data['account']), $data['document'], $data['amount_minor'], $data['operation_key'])->id
            : app(ZibalPayments::class)->verify(PaymentAttempt::findOrFail($data['attempt']))->id;
    } else {
        $id = $command['action'] === 'payment.in_store'
            ? app(PaymentReview::class)->inStore($actor, $data['password'], $data['document'], $data['amount_minor'], $data['operation_key'])->id
            : app(FinancialOperations::class)->execute($actor, $command['action'], $data);
    }
} catch (HttpException $exception) {
    $status = $exception->getStatusCode();
}
file_put_contents($command['output'], json_encode(['status' => $status, 'result' => $id, 'pid' => getmypid(), 'connection_id' => DB::selectOne('SELECT CONNECTION_ID() AS id')->id, 'elapsed_ms' => (microtime(true) - $start) * 1000], JSON_THROW_ON_ERROR));
