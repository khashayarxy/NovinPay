<?php

use App\Models\User;
use App\Services\ArticlePublishing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\HttpException;

try {
    require dirname(__DIR__).'/Concurrency/bootstrap.php';
} catch (Throwable $exception) {
    fwrite(STDERR, 'Disposable bootstrap failed'.PHP_EOL);
    exit(1);
}
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, 'Article audit failed: '.get_class($exception).PHP_EOL);
    exit(1);
});

$command = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
if ($command['hold']) {
    Event::listen('articles.after-write', function () use ($command): void {
        file_put_contents($command['ready'], 'ready');
        usleep(1200000);
    });
}
$start = microtime(true);
$status = 200;
$id = null;
try {
    $id = app(ArticlePublishing::class)->execute(User::findOrFail($command['actor']), $command['action'], $command['data'], $command['id'] ?? null);
} catch (HttpException $exception) {
    $status = $exception->getStatusCode();
}
file_put_contents($command['output'], json_encode(['status' => $status, 'result' => $id, 'pid' => getmypid(), 'connection_id' => DB::selectOne('SELECT CONNECTION_ID() AS id')->id, 'elapsed_ms' => (microtime(true) - $start) * 1000], JSON_THROW_ON_ERROR));
