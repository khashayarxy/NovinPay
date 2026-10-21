<?php

use App\Models\InventoryItem;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\CommerceManagement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/bootstrap.php';
if (! str_starts_with(strtolower(realpath($argv[1]) ?: ''), strtolower($auditDirectory.DIRECTORY_SEPARATOR))) {
    throw new RuntimeException('Command outside artifact directory');
}
$command = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$actor = User::findOrFail($command['actor']);
$target = $command['type'] === 'order' ? SalesOrder::findOrFail($command['target']) : InventoryItem::findOrFail($command['target']);
if ($command['hold']) {
    Event::listen('commerce.after-write', function () use ($command) {
        file_put_contents($command['ready'], 'locked');
        usleep(1200000);
    });
}
$start = hrtime(true);
try {
    $result = app(CommerceManagement::class)->execute($actor, $command['action'], $command['data'], $target);
    $status = 200;
} catch (HttpException $e) {
    $status = $e->getStatusCode();
    $result = null;
} catch (Throwable $e) {
    $status = 500;
    $result = get_class($e);
}
file_put_contents($command['output'], json_encode(['pid' => getmypid(), 'connection_id' => DB::selectOne('select connection_id() as id')->id, 'status' => $status, 'result' => $result, 'elapsed_ms' => (hrtime(true) - $start) / 1e6], JSON_PRETTY_PRINT));
exit($status === 500 ? 1 : 0);
