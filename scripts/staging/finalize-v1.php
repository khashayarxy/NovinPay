<?php

declare(strict_types=1);
use App\Models\AdminAccount;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('zend.exception_ignore_args', '1');
function np_need(bool $ok, string $code): void
{
    if (! $ok) {
        throw new RuntimeException($code);
    }
}
function np_path(string $name): void
{
    np_need(preg_match('~\A(?:app|config|database/migrations|resources|routes|public/build)/[a-zA-Z0-9_./-]+\z~D', $name) === 1 && ! str_contains($name, '..') && ! str_contains($name, '//'), 'package_path');
}
function np_write(string $path, string $bytes, int $mode = 0600): void
{
    np_need(! is_link($path) && (! file_exists($path) || is_file($path)), 'file_substitution');
    for ($parent = dirname($path); $parent !== dirname($parent); $parent = dirname($parent)) {
        np_need(! is_link($parent), 'parent_substitution');
    }
    if (! is_dir(dirname($path))) {
        np_need(mkdir(dirname($path), 0755, true), 'parent_create');
    }
    $tmp = $path.'.'.bin2hex(random_bytes(10)).'.tmp';
    $f = fopen($tmp, 'x+b');
    np_need(is_resource($f), 'temporary_create');
    try {
        np_need(chmod($tmp, $mode) && fwrite($f, $bytes) === strlen($bytes) && fflush($f) && fsync($f), 'durable_write');
    } finally {
        fclose($f);
    }
    np_need(rename($tmp, $path), 'atomic_replace');
}
function np_run(array $command, string $directory, ?string $output = null): void
{
    $errorStream = tmpfile();
    np_need(is_resource($errorStream), 'private_process_diagnostic');
    $descriptors = [0 => ['pipe', 'r'], 1 => $output ? ['file', $output, 'x'] : ['pipe', 'w'], 2 => $errorStream];
    $process = proc_open($command, $descriptors, $pipes, $directory);
    np_need(is_resource($process), 'process_start');
    fclose($pipes[0]);
    $out = '';
    if (! $output) {
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
    }
    $exit = proc_close($process);
    rewind($errorStream);
    $error = stream_get_contents($errorStream);
    fclose($errorStream);
    $detail = 'details_withheld';
    if (preg_match('~STAGING FINISH STOP: phase=([a-z_]+); category=([a-zA-Z0-9_./-]+)~', $out, $match)) {
        $detail = $match[1].'_'.$match[2];
    } elseif (str_contains($error, 'Permission denied')) {
        $detail = 'permission_denied';
    } elseif (str_contains($error, 'No space left')) {
        $detail = 'disk_full';
    }
    np_need($exit === 0, 'command_'.basename($command[0]).'_exit_'.$exit.'_'.$detail);
}
function np_snapshot(PDO $pdo): array
{
    $result = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        np_need(preg_match('/\A[a-z0-9_]+\z/D', $table) === 1, 'table_name');
        if ($table === 'migrations') {
            continue;
        }
        $columns = $pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN);
        $order = implode(',', array_map(fn ($c) => '`'.$c.'`', $columns));
        $hash = hash_init('sha256');
        $count = 0;
        $rows = $pdo->query('SELECT '.$order.' FROM `'.$table.'` ORDER BY '.$order);
        while ($row = $rows->fetch(PDO::FETCH_NUM)) {
            hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR)."\n");
            $count++;
        }
        $result[$table] = ['columns' => $columns, 'rows' => $count, 'sha256' => hash_final($hash)];
    }

    return $result;
}
function np_compare(PDO $pdo, array $before): void
{
    foreach ($before as $table => $state) {
        $order = implode(',', array_map(fn ($c) => '`'.$c.'`', $state['columns']));
        $rows = $pdo->query('SELECT '.$order.' FROM `'.$table.'` ORDER BY '.$order);
        $hash = hash_init('sha256');
        $count = 0;
        while ($row = $rows->fetch(PDO::FETCH_NUM)) {
            hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR)."\n");
            $count++;
        }
        np_need($count === $state['rows'] && hash_final($hash) === $state['sha256'], 'existing_table_changed_'.$table);
    }
}

$phase = 'identity';
$admitted = false;
$probe = null;
try {
    $stage = '/home/novinpay/novinpay-staging-workflow';
    $kit = '/home/novinpay/novinpay-staging-38b9117-kit';
    $php = '/opt/cpanel/ea-php84/root/usr/bin/php';
    np_need(PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80401 && posix_geteuid() === 1321 && $kit === __DIR__ && realpath($stage) === $stage, 'execution_identity');
    np_need(hash_file('sha256', $kit.'/repair-chat-update-v5.json') === 'da63dedef7ac9c005c22d59b698d763e56f42312ca7d08f51e1a18c3b797e707', 'manifest_identity');
    $manifest = json_decode(file_get_contents($kit.'/repair-chat-update-v5.json'), true, 64, JSON_THROW_ON_ERROR);
    foreach (['triggerLibrarySha256' => 'repair-chat-triggers-v3.php', 'basicLibrarySha256' => 'repair-chat-basic-v1.php', 'dumpLibrarySha256' => 'repair-chat-dump-v1.php'] as $key => $file) {
        np_need(! is_link($kit.'/'.$file) && hash_file('sha256', $kit.'/'.$file) === $manifest[$key], 'library_integrity');
        require_once $kit.'/'.$file;
    }
    $lock = fopen($kit.'/repair-chat-finalize-v1.lock', 'c+b');
    np_need(is_resource($lock) && chmod($kit.'/repair-chat-finalize-v1.lock', 0600) && flock($lock, LOCK_EX | LOCK_NB), 'finalizer_already_running');
    $startPath = $kit.'/repair-chat-started-v5.json';
    np_need(is_file($startPath) && ! is_link($startPath), 'started_marker');
    $start = json_decode(file_get_contents($startPath), true, 16, JSON_THROW_ON_ERROR);
    np_need($start['source'] === $manifest['source'] && $start['recovery'] === $kit.'/repair-chat-recovery-20261104091638-a81a5c2c', 'recorded_attempt');
    $sourceHash = hash_file('sha256', $startPath);
    $backup = $start['recovery'].'/snapshot.npsb';
    np_need(is_file($backup) && ! is_link($backup) && (fileperms($backup) & 0777) === 0600, 'encrypted_recovery');
    $backupHash = hash_file('sha256', $backup);
    foreach ($manifest['files'] as $name => $hash) {
        np_path($name);
        np_need(is_file($stage.'/'.$name) && ! is_link($stage.'/'.$name) && hash_file('sha256', $stage.'/'.$name) === $hash, 'installed_file_'.$name);
    }
    np_need(hash_file('sha256', $stage.'/public/.htaccess') === $manifest['htaccessSha256'], 'basic_rules');
    $httpAuth = np_basic_load($kit, $manifest);
    $httpAuthHash = hash_file('sha256', $kit.'/.staging-http-basic.json');
    require $stage.'/vendor/autoload.php';
    $env = Dotenv\Dotenv::parse(file_get_contents($stage.'/.env'));
    $envHash = hash_file('sha256', $stage.'/.env');
    np_need($env['APP_ENV'] === 'staging' && $env['DB_DATABASE'] === 'novinpay_stg38' && $env['DB_USERNAME'] === 'novinpay_s38run' && empty($env['DB_URL']), 'environment');
    $secret = json_decode(file_get_contents($kit.'/bootstrap-secrets.json'), true, 16, JSON_THROW_ON_ERROR);
    $observer = new PDO('mysql:host=localhost;dbname=novinpay_stg38;charset=utf8mb4', $secret['migrator_user'], $secret['migrator_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    np_stage_trigger_inventory($observer, $manifest['finalTriggers']);
    $observer = null;
    $encoded = file_get_contents($backup);
    np_need(substr($encoded, 0, 5) === 'NPSB1', 'backup_format');
    $key = hash_hkdf('sha256', $env['APP_KEY'], 32, 'novinpay-staging-backup-v1', substr($encoded, 5, 32));
    $plain = openssl_decrypt(substr($encoded, 65), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($encoded, 37, 12), substr($encoded, 49, 16), 'NPSB1');
    np_need(is_string($plain) && str_starts_with($plain, 'PK'), 'backup_authenticated_decryption');
    unset($plain,$key,$encoded);
    foreach (['SMSIR_ENABLED', 'SMSIR_NOTIFICATIONS_ENABLED', 'ZIBAL_ENABLED', 'ZIBAL_LIVE_REQUESTS', 'POSTEX_QUOTES_ENABLED', 'POSTEX_INDEPENDENT_POSTAGE_VERIFIED', 'BANK_TRANSFER_ENABLED'] as $flag) {
        np_need(($env[$flag] ?? '') === 'false', 'private_provider_guard');
    }
    $phase = 'application_readiness';
    chdir($stage);
    putenv('APP_CONFIG_CACHE='.$stage.'/bootstrap/cache/config.php');
    $app = require $stage.'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    np_need(! config('app.debug') && app()->environment('staging') && config('app.url') === 'https://staging.novinpaypos.com' && config('support_chat.enabled') === true, 'application_configuration');
    foreach (['customer.sms_enabled', 'customer.notifications_enabled', 'payments.zibal.enabled', 'payments.zibal.live_requests', 'postex.independent_postage_verified'] as $flag) {
        np_need(! config($flag), 'provider_disabled');
    }
    $pdo = DB::connection()->getPdo();
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    np_need($pdo->query('SELECT DATABASE()')->fetchColumn() === 'novinpay_stg38' && $pdo->query('SELECT CURRENT_USER()')->fetchColumn() === 'novinpay_s38run@localhost', 'runtime_identity');
    $applied = $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
    $expected = $manifest['migrations'];
    sort($expected);
    np_need($applied === $expected && count($applied) === 22, 'exact_migrations');
    np_need(AdminAccount::query()->where('owner_slot', 1)->where('is_active', true)->count() === 1, 'owner_preserved');
    $pdo->rollBack();
    foreach (['storage/app', 'storage/framework/views', 'storage/framework/sessions', 'bootstrap/cache'] as $dir) {
        np_need(is_dir($stage.'/'.$dir) && is_writable($stage.'/'.$dir) && ! is_link($stage.'/'.$dir), 'storage_permissions');
    }
    np_need(is_file(config('support_chat.directory').'/installation.env') && ! is_link(config('support_chat.directory')) && (fileperms(config('support_chat.directory')) & 0777) === 0700, 'private_chat_initialized');
    np_need($kernel->call('route:cache', ['--no-interaction' => true]) === 0 && $kernel->call('view:cache', ['--no-interaction' => true]) === 0, 'cache_readiness');
    foreach (['/', '/products', '/repair', '/admin/login', '/admin/repairs', '/admin/repairs/intake', '/admin/support', '/support-chat/api.php'] as $path) {
        np_need(app('router')->getRoutes()->match(Request::create('https://staging.novinpaypos.com'.$path, 'GET')) !== null, 'route_readiness');
    }
    $probeToken = bin2hex(random_bytes(32));
    $probe = $stage.'/public/np-chat-web-'.bin2hex(random_bytes(12)).'.php';
    $invalidate = $kit.'/repair-chat-opcache-finalize-v1.json';
    $probeCode = '<?php ini_set("display_errors","0"); header("Cache-Control: no-store"); header("X-Robots-Tag: noindex, nofollow"); if (!hash_equals("'.hash('sha256', $probeToken).'",hash("sha256",$_SERVER["HTTP_X_NP_PROBE"]??""))) {http_response_code(404);exit;} $ok=true; $paths=is_file("'.$invalidate.'")?json_decode(file_get_contents("'.$invalidate.'"),true):[]; if(!is_array($paths)||count($paths)>2000){http_response_code(500);echo "{}";exit;} foreach($paths as $p){if(!is_string($p)||!str_starts_with($p,"'.$stage.'/")||is_link($p)||!is_file($p)){$ok=false;break;}if(function_exists("opcache_is_script_cached")&&opcache_is_script_cached($p)){$ok=$ok&&function_exists("opcache_invalidate")&&opcache_invalidate($p,true);}} header("Content-Type: application/json");echo json_encode(["php"=>PHP_VERSION_ID,"uid"=>function_exists("posix_geteuid")?posix_geteuid():null,"sapi"=>PHP_SAPI,"gd"=>function_exists("imagewebp"),"fsync"=>function_exists("fsync"),"opcache"=>$ok]);';
    np_write($probe, $probeCode);
    $fetch = function (string $path, bool $basic = true, bool $isProbe = false) use ($httpAuth, $httpAuthHash, $kit, $manifest, $probeToken): array {
        np_need(hash_equals($httpAuthHash, hash_file('sha256', $kit.'/.staging-http-basic.json')) && hash_equals($manifest['basicPasswordFileSha256'], hash_file('sha256', $kit.'/.htpasswd')), 'basic_credentials_changed_during_update');
        $c = curl_init('https://staging.novinpaypos.com'.$path);
        np_need($c !== false, 'https_start');
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 25, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => ['Cache-Control: no-cache']];
        if ($basic) {
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERPWD] = $httpAuth['username'].':'.$httpAuth['password'];
        }if ($isProbe) {
            $options[CURLOPT_HTTPHEADER][] = 'X-NP-Probe: '.$probeToken;
        }curl_setopt_array($c, $options);
        $body = curl_exec($c);
        $code = curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($c);
        curl_close($c);
        np_need($body !== false && $error === 0, 'external_network_pending');

        return ['code' => $code, 'body' => $body];
    };
    $phase = 'web_prerequisites';
    $web = $fetch('/'.basename($probe), true, true);
    np_need($web['code'] === 200, 'web_probe_http_'.$web['code']);
    $web = json_decode($web['body'], true, 8, JSON_THROW_ON_ERROR);
    np_need($web['php'] >= 80401 && $web['uid'] === 1321 && in_array($web['sapi'], ['litespeed', 'fpm-fcgi', 'cgi-fcgi'], true) && $web['gd'] && $web['fsync'] && $web['opcache'], 'web_php_identity');
    np_need($fetch('/', false)['code'] === 401, 'basic_protection');

    $paths = [$probe];
    foreach (array_keys($manifest['files']) as $name) {
        if (str_ends_with($name, '.php')) {
            $paths[] = $stage.'/'.$name;
        }
    }foreach (array_merge(glob($stage.'/storage/framework/views/*.php'), glob($stage.'/bootstrap/cache/*.php')) as $p) {
        $paths[] = $p;
    }
    np_write($invalidate, json_encode(array_values(array_unique($paths)), JSON_THROW_ON_ERROR));
    $web = $fetch('/'.basename($probe), true, true);
    np_need($web['code'] === 200 && json_decode($web['body'], true)['opcache'] === true, 'app_scoped_opcache');
    np_need(hash_equals($envHash, hash_file('sha256', $stage.'/.env')) && hash_equals($sourceHash, hash_file('sha256', $startPath)) && hash_equals($backupHash, hash_file('sha256', $backup)), 'preservation_before_admission');
    $phase = 'traffic_admission';
    np_need($kernel->call('up', ['--no-interaction' => true]) === 0, 'maintenance_exit');
    $admitted = true;
    foreach (['/', '/products', '/repair', '/repair/track', '/contact', '/articles', '/account/login', '/admin/login', '/support-chat/support-widget.js', '/support-chat/support-widget.php', '/support-chat/api.php?get_csrf=1'] as $path) {
        np_need($fetch($path)['code'] === 200, 'https_route_'.$path);
    }
    np_need($fetch('/admin')['code'] === 302 && $fetch('/support-chat/operator-api.php')['code'] === 401 && $fetch('/support-chat/support/bootstrap.php')['code'] === 404 && $fetch('/', false)['code'] === 401, 'auth_isolation');
    $phase = 'temporary_probe_cleanup';
    np_need(unlink($probe) && ! file_exists($probe), 'probe_cleanup');
    np_need($fetch('/'.basename($probe))['code'] === 404, 'probe_removed_https');

    $receipt = ['result' => 'ISOLATED_STAGING_FINALIZATION_PASS', 'source_commit' => $manifest['source'], 'migrations' => 22, 'triggers' => 13, 'maintenance_off' => ! is_file($stage.'/storage/framework/down'), 'recovery_sha256' => $backupHash, 'started_marker_sha256' => $sourceHash, 'no_migrations_replayed' => true, 'production_modified' => false, 'https_checks_passed' => true, 'at_utc' => gmdate(DATE_ATOM)];
    $path = $kit.'/repair-chat-finalization-pass-v1.json';
    if (is_file($path)) {
        $old = json_decode(file_get_contents($path), true);
        np_need($old['source_commit'] === $receipt['source_commit'] && $old['recovery_sha256'] === $backupHash, 'existing_receipt_identity');
    } else {
        np_write($path, json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
    echo json_encode($receipt, JSON_PRETTY_PRINT),"\nISOLATED STAGING FINALIZATION PASS\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }if ($admitted) {
        try {
            $kernel->call('down', ['--retry' => 60, '--no-interaction' => true]);
        } catch (Throwable) {
        }
    }$code = $e instanceof RuntimeException && preg_match('~\A[a-zA-Z0-9_./-]+\z~D', $e->getMessage()) ? $e->getMessage() : 'details_withheld';
    echo "FINALIZATION STOP: phase={$phase}; category={$code}; preserve evidence.\n";
    exit(1);
} finally {
    if ($probe) {
        clearstatcache(true, $probe);
        if (is_file($probe)) {
            unlink($probe);
        }
    }if (isset($lock) && is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
