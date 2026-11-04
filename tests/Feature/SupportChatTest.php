<?php

namespace Tests\Feature;

use App\Models\AdminAccount;
use App\Models\CustomerAccount;
use App\Models\User;
use App\Support\AdminAccess;
use App\Support\Chat\ChatBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
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
        $this->actingAs(User::factory()->superAdmin()->create());
        app(ChatBridge::class)->initialize();
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

    public function test_operator_requires_current_laravel_grant_and_recent_authentication(): void
    {
        $this->get('https://localhost/support-chat/operator-api.php?action=bootstrap')->assertUnauthorized();
        $user = User::query()->sole();
        $this->actingAs($user)->withSession(['admin_session_version' => AdminAccess::account($user)->session_version]);
        $this->get('https://localhost/support-chat/operator/')->assertRedirect(route('admin.support.confirm'));
        $this->withSession(['support_authentication_nonce' => bin2hex(random_bytes(16)), 'support_authenticated_at' => time()]);
        $this->get('https://localhost/support-chat/operator-api.php?action=bootstrap')->assertOk()->assertJsonPath('authenticated', true);
        $this->withSession(['support_chat_window' => ['nonce' => session('support_authentication_nonce'), 'interactive_at' => time() - 1801]]);
        $this->get('https://localhost/support-chat/operator-api.php?action=bootstrap')->assertUnauthorized();
    }

    public function test_non_tls_and_disabled_chat_do_not_initialize_a_public_session(): void
    {
        $this->get('http://localhost/support-chat/api.php?get_csrf=1')->assertNotFound();
        config(['support_chat.enabled' => false]);
        $this->get('https://localhost/support-chat/api.php?get_csrf=1')->assertNotFound();
    }

    private function customerContext(): array
    {
        $token = $this->get('https://localhost/support-chat/api.php?get_csrf=1')->assertOk()->json('token');
        $_COOKIE[session_name()] = session_id();
        $draft = $this->get('https://localhost/support-chat/api.php?get_session=1')->assertOk()->json('draft_user_id');

        return [$token, $draft];
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
        $this->get('https://localhost/support-chat/api.php?get_session=1')->assertUnauthorized();
    }

    public function test_admin_revocation_cannot_reuse_an_authenticated_native_chat_cookie(): void
    {
        $user = User::query()->sole();
        $this->actingAs($user)->withSession(['admin_session_version' => 1, 'support_authentication_nonce' => bin2hex(random_bytes(16)), 'support_authenticated_at' => time()]);
        $this->get('https://localhost/support-chat/operator-api.php?action=bootstrap')->assertOk();
        $_COOKIE[session_name()] = session_id();
        AdminAccount::query()->where('user_id', $user->id)->update(['session_version' => 2]);
        $this->get('https://localhost/support-chat/operator-api.php?action=bootstrap')->assertForbidden();
        $this->get('https://localhost/support-chat/operator/')->assertForbidden();
    }

    public function test_operator_inbox_reply_reads_lifecycle_satisfaction_and_analytics(): void
    {
        [$customerToken, $id] = $this->customerContext();
        $this->post('https://localhost/support-chat/api.php', ['send_message' => '1', 'user_id' => $id, 'message' => 'synthetic question', 'protocol_version' => '2', 'client_message_id' => (string) Str::uuid(), 'csrf_token' => $customerToken])->assertOk();
        $user = User::query()->sole();
        $this->actingAs($user)->withSession(['admin_session_version' => 1, 'support_authentication_nonce' => bin2hex(random_bytes(16)), 'support_authenticated_at' => time()]);
        $operatorToken = $this->get('https://localhost/support-chat/operator-api.php?action=bootstrap')->assertOk()->json('token');
        $_COOKIE[session_name()] = session_id();
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
        $this->post('https://localhost/support-chat/operator-api.php', ['action' => 'account_add', 'csrf_token' => $operatorToken])->assertStatus(409);
    }

    public function test_corrupt_private_authority_fails_closed_without_disclosing_paths(): void
    {
        file_put_contents($this->private.'/runtime/operators/authority.json', 'not json');
        $this->actingAs(User::query()->sole())->withSession(['admin_session_version' => 1, 'support_authentication_nonce' => bin2hex(random_bytes(16)), 'support_authenticated_at' => time()]);
        $this->get('https://localhost/support-chat/operator-api.php?action=bootstrap')->assertStatus(503)->assertDontSee($this->private);
        mkdir($this->private.'/data', 0700);
        file_put_contents($this->private.'/data/chat_conversations.json', 'not json');
        $this->get('https://localhost/support-chat/api.php?get_csrf=1')->assertOk();
        $_COOKIE[session_name()] = session_id();
        $this->get('https://localhost/support-chat/api.php?get_session=1')->assertStatus(503)->assertDontSee($this->private);
    }

    public function test_support_reauthentication_requires_native_csrf_and_actual_current_password(): void
    {
        $user = User::query()->sole();
        $this->actingAs($user)->withSession(['admin_session_version' => 1]);
        $this->get('/admin/support/confirm')->assertOk();
        $this->post('/admin/support/confirm', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->assertNull(session('support_authentication_nonce'));
        $this->post('/admin/support/confirm', ['current_password' => 'password'])->assertRedirect('/support-chat/operator/');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/D', session('support_authentication_nonce'));
        $this->app->detectEnvironment(fn () => 'local');
        $this->post('/admin/support/confirm', ['current_password' => 'password'])->assertStatus(419);
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
        $this->customerContext();
        $this->get('https://localhost/support-chat/support-attachment.php?id='.$id)->assertNotFound();
    }
}
