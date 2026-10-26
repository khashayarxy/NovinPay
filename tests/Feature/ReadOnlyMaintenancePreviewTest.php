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
        foreach (['admin.maintenance.preview', 'admin.maintenance.preview.page'] as $name) {
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
}
