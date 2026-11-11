<?php

namespace Tests\Feature;

use App\Services\CustomerLogin;
use App\Services\IpPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IpPanelOtpTest extends TestCase
{
    private const BASE = 'https://edge.ippanel.com/v1/api';

    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'customer.sms_provider' => 'ippanel',
            'customer.ippanel' => [
                'enabled' => true, 'api_key' => 'synthetic-key', 'pattern_code' => 'syntheticpattern',
                'parameter' => 'code', 'sender' => '+983000505',
            ],
            'customer.test_otp_enabled' => false,
        ]);
        Http::preventStrayRequests();
    }

    public function test_sends_one_pattern_request_without_stripping_leading_zeroes(): void
    {
        Http::fake([self::BASE.'/send' => Http::response(['meta' => ['status' => true], 'data' => ['message_outbox_ids' => [12345678]]])]);
        $this->assertTrue(app(IpPanel::class)->send('09120000000', '004321'));
        Http::assertSent(fn ($request) => $request->url() === self::BASE.'/send'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'synthetic-key')
            && $request->data() === [
                'sending_type' => 'pattern', 'from_number' => '+983000505', 'code' => 'syntheticpattern',
                'recipients' => ['+989120000000'], 'params' => ['code' => '004321'],
            ]);
        Http::assertSentCount(1);
    }

    public function test_selected_provider_supports_normal_registration_and_two_minute_expiry(): void
    {
        Http::fake([self::BASE.'/send' => Http::response(['meta' => ['status' => true], 'data' => ['message_outbox_ids' => ['12345678']]])]);
        $this->post('/account/start', ['phone' => '۰۹۱۲۰۰۰۰۰۰۰'])->assertRedirect('/login')->assertSessionHasNoErrors();
        $code = Http::recorded()[0][0]['params']['code'];
        $this->assertMatchesRegularExpression('/\A[0-9]{6}\z/', $code);
        $row = DB::table('customer_login_challenges')->sole();
        $this->assertStringStartsWith('v2:', $row->code_hash);
        $this->assertFalse(Hash::check($code, substr($row->code_hash, 3)));
        $this->post('/account/verify', ['code' => $code])->assertRedirect('/account?section=security');
        $this->assertAuthenticated('customer');
        $this->post('/account/logout')->assertRedirect('/login');
        $this->assertDatabaseCount('customer_accounts', 1);
        $this->travel(1)->minutes();
        $challenge = app(CustomerLogin::class)->issue('09120000000');
        $next = Http::recorded()[1][0]['params']['code'];
        $this->travel(2)->minutes();
        $this->expectException(ValidationException::class);
        app(CustomerLogin::class)->verify($challenge, $next);
    }

    public function test_rejection_revokes_challenge_and_cooldown_prevents_duplicate_send(): void
    {
        Log::spy();
        Http::fake([self::BASE.'/send' => Http::response(['meta' => ['status' => false, 'message' => 'Insufficient credit']])]);
        $this->post('/account/start', ['phone' => '09120000000'])->assertRedirect('/login')->assertSessionHasErrors('code');
        $this->assertNull(DB::table('customer_login_challenges')->value('code_hash'));
        $this->post('/account/otp')->assertRedirect('/login')->assertSessionHasErrors('code');
        Http::assertSentCount(1);
        Log::shouldHaveReceived('log')->with('warning', 'Customer OTP provider outcome', \Mockery::on(
            fn ($context) => $context['category'] === 'insufficient_credit' && ! isset($context['recipient']) && ! isset($context['code']),
        ))->once();
    }

    public function test_timeout_does_not_retry_or_fall_back_to_smsir(): void
    {
        config(['customer.sms_enabled' => true, 'customer.approved_template_ids' => [123]]);
        $attempts = 0;
        Http::fake([self::BASE.'/send' => function () use (&$attempts) {
            $attempts++;

            return Http::failedConnection();
        }]);
        try {
            app(CustomerLogin::class)->issue('09120000000');
            $this->fail('The timed-out send was treated as accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
        $this->assertSame(1, $attempts);
        $this->assertNull(DB::table('customer_login_challenges')->value('code_hash'));
    }

    public function test_disabled_or_unknown_provider_never_sends(): void
    {
        Http::fake();
        foreach (['ippanel', 'unsupported-value'] as $provider) {
            config(['customer.sms_provider' => $provider, 'customer.ippanel.enabled' => false]);
            try {
                app(CustomerLogin::class)->issue('09120000000');
                $this->fail('An unavailable provider sent an OTP.');
            } catch (HttpException $exception) {
                $this->assertSame(503, $exception->getStatusCode());
            }
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('customer_login_challenges', 0);
    }

    public function test_read_only_pattern_status_checks_metadata_and_literal_webotp_line(): void
    {
        config(['app.url' => 'https://novinpaypos.com', 'customer.sms_provider' => 'smsir', 'customer.ippanel.enabled' => false]);
        Http::fake([self::BASE.'/patterns/syntheticpattern' => Http::response([
            'meta' => ['status' => true],
            'data' => [
                'pattern_code' => 'syntheticpattern', 'pattern_status' => 'active', 'delimiter' => '%',
                'variable' => [['name' => 'code']], 'pattern_message' => "Message %code%\r\n\r\n@novinpaypos.com #%code%",
            ],
        ])]);
        $this->artisan('customers:sms-status', ['--ippanel' => true])->assertExitCode(0);
        $this->assertTrue(app(IpPanel::class)->inspect()['webotp_template_match']);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_delivery_report_redacts_recipient_and_content(): void
    {
        Http::fake([
            self::BASE.'/report/by_bulk*' => Http::response(['meta' => ['status' => true], 'data' => [
                'messages_outbox_id' => '12345678', 'rcpts_count' => '1', 'state_id' => 6,
            ]]),
            self::BASE.'/report/recipients*' => Http::response(['meta' => ['status' => true], 'data' => [[
                'recipient' => '+989120000000', 'message' => 'synthetic secret', 'message_status' => '2',
            ]]]),
        ]);
        $this->assertSame(['accessible' => true, 'status' => 'provider_reported_delivered'], app(IpPanel::class)->delivery('12345678'));
    }

    #[DataProvider('providerFailures')]
    public function test_non_acceptance_is_safe_and_never_retried(int $http, array $response, string $category): void
    {
        Log::spy();
        Http::fake([self::BASE.'/send' => Http::response($response, $http)]);
        $this->assertFalse(app(IpPanel::class)->send('09120000000', '001234'));
        Http::assertSentCount(1);
        Log::shouldHaveReceived('log')->with('warning', 'Customer OTP provider outcome', \Mockery::on(
            fn ($context) => $context['category'] === $category && $context['http_status'] === $http,
        ))->once();
    }

    public static function providerFailures(): array
    {
        return [
            [401, [], 'authentication_or_permission'],
            [422, [], 'request_rejected'],
            [429, [], 'provider_rate_limited'],
            [503, [], 'provider_unavailable'],
            [200, ['meta' => ['status' => true], 'data' => ['message_outbox_ids' => []]], 'unexpected_response'],
        ];
    }
}
