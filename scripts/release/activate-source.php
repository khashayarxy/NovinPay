<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use NovinPay\Release\GateFailure;

const ROOT = '/home/novinpay/novinpay';
const KIT = '/home/novinpay/novinpay-direct-pr13';

ini_set('display_errors', '0');
ini_set('log_errors', '0');
umask(0077);

function gate(bool $valid, string $check): void
{
    if (! $valid) {
        throw new RuntimeException($check);
    }
}

$action = $argv[3] ?? '';
try {
    gate(PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80401 && posix_geteuid() === 1321
        && in_array($action, ['contract', 'baseline-files', 'smoke', 'preflight', 'ready', 'web-check', 'web-cache', 'quota'], true), 'execution_scope');
    require __DIR__.'/recovery-contract.php';
    $raw = NovinPay\Release\privateRead($argv[1]);
    $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    $manifest['_sha256'] = hash('sha256', $raw);
    gate(count($argv) === 4 && preg_match('/\A[a-f0-9]{64}\z/D', $argv[2]) === 1 && hash_equals($argv[2], hash('sha256', $raw)), 'manifest_pin');
    NovinPay\Release\sourceReleaseManifest($manifest);
    if ($action === 'contract') {
        foreach (['baseline', 'target', 'releaseName', 'targetBranch', 'cssAsset', 'successResult'] as $key) {
            echo $manifest[$key].PHP_EOL;
        }
        exit(0);
    }
    if ($action === 'baseline-files') {
        foreach ($manifest['baselineFiles'] as $path => $hash) {
            gate(is_file(ROOT.'/'.$path) && ! is_link(ROOT.'/'.$path) && hash_equals($hash, hash_file('sha256', ROOT.'/'.$path)), 'baseline_file_identity');
        }
        exit(0);
    }
    if ($action === 'smoke') {
        NovinPay\Release\sourceHttpsAcceptance($manifest);
        exit(0);
    }

    if ($action === 'quota') {
        NovinPay\Release\requestQuota($manifest, KIT, 'fence');
        echo "ADMIN_QUOTA_PASS\n";
        exit(0);
    }
    if ($action === 'preflight') {
        NovinPay\Release\sourceRecoveryEvidence($manifest);
    }
    if (in_array($action, ['web-check', 'web-cache'], true)) {
        $paths = [];
        if ($action === 'web-cache') {
            gate(is_file(ROOT.'/storage/framework/down'), 'maintenance_required');
            $list = NovinPay\Release\command(['git', 'diff', '--name-only', $manifest['baseline'], $manifest['target'], '--', 'app'], ROOT, 'changed_php');
            gate($list['exit'] === 0, 'changed_php');
            foreach (preg_split('/\r?\n/', trim($list['stdout']), -1, PREG_SPLIT_NO_EMPTY) as $path) {
                gate(preg_match('~\A(?:(?:app|lang)/[A-Za-z0-9_./-]+\.php)\z~D', $path) === 1, 'changed_php_path');
                $paths[] = ROOT.'/'.$path;
            }
            array_push($paths, ROOT.'/bootstrap/cache/config.php', ...glob(ROOT.'/storage/framework/views/*.php'));
        }
        $token = bin2hex(random_bytes(32));
        $endpoint = ROOT.'/public/_np_admin_cache_'.bin2hex(random_bytes(12)).'.php';
        $source = '<?php ini_set("display_errors","0");if(!hash_equals('.var_export(hash('sha256', $token), true).',hash("sha256",$_SERVER["HTTP_X_NP_RELEASE"]??""))){http_response_code(404);exit;}header("Cache-Control: no-store");$ok=PHP_VERSION_ID>=80401&&class_exists("IntlDateFormatter");$paths='.var_export(array_values(array_unique($paths)), true).';if($ok&&$paths&&ini_get("opcache.enable")){$ok=function_exists("opcache_invalidate")&&function_exists("opcache_is_script_cached");foreach($paths as $p){if($ok&&opcache_is_script_cached($p)&&!opcache_invalidate($p,true)){$ok=false;break;}}}http_response_code($ok?200:503);echo $ok?"ADMIN_WEB_PASS":"ADMIN_WEB_STOP";';
        $handle = fopen($endpoint, 'xb');
        gate(is_resource($handle), 'web_probe_create');
        try {
            gate(fwrite($handle, $source) === strlen($source) && fflush($handle) && fsync($handle), 'web_probe_write');
            fclose($handle);
            $handle = null;
            $curl = curl_init('https://novinpaypos.com/'.basename($endpoint));
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['X-NP-Release: '.$token]]);
            $body = curl_exec($curl);
            gate(curl_errno($curl) === 0 && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200 && $body === 'ADMIN_WEB_PASS', 'web_probe_response');
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            gate(is_file($endpoint) && ! is_link($endpoint) && unlink($endpoint), 'web_probe_cleanup');
        }
        echo "ADMIN_WEB_PASS\n";
        exit(0);
    }

    gate(class_exists(IntlDateFormatter::class), 'cli_intl_required');
    require ROOT.'/vendor/autoload.php';
    $app = require ROOT.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    gate($app->environment('production') && config('app.url') === 'https://novinpaypos.com', 'production_environment');
    foreach (['customer.notifications_enabled', 'payments.zibal.enabled', 'payments.zibal.live_requests', 'postex.enabled', 'postex.independent_postage_verified'] as $flag) {
        gate(config($flag) === false, 'provider_flag');
    }
    $pdo = DB::connection()->getPdo();
    $identity = $pdo->query('SELECT DATABASE(),CURRENT_USER(),VERSION()')->fetch(PDO::FETCH_NUM);
    gate($identity[0] === 'novinpay_live1' && $identity[1] === 'novinpay_app1@localhost' && str_starts_with($identity[2], '10.6.28'), 'database_identity');
    $migrations = $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
    gate(count($migrations) === count($manifest['migrations']) && $migrations === $manifest['migrations'], 'migration_history');
    foreach (['admin.dashboard', 'admin.products.index', 'admin.inventory.index', 'admin.repair-cases.index', 'admin.orders.index', 'admin.customers.index', 'admin.finance.reports', 'admin.deliveries.index', 'admin.test-customers.index', 'account.login', 'support.panel'] as $route) {
        gate(Route::has($route), 'required_routes');
    }
    NovinPay\Release\sourceTriggerEvidence($manifest);
    echo "ADMIN_RUNTIME_PASS\n";
} catch (Throwable $error) {
    if (function_exists('NovinPay\Release\failureReport')) {
        $report = NovinPay\Release\failureReport($error, $action, 'source_release_runtime');
        $report['result'] = 'SOURCE_RELEASE_CHECK_STOP';
        echo json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL;
        exit(1);
    }
    $reason = $error instanceof GateFailure ? $error->check : $error->getMessage();
    echo json_encode(['result' => 'ADMIN_RELEASE_CHECK_STOP', 'check' => preg_match('/\A[a-z0-9_]+\z/D', $reason) ? $reason : 'details_withheld', 'retrySafe' => false], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
