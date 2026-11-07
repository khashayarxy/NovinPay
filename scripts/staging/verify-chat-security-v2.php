<?php

declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('zend.exception_ignore_args', '1');
function need(bool $ok, string $code): void
{
    $GLOBALS['security_last_check'] = $code;
    if (! $ok) {
        throw new RuntimeException($code);
    }
}
$probe = null;
$phase = 'identity';
$checks = [];
$GLOBALS['security_last_check'] = 'start';
$GLOBALS['security_last_response'] = null;
try {
    $kit = '/home/novinpay/novinpay-staging-38b9117-kit';
    $stage = '/home/novinpay/novinpay-staging-workflow';
    need(PHP_SAPI === 'cli' && posix_geteuid() === 1321 && $kit === __DIR__ && realpath($stage) === $stage, 'identity');
    $m = json_decode(file_get_contents($kit.'/repair-chat-update-v5.json'), true, 64, JSON_THROW_ON_ERROR);
    need(hash_file('sha256', $stage.'/app/Http/Controllers/SupportChatController.php') === '8b6ede092769ac5549af714a1f336aa6163f9492dbbd4aad2c17bf1fb4b91157', 'patched_controller_identity');
    need(hash_file('sha256', $kit.'/repair-chat-basic-v1.php') === $m['basicLibrarySha256'], 'auth_library_identity');
    require $kit.'/repair-chat-basic-v1.php';
    $phase = 'private_basic_auth';
    $auth = np_basic_load($kit, $m);
    $phase = 'temporary_probe';
    $token = bin2hex(random_bytes(32));
    $probe = $stage.'/public/np-chat-security-'.bin2hex(random_bytes(12)).'.php';
    $code = '<?php ini_set("display_errors","0");header("Cache-Control:no-store");header("X-Robots-Tag:noindex");if(!hash_equals("'.hash('sha256', $token).'",hash("sha256",$_SERVER["HTTP_X_NP_PROBE"]??""))){http_response_code(404);exit;} $p="'.$stage.'/app/Http/Controllers/SupportChatController.php";$ok=!function_exists("opcache_is_script_cached")||!opcache_is_script_cached($p)||(function_exists("opcache_invalidate")&&opcache_invalidate($p,true));header("Content-Type:application/json");echo json_encode(["ok"=>$ok]);';
    $h = fopen($probe, 'xb');
    need(is_resource($h) && chmod($probe, 0600) && fwrite($h, $code) === strlen($code) && fflush($h) && fsync($h), 'private_probe_write');
    fclose($h);
    $fetch = function (string $path, bool $basic = true, bool $probeRequest = false) use ($auth, $token): array {
        $c = curl_init('https://staging.novinpaypos.com'.$path);
        $headers = ['Cache-Control: no-cache'];
        if ($probeRequest) {
            $headers[] = 'X-NP-Probe: '.$token;
        }
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 25, CURLOPT_HTTPHEADER => $headers];
        if ($basic) {
            $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $opts[CURLOPT_USERPWD] = $auth['username'].':'.$auth['password'];
        }curl_setopt_array($c, $opts);
        $body = curl_exec($c);
        $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($c);
        $GLOBALS['security_last_response'] = ['path' => $path, 'http' => $status, 'curlErrno' => $error];
        curl_close($c);
        need(is_string($body) && $error === 0, 'https_transport');
        need(! preg_match('/SUPPORT_(?:INSTALLATION_ID|PUBLIC_ROOT|CORE_DIR|RUNTIME_DIR)\s*=|CHAT_DATA_DIR\s*=|\/home\/novinpay\//', $body), 'private_configuration_disclosure');

        return [$status, $body];
    };
    $phase = 'web_opcache';
    [$status,$body] = $fetch('/'.basename($probe), true, true);
    need($status === 200 && (json_decode($body, true)['ok'] ?? false), 'scoped_web_opcache');
    $checks = [];
    $phase = 'http_endpoints';
    foreach (['/' => 200, '/support-chat/support-widget.php' => 200, '/support-chat/support-widget.js' => 200, '/support-chat/api.php?get_csrf=1' => 200, '/support-chat/operator-api.php' => 401, '/admin/support' => 302, '/support-chat/installation.env' => 404, '/support-chat/support/configuration.php' => 404, '/support-chat/data/messages.json' => 404, '/storage/app/private/support-chat/installation.env' => 404, '/.env' => 404] as $path => $expected) {
        [$status,$body] = $fetch($path);
        need($status === $expected || ($path === '/.env' && $status === 403), 'http_'.$status.'_'.str_replace(['/', '?', '=', '.'], '_', $path));
        if ($path === '/support-chat/support-widget.php') {
            need(str_contains($body, 'chatWindow') && str_contains($body, 'customer-v2.js'), 'widget_interface');
        }if (str_contains($path, 'get_csrf')) {
            need(is_array(json_decode($body, true)) && isset(json_decode($body, true)['token']), 'customer_api_json');
        }$checks[] = ['path' => $path, 'http' => $status, 'configurationDisclosure' => false];
    }
    $phase = 'basic_protection_and_cleanup';
    need($fetch('/', false)[0] === 401, 'basic_protection');
    need(unlink($probe), 'probe_cleanup');
    clearstatcache(true, $probe);
    need(! is_file($probe), 'probe_removed');
    $probe = null;
    echo json_encode(['result' => 'CHAT_DISCLOSURE_HTTP_VERIFICATION_PASS', 'checks' => $checks, 'basicProtectionPreserved' => true, 'productionModified' => false, 'messagesSent' => false, 'authenticatedOperatorBrowserAcceptance' => 'pending'], JSON_PRETTY_PRINT),PHP_EOL;
} catch (Throwable $e) {
    // Only our own check label (never arbitrary exception messages or bodies) is emitted.
    $check = $GLOBALS['security_last_check'] ?? 'unknown';
    if (! is_string($check) || strlen($check) > 180 || ! preg_match('/\A[a-zA-Z0-9_-]+\z/D', $check)) {
        $check = 'check_unavailable';
    }
    $basic = $e instanceof RuntimeException && preg_match('/\Abasic_[a-z_]+\z/D', $e->getMessage()) ? $e->getMessage() : null;
    $record = ['result' => 'CHAT_SECURITY_VERIFICATION_STOP', 'phase' => $phase, 'failedCheck' => $basic ?? $check, 'exceptionClass' => get_class($e), 'exceptionLine' => $e->getLine(), 'exceptionFile' => basename($e->getFile()), 'lastResponse' => $GLOBALS['security_last_response'] ?? null, 'completedChecks' => $checks, 'databaseWrites' => false, 'productionModified' => false];
    $saved = false;
    if (PHP_SAPI === 'cli' && function_exists('posix_geteuid') && posix_geteuid() === 1321 && __DIR__ === '/home/novinpay/novinpay-staging-38b9117-kit') {
        $p = __DIR__.'/chat-security-stop-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(4)).'.json';
        $f = fopen($p, 'xb');
        if (is_resource($f)) {
            chmod($p, 0600);
            $bytes = json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            $saved = fwrite($f, $bytes) === strlen($bytes) && fflush($f) && fsync($f);
            fclose($f);
        }
    }
    $record['privateDiagnosticSaved'] = $saved;
    echo json_encode($record, JSON_PRETTY_PRINT),PHP_EOL;
    exit(1);
} finally {
    if ($probe) {
        clearstatcache(true, $probe);
        if (is_file($probe) && ! unlink($probe)) {
            echo "PRIVATE PROBE CLEANUP PENDING\n";
        }
    }
}
