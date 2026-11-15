<?php

namespace Tests\Feature;

use App\Services\CustomerLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerLoginReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['customer.sms_provider' => 'ippanel', 'customer.test_otp_enabled' => false,
            'customer.ippanel' => ['enabled' => true, 'api_key' => 'synthetic-key', 'pattern_code' => 'syntheticpattern',
                'parameter' => 'code', 'sender' => '+983000505']]);
        Http::preventStrayRequests();
        $this->freezeTime();
    }

    public function test_ambiguous_delivery_keeps_the_bound_code_and_reconciles_without_another_send(): void
    {
        $this->get('/login');
        $flow = session('customer_login_flow');
        $attempt = (string) Str::uuid();
        $code = null;
        Http::fake(['https://edge.ippanel.com/v1/api/send' => function ($request) use (&$code, $attempt) {
            $code = $request['params']['code'];
            $this->assertSame($attempt, session('customer_send_attempt'));
            $this->assertSame(DB::table('customer_login_challenges')->value('challenge_id'), session('customer_challenge'));

            return Http::response([], 502);
        }]);
        $this->postJson('/account/start', ['phone' => '09120000000', 'flow' => $flow, 'request_id' => $attempt])
            ->assertStatus(503)->assertJsonPath('state', 'challenge');
        $challenge = session('customer_challenge');
        $this->assertTrue(app(CustomerLogin::class)->activeChallenge('09120000000', $challenge));
        $state = $this->getJson('/account/login-state?'.http_build_query(['flow' => $flow, 'request_id' => $attempt]))
            ->assertOk()->assertJsonPath('state', 'challenge')->assertJsonPath('challenge', $challenge)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertArrayNotHasKey('code', $state->json());
        $this->assertArrayNotHasKey('phone', $state->json());
        $this->travel(61)->seconds();
        $this->postJson('/account/otp', ['flow' => session('customer_login_flow'), 'request_id' => $attempt])->assertOk();
        Http::assertSentCount(1);
        $this->postJson('/account/verify', ['flow' => session('customer_login_flow'), 'challenge' => $challenge, 'code' => $code])->assertOk();
        $this->assertAuthenticated('customer');
        $this->getJson('/account/login-state?flow='.$flow)->assertOk()->assertJsonPath('state', 'authenticated')
            ->assertJsonPath('redirect', route('account.index', ['section' => 'security']));
    }

    public function test_delayed_delivery_does_not_extend_expiry_or_replace_the_valid_challenge(): void
    {
        Http::fake(['https://edge.ippanel.com/v1/api/send' => function () {
            $this->travel(80)->seconds();

            return Http::response(['meta' => ['status' => true], 'data' => ['message_outbox_ids' => [123]]]);
        }]);
        $this->post('/account/start', ['phone' => '09120000000'])->assertRedirect();
        $challenge = session('customer_challenge');
        $state = app(CustomerLogin::class)->challengeState('09120000000', $challenge);
        $this->assertSame(40, $state['expiresAt'] - now()->timestamp);
        $this->post('/account/start', ['phone' => '09120000000'])->assertRedirect();
        $this->assertSame($challenge, session('customer_challenge'));
        Http::assertSentCount(1);
        $this->postJson('/account/verify', ['challenge' => $challenge, 'code' => Http::recorded()[0][0]['params']['code']])->assertOk();
    }

    public function test_stale_tab_cannot_consume_attempts_on_a_replacement_challenge(): void
    {
        Http::fake(['https://edge.ippanel.com/v1/api/send' => Http::response(['meta' => ['status' => true], 'data' => ['message_outbox_ids' => [123]]])]);
        $this->post('/account/start', ['phone' => '09120000000']);
        $old = session('customer_challenge');
        $this->travel(61)->seconds();
        $this->post('/account/otp');
        $current = session('customer_challenge');
        $this->assertNotSame($old, $current);
        $this->postJson('/account/verify', ['challenge' => $old, 'code' => Http::recorded()[0][0]['params']['code']])
            ->assertStatus(409)->assertJsonPath('reason', 'challenge_changed');
        $this->assertSame(0, (int) DB::table('customer_login_challenges')->value('attempts'));
    }

    public function test_expired_request_replay_and_foreign_state_queries_cannot_issue_or_authenticate(): void
    {
        Http::fake(['https://edge.ippanel.com/v1/api/send' => Http::response(['meta' => ['status' => true], 'data' => ['message_outbox_ids' => [123]]])]);
        $attempt = (string) Str::uuid();
        $this->post('/account/start', ['phone' => '09120000000', 'request_id' => $attempt]);
        $flow = session('customer_login_flow');
        $this->getJson('/account/login-state?flow=foreign')->assertStatus(409)->assertJsonMissingPath('challenge');
        $this->travel(121)->seconds();
        $this->getJson('/account/login-state?flow='.$flow)->assertOk()->assertJsonPath('state', 'expired');
        $this->postJson('/account/otp', ['flow' => $flow, 'request_id' => $attempt])->assertOk();
        Http::assertSentCount(1);
        $this->assertGuest('customer');
        $this->post('/account/otp', ['flow' => $flow, 'request_id' => (string) Str::uuid()]);
        Http::assertSentCount(2);
    }

    public function test_definitive_rejection_ends_the_reserved_request_without_replaying_it(): void
    {
        Http::fake(['https://edge.ippanel.com/v1/api/send' => Http::response(['meta' => ['status' => false]], 400)]);
        $attempt = (string) Str::uuid();
        $this->postJson('/account/start', ['phone' => '09120000000', 'request_id' => $attempt])->assertStatus(503);
        $this->getJson('/account/login-state?request_id='.$attempt)->assertOk()->assertJsonPath('state', 'expired');
        $this->travel(61)->seconds();
        $this->postJson('/account/otp', ['request_id' => $attempt])->assertOk();
        Http::assertSentCount(1);
        $this->assertGuest('customer');
    }

    public function test_inline_send_validation_returns_json_without_issuing_or_replacing_a_challenge(): void
    {
        Http::fake();
        $this->postJson('/account/start', ['phone' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson('/account/start', ['phone' => '09120000000', 'request_id' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('request_id');
        Http::assertNothingSent();
        $this->assertDatabaseCount('customer_login_challenges', 0);
    }
}
