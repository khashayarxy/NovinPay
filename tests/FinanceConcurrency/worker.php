<?php

require dirname(__DIR__).'/Concurrency/bootstrap.php';

use App\Models\User;
use App\Services\FinancialOperations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\HttpException;

$command = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
if ($command['hold']) {
    Event::listen('finance.after-write', function () use ($command): void {
        file_put_contents($command['ready'], 'ready');
        usleep(1200000);
    });
}
$start = microtime(true);
$status = 200;
$id = null;
try {
    $id = app(FinancialOperations::class)->execute(User::findOrFail($command['actor']), $command['action'], $command['data']);
} catch (HttpException $exception) {
    $status = $exception->getStatusCode();
}
file_put_contents($command['output'], json_encode(['status' => $status, 'result' => $id, 'pid' => getmypid(), 'connection_id' => DB::selectOne('SELECT CONNECTION_ID() AS id')->id, 'elapsed_ms' => (microtime(true) - $start) * 1000], JSON_THROW_ON_ERROR));
