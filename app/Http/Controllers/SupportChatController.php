<?php

namespace App\Http\Controllers;

use App\Services\SupportTeamAdministration;
use App\Support\AdminAccess;
use App\Support\Chat\ChatBridge;
use App\Support\Chat\LegacyResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SupportChatController extends Controller
{
    private const ENDPOINTS = ['api.php', 'operator-api.php', 'operator-avatar.php', 'support-attachment.php', 'support-avatar.php', 'support-profile-image.php', 'support-content.php', 'support-content-admin.php', 'support-theme.php', 'support-typing.php', 'customer-notifications.php', 'get_activity.php', 'update_activity.php', 'support-widget.php', 'logout.php', 'operator/manifest.php'];

    public function operatorAsset(Request $request, ChatBridge $bridge, string $asset): Response
    {
        return $this->__invoke($request, $bridge, 'operator/'.$asset);
    }

    public function __invoke(Request $request, ChatBridge $bridge, string $path = ''): Response
    {
        $started = hrtime(true);
        abort_unless(config('support_chat.enabled'), 404);
        abort_unless($request->isSecure() && preg_match('~\A[a-zA-Z0-9_./-]*\z~D', $path) && ! str_contains($path, '..') && ! str_starts_with($path, '/'), 404);
        $operatorPage = in_array($path, ['operator', 'operator/', 'operator/index.html'], true);
        if ($operatorPage && ! auth()->check()) {
            return redirect()->route('admin.login');
        }
        if (str_starts_with($path, 'operator/') || $path === 'operator') {
            abort_unless(auth()->check() && auth()->user()->can('support.view'), 403);
            $grant = AdminAccess::account(auth()->user());
            abort_unless($grant && ! $grant->must_change_password && $request->session()->get('admin_session_version') === $grant->session_version, 403);
        }
        if ($operatorPage && (! is_string($request->session()->get('support_authentication_nonce')) || time() - (int) $request->session()->get('support_authenticated_at', 0) >= 8 * 3600 || ($request->session()->has('support_chat_window') && time() - (int) $request->session()->get('support_chat_window.interactive_at', 0) > 1800))) {
            return redirect()->route('admin.support.confirm');
        }
        if ($path === 'operator') {
            return redirect('/support-chat/operator/index.html', 302);
        }
        if ($operatorPage) {
            $path = 'operator/index.html';
        }
        $file = resource_path('support-chat/'.$path);
        if (! in_array($path, self::ENDPOINTS, true)) {
            abort_unless($request->isMethod('GET') && ($path === 'support-widget.js' || preg_match('~\A(?:support-client/|operator/|assets/fonts/|assets/icons/)[a-zA-Z0-9_./-]+\.(?:js|css|html|json|woff2|png|svg)\z~D', $path)) && is_file($file) && ! is_link($file), 404);
            $mime = match (pathinfo($file, PATHINFO_EXTENSION)) {
                'js' => 'text/javascript', 'css' => 'text/css', 'html' => 'text/html', 'json' => 'application/json', 'woff2' => 'font/woff2', 'png' => 'image/png', 'svg' => 'image/svg+xml',
            };

            return response(file_get_contents($file), 200, ['Content-Type' => $mime.(in_array(pathinfo($file, PATHINFO_EXTENSION), ['js', 'css', 'html', 'json', 'svg'], true) ? '; charset=utf-8' : ''), 'X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store, private']);
        }
        $original = ['get' => $_GET, 'post' => $_POST, 'files' => $_FILES, 'server' => $_SERVER, 'cookie' => $_COOKIE];
        $timezone = date_default_timezone_get();
        $initialHeaders = headers_list();
        $initialStatus = http_response_code();
        $bufferLevel = ob_get_level();
        $payload = null;
        $status = 200;
        ob_start();
        try {
            $bridge->begin();
            $_GET = $request->query->all();
            $_POST = $request->request->all();
            // Native modules must consume Laravel's authenticated, decrypted
            // cookies, never PHP's still-encrypted wire values.
            $_COOKIE = $request->cookies->all();
            // The native FormData protocol uses empty strings (including image-only
            // messages). Restore Laravel's empty-to-null normalization at this boundary.
            array_walk_recursive($_POST, static function (&$value): void {
                if ($value === null) {
                    $value = '';
                }
            });
            $_SERVER['REQUEST_METHOD'] = $request->method();
            $_SERVER['HTTPS'] = 'on';
            $_SERVER['SERVER_PORT'] = 443;
            $_SERVER['DOCUMENT_ROOT'] = public_path();
            $_SERVER['REMOTE_ADDR'] = $request->server('REMOTE_ADDR', '127.0.0.1');
            $_SERVER['HTTP_X_CSRF_TOKEN'] = $request->header('X-CSRF-Token');
            $_SERVER['CONTENT_LENGTH'] = $request->header('Content-Length', '0');
            http_response_code(200);
            self::loadModule(resource_path('support-chat/support/bootstrap.php'), true);
            self::loadModule(resource_path('support-chat/support/operators.php'), true);
            if (in_array($path, ['operator-api.php', 'support-content-admin.php'], true)) {
                $bridge->operator();
                if ($path === 'operator-api.php' && in_array($request->input('action'), ['account_add', 'account_revoke'], true)) {
                    \support_request_method('POST');
                    $operator = \support_operator();
                    \support_csrf($operator);
                    \support_ops_admin($operator);
                    app(SupportTeamAdministration::class)->apply($request->user(), $request->input('action'), $request->all());
                    $bridge->syncOperators();
                    throw new LegacyResponse(['status' => 'success']);
                }
                if ($path === 'operator-api.php' && $request->input('action') === 'accounts' && $request->isMethod('GET')) {
                    $operator = \support_operator();
                    $accounts = \support_ops_store(fn (array &$state) => array_values(array_map(fn ($account) => $operator['operator_role'] === 'administrator' ? \support_ops_public($account) : array_intersect_key($account, array_flip(['id', 'name', 'active'])), $state['accounts'])));
                    throw new LegacyResponse(['status' => 'success', 'accounts' => $accounts, 'can_manage_accounts' => $request->user()->can('staff.manage')]);
                }
                if ($request->input('action') === 'account_update') {
                    self::loadModule(resource_path('support-chat/support/operator-profile.php'), true);
                    $operator = \support_operator();
                    \support_csrf($operator);
                    \support_ops_admin($operator);
                    $target = \support_profile_target($operator, $request->input('id'));
                    $account = \support_ops_store(fn (array &$state) => $state['accounts'][$target] ?? null);
                    if (! $account || $request->input('role') !== $account['role'] || $request->input('active') !== ($account['active'] ? '1' : '0')) {
                        \support_failure(409, 'native_account_management');
                    }
                    $_POST['action'] = 'profile_save';
                }
                if (in_array($request->input('action'), ['login', 'account_add', 'account_revoke', 'account_password', 'profile_password'], true)) {
                    \support_failure(409, 'native_account_management');
                }
            }
            self::loadModule($file);
        } catch (LegacyResponse $ready) {
            $payload = $ready->payload;
            $status = $ready->status;
        } catch (\SupportFailure $failure) {
            $status = $failure->status;
            $payload = ['status' => 'error', 'code' => $failure->reason, 'message' => 'درخواست پشتیبانی انجام نشد.'];
        } catch (ValidationException $error) {
            $status = 422;
            $payload = ['status' => 'error', 'code' => 'validation_failed', 'message' => 'اطلاعات یا گذرواژهٔ تأیید معتبر نیست.'];
        } catch (HttpExceptionInterface $error) {
            $status = $error->getStatusCode();
            $payload = ['status' => 'error', 'code' => 'operator_permission_denied'];
        } catch (\Throwable) {
            $status = 503;
            $payload = ['status' => 'error', 'code' => 'service_unavailable', 'message' => 'پشتیبانی موقتاً در دسترس نیست.'];
        } finally {
            $content = ob_get_contents();
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            $outHeaders = headers_list();
            foreach ($outHeaders as $header) {
                if (! in_array($header, $initialHeaders, true)) {
                    header_remove(explode(':', $header, 2)[0]);
                }
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            $bridge->finish();
            date_default_timezone_set($timezone);
            http_response_code($initialStatus ?: 200);
            $_GET = $original['get'];
            $_POST = $original['post'];
            $_FILES = $original['files'];
            $_SERVER = $original['server'];
            $_COOKIE = $original['cookie'];
        }
        if (preg_match('/^(?:SUPPORT_[A-Z0-9_]+|CHAT_DATA_DIR)\s*=/m', $content)) {
            $content = '';
            $status = 503;
            $payload = ['status' => 'error', 'code' => 'service_unavailable'];
        }
        $response = $payload !== null ? response()->json($payload, $status, [], JSON_UNESCAPED_UNICODE) : response($content, $status);
        foreach ($outHeaders as $header) {
            if (str_contains($header, ':')) {
                [$name, $value] = explode(':', $header, 2);
                if (strtolower($name) === 'set-cookie') {
                    $response->headers->setCookie(Cookie::fromString(trim($value)));
                } else {
                    $response->headers->set($name, trim($value));
                }
            }
        }
        // Declare known response types explicitly; PHP CLI does not retain native
        // header() calls, and binary chat responses must never fall back to HTML.
        if ($payload === null && $status >= 200 && $status < 300) {
            $image = in_array($path, ['support-attachment.php', 'operator-avatar.php', 'support-avatar.php', 'support-profile-image.php'], true);
            $type = match (true) {
                $image => 'image/webp',
                $path === 'support-theme.php' => 'text/javascript; charset=utf-8',
                $path === 'operator/manifest.php' => 'application/manifest+json; charset=utf-8',
                $path === 'operator-api.php' && $request->input('action') === 'analytics_csv' => 'text/csv; charset=utf-8',
                default => null,
            };
            if ($type !== null) {
                $response->headers->set('Content-Type', $type);
            }
            if ($image) {
                $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
                $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
                $response->headers->set('Content-Disposition', 'inline; filename="image.webp"');
            }
        }
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Public duration only: no SQL, filenames, identities or request values.
        $response->headers->set('Server-Timing', 'chat;dur='.round((hrtime(true) - $started) / 1_000_000, 2));

        return $response;
    }

    /** Native modules must not overwrite the controller's endpoint or response variables. */
    private static function loadModule(string $module, bool $once = false): void
    {
        if ($once) {
            require_once $module;
        } else {
            require $module;
        }
    }
}
