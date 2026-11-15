<?php

use App\Models\InventoryItem;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\CommerceManagement;
use App\Services\CustomerLogin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/bootstrap.php';
if (! str_starts_with(strtolower(realpath($argv[1]) ?: ''), strtolower($auditDirectory.DIRECTORY_SEPARATOR))) {
    throw new RuntimeException('Command outside artifact directory');
}
$command = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
if ($command['type'] === 'customer_otp') {
    // Same guarded disposable DB as the existing harness. Never contact an SMS provider.
    config(['customer.sms_provider' => 'ippanel', 'customer.test_otp_enabled' => false,
        'customer.ippanel' => ['enabled' => true, 'api_key' => 'synthetic-key',
            'pattern_code' => 'syntheticpattern', 'parameter' => 'code', 'sender' => '+983000505']]);
    Http::preventStrayRequests();
    Http::fake(['https://edge.ippanel.com/v1/api/send' => Http::response([
        'meta' => ['status' => true], 'data' => ['message_outbox_ids' => [123]],
    ])]);
    if ($command['hold']) {
        DB::listen(function ($query) use ($command) {
            if (str_contains($query->sql, 'customer_login_challenges') && str_contains(strtolower($query->sql), 'for update')) {
                file_put_contents($command['ready'], 'locked');
                usleep(1200000);
            }
        });
    }
    $start = hrtime(true);
    try {
        app(CustomerLogin::class)->issue('09120000000');
        $status = 200;
    } catch (HttpException $e) {
        $status = $e->getStatusCode();
    }
    file_put_contents($command['output'], json_encode(['pid' => getmypid(), 'connection_id' => DB::selectOne('select connection_id() as id')->id,
        'status' => $status, 'sends' => count(Http::recorded()), 'elapsed_ms' => (hrtime(true) - $start) / 1e6], JSON_PRETTY_PRINT));
    exit(0);
}
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
