<?php

namespace Tests\Feature;

use App\Services\IndependentSupportOperators;
use App\Support\Chat\ChatBridge;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class IndependentSupportEnrollmentTest extends TestCase
{
    private string $private;

    protected function setUp(): void
    {
        parent::setUp();
        $this->private = sys_get_temp_dir().'/novinpay-independent-test-'.bin2hex(random_bytes(12));
        config(['support_chat.enabled' => true, 'support_chat.directory' => $this->private]);
        app(ChatBridge::class)->initialize();
    }

    protected function tearDown(): void
    {
        app(ChatBridge::class)->finish();
        File::deleteDirectory($this->private);
        parent::tearDown();
    }

    public function test_enrollment_preserves_ids_profiles_history_and_native_passwords_but_revokes_inherited_access(): void
    {
        $bridge = app(ChatBridge::class);
        $bridge->begin();
        $owner = support_ops_account('np_123', 'Synthetic owner', 'UnusableOldPassword12!', 'administrator');
        $owner['id'] = substr(hash_hmac('sha256', 'chat-operator:123', config('app.key')), 0, 32);
        $mapped = support_ops_account('np_124', 'Mapped support', 'OtherOldPassword12!', 'support');
        $mapped['id'] = substr(hash_hmac('sha256', 'chat-operator:124', config('app.key')), 0, 32);
        $native = support_ops_account('native_support', 'Independent support', 'KeepNativePassword12!', 'support');
        support_ops_store(function (array &$state) use ($owner, $mapped, $native): void {
            $state['accounts'] = array_column([$owner, $mapped, $native], null, 'id');
            support_ops_audit($state, $owner['id'], 'historical_event');
        }, true);
        $bridge->finish();

        $original = file_get_contents($this->private.'/runtime/operators/authority.json');
        app(IndependentSupportOperators::class)->enroll(null, 'NewIndependentPassword12!');
        $this->assertSame($original, file_get_contents($this->private.'/runtime/operators/authority-before-independent-'.hash('sha256', $original).'.json'));
        $state = json_decode(file_get_contents($this->private.'/runtime/operators/authority.json'), true);
        $this->assertSame(array_column([$owner, $mapped, $native], 'id'), array_keys($state['accounts']));
        $this->assertSame($owner['name'], $state['accounts'][$owner['id']]['name']);
        $this->assertFalse($state['accounts'][$mapped['id']]['active']);
        $this->assertTrue($state['accounts'][$native['id']]['active']);
        $this->assertSame($native['password'], $state['accounts'][$native['id']]['password']);
        $this->assertNotSame($owner['epoch'], $state['accounts'][$owner['id']]['epoch']);
        $this->assertTrue(password_verify('NewIndependentPassword12!', $state['accounts'][$owner['id']]['password']));
        $this->assertContains('historical_event', array_column($state['events'], 'event'));
        $before = file_get_contents($this->private.'/runtime/operators/authority.json');
        try {
            app(IndependentSupportOperators::class)->enroll('np_123', 'AnotherPassword12!');
            $this->fail('Enrollment must not silently replay.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Already enrolled', $error->getMessage());
        }
        $this->assertSame($before, file_get_contents($this->private.'/runtime/operators/authority.json'));
    }

    public function test_unknown_owner_cannot_replace_an_existing_authority(): void
    {
        $bridge = app(ChatBridge::class);
        $bridge->begin();
        support_ops_store(function (array &$state): void {
            $account = support_ops_account('existing_owner', 'Owner', 'ExistingPassword12!', 'administrator');
            $state['accounts'][$account['id']] = $account;
        }, true);
        $bridge->finish();
        $path = $this->private.'/runtime/operators/authority.json';
        $before = file_get_contents($path);
        try {
            app(IndependentSupportOperators::class)->enroll('unexpected_owner', 'NewIndependentPassword12!');
            $this->fail('Unexpected owner must not replace existing accounts.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('existing active support administrator', $error->getMessage());
        }
        $this->assertSame($before, file_get_contents($path));
    }
}
