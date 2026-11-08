<?php

use App\Support\Chat\ChatBridge;
use App\Support\Chat\LegacyResponse;
use Illuminate\Contracts\Console\Kernel;

// Actual competing PHP processes, restricted to a freshly generated test fixture.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$directory = $argv[1] ?? '';
$index = $argv[2] ?? '';
if (! $app->environment('testing') || ! preg_match('/\Anovinpay-chat-test-[a-f0-9]{24}\z/D', basename($directory)) || ! in_array($index, ['0', '1'], true) || is_link($directory) || realpath($directory) === false || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())) {
    exit(2);
}
config(['support_chat.directory' => $directory]);
$bridge = app(ChatBridge::class);
$bridge->begin();
try {
    require_once resource_path('support-chat/support/bootstrap.php');
    require_once resource_path('support-chat/support/messages.php');
    $fixture = json_decode(file_get_contents($directory.'/ownership-race.json'), true, flags: JSON_THROW_ON_ERROR);
    file_put_contents($directory.'/ownership-ready-'.$index, '1');
    $deadline = microtime(true) + 5;
    while (! is_file($directory.'/ownership-start')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('barrier_timeout');
        }
        usleep(1000);
    }
    $entry = $fixture['actors'][(int) $index];
    support_accept_message($entry['context'], 'operator', $fixture['conversation'], $entry['uuid'], 'synthetic competing reply');
    echo json_encode(['status' => 'accepted']);
} catch (LegacyResponse $result) {
    echo json_encode(['status' => $result->payload['code'] ?? 'unexpected', 'http' => $result->status]);
} finally {
    $bridge->finish();
}
