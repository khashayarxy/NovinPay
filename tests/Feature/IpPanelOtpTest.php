<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CustomerLogin;
use App\Services\IpPanel;
use App\Support\RepairIdentity;
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

    public static function mobileDigitInputs(): array
    {
        return [
            'English' => ['09120000000'],
            'Persian' => ['۰۹۱۲۰۰۰۰۰۰۰'],
            'Arabic' => ['٠٩١٢٠٠٠٠٠٠٠'],
        ];
    }

    #[DataProvider('mobileDigitInputs')]
    public function test_mobile_digits_are_normalized_before_otp_delivery_and_storage(string $input): void
    {
        Http::fake([self::BASE.'/send' => Http::response(['meta' => ['status' => true], 'data' => ['message_outbox_ids' => [12345678]]])]);
        $this->post('/account/start', ['phone' => $input])->assertRedirect('/login')
            ->assertSessionHas('customer_login_phone', '09120000000');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['recipients'] === ['+989120000000']);
        $this->assertDatabaseHas('customer_login_challenges', [
            'phone_digest' => RepairIdentity::digest('customer-account-phone', '09120000000'),
        ]);
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

    public function test_normal_customer_can_complete_fifteen_login_cycles_with_daily_quota_and_cooldown(): void
    {
        $this->freezeTime();
        $phone = '09120000000';
        Http::fake([self::BASE.'/send' => Http::response(['meta' => ['status' => true], 'data' => ['message_outbox_ids' => [12345678]]])]);
        for ($index = 0; $index < 15; $index++) {
            $this->post('/account/start', ['phone' => $phone])->assertRedirect('/login')->assertSessionHasNoErrors();
            $this->postJson('/account/otp')->assertTooManyRequests()->assertHeader('Retry-After', '60')
                ->assertJsonPath('reason', 'resend_cooldown');
            $this->post('/account/verify', ['code' => Http::recorded()[$index][0]['params']['code']])->assertRedirect('/account?section=security');
            $this->post('/account/logout')->assertRedirect('/login');
            $this->travel(61)->seconds();
        }
        $this->assertDatabaseCount('customer_accounts', 1);
        $this->postJson('/account/start', ['phone' => $phone])->assertTooManyRequests()->assertJsonPath('reason', 'daily_sms_limit');
        Http::assertSentCount(15);
        $this->travel(24)->hours();
        $this->post('/account/start', ['phone' => $phone])->assertRedirect('/login')->assertSessionHasNoErrors();
        Http::assertSentCount(16);
    }

    public function test_shared_ip_throttle_preserves_current_challenge_and_formats_web_and_json_responses(): void
    {
        $this->freezeTime();
        Http::fake([self::BASE.'/send' => Http::response(['meta' => ['status' => true], 'data' => ['message_outbox_ids' => [12345678]]])]);
        for ($index = 0; $index < 20; $index++) {
            $this->post('/account/start', ['phone' => sprintf('09120%06d', $index)])->assertRedirect('/login')->assertSessionHasNoErrors();
        }
        $challenge = session('customer_challenge');
        $this->post('/account/otp')->assertRedirect('/login')->assertHeader('Retry-After', '60')
            ->assertSessionHas('customer_challenge', $challenge);
        $page = $this->get('/login')->assertOk()->assertSee('درخواست‌های زیادی ثبت شده است.')->assertSee('data-account-wait="60"', false);
        if ($directory = getenv('NOVINPAY_QA_DIR')) {
            $this->assertTrue(is_dir($directory) && ! is_link($directory));
            file_put_contents($directory.DIRECTORY_SEPARATOR.'otp-throttle.html', $page->getContent());
        }
        $this->postJson('/account/otp')->assertTooManyRequests()->assertHeader('Retry-After', '60')->assertJsonPath('retry_after', 60);
        Http::assertSentCount(20);
    }

    public function test_phone_cooldown_blocks_an_overlapping_send_and_requests_from_another_ip(): void
    {
        $this->freezeTime();
        $phone = '09120000000';
        $sends = 0;
        Http::fake([self::BASE.'/send' => function () use ($phone, &$sends) {
            $sends++;
            try {
                app(CustomerLogin::class)->issue($phone);
                $this->fail('The overlapping request bypassed the phone reservation.');
            } catch (HttpException $e) {
                $this->assertSame(429, $e->getStatusCode());
            }

            return Http::response(['meta' => ['status' => true], 'data' => ['message_outbox_ids' => [12345678]]]);
        }]);
        $this->post('/account/start', ['phone' => $phone])->assertRedirect('/login')->assertSessionHasNoErrors();
        $first = DB::table('customer_login_challenges')->sole()->challenge_id;
        $this->post('/account/restart')->assertRedirect('/login');
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.16'])->postJson('/account/start', ['phone' => $phone])
            ->assertTooManyRequests()->assertJsonPath('reason', 'resend_cooldown')->assertSessionMissing('customer_challenge');
        $this->assertSame($first, DB::table('customer_login_challenges')->sole()->challenge_id);
        $this->assertSame(1, $sends);
    }

    public function test_definitive_rejections_release_daily_quota_but_ambiguous_results_do_not(): void
    {
        $this->freezeTime();
        $phone = '09120000000';
        $reply = Http::response(['meta' => ['status' => false]], 429);
        Http::fake([self::BASE.'/send' => function () use (&$reply) {
            return $reply;
        }]);
        for ($index = 0; $index < 16; $index++) {
            $this->postJson('/account/start', ['phone' => $phone])->assertStatus(503)->assertHeader('Retry-After', '60');
            $this->travel(61)->seconds();
        }
        $reply = Http::response([], 502);
        for ($index = 0; $index < 15; $index++) {
            $this->postJson('/account/start', ['phone' => $phone])->assertStatus(503);
            $this->travel(61)->seconds();
        }
        $this->postJson('/account/start', ['phone' => $phone])->assertTooManyRequests()->assertJsonPath('reason', 'daily_sms_limit');
        Http::assertSentCount(31);
        $this->assertNull(DB::table('customer_login_challenges')->value('code_hash'));
    }

    public function test_password_throttle_keeps_the_existing_phone_and_otp_challenge(): void
    {
        $this->freezeTime();
        Http::fake([self::BASE.'/send' => Http::response(['meta' => ['status' => true], 'data' => ['message_outbox_ids' => [12345678]]])]);
        $this->post('/account/start', ['phone' => '09120000000'])->assertRedirect('/login');
        $challenge = session('customer_challenge');
        for ($index = 0; $index < 5; $index++) {
            $this->post('/account/password-login', ['password' => 'synthetic-wrong'])->assertRedirect('/login?method=password');
        }
        $this->post('/account/password-login', ['password' => 'synthetic-wrong'])->assertRedirect('/login?method=password')
            ->assertHeader('Retry-After', '60')->assertSessionHas('customer_challenge', $challenge);
        $this->get('/login?method=password')->assertOk()->assertSee('data-account-wait="60"', false);
        $this->post('/account/verify', ['code' => Http::recorded()[0][0]['params']['code']])->assertRedirect('/account?section=security');
        Http::assertSentCount(1);
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
