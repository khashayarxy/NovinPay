<?php

namespace Tests\Feature;

use App\Http\Middleware\PreviewAwareSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ReadOnlyMaintenancePreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_normal_session_lock_resolver_is_preserved(): void
    {
        config(['session.driver' => 'array', 'session.block' => true, 'session.block_store' => 'array']);
        $request = Request::create('/admin/maintenance', 'GET');
        $request->setRouteResolver(fn () => (new Route('GET', 'admin/maintenance', fn () => null))->name('admin.maintenance.index'));
        $response = app(PreviewAwareSession::class)->handle($request, fn () => new Response('normal locked session'));
        $this->assertSame('normal locked session', $response->getContent());
    }

    public function test_preview_reads_sessions_without_saving_cookies_or_running_garbage_collection(): void
    {
        config(['session.driver' => 'database', 'session.lottery' => [100, 100], 'session.block' => true]);
        DB::table('sessions')->insert(['id' => str_repeat('a', 40), 'user_id' => null, 'ip_address' => null, 'user_agent' => null, 'payload' => base64_encode(serialize([])), 'last_activity' => 1]);
        $before = DB::table('sessions')->get()->toJson();
        $manager = new SessionManager($this->app);
        $middleware = new PreviewAwareSession($manager, fn () => throw new \RuntimeException('Preview must not write a cache lock'));
        foreach (['admin.maintenance.preview', 'admin.maintenance.preview.page', 'support.chat.operator.asset'] as $name) {
            $request = Request::create('/admin/maintenance/preview', 'GET');
            $route = (new Route('GET', 'admin/maintenance/preview', fn () => null))->name($name);
            $request->setRouteResolver(fn () => $route);
            $response = $middleware->handle($request, function (Request $request): Response {
                $request->session()->put('synthetic_unsaved_value', 'must remain memory-only');

                return new Response('preview');
            });
            $this->assertSame($before, DB::table('sessions')->get()->toJson());
            $this->assertSame([], $response->headers->getCookies());
        }
        // Positive control: the unchanged normal session lifecycle still runs
        // GC and saves sessions for a non-preview admin request.
        config(['session.block' => false]);
        $request = Request::create('/admin/maintenance', 'GET');
        $request->setRouteResolver(fn () => (new Route('GET', 'admin/maintenance', fn () => null))->name('admin.maintenance.index'));
        $middleware->handle($request, fn () => new Response('normal'));
        $this->assertDatabaseMissing('sessions', ['id' => str_repeat('a', 40)]);
        $this->assertNotSame($before, DB::table('sessions')->get()->toJson());
    }

    public function test_only_public_chat_configuration_gets_are_read_only(): void
    {
        config(['session.driver' => 'database', 'session.lottery' => [100, 100], 'session.block' => false]);
        $middleware = new PreviewAwareSession(new SessionManager($this->app));
        $readOnlyRequests = [
            ['api.php', 'widget_config=1'],
            ['api.php', 'recovery_availability=1'],
            ['api.php', 'get_conversation=1&user_id=user_test'],
            ['api.php', 'receipts=1&user_id=user_test'],
            ['api.php', 'get_session=1'],
            ['api.php', 'get_users=1'],
            ['support-widget.php', ''],
            ['support-content.php', 'action=home'],
            ['customer-notifications.php', 'action=status'],
            ['customer-notifications.php', 'action=hint'],
            ['get_activity.php', 'scope=customer'],
            ['support-typing.php', 'scope=customer&user_id=user_test'],
        ];
        foreach ($readOnlyRequests as [$path, $query]) {
            $uri = '/support-chat/'.$path.($query !== '' ? '?'.$query : '');
            $request = Request::create($uri, 'GET');
            $route = (new Route('GET', 'support-chat/{path}', fn () => null))->name('support.chat');
            $route->bind($request);
            $request->setRouteResolver(fn () => $route);
            $before = DB::table('sessions')->get()->toJson();
            $response = $middleware->handle($request, fn () => new Response('read-only'));
            $this->assertSame($before, DB::table('sessions')->get()->toJson(), "Expected {$uri} not to write sessions");
            $this->assertSame([], $response->headers->getCookies(), "Expected {$uri} not to emit session Set-Cookie");
        }
        // CSRF/bootstrap, message POSTs and operator APIs retain normal writes,
        // even when a caller adds a configuration or polling query flag.
        foreach ([
            ['GET', 'api.php', 'get_csrf=1'],
            ['GET', 'api.php', 'widget_config=&get_csrf=1'],
            ['GET', 'api.php', 'get_session=1&bootstrap=1'],
            ['POST', 'api.php', 'widget_config=1'],
            ['GET', 'operator-api.php', 'widget_config=1'],
        ] as [$method, $path, $query]) {
            DB::table('sessions')->delete();
            $freshMiddleware = new PreviewAwareSession(new SessionManager($this->app));
            $request = Request::create('/support-chat/'.$path.'?'.$query, $method);
            if ($request->query('widget_config') === '') {
                $request->query->set('widget_config', null);
            }
            $route = (new Route(['GET', 'POST'], 'support-chat/{path}', fn () => null))->name('support.chat');
            $route->bind($request);
            $request->setRouteResolver(fn () => $route);
            $response = $freshMiddleware->handle($request, fn () => new Response('stateful'));
            $this->assertNotEmpty($response->headers->getCookies());
            $this->assertDatabaseCount('sessions', 1);
        }
    }
}
