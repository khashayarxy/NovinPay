<?php

declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('zend.exception_ignore_args', '1');
umask(0077);
const STAGE = '/home/novinpay/novinpay-staging-workflow';
const KIT = '/home/novinpay/novinpay-staging-38b9117-kit';
const PACKAGES = ['symfony/http-foundation', 'symfony/http-kernel', 'symfony/routing'];
$phase = 'identity';
$armed = false;
$probe = null;
$out = null;
$lock = null;
function sp_need(bool $ok, string $check): void
{
    if (! $ok) {
        throw new RuntimeException($check);
    }
}
function sp_read(string $path): string
{
    sp_need(is_file($path) && ! is_link($path) && fileowner($path) === 1321, 'private_file_identity');

    return (string) file_get_contents($path);
}
function sp_put(string $path, string $bytes): void
{
    sp_need(! file_exists($path) && ! is_link($path), 'evidence_exists');
    $h = fopen($path, 'xb');
    sp_need(is_resource($h), 'evidence_create');
    try {
        sp_need(chmod($path, 0600) && fwrite($h, $bytes) === strlen($bytes) && fflush($h) && fsync($h), 'evidence_flush');
    } finally {
        fclose($h);
    }
}
function sp_run(array $args, string $label): string
{
    global $out;
    sp_need(preg_match('/\A[a-z_]+\z/D', $label) === 1, 'operation_label');
    $error = tmpfile();
    $p = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => $error], $pipes, STAGE, null, ['bypass_shell' => true]);
    sp_need(is_resource($p), 'process_start');
    stream_set_blocking($pipes[1], false);
    $text = '';
    $exit = null;
    $deadline = microtime(true) + 300;
    try {
        do {
            $text .= stream_get_contents($pipes[1]);
            sp_need(strlen($text) <= 8388608, 'bounded_output');
            $s = proc_get_status($p);
            if (! $s['running']) {
                $exit = $s['exitcode'];
                break;
            }sp_need(microtime(true) < $deadline, 'process_timeout');
            usleep(50000);
        } while (true);
        $text .= stream_get_contents($pipes[1]);
    } finally {
        if ($exit === null) {
            proc_terminate($p);
        }fclose($pipes[1]);
        $close = proc_close($p);
    }rewind($error);
    $stderr = (string) stream_get_contents($error);
    fclose($error);
    if ($out) {
        sp_put($out.'/'.$label.'.log', $text."\nSTDERR\n".$stderr);
    }sp_need(($exit !== null && $exit >= 0 ? $exit : $close) === 0, $label.'_failed');

    return $text;
}
function sp_versions(string $file): array
{
    $j = json_decode(sp_read($file), true, 512, JSON_THROW_ON_ERROR);
    $packages = $j['packages'] ?? $j;
    $result = [];
    foreach ($packages as $p) {
        $result[$p['name']] = ['version' => $p['version'], 'reference' => $p['source']['reference'] ?? null];
    }ksort($result);

    return $result;
}
function sp_http(string $path, array $auth, ?string $token = null): array
{
    $h = curl_init('https://staging.novinpaypos.com'.$path);
    $headers = [];
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_USERPWD => $auth['username'].':'.$auth['password'], CURLOPT_HTTPHEADER => $token ? ['X-NP-Probe: '.$token] : [], CURLOPT_HEADERFUNCTION => static function ($h, $line) use (&$headers) {
        $p = explode(':', $line, 2);
        if (count($p) === 2 && in_array(strtolower(trim($p[0])), ['content-type', 'server-timing', 'cache-control'], true)) {
            $headers[strtolower(trim($p[0]))] = trim($p[1]);
        }

return strlen($line);
    }]);
    $start = hrtime(true);
    $body = curl_exec($h);
    $r = ['http' => (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE), 'curlErrno' => curl_errno($h), 'elapsed_ms' => round((hrtime(true) - $start) / 1e6, 2), 'headers' => $headers, 'body' => $body];
    curl_close($h);
    $GLOBALS['sp_last_http'] = ['http' => $r['http'], 'curlErrno' => $r['curlErrno']];
    sp_need(is_string($body) && $r['curlErrno'] === 0, 'https_transport');
    sp_need(! preg_match('/SUPPORT_[A-Z0-9_]+\s*=|APP_KEY\s*=|DB_PASSWORD\s*=|\/home\/novinpay\//', $body), 'response_disclosure');

    return $r;
}
function sp_cleanup_probe(): bool
{
    global $probe;
    if ($probe === null) {
        return true;
    }if (! preg_match('~\A'.preg_quote(STAGE.'/public/np-security-', '~').'[a-f0-9]{24}\.php\z~D', $probe) || is_link($probe)) {
        return false;
    }if (file_exists($probe) && (! is_file($probe) || ! unlink($probe))) {
        return false;
    }$probe = null;

    return true;
}

/** Bind the manifest to the executing helper, never a copied version literal. */
function sp_manifest(string $helper, string $pin, callable $read): array
{
    sp_need(preg_match('/\A[a-f0-9]{64}\z/D', $pin) === 1, 'manifest_pin_format');
    $raw = $read(substr($helper, 0, -4).'.json');
    sp_need(hash_equals($pin, hash('sha256', $raw)), 'manifest_pin');

    return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
}

// Including this file for isolated contract tests must never execute host operations.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}
try {
    sp_need(PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80401 && function_exists('posix_geteuid') && posix_geteuid() === 1321 && __DIR__ === KIT && realpath(STAGE) === STAGE && ! is_link(STAGE) && count($argv) === 2, 'execution_identity');
    $m = sp_manifest(__FILE__, $argv[1], 'sp_read');
    foreach (['helper' => basename(__FILE__), 'composer' => 'repair-chat-security-composer-v1.b64', 'lock' => 'repair-chat-security-composer-v1.lock', 'operators' => 'repair-chat-security-operators-v1.php', 'basic' => 'repair-chat-basic-v1.php'] as $key => $name) {
        sp_need(hash_equals($m['hashes'][$key], hash('sha256', sp_read(KIT.'/'.$name))), 'artifact_'.$key);
    }
    sp_need(hash('sha256', sp_read(STAGE.'/composer.lock')) === $m['oldLockSha256'], 'old_lock_identity');
    sp_need(hash('sha256', sp_read(STAGE.'/composer.json')) === $m['composerJsonSha256'], 'composer_json_identity');
    sp_need(hash('sha256', sp_read(STAGE.'/resources/support-chat/support/operators.php')) === $m['oldOperatorsSha256'], 'old_operators_identity');
    sp_need(hash('sha256', sp_read(KIT.'/chat-authority-20261105-v1-operators.before')) === $m['oldOperatorsSha256'], 'interrupted_backup_identity');
    foreach (['vendor', 'vendor/composer', 'vendor/symfony', 'vendor/symfony/http-foundation', 'vendor/symfony/http-kernel', 'vendor/symfony/routing', 'public', 'bootstrap/cache', 'storage/framework'] as $dir) {
        sp_need(! is_link(STAGE.'/'.$dir) && realpath(STAGE.'/'.$dir) === STAGE.'/'.$dir, 'directory_substitution');
    }
    $old = json_decode(sp_read(STAGE.'/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    $new = json_decode(sp_read(KIT.'/repair-chat-security-composer-v1.lock'), true, 512, JSON_THROW_ON_ERROR);
    $changed = [];
    foreach (['packages', 'packages-dev'] as $group) {
        $a = array_column($old[$group], null, 'name');
        $b = array_column($new[$group], null, 'name');
        sp_need(array_keys($a) === array_keys($b), 'lock_inventory');
        foreach ($a as $name => $pkg) {
            if ($pkg !== $b[$name]) {
                sp_need($group === 'packages' && in_array($name, PACKAGES, true) && $pkg['version'] === 'v8.1.8' && $b[$name]['version'] === 'v8.1.11', 'only_three_security_updates');
                $changed[] = $name;
            }
        }
    }
    sort($changed);
    sp_need($changed === PACKAGES, 'three_updates_required');
    $runtimeBefore = [];
    $runtimeAfter = [];
    foreach ($old['packages'] as $p) {
        $runtimeBefore[$p['name']] = ['version' => $p['version'], 'reference' => $p['source']['reference'] ?? null];
    }foreach ($new['packages'] as $p) {
        $runtimeAfter[$p['name']] = ['version' => $p['version'], 'reference' => $p['source']['reference'] ?? null];
    }ksort($runtimeBefore);
    ksort($runtimeAfter);
    unset($old['packages'],$old['packages-dev'],$new['packages'],$new['packages-dev']);
    sp_need($old === $new, 'root_lock_metadata');
    $versions = sp_versions(STAGE.'/vendor/composer/installed.json');
    sp_need($versions === $runtimeBefore, 'runtime_matches_pinned_lock');
    foreach (PACKAGES as $name) {
        sp_need(($versions[$name]['version'] ?? null) === 'v8.1.8', 'installed_baseline');
    }
    sp_need(is_file(STAGE.'/vendor/autoload.php') && ! file_exists(STAGE.'/storage/framework/down') && ! file_exists(STAGE.'/storage/framework/maintenance.php'), 'existing_runtime_fence');
    require STAGE.'/vendor/autoload.php';
    $env = Dotenv\Dotenv::parse(sp_read(STAGE.'/.env'));
    sp_need(($env['APP_ENV'] ?? null) === 'staging' && ($env['APP_URL'] ?? null) === 'https://staging.novinpaypos.com' && ($env['DB_DATABASE'] ?? null) === 'novinpay_stg38' && ($env['DB_USERNAME'] ?? null) === 'novinpay_s38run' && empty($env['DB_URL']), 'staging_environment');
    unset($env);
    require KIT.'/repair-chat-basic-v1.php';
    $auth = np_basic_load(KIT, $m);
    $state = STAGE.'/storage/framework/site-maintenance/state.json';
    if (is_file($state)) {
        sp_need((json_decode(sp_read($state), true, 16, JSON_THROW_ON_ERROR)['enabled'] ?? null) === false, 'public_maintenance_state');
    }
    $protected = [];
    foreach (['.env', 'public/.htaccess', 'composer.json'] as $name) {
        $protected[$name] = hash('sha256', sp_read(STAGE.'/'.$name));
    }if (is_file($state)) {
        $protected['storage/framework/site-maintenance/state.json'] = hash('sha256', sp_read($state));
    }
    sp_need(disk_free_space(STAGE) > 536870912, 'physical_headroom');
    $lock = fopen(KIT.'/repair-chat-security-operation.lock', 'c+b');
    sp_need(is_resource($lock) && chmod(KIT.'/repair-chat-security-operation.lock', 0600) && flock($lock, LOCK_EX | LOCK_NB), 'concurrent_operation');
    $out = KIT.'/symfony-security-v1-'.substr($argv[1], 0, 12);
    sp_need(! file_exists($out) && ! is_link($out), 'attempt_exists_preserve');
    sp_need(mkdir($out, 0700), 'private_evidence');
    sp_put($out.'/started.json', json_encode(['manifest' => $argv[1], 'sourceCommit' => $m['sourceCommit'], 'atUtc' => gmdate('c')], JSON_THROW_ON_ERROR));
    $composerBytes = base64_decode(sp_read(KIT.'/repair-chat-security-composer-v1.b64'), true);
    sp_need(is_string($composerBytes) && hash_equals($m['composerPharSha256'], hash('sha256', $composerBytes)), 'composer_phar_identity');
    sp_put($out.'/composer.phar', $composerBytes);
    unset($composerBytes);
    $phase = 'code_backup';
    $backupArgs = ['/bin/tar', '-czf', $out.'/code-before.tar.gz', '-C', STAGE, 'vendor', 'composer.lock', 'resources/support-chat/support/operators.php'];
    if (is_file(STAGE.'/bootstrap/cache/routes-v7.php')) {
        $backupArgs[] = 'bootstrap/cache/routes-v7.php';
    }sp_run($backupArgs, 'code_backup');
    sp_need(chmod($out.'/code-before.tar.gz', 0600) && filesize($out.'/code-before.tar.gz') > 1000, 'code_backup_integrity');
    sp_run(['/bin/tar', '-tzf', $out.'/code-before.tar.gz'], 'code_backup_check');
    $phase = 'fence';
    $armed = true;
    sp_run([PHP_BINARY, STAGE.'/artisan', 'down', '--retry=60', '--no-interaction'], 'staging_down');
    sp_need(is_file(STAGE.'/storage/framework/down'), 'fence_exists');
    sp_need(sp_http('/', $auth)['http'] === 503, 'https_fence');
    $phase = 'dependencies';
    $tmp = STAGE.'/composer.lock.security-v1.tmp';
    sp_put($tmp, sp_read(KIT.'/repair-chat-security-composer-v1.lock'));
    sp_need(rename($tmp, STAGE.'/composer.lock'), 'lock_atomic_publish');
    putenv('COMPOSER_HOME='.$out.'/composer-home');
    putenv('COMPOSER_CACHE_DIR='.$out.'/composer-cache');
    putenv('COMPOSER_NO_INTERACTION=1');
    sp_run([PHP_BINARY, $out.'/composer.phar', 'install', '--no-dev', '--no-scripts', '--no-plugins', '--prefer-dist', '--no-interaction', '--no-progress'], 'composer_install');
    $after = sp_versions(STAGE.'/vendor/composer/installed.json');
    sp_need($after === $runtimeAfter, 'installed_reference_identity');
    sp_need(array_keys($versions) === array_keys($after), 'installed_inventory_preserved');
    foreach ($versions as $name => $value) {
        if (in_array($name, PACKAGES, true)) {
            sp_need($after[$name]['version'] === 'v8.1.11', 'installed_patch_version');
        } else {
            sp_need($value === $after[$name], 'unrelated_dependency_preserved');
        }
    }
    $audit = json_decode(sp_run([PHP_BINARY, $out.'/composer.phar', 'audit', '--locked', '--format=json', '--no-interaction'], 'composer_audit'), true, 32, JSON_THROW_ON_ERROR);
    sp_need(empty($audit['advisories']) && empty($audit['filter']), 'audit_zero');
    $phase = 'interrupted_patch_resume';
    $op = STAGE.'/resources/support-chat/support/operators.php';
    sp_need(hash('sha256', sp_read($op)) === $m['oldOperatorsSha256'], 'operators_unchanged');
    $tmp = $op.'.security-v1.tmp';
    sp_put($tmp, sp_read(KIT.'/repair-chat-security-operators-v1.php'));
    sp_need(rename($tmp, $op), 'operators_atomic_publish');
    $phase = 'routes_and_local_readiness';
    sp_run([PHP_BINARY, STAGE.'/artisan', 'route:cache', '--no-interaction'], 'route_cache');
    $check = 'require '.var_export(STAGE.'/vendor/autoload.php', true).';$app=require '.var_export(STAGE.'/bootstrap/app.php', true).';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();if(app()->environment()!=="staging"||config("database.connections.mariadb.database")!=="novinpay_stg38")exit(11);foreach(["customer.sms_enabled","customer.notifications_enabled","payments.zibal.enabled","payments.zibal.live_requests","postex.independent_postage_verified"]as $k)if(config($k))exit(12);$db=Illuminate\\Support\\Facades\\DB::connection()->getPdo();$db->exec("SET TRANSACTION READ ONLY");$db->beginTransaction();try{if($db->query("SELECT DATABASE()")->fetchColumn()!=="novinpay_stg38"||(int)$db->query("SELECT COUNT(*) FROM migrations")->fetchColumn()!==22)exit(13);if(App\\Models\\AdminAccount::where("owner_slot",1)->where("is_active",true)->count()!==1)exit(14);}finally{$db->rollBack();}foreach(["admin.support.index","admin.login","support.chat"]as $r)if(!Illuminate\\Support\\Facades\\Route::has($r))exit(15);echo "LOCAL_STAGING_SECURITY_READINESS_PASS";';
    $check = str_replace('\\\\', '\\', $check);
    sp_run([PHP_BINARY, '-r', $check], 'local_readiness');
    $phase = 'web_opcode_and_version';
    $token = bin2hex(random_bytes(32));
    $probe = STAGE.'/public/np-security-'.bin2hex(random_bytes(12)).'.php';
    $code = '<?php ini_set("display_errors","0");header("Cache-Control:no-store");header("X-Robots-Tag:noindex");if(!hash_equals('.var_export(hash('sha256', $token), true).',hash("sha256",$_SERVER["HTTP_X_NP_PROBE"]??""))){http_response_code(404);exit;} $root='.var_export(STAGE, true).';$paths=[$root."/vendor/composer",$root."/vendor/symfony/http-foundation",$root."/vendor/symfony/http-kernel",$root."/vendor/symfony/routing"];$ok=true;foreach($paths as $path)foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS))as $f)if($f->isFile()&&$f->getExtension()==="php"&&function_exists("opcache_is_script_cached")&&opcache_is_script_cached($f->getPathname()))$ok=$ok&&opcache_invalidate($f->getPathname(),true);foreach([$root."/resources/support-chat/support/operators.php",$root."/bootstrap/cache/routes-v7.php"]as $file)if(function_exists("opcache_is_script_cached")&&opcache_is_script_cached($file))$ok=$ok&&opcache_invalidate($file,true);require $root."/vendor/autoload.php";foreach(["symfony/http-foundation","symfony/http-kernel","symfony/routing"]as $p)$ok=$ok&&Composer\\InstalledVersions::getPrettyVersion($p)==="v8.1.11";http_response_code($ok?200:503);echo $ok?"WEB_SECURITY_PATCH_PASS":"WEB_SECURITY_PATCH_STOP";';
    $code = str_replace('\\\\', '\\', $code);
    sp_put($probe, $code);
    $web = sp_http('/'.basename($probe), $auth, $token);
    sp_need($web['http'] === 200 && $web['body'] === 'WEB_SECURITY_PATCH_PASS', 'web_package_version');
    sp_need(unlink($probe), 'probe_cleanup');
    $probe = null;
    foreach ($protected as $name => $hash) {
        sp_need(hash_equals($hash, hash('sha256', sp_read(STAGE.'/'.$name))), 'protected_state_preserved');
    }
    $phase = 'traffic_admission';
    sp_run([PHP_BINARY, STAGE.'/artisan', 'up', '--no-interaction'], 'staging_up');
    $phase = 'https_acceptance';
    $observations = [];
    foreach (['/' => 200, '/products' => 200, '/repair' => 200, '/admin/login' => 200, '/admin/support' => 302, '/support-chat/support-widget.php' => 200, '/support-chat/api.php?widget_config=1' => 200, '/support-chat/operator-api.php' => 401] as $path => $expected) {
        $r = sp_http($path, $auth);
        sp_need($r['http'] === $expected, 'https_expected_status');
        if ($path === '/support-chat/support-widget.php') {
            sp_need(str_contains($r['body'], 'chatWindow'), 'widget_contract');
        }if (str_contains($path, 'widget_config')) {
            sp_need(is_string(json_decode($r['body'], true, 32, JSON_THROW_ON_ERROR)['name'] ?? null), 'widget_config_contract');
        }unset($r['body']);
        $observations[] = ['path' => $path] + $r;
    }
    sp_need(! is_file(STAGE.'/storage/framework/down'), 'maintenance_off');
    foreach ($protected as $name => $hash) {
        sp_need(hash_equals($hash, hash('sha256', sp_read(STAGE.'/'.$name))), 'final_preservation');
    }
    $receipt = ['result' => 'STAGING_SYMFONY_SECURITY_PATCH_PASS', 'sourceCommit' => $m['sourceCommit'], 'packages' => array_intersect_key($after, array_flip(PACKAGES)), 'advisories' => 0, 'migrations' => 22, 'maintenanceOff' => true, 'backupSha256' => hash_file('sha256', $out.'/code-before.tar.gz'), 'backup' => $out.'/code-before.tar.gz', 'probeRemoved' => true, 'http' => $observations, 'productionModified' => false, 'migrationsExecuted' => false, 'chatDataReset' => false, 'twoWayBrowserVerified' => false, 'atUtc' => gmdate('c')];
    sp_put($out.'/PASS.json', json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $armed = false;
    echo json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),"\n";
} catch (Throwable $e) {
    if ($armed && $phase === 'https_acceptance' && $e instanceof RuntimeException && $e->getMessage() === 'https_transport' && in_array($GLOBALS['sp_last_http']['curlErrno'] ?? -1, [6, 7, 18, 28, 52, 55, 56], true) && in_array($GLOBALS['sp_last_http']['http'] ?? -1, [0, 200, 302, 401], true)) {
        try {
            sp_run([PHP_BINARY, '-r', $check], 'pending_local_recheck');
            foreach ($protected as $name => $hash) {
                sp_need(hash_equals($hash, hash('sha256', sp_read(STAGE.'/'.$name))), 'pending_preservation');
            }sp_need(! is_file(STAGE.'/storage/framework/down') && sp_versions(STAGE.'/vendor/composer/installed.json') === $runtimeAfter, 'pending_runtime');
            $r = ['result' => 'STAGING_SECURITY_VERIFICATION_PENDING', 'phase' => $phase, 'exitCode' => 75, 'maintenanceOff' => true, 'retrySafe' => false, 'productionModified' => false];
            sp_put($out.'/PENDING.json', json_encode($r, JSON_THROW_ON_ERROR));
            $armed = false;
            $r['probeRemoved'] = sp_cleanup_probe();
            fwrite(STDERR, json_encode($r)."\n");
            exit(75);
        } catch (Throwable) {
        }
    }
    if ($armed) {
        try {
            sp_run([PHP_BINARY, STAGE.'/artisan', 'down', '--retry=60', '--no-interaction'], 'failure_fence');
        } catch (Throwable) {
        }
    }$r = ['result' => 'STAGING_SECURITY_PATCH_STOP', 'phase' => $phase, 'category' => $e instanceof RuntimeException && preg_match('/\A[a-z_]+\z/D',$e->getMessage()) ? $e->getMessage() : 'details_withheld', 'maintenanceMayBeActive' => $armed, 'retrySafe' => false, 'productionModified' => false];
    if ($out) {
        try {
            sp_put($out.'/STOP.json',json_encode($r,JSON_THROW_ON_ERROR));
        } catch (Throwable) {
        }
    }$r['probeRemoved'] = sp_cleanup_probe();
    fwrite(STDERR,json_encode($r)."\n");
    exit(1);
} finally {
    if ($probe && is_file($probe) && ! is_link($probe)) {
        unlink($probe);
    }if (is_resource($lock)) {
        flock($lock,LOCK_UN);
        fclose($lock);
    }
}
