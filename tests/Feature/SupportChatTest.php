<?php

namespace Tests\Feature;

use App\Models\AdminAccount;
use App\Models\CustomerAccount;
use App\Models\User;
use App\Services\IndependentSupportOperators;
use App\Support\Chat\ChatBridge;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class SupportChatTest extends TestCase
{
    use RefreshDatabase;

    private string $private;

    protected function setUp(): void
    {
        parent::setUp();
        $this->private = sys_get_temp_dir().'/novinpay-chat-test-'.bin2hex(random_bytes(12));
        config(['support_chat.enabled' => true, 'support_chat.directory' => $this->private]);
        User::factory()->superAdmin()->create();
        app(ChatBridge::class)->initialize();
        app(IndependentSupportOperators::class)->enroll('owner', 'IndependentSupport12!');
        auth()->forgetGuards();
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_COOKIE = [];
        app(ChatBridge::class)->finish();
        // Only this test's unpredictable, freshly created private directory is removed.
        if (isset($this->private) && str_starts_with($this->private, sys_get_temp_dir().'/novinpay-chat-test-')) {
            File::deleteDirectory($this->private);
        }
        parent::tearDown();
    }

    public function test_anonymous_context_and_widget_have_no_external_dependencies(): void
    {
        $this->get('https://localhost/support-chat/api.php?get_csrf=1')->assertOk()->assertJsonStructure(['token', 'context']);
        $this->get('https://localhost/support-chat/support-widget.php')->assertOk();
        $this->get('https://localhost/support-chat/support-widget.js')->assertOk();
        $this->get('https://localhost/support-chat/support/bootstrap.php')->assertNotFound();
        $this->get('https://localhost/support-chat/UPSTREAM.json')->assertNotFound();
        $this->get('https://localhost/support-chat/assets/fonts/Vazirmatn.woff2')->assertOk()->assertHeader('Content-Type', 'font/woff2');
    }

    public function test_customer_boot_configuration_matches_browser_contract(): void
    {
        $response = $this->get('https://localhost/support-chat/api.php?widget_config=1')->assertOk()->assertJsonStructure(['name', 'installation', 'source_paths', 'attachments', 'typing', 'customer_theme_default']);
        $this->assertIsString($response->json('name'));
        $this->assertIsArray($response->json('source_paths'));
        $widget = $this->get('https://localhost/support-chat/support-widget.php')->assertOk();
        $this->assertSame(1, preg_match('/<script type="application\/json" id="supportWidgetConfig">(.*?)<\/script>/s', $widget->getContent(), $matches));
        $this->assertSame($response->json(), json_decode($matches[1], true, 32, JSON_THROW_ON_ERROR));
    }

    public function test_typing_is_bidirectional_session_scoped_and_expires(): void
    {
        [$customer, $id] = $this->customerContext();
        $customerCookie = [session_name(), session_id()];
        $this->post('https://localhost/support-chat/api.php', ['send_message' => '1', 'user_id' => $id, 'message' => 'typing fixture', 'protocol_version' => '2', 'client_message_id' => (string) Str::uuid(), 'csrf_token' => $customer])->assertOk();
        $operator = $this->loginOperator();
        $endpoint = 'https://localhost/support-chat/support-typing.php';
        $publish = fn ($role, $token, $typing = '1', $age = '0') => $this->post($endpoint, ['scope' => $role, 'user_id' => $id, 'typing' => $typing, 'idle_seconds' => $age, 'csrf_token' => $token]);
        $observe = fn ($role) => $this->get($endpoint.'?'.http_build_query(['scope' => $role, 'user_id' => $id]))->assertOk();
        $publish('customer', $customer)->assertOk();
        $observe('customer')->assertJsonPath('typing', false);
        $reply = $observe('operator')->assertJsonPath('typing', true);
        $this->assertGreaterThanOrEqual(29, $reply->json('expires_in'));
        $publish('customer', $customer, '1', '30')->assertOk();
        $observe('operator')->assertJsonPath('typing', false);
        $publish('customer', $customer, '1', '12')->assertOk();
        $this->assertLessThanOrEqual(18, $observe('operator')->assertJsonPath('typing', true)->json('expires_in'));
        $publish('customer', $customer)->assertOk();
        $this->assertGreaterThanOrEqual(29, $observe('operator')->json('expires_in'));
        $publish('customer', $customer, '0')->assertOk();
        $observe('operator')->assertJsonPath('typing', false);
        $publish('operator', $operator)->assertOk();
        $observe('operator')->assertJsonPath('typing', false);
        $observe('customer')->assertJsonPath('typing', true);
        $this->withCookie($customerCookie[0], str_repeat('b', 32))->get($endpoint.'?scope=customer&user_id='.$id)->assertUnauthorized();
        $this->withCookie(...$customerCookie);
        $publish('operator', $operator, '0')->assertOk();
        $observe('customer')->assertJsonPath('typing', false);
    }

    public function test_legacy_and_current_operator_replies_have_same_opaque_group_across_pages(): void
    {
        [$customer, $id] = $this->customerContext();
        $this->post('https://localhost/support-chat/api.php', ['send_message' => '1', 'user_id' => $id, 'message' => 'history fixture', 'protocol_version' => '2', 'client_message_id' => (string) Str::uuid(), 'csrf_token' => $customer])->assertOk();
        $operator = $this->loginOperator();
        foreach (['historical', 'current'] as $message) {
            $this->post('https://localhost/support-chat/api.php', ['action' => 'send_reply', 'user_id' => $id, 'reply' => $message, 'protocol_version' => '2', 'client_message_id' => (string) Str::uuid(), 'csrf_token' => $operator])->assertOk();
        }
        $bridge = app(ChatBridge::class);
        $bridge->begin();
        try {
            support_store(function (array &$state, bool &$changed) use ($id): void {
                $old = &$state['conversations'][$id]['messages'][1];
                foreach (['protocol_version', 'client_message_id', 'sender_principal', 'payload_digest', 'accepted_at', 'team_reply_through', 'team_reply_protocol'] as $key) {
                    unset($old[$key]);
                }
                $changed = true;
            }, true);
        } finally {
            $bridge->finish();
        }
        $url = 'https://localhost/support-chat/api.php?get_conversation=1&limit=1&user_id='.$id;
        $new = $this->get($url)->assertOk()->assertJsonPath('messages.0.legacy', false)->json('messages.0');
        $old = $this->get($url.'&before_sequence='.$new['id'])->assertOk()->assertJsonPath('messages.0.legacy', true)->json('messages.0');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $new['sender_account_group']);
        $this->assertSame($new['sender_account_group'], $old['sender_account_group']);
        $this->assertNull($old['sender']);
        $this->assertArrayNotHasKey('operator_id', $old);
        $this->assertArrayNotHasKey('sender_principal', $old);
        $this->assertArrayNotHasKey('sender_account_group', $this->get($url.'&scope=operator')->assertOk()->json('messages.0'));
    }

    public function test_operator_entry_redirects_to_explicit_document_without_loop(): void
    {
        $this->actingAs(User::query()->sole())->withSession(['admin_session_version' => 1, 'support_authentication_nonce' => bin2hex(random_bytes(16)), 'support_authenticated_at' => time()]);
        $this->get('https://localhost/support-chat/operator')->assertRedirect('/support');
        $this->get('https://localhost/admin/support')->assertRedirect('/support');
        $this->get('https://localhost/support-chat/operator/index.html')->assertRedirect('/support');
        // The standard test URL builder trims trailing slashes before dispatch.
        $request = $this->createTestRequest(Request::create('https://localhost/support/'));
        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);
        $this->createTestResponse($response, $request)->assertRedirect('/support');
        $kernel->terminate($request, $response);
        $this->get('https://localhost/support')->assertOk()->assertSee('<base href="/support-chat/">', false);
        $this->get('https://localhost/support/sw.js')->assertOk()->assertHeader('Service-Worker-Allowed', '/support');
        $this->get('https://localhost/support-chat/operator/manifest.php')->assertOk()->assertJsonPath('start_url', '/support')->assertJsonPath('scope', '/support');
        $this->get('https://localhost/support-chat/operator/app.js')->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
        $this->get('https://localhost/support-chat/support-client/engine.js')->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
    }

    public function test_independent_login_shell_is_available_without_admin_authentication(): void
    {
        $this->get('https://localhost/support')->assertOk()->assertSee('id="username"', false)->assertSee('id="password"', false)->assertDontSee('/admin/support/confirm');
        $this->get('https://localhost/support-chat/operator/app.js')->assertOk()->assertDontSee('/admin/support/confirm');
    }

    public function test_admin_login_does_not_authenticate_support(): void
    {
        $owner = User::query()->sole();
        $this->post('https://localhost/admin/login', ['email' => $owner->email, 'password' => 'password'])->assertRedirect();
        $this->get('https://localhost/support-chat/operator-api.php?action=bootstrap')->assertOk()->assertJsonPath('authenticated', false);
        $this->get('https://localhost/support-chat/operator-api.php?action=inbox')->assertUnauthorized();
    }

    public function test_support_login_and_logout_leave_admin_authentication_unchanged(): void
    {
        $owner = User::query()->sole();
        $this->actingAs($owner)->withSession(['admin_session_version' => 1]);
        $token = $this->loginOperator();
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'logout', 'csrf_token' => $token])->assertOk();
        $this->assertAuthenticatedAs($owner, 'web');
        $this->get('https://localhost/admin/repairs')->assertOk();
    }

    public function test_public_chat_assets_do_not_depend_on_database_sessions(): void
    {
        $default = config('database.default');
        // CI logs to STDERR, which PHPUnit treats as a subprocess failure.
        // Assert the expected safe error log without emitting that deliberate fault.
        Log::spy();
        config(['session.driver' => 'database', 'session.connection' => 'qa_missing', 'database.default' => 'qa_missing', 'database.connections.qa_missing' => ['driver' => 'sqlite', 'database' => $this->private.'/absent.sqlite', 'prefix' => '']]);
        try {
            foreach (['support-client/customer-v2.js', 'support-client/engine.js', 'support-client/customer-v2.css'] as $path) {
                $response = $this->get('https://localhost/support-chat/'.$path)->assertOk();
                $this->assertFalse($response->headers->has('Set-Cookie'));
            }
            $this->get('https://localhost/support-chat/api.php?get_csrf=1')->assertStatus(500);
            Log::shouldHaveReceived('error')->once()->with('Database operation failed; SQL and bindings withheld.', ['sqlstate' => 'unknown']);
            $this->assertFileDoesNotExist($this->private.'/absent.sqlite');
        } finally {
            config(['database.default' => $default]);
            DB::purge('qa_missing');
        }
    }

    public function test_stateless_asset_route_keeps_private_operator_and_transport_boundaries(): void
    {
        $this->get('https://localhost/support')->assertOk();
        $this->get('https://localhost/support/sw.js')->assertOk();
        $this->get('http://localhost/support-chat/support-client/engine.js')->assertNotFound();
        $this->get('https://localhost/support-chat/operator/modules/team.js')->assertOk();
        $this->get('https://localhost/support-chat/operator-api.php?action=inbox')->assertUnauthorized();
        $this->get('https://localhost/support-chat/support/bootstrap.php')->assertNotFound();
        config(['support_chat.enabled' => false]);
        $this->get('https://localhost/support-chat/support-client/engine.js')->assertNotFound();
    }

    public function test_combined_customer_bootstrap_preserves_identity_csrf_and_legacy_contract(): void
    {
        $boot = $this->get('https://localhost/support-chat/api.php?get_session=1&bootstrap=1')->assertOk()
            ->assertJsonStructure(['token', 'context', 'draft_user_id'])->assertJsonPath('user_id', null);
        $this->withCookie(session_name(), session_id());
        $identity = $this->get('https://localhost/support-chat/api.php?get_csrf=1')->assertOk();
        $this->assertSame($boot->json('token'), $identity->json('token'));
        $this->assertSame($boot->json('context.scope_id'), $identity->json('context.scope_id'));
        $this->get('https://localhost/support-chat/api.php?get_session=1')->assertOk()->assertJsonMissingPath('token');
        $data = ['send_message' => '1', 'user_id' => $boot->json('draft_user_id'), 'message' => 'synthetic bootstrap test', 'protocol_version' => '2', 'client_message_id' => (string) Str::uuid()];
        $this->post('https://localhost/support-chat/api.php', $data)->assertForbidden();
        $this->post('https://localhost/support-chat/api.php', $data + ['csrf_token' => $boot->json('token')])->assertOk();
        if (getenv('NP_CHAT_PROFILE') === '1') {
            $samples = ['legacy_two_requests' => [], 'combined_one_request' => []];
            for ($i = 0; $i < 7; $i++) {
                foreach (array_keys($samples) as $kind) {
                    $start = hrtime(true);
                    if ($kind === 'legacy_two_requests') {
                        $this->get('https://localhost/support-chat/api.php?get_csrf=1')->assertOk();
                        $this->get('https://localhost/support-chat/api.php?get_session=1')->assertOk();
                    } else {
                        $this->get('https://localhost/support-chat/api.php?get_session=1&bootstrap=1')->assertOk();
                    }
                    $samples[$kind][] = round((hrtime(true) - $start) / 1e6, 3);
                }
            }
            foreach ($samples as &$values) {
                sort($values);
                $values = ['samples' => 7, 'median_ms' => $values[3], 'p95_ms' => $values[6]];
            }
            unset($values);
            $report = fopen(sys_get_temp_dir().'/np-chat-profile-'.bin2hex(random_bytes(8)).'.json', 'xb');
            $this->assertIsResource($report);
            fwrite($report, json_encode(['isolated_php_kernel_only' => $samples], JSON_THROW_ON_ERROR));
            fclose($report);
        }
    }

    public function test_browser_cookie_round_trip_uses_laravel_authenticated_decryption(): void
    {
        $first = $this->get('https://localhost/support-chat/api.php?get_csrf=1')->assertOk();
        $name = session_name();
        $id = session_id();
        // PHP globals contain the encrypted wire value; Laravel's cookie bag is
        // authenticated and decrypted by middleware before the controller runs.
        $_COOKIE[$name] = str_repeat('encrypted-wire-value', 20);
        $second = $this->withCookie($name, $id)->get('https://localhost/support-chat/api.php?get_csrf=1')->assertOk();
        $this->assertSame($first->json('context.scope_id'), $second->json('context.scope_id'));
        $this->get('https://localhost/support-chat/api.php?get_session=1')->assertOk()->assertJsonPath('status', 'success');
        $this->withUnencryptedCookie($name, 'tampered-wire-value')->get('https://localhost/support-chat/api.php?get_session=1')->assertUnauthorized();
    }

    public function test_widget_renders_interface_without_private_configuration_output(): void
    {
        $response = $this->get('https://localhost/support-chat/support-widget.php')->assertOk();
        $response->assertSee('chatWindow', false)->assertSee('customer-v2.js', false);
        foreach (['SUPPORT_INSTALLATION_ID=', 'SUPPORT_PUBLIC_ROOT=', 'CHAT_DATA_DIR=', 'SUPPORT_RUNTIME_DIR=', $this->private] as $privateValue) {
            $response->assertDontSee($privateValue, false);
        }
        foreach (['installation.env', 'support/installation.env', 'support/configuration.php', '../installation.env', 'data/messages.json', 'runtime/session', '.env'] as $path) {
            $this->get('https://localhost/support-chat/'.$path)->assertNotFound();
        }
    }

    public function test_operator_requires_native_credentials_and_csrf(): void
    {
        $boot = $this->get('https://localhost/support-chat/operator-api.php?action=bootstrap')->assertOk()->assertJsonPath('authenticated', false);
        $this->withCookie(session_name(), session_id());
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'login', 'username' => 'owner', 'password' => 'IndependentSupport12!', 'csrf_token' => 'invalid'])->assertForbidden();
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'login', 'username' => 'owner', 'password' => 'password', 'csrf_token' => $boot->json('token')])->assertUnauthorized();
        $this->loginOperator();
        $this->get('https://localhost/support-chat/operator-api.php?action=inbox')->assertOk();
        $this->assertGuest('web');
    }

    public function test_non_tls_and_disabled_chat_do_not_initialize_a_public_session(): void
    {
        $this->get('http://localhost/support-chat/api.php?get_csrf=1')->assertNotFound();
        config(['support_chat.enabled' => false]);
        $this->get('https://localhost/support-chat/api.php?get_csrf=1')->assertNotFound();
    }

    public function test_native_team_management_never_creates_or_changes_laravel_accounts(): void
    {
        $token = $this->loginOperator();
        $before = [User::count(), AdminAccount::count()];
        $data = ['action' => 'account_add', 'username' => 'native_support', 'name' => 'Synthetic Support', 'password' => 'NativeSupportPassword12!', 'role' => 'support', 'csrf_token' => $token];
        $this->post('https://localhost/support-chat/operator-api.php', array_replace($data, ['csrf_token' => 'invalid']))->assertForbidden();
        $id = $this->post('https://localhost/support-chat/operator-api.php', $data)->assertOk()->json('account.id');
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'account_revoke', 'id' => $id, 'csrf_token' => $token])->assertOk();
        $this->assertSame($before, [User::count(), AdminAccount::count()]);
        $accounts = $this->get('https://localhost/support-chat/operator-api.php?action=accounts')->assertOk()->json('accounts');
        $this->assertFalse(collect($accounts)->firstWhere('id', $id)['active']);
    }

    public function test_native_support_role_cannot_manage_accounts(): void
    {
        $token = $this->loginOperator();
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'account_add', 'username' => 'native_support', 'name' => 'Support', 'password' => 'NativeSupportPassword12!', 'role' => 'support', 'csrf_token' => $token])->assertOk();
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'logout', 'csrf_token' => $token])->assertOk();
        $token = $this->loginOperator('native_support', 'NativeSupportPassword12!');
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'account_add', 'username' => 'forbidden', 'name' => 'Forbidden', 'password' => 'NativeSupportPassword12!', 'role' => 'administrator', 'csrf_token' => $token])->assertForbidden();
    }

    private function customerContext(): array
    {
        $token = $this->get('https://localhost/support-chat/api.php?get_csrf=1')->assertOk()->json('token');
        $_COOKIE[session_name()] = session_id();
        $this->withCookie(session_name(), session_id());
        $draft = $this->get('https://localhost/support-chat/api.php?get_session=1')->assertOk()->json('draft_user_id');

        return [$token, $draft];
    }

    public function test_empty_installation_keeps_sync_epoch_before_and_after_first_send(): void
    {
        [$token, $id] = $this->customerContext();
        $url = 'https://localhost/support-chat/api.php?'.http_build_query(['get_conversation' => '1', 'scope' => 'customer', 'user_id' => $id]);
        $first = $this->get($url)->assertOk();
        $next = $url.'&after_sequence=0&store_epoch='.$first->json('store_epoch');
        $this->get($next)->assertOk();
        $data = ['send_message' => '1', 'user_id' => $id, 'message' => 'Fixture message', 'protocol_version' => '2', 'client_message_id' => (string) Str::uuid(), 'csrf_token' => $token];
        $ack = $this->post('https://localhost/support-chat/api.php', $data)->assertOk();
        $this->assertSame($first->json('store_epoch'), $ack->json('store_epoch'));
        $this->get($next)->assertOk()->assertJsonCount(1, 'messages');
        $this->post('https://localhost/support-chat/api.php', $data)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('message_id', $ack->json('message_id'));
    }

    public function test_rapid_sends_and_lost_ack_retries_preserve_order_without_duplicates(): void
    {
        [$token, $id] = $this->customerContext();
        $ids = [];
        for ($i = 0; $i < 6; $i++) {
            $data = ['send_message' => '1', 'user_id' => $id, 'message' => 'Fixture '.$i, 'protocol_version' => '2', 'client_message_id' => (string) Str::uuid(), 'csrf_token' => $token];
            $ack = $this->post('https://localhost/support-chat/api.php', $data)->assertOk()->assertJsonPath('replayed', false);
            $ids[] = $ack->json('message_id');
            $this->post('https://localhost/support-chat/api.php', $data)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('message_id', end($ids));
        }
        $page = $this->get('https://localhost/support-chat/api.php?'.http_build_query(['get_conversation' => '1', 'scope' => 'customer', 'user_id' => $id]))->assertOk()->assertJsonCount(6, 'messages');
        $this->assertSame($ids, array_column($page->json('messages'), 'id'));
        $this->assertCount(6, array_unique($ids));
        $this->get('https://localhost/support-chat/api.php?get_session=1')->assertOk()->assertJsonPath('user_id', $id);
    }

    public function test_message_ack_replay_edit_history_and_foreign_session_isolation(): void
    {
        [$token, $id] = $this->customerContext();
        $data = ['send_message' => '1', 'user_id' => $id, 'message' => '<script>alert(1)</script>', 'protocol_version' => '2', 'client_message_id' => (string) Str::uuid()];
        $this->post('https://localhost/support-chat/api.php', $data)->assertForbidden();
        $data['csrf_token'] = $token;
        $first = $this->post('https://localhost/support-chat/api.php', $data)->assertOk()->assertJsonPath('replayed', false)->json();
        $this->post('https://localhost/support-chat/api.php', $data)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('message_id', $first['message_id']);
        $changed = array_replace($data, ['message' => 'different']);
        $this->post('https://localhost/support-chat/api.php', $changed)->assertStatus(409);
        $page = $this->get('https://localhost/support-chat/api.php?get_conversation=1&user_id='.$id)->assertOk()->json();
        $this->assertCount(1, $page['messages']);
        $this->assertSame('<script>alert(1)</script>', $page['messages'][0]['text']);
        $stored = json_decode(file_get_contents($this->private.'/data/chat_conversations.json'), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $stored['conversations'][$id]['messages'][0]['text']);
        $this->post('https://localhost/support-chat/api.php', ['edit_message' => '1', 'protocol_version' => '2', 'scope' => 'customer', 'user_id' => $id, 'message_id' => (string) $first['message_id'], 'revision' => '0', 'edit_request_id' => (string) Str::uuid(), 'text' => 'edited safely', 'csrf_token' => $token])->assertOk();
        $_COOKIE = [];
        $this->defaultCookies = [];
        $this->customerContext();
        $this->get('https://localhost/support-chat/api.php?get_conversation=1&user_id='.$id)->assertNotFound();
        $this->post('https://localhost/support-chat/support-attachment.php', ['scope' => 'customer', 'user_id' => $id, 'upload_id' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_verified_customer_binding_logout_and_account_switch_cannot_leak_history(): void
    {
        [$token, $id] = $this->customerContext();
        $account = new CustomerAccount;
        $account->public_id = (string) Str::uuid();
        $account->phone = '09000000000';
        $account->phone_digest = hash('sha256', 'synthetic fixture');
        $account->verified_at = now();
        $account->is_active = true;
        $account->session_version = 1;
        $account->save();
        $this->actingAs($account, 'customer')->withSession(['customer_session_version' => 1]);
        $this->get('https://localhost/support-chat/api.php?get_session=1')->assertOk();
        $this->assertDatabaseHas('chat_customer_owners', ['customer_account_id' => $account->id]);
        auth('customer')->logout();
        $this->post('https://localhost/support-chat/api.php', ['send_message' => '1', 'csrf_token' => $token, 'protocol_version' => '2', 'user_id' => $id, 'message' => 'synthetic rejected message', 'client_message_id' => (string) Str::uuid()])->assertUnauthorized();
        $this->get('https://localhost/support-chat/api.php?get_session=1')->assertUnauthorized();
    }

    public function test_admin_logout_and_revocation_do_not_end_independent_support_session(): void
    {
        $owner = User::query()->sole();
        $this->actingAs($owner)->withSession(['admin_session_version' => 1]);
        $this->loginOperator();
        $this->post('https://localhost/admin/logout')->assertRedirect('/admin/login');
        AdminAccount::query()->where('user_id', $owner->id)->update(['session_version' => 2]);
        $this->get('https://localhost/support-chat/operator-api.php?action=bootstrap')->assertOk()->assertJsonPath('authenticated', true);
        $this->assertGuest('web');
    }

    public function test_native_authority_read_does_not_rewrite_or_resynchronize_admins(): void
    {
        $path = $this->private.'/runtime/operators/authority.json';
        $before = file_get_contents($path);
        $bridge = app(ChatBridge::class);
        $bridge->begin();
        try {
            support_ops_store(static fn (array &$state) => null);
        } finally {
            $bridge->finish();
        }
        $this->assertSame($before, file_get_contents($path));
    }

    public function test_operator_noop_optimization_still_persists_expired_metadata_cleanup(): void
    {
        $bridge = app(ChatBridge::class);
        $path = $this->private.'/runtime/operators/authority.json';
        $bridge->begin();
        try {
            support_ops_store(static function (array &$state): void {
                $id = array_key_first($state['accounts']);
                $state['events'][] = ['at' => time() - 8 * 86400, 'actor' => $id, 'event' => 'synthetic_old_event'];
                $state['subscriptions'][str_repeat('a', 32)] = ['operator' => $id, 'sealed' => 'synthetic', 'expires' => time() - 1, 'session_expires' => time() + 60];
            }, true);
            $before = json_decode(file_get_contents($path), true);
            $this->assertNotEmpty($before['events']);
            $this->assertNotEmpty($before['subscriptions']);
            support_ops_store(static fn (array &$state) => null, true);
            $after = json_decode(file_get_contents($path), true);
            $this->assertSame(['independent_authentication_enrolled'], array_column($after['events'], 'event'));
            $this->assertSame([], $after['subscriptions']);
        } finally {
            $bridge->finish();
        }
    }

    public function test_operator_inbox_reply_reads_lifecycle_satisfaction_and_analytics(): void
    {
        [$customerToken, $id] = $this->customerContext();
        $this->post('https://localhost/support-chat/api.php', ['send_message' => '1', 'user_id' => $id, 'message' => 'synthetic question', 'protocol_version' => '2', 'client_message_id' => (string) Str::uuid(), 'csrf_token' => $customerToken])->assertOk();
        $user = User::query()->sole();
        $this->actingAs($user)->withSession(['admin_session_version' => 1, 'support_authentication_nonce' => bin2hex(random_bytes(16)), 'support_authenticated_at' => time()]);
        $operatorToken = $this->loginOperator();
        $_COOKIE[session_name()] = session_id();
        $this->withCookie(session_name(), session_id());
        $this->get('https://localhost/support-chat/operator-api.php?action=inbox')->assertOk()->assertJsonCount(1, 'conversations');
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'workflow', 'operation' => 'status', 'value' => 'resolved', 'user_id' => $id])->assertForbidden();
        $reply = $this->post('https://localhost/support-chat/api.php', ['action' => 'send_reply', 'user_id' => $id, 'reply' => 'synthetic answer', 'protocol_version' => '2', 'client_message_id' => (string) Str::uuid(), 'csrf_token' => $operatorToken])->assertOk()->json('message_id');
        $this->post('https://localhost/support-chat/api.php', ['scope' => 'customer', 'read_visible' => '1', 'user_id' => $id, 'message_ids' => json_encode([$reply]), 'csrf_token' => $customerToken])->assertOk();
        $closed = $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'workflow', 'operation' => 'status', 'value' => 'resolved', 'user_id' => $id, 'csrf_token' => $operatorToken])->assertOk()->json('workflow.resolution_event');
        $vote = ['action' => 'satisfaction', 'user_id' => $id, 'event' => $closed, 'value' => 'positive', 'csrf_token' => $customerToken];
        $this->post('https://localhost/support-chat/api.php', $vote)->assertOk()->assertJsonPath('replayed', false);
        $this->post('https://localhost/support-chat/api.php', $vote)->assertOk()->assertJsonPath('replayed', true);
        $this->get('https://localhost/support-chat/operator-api.php?action=analytics')->assertOk()->assertJsonStructure(['stats']);
        $this->post('https://localhost/support-chat/api.php', ['lifecycle' => 'reopen', 'user_id' => $id, 'csrf_token' => $customerToken])->assertOk()->assertJsonPath('lifecycle.resolved', false);
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'account_add', 'csrf_token' => $operatorToken])->assertStatus(400);
    }

    public function test_corrupt_private_authority_fails_closed_without_disclosing_paths(): void
    {
        $this->loginOperator();
        file_put_contents($this->private.'/runtime/operators/authority.json', 'not json');
        $this->actingAs(User::query()->sole())->withSession(['admin_session_version' => 1, 'support_authentication_nonce' => bin2hex(random_bytes(16)), 'support_authenticated_at' => time()]);
        $this->get('https://localhost/support-chat/operator-api.php?action=inbox')->assertStatus(503)->assertDontSee($this->private);
        if (! is_dir($this->private.'/data')) {
            mkdir($this->private.'/data', 0700);
        }
        file_put_contents($this->private.'/data/chat_conversations.json', 'not json');
        $this->get('https://localhost/support-chat/api.php?get_csrf=1')->assertOk();
        $_COOKIE[session_name()] = session_id();
        $this->withCookie(session_name(), session_id());
        $this->get('https://localhost/support-chat/api.php?get_session=1')->assertStatus(503)->assertDontSee($this->private);
    }

    public function test_independent_password_change_requires_current_native_password_and_revokes_old_session(): void
    {
        $token = $this->loginOperator();
        $data = ['action' => 'profile_password', 'current_password' => 'wrong', 'password' => 'NewNativePassword12!', 'csrf_token' => $token];
        $this->post('https://localhost/support-chat/operator-api.php', $data)->assertForbidden();
        $data['current_password'] = 'IndependentSupport12!';
        $this->post('https://localhost/support-chat/operator-api.php', $data)->assertOk();
        $this->get('https://localhost/support-chat/operator-api.php?action=inbox')->assertUnauthorized();
        $this->assertGuest('web');
    }

    public function test_cli_working_directory_is_not_mistaken_for_the_public_document_root(): void
    {
        $bridge = app(ChatBridge::class);
        $cwd = getcwd();
        $document = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $bridge->begin();
        try {
            chdir($this->private);
            $_SERVER['DOCUMENT_ROOT'] = '';
            $result = support_private_dir($this->private.'/worker-probe');
            $this->assertSame(realpath($this->private.'/worker-probe'), $result);
            try {
                support_private_dir(public_path('build'));
                $this->fail('The explicit public directory must still be rejected.');
            } catch (\SupportFailure $failure) {
                $this->assertSame('private_storage_unavailable', $failure->reason);
            }
        } finally {
            chdir($cwd);
            if ($document === null) {
                unset($_SERVER['DOCUMENT_ROOT']);
            } else {
                $_SERVER['DOCUMENT_ROOT'] = $document;
            }
            $bridge->finish();
        }
    }

    public function test_image_only_message_preserves_empty_form_text_across_laravel_normalization(): void
    {
        [$token, $conversation] = $this->customerContext();
        $context = $_SESSION;
        $bridge = app(ChatBridge::class);
        $bridge->begin();
        try {
            $image = imagecreatetruecolor(2, 2);
            ob_start();
            imagewebp($image);
            $bytes = ob_get_clean();
            imagedestroy($image);
            $id = bin2hex(random_bytes(16));
            support_atomic_replace(support_attachment_path($id), $bytes);
            support_store(function (array &$state, bool &$changed) use ($context, $conversation, $bytes, $id): void {
                $state['uploads'][$id] = ['sender' => support_principal($context, 'customer'), 'epoch' => $context['auth_epoch'], 'conversation' => $conversation, 'created' => time(), 'expires' => time() + 86400, 'bytes' => strlen($bytes), 'mime' => 'image/webp', 'width' => 2, 'height' => 2, 'sha256' => hash('sha256', $bytes), 'state' => 'uploaded'];
                $changed = true;
            }, true);
        } finally {
            $bridge->finish();
        }
        $this->post('https://localhost/support-chat/api.php', ['send_message' => '1', 'user_id' => $conversation, 'message' => '', 'attachments' => json_encode([$id]), 'protocol_version' => '2', 'client_message_id' => (string) Str::uuid(), 'csrf_token' => $token])->assertOk()->assertJsonPath('status', 'accepted');
        $this->get('https://localhost/support-chat/support-attachment.php?id='.$id)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $_COOKIE = [];
        $this->defaultCookies = [];
        $this->customerContext();
        $this->get('https://localhost/support-chat/support-attachment.php?id='.$id)->assertNotFound();
    }

    public function test_operator_bootstrap_does_not_query_laravel_accounts_or_database_sessions(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->get('https://localhost/support-chat/operator-api.php?action=bootstrap')->assertOk()->assertJsonPath('authenticated', false);
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_operator_soft_deletion_preserves_history_attribution_and_rejects_reactivation(): void
    {
        [$customerToken, $conversation] = $this->customerContext();
        $this->post('https://localhost/support-chat/api.php', ['send_message' => '1', 'user_id' => $conversation, 'message' => 'synthetic question', 'protocol_version' => '2', 'client_message_id' => (string) Str::uuid(), 'csrf_token' => $customerToken])->assertOk();
        $ownerToken = $this->loginOperator();
        $id = $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'account_add', 'username' => 'historical_support', 'name' => 'Historical Operator', 'password' => 'NativeSupportPassword12!', 'role' => 'support', 'csrf_token' => $ownerToken])->assertOk()->json('account.id');
        $operatorToken = $this->loginOperator('historical_support', 'NativeSupportPassword12!');
        $this->post('https://localhost/support-chat/api.php', ['action' => 'send_reply', 'user_id' => $conversation, 'reply' => 'historical response', 'sender_name' => 'Forged Client Name', 'operator_id' => str_repeat('f', 32), 'protocol_version' => '2', 'client_message_id' => (string) Str::uuid(), 'csrf_token' => $operatorToken])->assertOk();
        $ownerToken = $this->loginOperator();
        $data = ['action' => 'account_delete', 'id' => $id, 'csrf_token' => $ownerToken];
        $this->post('https://localhost/support-chat/operator-api.php', $data)->assertConflict()->assertJsonPath('code', 'operator_must_be_inactive');
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'account_revoke', 'id' => $id, 'csrf_token' => $ownerToken])->assertOk();
        $authorityPath = $this->private.'/runtime/operators/authority.json';
        $beforeAccount = json_decode(file_get_contents($authorityPath), true)['accounts'][$id];
        $conversationPath = $this->private.'/data/chat_conversations.json';
        $conversationBefore = file_get_contents($conversationPath);
        $this->post('https://localhost/support-chat/operator-api.php', array_replace($data, ['csrf_token' => 'invalid']))->assertForbidden();
        $this->post('https://localhost/support-chat/operator-api.php', $data)->assertOk()->assertJsonPath('replayed', false);
        $this->post('https://localhost/support-chat/operator-api.php', $data)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame($conversationBefore, file_get_contents($conversationPath));
        $afterState = json_decode(file_get_contents($authorityPath), true);
        $after = $afterState['accounts'][$id];
        $this->assertSame('Historical Operator', $after['name']);
        $this->assertFalse($after['active']);
        $this->assertIsInt($after['deleted_at']);
        $this->assertNotSame($beforeAccount['epoch'], $after['epoch']);
        $this->assertFalse(password_verify('NativeSupportPassword12!', $after['password']));
        $this->assertContains('account_deleted', array_column($afterState['events'], 'event'));
        foreach (['accounts', 'operator_accounts'] as $action) {
            $accounts = $this->get('https://localhost/support-chat/operator-api.php?action='.$action)->assertOk()->json('accounts');
            $this->assertNotContains($id, array_column($accounts, 'id'));
        }
        $page = $this->get('https://localhost/support-chat/api.php?get_conversation=1&scope=operator&user_id='.$conversation)->assertOk();
        $reply = collect($page->json('messages'))->firstWhere('type', 'admin');
        $this->assertSame('Historical Operator', $reply['sender']['name']);
        $this->assertSame('historical response', $reply['text']);
        $customerPage = $this->get('https://localhost/support-chat/api.php?get_conversation=1&scope=customer&user_id='.$conversation)->assertOk();
        $this->assertNull(collect($customerPage->json('messages'))->firstWhere('type', 'admin')['sender']);
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'account_update', 'id' => $id, 'name' => 'Restore', 'role' => 'support', 'active' => '1', 'csrf_token' => $ownerToken])->assertNotFound();
        $bridge = app(ChatBridge::class);
        $bridge->begin();
        try {
            $this->assertNull(support_ops_session(['operator_id' => $id, 'credential_version' => support_ops_version($beforeAccount)]));
        } finally {
            $bridge->finish();
        }
    }

    public function test_operator_deletion_permissions_self_and_foreign_account_guards(): void
    {
        $token = $this->loginOperator();
        $ownerId = $this->get('https://localhost/support-chat/operator-api.php?action=bootstrap')->assertOk()->json('operator.id');
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'account_delete', 'id' => $ownerId, 'csrf_token' => $token])->assertForbidden()->assertJsonPath('code', 'self_deletion_forbidden');
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'account_revoke', 'id' => $ownerId, 'csrf_token' => $token])->assertConflict()->assertJsonPath('code', 'last_administrator');
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'account_delete', 'id' => str_repeat('f', 32), 'csrf_token' => $token])->assertNotFound();
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'account_delete', 'id' => '../unrelated', 'csrf_token' => $token])->assertStatus(400);
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'account_add', 'username' => 'restricted_support', 'name' => 'Restricted', 'password' => 'NativeSupportPassword12!', 'role' => 'support', 'csrf_token' => $token])->assertOk();
        $token = $this->loginOperator('restricted_support', 'NativeSupportPassword12!');
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'account_delete', 'id' => $ownerId, 'csrf_token' => $token])->assertForbidden();
    }

    public function test_reply_ownership_acceptance_replay_expiry_and_customer_messages(): void
    {
        [$customerToken, $id] = $this->customerContext();
        $this->post('https://localhost/support-chat/api.php', ['send_message' => '1', 'user_id' => $id, 'message' => 'ownership question', 'protocol_version' => '2', 'client_message_id' => (string) Str::uuid(), 'csrf_token' => $customerToken])->assertOk();
        $ownerToken = $this->loginOperator();
        $other = $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'account_add', 'username' => 'reply_operator', 'name' => 'Other Operator', 'password' => 'NativeSupportPassword12!', 'role' => 'support', 'csrf_token' => $ownerToken])->assertOk()->json('account.id');
        $data = ['action' => 'send_reply', 'user_id' => $id, 'reply' => 'first accepted reply', 'protocol_version' => '2', 'client_message_id' => (string) Str::uuid(), 'csrf_token' => $ownerToken];
        $first = $this->post('https://localhost/support-chat/api.php', $data)->assertOk()->assertJsonPath('reply_ownership.is_owner', true);
        $this->assertSame(300, $first->json('reply_ownership.expires_at') - $first->json('reply_ownership.server_now'));
        $path = $this->private.'/data/chat_conversations.json';
        $lease = json_decode(file_get_contents($path), true)['conversations'][$id]['reply_ownership'];
        $this->post('https://localhost/support-chat/api.php', $data)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame($lease, json_decode(file_get_contents($path), true)['conversations'][$id]['reply_ownership']);
        $otherToken = $this->loginOperator('reply_operator', 'NativeSupportPassword12!');
        $page = $this->get('https://localhost/support-chat/api.php?get_conversation=1&scope=operator&user_id='.$id)->assertOk()->assertJsonPath('reply_ownership.is_owner', false);
        $this->assertSame($first->json('reply_ownership.operator_id'), $page->json('reply_ownership.operator_id'));
        $conflict = array_replace($data, ['client_message_id' => (string) Str::uuid(), 'csrf_token' => $otherToken, 'owner_id' => $other, 'expires_at' => 0]);
        $this->post('https://localhost/support-chat/api.php', $conflict)->assertConflict()->assertJsonPath('code', 'conversation_owned');
        $this->post('https://localhost/support-chat/api.php', array_replace($conflict, ['attachments' => json_encode([str_repeat('a', 32)])]))->assertConflict()->assertJsonPath('code', 'conversation_owned');
        $this->post('https://localhost/support-chat/api.php', ['send_message' => '1', 'user_id' => $id, 'message' => 'customer still allowed', 'protocol_version' => '2', 'client_message_id' => (string) Str::uuid(), 'csrf_token' => $customerToken])->assertOk();
        $this->get('https://localhost/support-chat/api.php?get_conversation=1&scope=customer&user_id='.$id)->assertOk()->assertJsonMissingPath('reply_ownership');
        $this->assertSame($lease, json_decode(file_get_contents($path), true)['conversations'][$id]['reply_ownership']);
        $bridge = app(ChatBridge::class);
        $bridge->begin();
        try {
            support_store(function (array &$state, bool &$changed) use ($id): void {
                $state['conversations'][$id]['reply_ownership']['last_reply_at'] = time() - 300;
                $changed = true;
            }, true);
        } finally {
            $bridge->finish();
        }
        $this->get('https://localhost/support-chat/api.php?get_conversation=1&scope=operator&user_id='.$id)->assertOk()->assertJsonPath('reply_ownership.operator_id', null);
        $second = $this->post('https://localhost/support-chat/api.php', $conflict)->assertOk()->assertJsonPath('reply_ownership.operator_id', $other);
        $ownerToken = $this->loginOperator();
        $this->post('https://localhost/support-chat/api.php', array_replace($data, ['csrf_token' => $ownerToken]))->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('reply_ownership.operator_id', $other);
        $this->post('https://localhost/support-chat/api.php', array_replace($data, ['client_message_id' => (string) Str::uuid(), 'csrf_token' => $ownerToken]))->assertConflict();
        $this->assertSame($second->json('reply_ownership.expires_at'), json_decode(file_get_contents($path), true)['conversations'][$id]['reply_ownership']['last_reply_at'] + 300);
    }

    public function test_reply_ownership_releases_on_close_revocation_deletion_and_handoff(): void
    {
        [$customerToken, $id] = $this->customerContext();
        $this->post('https://localhost/support-chat/api.php', ['send_message' => '1', 'user_id' => $id, 'message' => 'release question', 'protocol_version' => '2', 'client_message_id' => (string) Str::uuid(), 'csrf_token' => $customerToken])->assertOk();
        $token = $this->loginOperator();
        $other = $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'account_add', 'username' => 'lease_owner', 'name' => 'Lease Owner', 'password' => 'NativeSupportPassword12!', 'role' => 'support', 'csrf_token' => $token])->assertOk()->json('account.id');
        $otherToken = $this->loginOperator('lease_owner', 'NativeSupportPassword12!');
        $this->post('https://localhost/support-chat/api.php', ['action' => 'send_reply', 'user_id' => $id, 'reply' => 'held', 'protocol_version' => '2', 'client_message_id' => (string) Str::uuid(), 'csrf_token' => $otherToken])->assertOk();
        $token = $this->loginOperator();
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'workflow', 'operation' => 'assign', 'value' => $other, 'user_id' => $id, 'csrf_token' => $token])->assertOk();
        $this->get('https://localhost/support-chat/api.php?get_conversation=1&scope=operator&user_id='.$id)->assertOk()->assertJsonPath('reply_ownership.operator_id', null);
        $otherToken = $this->loginOperator('lease_owner', 'NativeSupportPassword12!');
        $this->post('https://localhost/support-chat/api.php', ['action' => 'send_reply', 'user_id' => $id, 'reply' => 'held again', 'protocol_version' => '2', 'client_message_id' => (string) Str::uuid(), 'csrf_token' => $otherToken])->assertOk();
        $token = $this->loginOperator();
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'workflow', 'operation' => 'status', 'value' => 'resolved', 'user_id' => $id, 'csrf_token' => $token])->assertOk();
        $this->get('https://localhost/support-chat/api.php?get_conversation=1&scope=operator&user_id='.$id)->assertOk()->assertJsonPath('reply_ownership.operator_id', null);
        $this->post('https://localhost/support-chat/api.php', ['lifecycle' => 'reopen', 'user_id' => $id, 'csrf_token' => $customerToken])->assertOk();
        $otherToken = $this->loginOperator('lease_owner', 'NativeSupportPassword12!');
        $this->post('https://localhost/support-chat/api.php', ['action' => 'send_reply', 'user_id' => $id, 'reply' => 'new lease', 'protocol_version' => '2', 'client_message_id' => (string) Str::uuid(), 'csrf_token' => $otherToken])->assertOk();
        $token = $this->loginOperator();
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'account_revoke', 'id' => $other, 'csrf_token' => $token])->assertOk();
        $this->get('https://localhost/support-chat/api.php?get_conversation=1&scope=operator&user_id='.$id)->assertOk()->assertJsonPath('reply_ownership.operator_id', null);
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'account_delete', 'id' => $other, 'csrf_token' => $token])->assertOk();
        $this->get('https://localhost/support-chat/api.php?get_conversation=1&scope=operator&user_id='.$id)->assertOk()->assertJsonPath('reply_ownership.operator_id', null);
    }

    public function test_reply_ownership_renews_only_for_new_human_replies_not_reads_or_automation(): void
    {
        [$customerToken, $id] = $this->customerContext();
        $this->post('https://localhost/support-chat/api.php', ['send_message' => '1', 'user_id' => $id, 'message' => 'ownership renewal', 'protocol_version' => '2', 'client_message_id' => (string) Str::uuid(), 'csrf_token' => $customerToken])->assertOk();
        $token = $this->loginOperator();
        $data = ['action' => 'send_reply', 'user_id' => $id, 'reply' => 'human reply', 'protocol_version' => '2', 'client_message_id' => (string) Str::uuid(), 'csrf_token' => $token];
        $this->post('https://localhost/support-chat/api.php', $data)->assertOk();
        $article = str_repeat('a', 32);
        $this->post('https://localhost/support-chat/support-content-admin.php', ['action' => 'article_save', 'revision' => '0', 'id' => $article, 'title' => 'Synthetic question', 'body' => 'Synthetic automatic answer', 'published' => '1', 'suggested' => '1', 'csrf_token' => $token])->assertOk();
        $bridge = app(ChatBridge::class);
        $bridge->begin();
        try {
            support_store(function (array &$state, bool &$changed) use ($id): void {
                $state['conversations'][$id]['reply_ownership']['last_reply_at'] = time() - 60;
                $changed = true;
            }, true);
        } finally {
            $bridge->finish();
        }
        $path = $this->private.'/data/chat_conversations.json';
        $lease = json_decode(file_get_contents($path), true)['conversations'][$id]['reply_ownership'];
        $this->get('https://localhost/support-chat/api.php?get_conversation=1&scope=operator&user_id='.$id)->assertOk()->assertJsonPath('reply_ownership.is_owner', true);
        $this->post('https://localhost/support-chat/api.php', ['operator_activity' => '1', 'csrf_token' => $token])->assertOk();
        $this->post('https://localhost/support-chat/support-typing.php', ['scope' => 'operator', 'user_id' => $id, 'typing' => '1', 'csrf_token' => $token])->assertOk();
        $this->post('https://localhost/support-chat/api.php', ['action' => 'faq_answer', 'user_id' => $id, 'article_id' => $article, 'csrf_token' => $customerToken])->assertOk();
        $this->assertSame($lease, json_decode(file_get_contents($path), true)['conversations'][$id]['reply_ownership']);
        $this->post('https://localhost/support-chat/api.php', array_replace($data, ['client_message_id' => (string) Str::uuid()]))->assertOk()->assertJsonPath('reply_ownership.is_owner', true);
        $renewed = json_decode(file_get_contents($path), true)['conversations'][$id]['reply_ownership'];
        $this->assertGreaterThan($lease['last_reply_at'], $renewed['last_reply_at']);
        $this->assertSame($lease['operator_id'], $renewed['operator_id']);
        $this->assertEqualsWithDelta(time(), $renewed['last_reply_at'], 1);
    }

    public function test_reply_ownership_is_atomic_between_two_real_competing_php_processes(): void
    {
        [$customerToken, $id] = $this->customerContext();
        $this->post('https://localhost/support-chat/api.php', ['send_message' => '1', 'user_id' => $id, 'message' => 'race fixture', 'protocol_version' => '2', 'client_message_id' => (string) Str::uuid(), 'csrf_token' => $customerToken])->assertOk();
        $token = $this->loginOperator();
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'account_add', 'username' => 'concurrent_operator', 'name' => 'Concurrent Operator', 'password' => 'NativeSupportPassword12!', 'role' => 'support', 'csrf_token' => $token])->assertOk();
        $bridge = app(ChatBridge::class);
        $bridge->begin();
        try {
            $actors = support_ops_store(function (array &$state): array {
                return array_map(fn ($account) => ['uuid' => (string) Str::uuid(), 'context' => ['role' => 'operator', 'operator_id' => $account['id'], 'operator_name' => $account['name'], 'operator_role' => $account['role'], 'credential_version' => support_ops_version($account), 'auth_epoch' => bin2hex(random_bytes(16))]], array_values($state['accounts']));
            });
            file_put_contents($this->private.'/ownership-race.json', json_encode(['conversation' => $id, 'actors' => $actors], JSON_THROW_ON_ERROR));
        } finally {
            $bridge->finish();
        }
        $processes = [];
        try {
            foreach ([0, 1] as $index) {
                $process = new Process([PHP_BINARY, base_path('tests/SupportChatOwnership/submit.php'), $this->private, (string) $index], base_path(), timeout: 15);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 8;
            while (! is_file($this->private.'/ownership-ready-0') || ! is_file($this->private.'/ownership-ready-1')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Competing test workers did not reach the barrier.');
                }
                usleep(1000);
            }
            file_put_contents($this->private.'/ownership-start', '1');
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'];
            }
            sort($results);
            $this->assertSame(['accepted', 'conversation_owned'], $results);
            $conversation = json_decode(file_get_contents($this->private.'/data/chat_conversations.json'), true)['conversations'][$id];
            $replies = array_values(array_filter($conversation['messages'], fn ($message) => $message['type'] === 'admin'));
            $this->assertCount(1, $replies);
            $this->assertSame($replies[0]['operator_id'], $conversation['reply_ownership']['operator_id']);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }

    private function loginOperator(string $username = 'owner', string $password = 'IndependentSupport12!'): string
    {
        $boot = $this->get('https://localhost/support-chat/operator-api.php?action=bootstrap')->assertOk();
        $this->withCookie(session_name(), session_id());
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'login', 'username' => $username, 'password' => $password, 'csrf_token' => $boot->json('token')])->assertOk();
        $this->withCookie(session_name(), session_id());

        return $this->get('https://localhost/support-chat/operator-api.php?action=bootstrap')->assertOk()->assertJsonPath('authenticated', true)->json('token');
    }
}
