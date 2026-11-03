<?php

require dirname(__DIR__).'/Concurrency/bootstrap.php';

use App\Models\User;
use App\Services\FinancialOperations;
use App\Services\PaymentReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\HttpException;

$command = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
if ($command['hold']) {
    Event::listen(str_starts_with($command['action'], 'payment.') ? 'payment.after-write' : 'finance.after-write', function () use ($command): void {
        file_put_contents($command['ready'], 'ready');
        usleep(1200000);
    });
}
$start = microtime(true);
$status = 200;
$id = null;
try {
    $actor = User::findOrFail($command['actor']);
    $data = $command['data'];
    $id = $command['action'] === 'payment.in_store'
        ? app(PaymentReview::class)->inStore($actor, $data['password'], $data['document'], $data['amount_minor'], $data['operation_key'])->id
        : app(FinancialOperations::class)->execute($actor, $command['action'], $data);
} catch (HttpException $exception) {
    $status = $exception->getStatusCode();
}
file_put_contents($command['output'], json_encode(['status' => $status, 'result' => $id, 'pid' => getmypid(), 'connection_id' => DB::selectOne('SELECT CONNECTION_ID() AS id')->id, 'elapsed_ms' => (microtime(true) - $start) * 1000], JSON_THROW_ON_ERROR));
