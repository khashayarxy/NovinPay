<?php

// Phase 7B verification only: no migrations, model writes, login submissions or cache writes.
declare(strict_types=1);
use App\Models\Article;
use App\Support\SiteMaintenanceState;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use NovinPay\Release\GateFailure;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
require_once __DIR__.'/lib.php';
function check(bool $ok, string $code): void
{
    if (! $ok) {
        throw new GateFailure($code, 'required health condition passes', [], 'Preserve the deployment fence on application failure; inspect this check.');
    }
}
// Exit 75 means external verification is inconclusive, never deployment success.
class HttpsMonitorUnavailable extends RuntimeException {}
function checkedTransport(callable $request, array $expected, int $retryDelayUs = 500000): array
{
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $result = $request();
        $errno = $result['errno'];
        $status = $result['status'];
        // An observed bad HTTP response is not hidden by a simultaneous timeout.
        check($status === 0 || in_array($status, $expected, true), 'https_unexpected_status');
        if ($errno === 0) {
            check(is_string($result['body']) && in_array($status, $expected, true), 'https_invalid_response');

            return [$result['headers'], $result['body']];
        }
        // TLS/certificate/client errors and all unknown errors remain hard failures.
        check(in_array($errno, [6, 7, 18, 28, 52, 55, 56], true), 'https_security_or_client_failure');
        fwrite(STDERR, 'HTTPS MONITOR RETRY: attempt '.$attempt.'/3; curl_errno='.$errno.'; http_status='.$status.". Verification not passed.\n");
        if ($attempt < 3 && $retryDelayUs > 0) {
            usleep($retryDelayUs);
        }
    }
    throw new HttpsMonitorUnavailable('https_transport_unconfirmed');
}
try {
    $root = '/home/novinpay/novinpay';
    check(PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80401 && getcwd() === $root, 'php_path');
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $cfg = (new ConfigurationUrlParser)->parseConfiguration(config('database.connections.mariadb'));
    check(base_path() === $root && config('app.env') === 'production' && ! config('app.debug') && config('app.url') === 'https://novinpaypos.com'
     && config('database.default') === 'mariadb' && $cfg['database'] === 'novinpay_live1' && $cfg['username'] === 'novinpay_app1'
     && $cfg['timezone'] === '+00:00' && $cfg['strict'] === true, 'effective_environment');
    check(config('app.maintenance.driver') === 'file', 'file_maintenance');
    $pdo = DB::connection()->getPdo();
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    try {
        $s = $pdo->query('SELECT DATABASE() db,CURRENT_USER() account,@@session.time_zone zone,@@session.explicit_defaults_for_timestamp dates,@@session.check_constraint_checks checks_on,@@session.foreign_key_checks fk')->fetch(PDO::FETCH_ASSOC);
        check($s['db'] === 'novinpay_live1' && explode('@', $s['account'])[0] === 'novinpay_app1' && $s['zone'] === '+00:00' && (int) $s['dates'] === 1 && (int) $s['checks_on'] === 1 && (int) $s['fk'] === 1, 'database_readonly_identity');
        \NovinPay\Release\verifyMigrationInventory(
            $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN),
            \NovinPay\Release\migrationInventory($root.'/database/migrations'));
        $public = Article::published()->with('publishedVersion.category')->limit(10)->get();
        $hidden = Article::query()->whereNotIn('id', Article::published()->select('id'))->limit(10)->get(['id', 'slug']);
    } finally {
        $pdo->rollBack();
    }
    foreach (['articles.index', 'articles.category', 'articles.show', 'products.index', 'repair', 'admin.articles.index', 'admin.maintenance.index', 'admin.maintenance.preview.page', 'admin.maintenance.enable', 'admin.maintenance.disable'] as $name) {
        check(Route::has($name), 'required_routes');
    }
    $maintenance = new SiteMaintenanceState($root.'/storage/framework/site-maintenance');
    check(! $maintenance->read()['enabled'], 'admin_maintenance_not_disabled');
    if (($argv[1] ?? '') === '--local') {
        echo "LOCAL READINESS PASS: production identity, existing 14 migrations, article routes and read-only publication query.\n";
        exit;
    }
    function page(string $path, int|array $expected): array
    {
        return checkedTransport(function () use ($path): array {
            $h = curl_init('https://novinpaypos.com'.$path);
            $headers = [];
            curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROXY => '', CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 40, CURLOPT_HEADERFUNCTION => function ($h, $line) use (&$headers) {
                $p = explode(':', $line, 2);
                if (count($p) === 2) {
                    $headers[strtolower(trim($p[0]))] = trim($p[1]);
                }

                return strlen($line);
            }]);
            try {
                $body = curl_exec($h);

                return ['errno' => curl_errno($h), 'status' => (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => $body];
            } finally {
                curl_close($h);
            }
        }, is_array($expected) ? $expected : [$expected]);
    }
    function navigation(string $html, string $path): void
    {
        check(preg_match('~<nav[^>]*class="np-quick-nav[^"]*"[^>]*>(.*?)</nav>~s', $html, $m) === 1 && preg_match_all('~<a[^>]*href="([^"]*)"[^>]*>(.*?)</a>~s', $m[1], $links) === 2, 'route_shortcuts_present');
        [$labels,$urls] = match ($path) {
            '/products' => [['صفحه اصلی', 'تعمیرات'], ['/', '/repair']],'/repair' => [['صفحه اصلی', 'فروشگاه'], ['/', '/products']],default => [['فروشگاه', 'تعمیرات'], ['/products', '/repair']]
        };
        check(array_map('trim', $links[2]) === $labels && array_map(fn ($u) => (parse_url($u, PHP_URL_PATH) ?? '/'), $links[1]) === $urls, 'route_shortcuts_mapping');
        check(preg_match('~<nav[^>]*id="mobile-navigation"[^>]*>(.*?)</nav>~s', $html, $m) === 1 && preg_match_all('~<a[^>]*href="([^"]*)"[^>]*>(.*?)</a>~s', $m[1], $links) === 3, 'vertical_menu_present');
        check(array_map('trim', $links[2]) === ['پیگیری تعمیرات', 'مقالات', 'تماس با ما'] && array_map(fn ($u) => (parse_url($u, PHP_URL_PATH) ?? '/'), $links[1]) === ['/repair/track', '/articles', '/contact'], 'vertical_menu_order');
    }
    foreach (['/', '/products', '/repair', '/repair/track', '/contact', '/consultation', '/admin/login'] as $path) {
        [$headers,$body] = page($path, 200);
        check(str_contains($body, 'dir="rtl"') && str_contains($body, 'lang="fa"'), 'persian_rtl');
        if ($path !== '/admin/login') {
            navigation($body, $path);
        }
    }
    [$headers,$home] = page('/', 200);
    check(str_contains($home, '/articles'), 'article_discovery');
    check(preg_match('~<nav[^>]*class="np-quick-nav[^"]*"[^>]*>(.*?)</nav>~s', $home, $m) === 1 && preg_match_all('~<a[^>]*href="([^"]*)"[^>]*>(.*?)</a>~s', $m[1], $links) === 2, 'quick_navigation');
    check(array_map('trim', $links[2]) === ['فروشگاه', 'تعمیرات'] && array_map(fn ($u) => (parse_url($u, PHP_URL_PATH) ?? '/'), $links[1]) === ['/products', '/repair'], 'shop_repair_shortcuts');
    $manifest = json_decode(file_get_contents($root.'/public/build/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($manifest as $entry) {
        $file = $entry['file'];
        [$headers,$body] = page('/build/'.$file, 200);
        check(hash_file('sha256', $root.'/public/build/'.$file) === hash('sha256', $body), 'asset_bytes');
        if ($entry['isEntry'] ?? false) {
            check(str_contains($home, '/build/'.$file), 'active_manifest_html');
        }
    }
    foreach (['/articles' => 200, '/articles/np-release-missing-d94d102' => 404, '/articles/category/np-release-missing-d94d102' => 404] as $path => $status) {
        [$headers,$body] = page($path, $status);
        check(str_contains(strtolower($headers['cache-control'] ?? ''), 'no-store'), 'article_no_store');
    }
    foreach ($public as $article) {
        [$headers,$body] = page('/articles/'.rawurlencode($article->slug), 200);
        check(str_contains(strtolower($headers['cache-control'] ?? ''), 'no-store'), 'published_no_store');
        page('/articles/category/'.rawurlencode($article->publishedVersion->category->slug), 200);
    }
    foreach ($hidden as $article) {
        page('/articles/'.rawurlencode($article->slug), 404);
    }
    foreach (['/admin', '/admin/articles', '/admin/repair-requests', '/admin/products', '/admin/maintenance', '/admin/maintenance/preview', '/admin/maintenance/preview/page'] as $path) {
        [$headers] = page($path, 302);
        check(parse_url($headers['location'] ?? '', PHP_URL_PATH) === '/admin/login', 'anonymous_admin_denied');
    }
    foreach (['/.env', '/storage/logs/laravel.log'] as $path) {
        page($path, [403, 404]);
    }
    [$mh,$mb] = page('/maintenance.php', 503);
    check(preg_match('~<h1[^>]*>(.*?)</h1>~s', $mb, $heading) === 1, 'maintenance_heading_present');
    $heading = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($heading[1]), ENT_QUOTES, 'UTF-8')));
    check($heading === 'در حال تعمیرات و به‌روزرسانی سایت نوین‌پی هستیم' && ! str_contains($mb, 'np-header') && ($mh['retry-after'] ?? '') === '60'
     && str_contains(strtolower($mh['cache-control'] ?? ''), 'no-store') && str_contains(strtolower($mh['x-robots-tag'] ?? ''), 'noindex')
     && str_contains($mb, 'tel:09384144021') && str_contains($mb, 'tel:09362896373') && str_contains($mb, 'data:image/webp;base64,'), 'standalone_final_design');
    echo "HTTPS VERIFICATION PASS: public pages, article cache/404 rules, assets, shortcuts and anonymous admin denial.\nPublished samples checked: ".count($public).'; unpublished samples checked: '.count($hidden).". No content was created or published.\n";
} catch (HttpsMonitorUnavailable $e) {
    fwrite(STDERR, "HTTPS VERIFICATION INCOMPLETE: transport unavailable after three attempts. Not deployment PASS.\n");
    exit(75);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(\NovinPay\Release\failureReport($e, 'health', ($argv[1] ?? '') === '--local' ? 'local_readiness' : 'https_verification'), JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}
