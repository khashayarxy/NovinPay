<?php

namespace Tests\Feature;

use App\Services\CustomerLogin;
use App\Services\SmsIr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SmsIrIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['customer.sms_enabled' => true, 'customer.sms_api_key' => 'synthetic-key', 'customer.otp_template' => 710, 'customer.otp_parameter' => 'Code', 'customer.approved_template_ids' => [710]]);
    }

    public function test_exact_parameter_case_and_documented_contract_are_preserved(): void
    {
        Http::fake(['api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageId' => 123]])]);
        app(CustomerLogin::class)->issue('09000000000');
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://api.sms.ir/v1/send/verify'
            && $request->hasHeader('x-api-key', 'synthetic-key')
            && $request['templateId'] === 710 && $request['parameters'][0]['name'] === 'Code'
            && preg_match('/\A[0-9]{6}\z/', $request['parameters'][0]['value']));
        Http::assertSentCount(1);
    }

    public function test_unapproved_template_is_blocked_before_state_or_network_changes(): void
    {
        config(['customer.approved_template_ids' => []]);
        Http::fake();
        try {
            app(CustomerLogin::class)->issue('09000000000');
            $this->fail('Unreviewed template was sent');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertDatabaseCount('customer_login_challenges', 0);
            Http::assertNothingSent();
        }
    }

    public function test_status_command_does_not_send_or_access_provider_by_default(): void
    {
        Http::fake();
        $this->artisan('customers:sms-status')->assertExitCode(0);
        Http::assertNothingSent();
        $this->assertDatabaseCount('customer_login_challenges', 0);
    }

    public function test_disabled_purposes_cannot_send_even_with_an_approved_template(): void
    {
        config(['customer.sms_enabled' => false, 'customer.notifications_enabled' => false]);
        Http::fake();
        foreach (['otp', 'notification', 'unrecognized'] as $purpose) {
            $this->assertFalse(app(SmsIr::class)->send('09000000000', 710, 'Code', '123456', $purpose));
        }
        Http::assertNothingSent();
    }

    public function test_read_only_metadata_requires_exact_case_without_altering_approval(): void
    {
        config(['customer.approved_template_ids' => []]);
        Http::fake(['api.sms.ir/v1/templates/710' => Http::response(['status' => 1, 'data' => ['id' => 710, 'status' => 1, 'parameters' => [['name' => 'CODE']]]])]);
        $metadata = app(SmsIr::class)->inspect(710, 'Code');
        $this->assertTrue($metadata['accessible']);
        $this->assertFalse($metadata['exact_parameter_match']);
        $this->assertFalse($metadata['approval_attested']);
        $this->assertFalse(app(SmsIr::class)->ready(710, 'Code'));
        Http::assertSent(fn ($request) => $request->method() === 'GET');
        $this->assertDatabaseCount('customer_login_challenges', 0);
    }

    public function test_metadata_access_failure_is_reported_without_exposing_response(): void
    {
        Http::fake(['api.sms.ir/*' => Http::response(['status' => 12, 'message' => 'PRIVATE RESPONSE'], 401)]);
        $result = app(SmsIr::class)->inspect(710, 'Code');
        $this->assertFalse($result['accessible']);
        $this->assertSame(401, $result['http_status']);
        $this->assertSame(12, $result['provider_status']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($result));
    }

    public function test_resend_cooldown_and_replaced_challenge_are_enforced(): void
    {
        Http::fake(['api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageId' => 123]])]);
        $service = app(CustomerLogin::class);
        $old = $service->issue('09000000000');
        $oldCode = Http::recorded()[0][0]['parameters'][0]['value'];
        try {
            $service->issue('09000000000');
            $this->fail('Cooldown was bypassed');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->getStatusCode());
            Http::assertSentCount(1);
        }
        $this->travel(61)->seconds();
        $new = $service->issue('09000000000');
        $newCode = Http::recorded()[1][0]['parameters'][0]['value'];
        $this->assertNotSame($old, $new);
        try {
            $service->verify($old, $oldCode);
            $this->fail('Replaced challenge was accepted');
        } catch (ValidationException) {
            $this->assertDatabaseCount('customer_accounts', 0);
        }
        $this->assertSame('09000000000', $service->verify($new, $newCode)->phone);
    }

    public static function rejectedResponses(): array
    {
        return [
            'unauthorized' => [401, ['status' => 12]],
            'missing acknowledgement' => [200, ['status' => 1]],
            'invalid acknowledgement' => [200, ['status' => 1, 'data' => ['messageId' => 0]]],
            'unexpected type' => [200, ['status' => 1, 'data' => ['messageId' => '123']]],
            'provider rejected' => [200, ['status' => 109]],
        ];
    }

    #[DataProvider('rejectedResponses')]
    public function test_rejected_or_unknown_provider_response_cannot_leave_a_usable_otp(int $status, array $response): void
    {
        Http::fake(['api.sms.ir/*' => Http::response($response, $status)]);
        try {
            app(CustomerLogin::class)->issue('09000000000');
            $this->fail('Rejected send was accepted');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertNull(DB::table('customer_login_challenges')->value('code_hash'));
            $this->assertDatabaseCount('customer_accounts', 0);
            Http::assertSentCount(1);
        }
    }

    public static function invalidConfigurations(): array
    {
        return [['customer.otp_parameter', ''], ['customer.otp_parameter', "Code\r\n"], ['customer.otp_template', 2147483648], ['customer.sms_api_key', "secret\r\nHeader: injected"]];
    }

    #[DataProvider('invalidConfigurations')]
    public function test_invalid_contract_configuration_prevents_sending(string $key, mixed $value): void
    {
        config([$key => $value]);
        Http::fake();
        $this->assertFalse(app(SmsIr::class)->ready(config('customer.otp_template'), config('customer.otp_parameter')));
        Http::assertNothingSent();
    }
}
