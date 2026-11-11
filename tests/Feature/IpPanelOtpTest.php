<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CustomerLogin;
use App\Services\IpPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
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
        $this->designateSmsTestPhone('09120000000');
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
        $this->designateSmsTestPhone('09120000000');
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

    private function designateSmsTestPhone(string $phone): void
    {
        config(['customer.test_otp_enabled' => true, 'customer.test_otp_phones' => [$phone],
            'customer.test_sms_phone' => $phone, 'customer.test_reset_on_logout' => true]);
    }

    public function test_designated_sms_test_customer_can_reregister_after_password_login_and_logout(): void
    {
        $phone = '09120000000';
        $password = 'synthetic-test-password';
        $this->designateSmsTestPhone($phone);
        Http::fake([self::BASE.'/send' => Http::response(['meta' => ['status' => true], 'data' => ['message_outbox_ids' => [12345678]]])]);
        $login = app(CustomerLogin::class);
        $this->post('/account/start', ['phone' => $phone])->assertRedirect('/login')->assertSessionHasNoErrors();
        $this->post('/account/otp')->assertRedirect('/login')->assertSessionHasErrors('code');
        Http::assertSentCount(1);
        $code = Http::recorded()[0][0]['params']['code'];
        $this->assertMatchesRegularExpression('/\A[0-9]{6}\z/', $code);
        $row = DB::table('customer_login_challenges')->sole();
        $this->assertSame($login->testDigest($phone), $row->phone_digest);
        $this->assertFalse(Hash::check($code, substr($row->code_hash, strlen('v3:test-sms:'))));
        $this->assertNull($login->privateTestCode($phone));
        $owner = User::factory()->superAdmin()->create();
        $this->actingAs($owner, 'web')->withSession(['admin_session_version' => 1]);
        $this->get('/admin/test-customers')->assertOk()->assertDontSee('نمایش کد آزمایشی فعال');
        $this->post('/admin/test-customers/'.$login->testDigest($phone).'/code', ['current_password' => 'password'])
            ->assertRedirect('/admin/test-customers')->assertSessionHasErrors('challenge');
        $this->post('/account/verify', ['code' => $code])->assertRedirect('/account?section=security');
        $original = Auth::guard('customer')->user();
        $this->assertTrue($login->isTestAccount($original));
        $this->post('/account/profile', ['name' => 'Synthetic customer'])->assertRedirect();
        $this->post('/account/password', ['password' => $password, 'password_confirmation' => $password])->assertRedirect('/account');
        Auth::guard('customer')->logout();
        $this->app['session.store']->flush();
        $this->travel(1)->minutes();
        $this->post('/account/start', ['phone' => $phone])->assertRedirect('/login');
        $this->post('/account/password-login', ['password' => $password])->assertRedirect('/account');
        $original = $original->fresh();
        $this->assertAuthenticatedAs($original, 'customer');
        $this->post('/account/logout')->assertRedirect('/login')->assertSessionMissing('customer_session_version');
        $this->assertModelMissing($original);
        $this->assertNull($login->passwordAccount($phone, $password));
        $this->get('/account')->assertRedirect('/login');

        $this->post('/account/start', ['phone' => $phone])->assertRedirect('/login')->assertSessionHasNoErrors();
        Http::assertSentCount(3);
        $this->post('/account/verify', ['code' => Http::recorded()[2][0]['params']['code']])->assertRedirect('/account?section=security');
        $this->assertNotSame($original->id, Auth::guard('customer')->id());
        $this->assertNull(Auth::guard('customer')->user()->password);
    }

    public function test_private_codes_cannot_cross_the_sms_delivery_policy_change(): void
    {
        $phone = '09120000000';
        $this->designateSmsTestPhone($phone);
        config(['customer.test_sms_phone' => '']);
        Http::fake([self::BASE.'/send' => Http::response(['meta' => ['status' => true], 'data' => ['message_outbox_ids' => [12345678]]])]);
        $login = app(CustomerLogin::class);
        $this->post('/account/start', ['phone' => $phone])->assertRedirect('/login');
        $privateCode = $login->privateTestCode($phone);
        $this->assertNotNull($privateCode);
        config(['customer.test_sms_phone' => $phone]);
        $this->assertNull($login->privateTestCode($phone));
        $this->post('/account/verify', ['code' => $privateCode])->assertSessionHasErrors('code');
        $this->assertGuest('customer');
        Http::assertNothingSent();
        $this->travel(1)->minutes();
        $this->post('/account/otp')->assertRedirect('/login')->assertSessionHasNoErrors();
        Http::assertSentCount(1);
        config(['customer.test_sms_phone' => '']);
        $this->assertNull($login->privateTestCode($phone));
        config(['customer.test_sms_phone' => $phone]);
        $this->travel(2)->minutes();
        $this->post('/account/verify', ['code' => Http::recorded()[0][0]['params']['code']])->assertSessionHasErrors('code');
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
