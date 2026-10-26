<?php

namespace Tests\Feature;

use App\Support\SiteMaintenanceState;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\SiteMaintenanceStateTest;

class StandaloneMaintenanceTest extends TestCase
{
    public function test_real_http_maintenance_survives_missing_composer_without_a_database_or_environment_file(): void
    {
        $files = new Filesystem;
        $fixture = sys_get_temp_dir().'/novinpay-maintenance-'.bin2hex(random_bytes(8));
        $server = null;
        try {
            foreach (['public/brand/lockups', 'config', 'storage/framework/views', 'app/Support'] as $directory) {
                $files->makeDirectory($fixture.'/'.$directory, 0755, true);
            }
            $files->copyDirectory(resource_path('maintenance'), $fixture.'/resources/maintenance');
            foreach (['public/index.php', 'public/maintenance.php', 'public/brand/lockups/logo-wordmark-white-on-black.svg', 'config/business.php', 'app/Support/SiteMaintenanceState.php', 'app/Support/MaintenanceStateFault.php', 'app/Support/MaintenanceStateConflict.php'] as $file) {
                copy(base_path($file), $fixture.'/'.$file);
            }

            // Exercise the actual installed Laravel down command, but isolate all
            // maintenance markers and compiled views from the developer's site.
            $code = 'require '.var_export(base_path('vendor/autoload.php'), true).';'
                .'$app=require '.var_export(base_path('bootstrap/app.php'), true).';'
                .'$app->useStoragePath('.var_export($fixture.'/storage', true).');'
                .'$kernel=$app->make(Illuminate\\Contracts\\Console\\Kernel::class);$kernel->bootstrap();'
                .'config(["view.compiled"=>'.var_export($fixture.'/storage/framework/views', true).']);'
                .'exit($kernel->call("down",["--render"=>"errors::503","--retry"=>120]));';
            $down = new Process([PHP_BINARY, '-r', $code], base_path());
            $down->mustRun();
            $this->assertFileExists($fixture.'/storage/framework/down');
            $this->assertFileExists($fixture.'/storage/framework/maintenance.php');
            $this->assertFileDoesNotExist($fixture.'/vendor/autoload.php');
            $this->assertFileDoesNotExist($fixture.'/.env');

            $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            $this->assertNotFalse($socket);
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            // A disposable native PHP HTTP fixture, never the Herd site or artisan dev.
            file_put_contents($fixture.'/router.php', '<?php require __DIR__."/public/".(str_starts_with($_SERVER["REQUEST_URI"],"/maintenance.php")?"maintenance.php":"index.php");');
            $server = new Process([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-S', $address, '-t', $fixture.'/public', $fixture.'/router.php'], $fixture);
            $server->start();
            $ready = false;
            for ($attempt = 0; $attempt < 60; $attempt++) {
                $connection = @stream_socket_client('tcp://'.$address, $errno, $error, 0.1);
                if ($connection) {
                    fclose($connection);
                    $ready = true;
                    break;
                }
                usleep(50000);
            }
            $this->assertTrue($ready, 'Disposable PHP fixture did not start.');

            foreach (['/index.php' => 'text/html', '/index.php?json=1' => 'application/json', '/maintenance.php' => 'text/html', '/maintenance.php?json=1' => 'application/json'] as $path => $accept) {
                $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5, 'header' => 'Accept: '.$accept]]);
                $body = file_get_contents('http://'.$address.$path, false, $context);
                $headers = implode("\n", $http_response_header);
                $this->assertStringContainsString('503 Service Unavailable', $headers);
                $this->assertStringContainsString('no-store', $headers);
                $this->assertMatchesRegularExpression('/Retry-After: (60|120)/i', $headers);
                $this->assertStringNotContainsString('Set-Cookie:', $headers);
                $this->assertStringContainsString('سایت موقتاً در دسترس نیست', $body);
                $this->assertStringNotContainsString('Fatal error', $body);
                $this->assertStringNotContainsString($fixture, $body);
                $this->assertStringNotContainsString('<script', $body);
                $this->assertStringNotContainsString('np-header', $body);
            }

            // The early response also has a safe retry default when --retry was omitted.
            $payload = json_decode(file_get_contents($fixture.'/storage/framework/down'), true, flags: JSON_THROW_ON_ERROR);
            $payload['retry'] = null;
            file_put_contents($fixture.'/storage/framework/down', json_encode($payload, JSON_THROW_ON_ERROR));
            file_get_contents('http://'.$address.'/index.php', false, $context);
            $this->assertStringContainsString('Retry-After: 60', implode("\n", $http_response_header));

            // The emergency endpoint retains 503 even without any maintenance marker.
            unlink($fixture.'/storage/framework/down');
            $body = file_get_contents('http://'.$address.'/maintenance.php', false, $context);
            $this->assertStringContainsString('503 Service Unavailable', implode("\n", $http_response_header));
            $this->assertStringContainsString('سایت موقتاً در دسترس نیست', $body);
            $this->assertFileDoesNotExist($fixture.'/storage/framework/sessions');

            // Admin-controlled mode is also enforced before Composer, including
            // JSON and forged Laravel-maintenance bypass cookies.
            $siteState = new SiteMaintenanceState($fixture.'/storage/framework/site-maintenance');
            $siteState->change(SiteMaintenanceState::INITIAL, true, 1, SiteMaintenanceState::render(base_path()));
            foreach (['text/html', 'application/json'] as $accept) {
                $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5, 'header' => 'Accept: '.$accept."\r\nCookie: laravel_maintenance=forged"]]);
                $body = file_get_contents('http://'.$address.'/index.php', false, $context);
                $headers = implode("\n", $http_response_header);
                $this->assertStringContainsString('503 Service Unavailable', $headers);
                $this->assertStringContainsString('no-store', $headers);
                $this->assertStringNotContainsString('Set-Cookie', $headers);
                $this->assertStringNotContainsString('Fatal error', $body);
                if ($accept === 'application/json') {
                    $this->assertSame(['message' => 'Service Unavailable'], json_decode($body, true));
                } else {
                    $this->assertStringContainsString('سایت موقتاً در دسترس نیست', $body);
                }
            }

            // Exercise raw targets before any HTTP client/framework normalization.
            $targets = array_column(SiteMaintenanceStateTest::malformedTargets(), 0);
            $probe = '$_SERVER["HTTP_ACCEPT"]="application/json";foreach('.var_export($targets, true).' as $target){'
                .'$_SERVER["REQUEST_URI"]=$target;$response=require '.var_export($fixture.'/resources/maintenance/guard.php', true).';'
                .'if(!is_array($response)||$response["status"]!==503||json_decode($response["body"],true)!==["message"=>"Service Unavailable"])exit(9);}echo "RAW TARGETS BLOCKED";';
            $rawGuard = new Process([PHP_BINARY, '-r', $probe]);
            $rawGuard->mustRun();
            $this->assertSame('RAW TARGETS BLOCKED', $rawGuard->getOutput());

            // Reproduce the original network-path case on the actual early HTTP entry.
            $connection = stream_socket_client('tcp://'.$address, $errno, $error, 5);
            stream_set_timeout($connection, 5);
            fwrite($connection, "GET //example.invalid/admin/login HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
            $raw = stream_get_contents($connection);
            fclose($connection);
            $this->assertStringContainsString('503 Service Unavailable', $raw);
            $this->assertStringContainsString('Retry-After: 60', $raw);

            // The guard must survive corrupt timestamps even with no Composer/DB.
            $original = $siteState->read();
            foreach (["2026-10-25T12:00:00+00:00\0", '2026-02-30T12:00:00+00:00', null] as $timestamp) {
                $corrupt = $original;
                $corrupt['updated_at'] = $timestamp;
                file_put_contents($fixture.'/storage/framework/site-maintenance/state.json', json_encode($corrupt, JSON_THROW_ON_ERROR));
                foreach ([['GET', 'text/html'], ['GET', 'application/json'], ['HEAD', 'text/html']] as [$method, $accept]) {
                    $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5, 'method' => $method, 'header' => 'Accept: '.$accept]]);
                    $body = file_get_contents('http://'.$address.'/index.php', false, $context);
                    $headers = implode("\n", $http_response_header);
                    $this->assertStringContainsString('503 Service Unavailable', $headers);
                    $this->assertStringContainsString('no-store', $headers);
                    $this->assertStringContainsString('Retry-After: 60', $headers);
                    $this->assertStringContainsString('noindex', $headers);
                    if ($method === 'HEAD') {
                        $this->assertSame('', $body);
                    } elseif ($accept === 'application/json') {
                        $this->assertSame(['message' => 'Service Unavailable'], json_decode($body, true));
                    } else {
                        $this->assertStringContainsString('سایت موقتاً در دسترس نیست', $body);
                    }
                    $this->assertStringNotContainsString('Fatal error', $body);
                    $this->assertStringNotContainsString($fixture, $body);
                }
            }
        } finally {
            $server?->stop();
            // Only this test's explicitly created random temporary directory.
            $this->assertStringStartsWith(sys_get_temp_dir().'/novinpay-maintenance-', $fixture);
            $files->deleteDirectory($fixture);
        }
    }
}
