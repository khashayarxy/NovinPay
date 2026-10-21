<?php

require __DIR__.'/bootstrap.php';
use App\Models\InventoryUnit;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

auditCheck($driver === 'mysql', 'Actual MySQL required');
Artisan::call('migrate:fresh', ['--force' => true]);
$actor = User::factory()->superAdmin()->create();
$customer = auditCustomer();
$reports = [];
function race(string $label, array $first, array $second, array $expected): array
{
    $base = $GLOBALS['auditDirectory'].'/race-'.$label.'-'.bin2hex(random_bytes(3));
    $first += ['hold' => true, 'ready' => $base.'.ready', 'output' => $base.'-first.json'];
    $second += ['hold' => false, 'ready' => $base.'.ready', 'output' => $base.'-second.json'];
    file_put_contents($base.'-first-command.json', json_encode($first));
    file_put_contents($base.'-second-command.json', json_encode($second));
    $pipes = [];
    $one = proc_open([PHP_BINARY, __DIR__.'/worker.php', $base.'-first-command.json'], [0 => ['pipe', 'r'], 1 => ['file', $base.'-one.log', 'w'], 2 => ['file', $base.'-one-error.log', 'w']], $pipes);
    $deadline = microtime(true) + 20;
    while (! is_file($base.'.ready') && microtime(true) < $deadline) {
        usleep(10000);
    }
    auditCheck(is_file($base.'.ready'), 'first worker reached locked write');
    $two = proc_open([PHP_BINARY, __DIR__.'/worker.php', $base.'-second-command.json'], [0 => ['pipe', 'r'], 1 => ['file', $base.'-two.log', 'w'], 2 => ['file', $base.'-two-error.log', 'w']], $pipes);
    proc_close($one);
    proc_close($two);
    $a = json_decode(file_get_contents($first['output']), true, flags: JSON_THROW_ON_ERROR);
    $b = json_decode(file_get_contents($second['output']), true, flags: JSON_THROW_ON_ERROR);
    auditCheck([$a['status'], $b['status']] === $expected, $label.' expected outcome');
    auditCheck($a['connection_id'] !== $b['connection_id'] && $a['pid'] !== $b['pid'], 'distinct competing connections');
    auditCheck($b['elapsed_ms'] > 500, 'second worker actually waited');

    return ['scenario' => $label, 'first' => $a, 'second' => $b];
}
function cmd($actor, $target, $action, $data = []): array
{
    return ['actor' => $actor->id, 'type' => $target instanceof SalesOrder ? 'order' : 'item', 'target' => $target->id, 'action' => $action, 'data' => ['operation_key' => (string) Str::uuid(), 'version' => $target->fresh()->version, 'reason' => 'Synthetic concurrent operation'] + $data];
}
foreach ([false, true] as $serialized) {
    $item = auditItem($actor, $serialized);
    auditCommand($actor, 'item.receive', ['quantity' => 1, 'serials' => $serialized ? 'COMPETE-SERIAL-01' : '', 'reason' => 'Synthetic initial receipt'], $item);
    $a = auditOrder($actor, $customer, $item);
    $b = auditOrder($actor, $customer, $item);
    $reports[] = race($serialized ? 'serial-last-unit' : 'bulk-last-unit', cmd($actor, $a, 'order.reserve'), cmd($actor, $b, 'order.reserve'), [200, 409]);
    auditCheck($item->fresh()->reserved === 1 && $item->fresh()->available === 0, 'No overselling');
    auditCommand($actor, 'order.confirm', ['reason' => 'Synthetic confirmation'], $a);
    $reports[] = race($serialized ? 'serial-double-fulfill' : 'bulk-double-fulfill', cmd($actor, $a, 'order.fulfill', ['fulfillment_info' => 'Synthetic handover only']), cmd($actor, $a, 'order.fulfill', ['fulfillment_info' => 'Second handover forbidden']), [200, 409]);
    auditCheck($item->fresh()->dispatched === 1 && $item->fresh()->on_hand === 0, 'One dispatch only');
}
$item = auditItem($actor);
auditCommand($actor, 'item.receive', ['quantity' => 1, 'reason' => 'Initial stock'], $item);
$order = auditOrder($actor, $customer, $item);
$same = cmd($actor, $order, 'order.reserve');
$reports[] = race('same-key-reservation', $same, $same, [200, 200]);
auditCheck(StockMovement::where('sales_order_id', $order->id)->where('kind', 'reserve')->count() === 1, 'Single reservation ledger');
auditCommand($actor, 'order.confirm', ['reason' => 'Synthetic confirmation'], $order);
$same = cmd($actor, $order, 'order.fulfill', ['fulfillment_info' => 'Repeated identical handover']);
$reports[] = race('same-key-fulfillment', $same, $same, [200, 200]);
auditCheck(StockMovement::where('sales_order_id', $order->id)->where('kind', 'dispatch')->count() === 1, 'Single fulfillment ledger');
$item = auditItem($actor);
auditCommand($actor, 'item.receive', ['quantity' => 1, 'reason' => 'Initial stock'], $item);
$order = auditOrder($actor, $customer, $item);
$reports[] = race('adjust-versus-reserve', cmd($actor, $item, 'item.adjust', ['quantity' => -1]), cmd($actor, $order, 'order.reserve'), [200, 409]);
auditCheck($item->fresh()->on_hand === 0 && $item->fresh()->reserved === 0, 'Adjustment conservation');
$first = auditItem($actor, true);
$second = auditItem($actor, true);
$reports[] = race('global-serial-receipt', cmd($actor, $first, 'item.receive', ['quantity' => 1, 'serials' => 'GLOBAL-SERIAL-UNIQUE']), cmd($actor, $second, 'item.receive', ['quantity' => 1, 'serials' => 'GLOBAL-SERIAL-UNIQUE']), [200, 409]);
auditCheck(InventoryUnit::where('serial', 'GLOBAL-SERIAL-UNIQUE')->count() === 1, 'Global serial uniqueness');
file_put_contents($auditDirectory.'/concurrency-mysql.json', json_encode(['mysql' => DB::selectOne('select version() as v')->v, 'scenarios' => $reports, 'passed' => count($reports)], JSON_PRETTY_PRINT));
echo 'Passed '.count($reports).' actual competing-connection scenarios'.PHP_EOL;
