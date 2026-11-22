<?php

namespace Tests\Feature;

use App\Mail\UnifiedNotificationMail;
use App\Models\CustomerAccount;
use App\Services\CustomerEmailLogin;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class ThreePathAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_shows_mobile_and_email_tabs_and_optional_google_button(): void
    {
        config(['customer.email_auth_enabled' => true, 'services.google.enabled' => false]);

        $this->get('/login')
            ->assertOk()
            ->assertSee('شماره موبایل')
            ->assertSee('ایمیل')
            ->assertDontSee('ورود با حساب گوگل');

        config([
            'services.google.enabled' => true,
            'services.google.client_id' => 'google-client-id',
            'services.google.client_secret' => 'google-client-secret',
        ]);

        $this->get('/login')
            ->assertOk()
            ->assertSee('ورود با حساب گوگل');

        $this->get('/login?channel=email')
            ->assertOk()
            ->assertSee('نشانی ایمیل')
            ->assertSee('ارسال کد تأیید');
    }

    public function test_email_otp_registration_and_login_flow(): void
    {
        Mail::fake();
        config(['customer.email_auth_enabled' => true]);

        $capturedCode = null;
        $this->post('/account/email/start', ['email' => 'User@Example.COM'])
            ->assertRedirect(route('account.login', ['channel' => 'email']));

        Mail::assertSent(UnifiedNotificationMail::class, function (UnifiedNotificationMail $mail) use (&$capturedCode): bool {
            $faCode = $mail->fields['کد تأیید'] ?? '';
            $capturedCode = strtr($faCode, [
                '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            ]);

            return $mail->hasTo('user@example.com') && preg_match('/\A[0-9]{6}\z/', $capturedCode) === 1;
        });

        $challenge = session('customer_email_challenge');
        $this->assertIsString($challenge);

        // Wrong code must fail.
        $wrongCode = $capturedCode === '000000' ? '111111' : '000000';
        $this->post('/account/email/verify', [
            'challenge' => $challenge,
            'code' => $wrongCode,
        ])->assertRedirect(route('account.login', ['channel' => 'email']))
            ->assertSessionHasErrors('code');

        // Correct code creates account and logs in.
        $this->post('/account/email/verify', [
            'challenge' => $challenge,
            'code' => $capturedCode,
        ])->assertRedirect(route('account.index'));

        $this->assertAuthenticated('customer');
        $account = CustomerAccount::query()->sole();
        $this->assertSame('user@example.com', $account->email);
        $this->assertNull($account->phone);
        $this->assertNotNull($account->email_verified_at);
    }

    public function test_email_password_login_succeeds_and_rejects_wrong_password(): void
    {
        $emailLogin = app(CustomerEmailLogin::class);
        $account = new CustomerAccount;
        $account->forceFill([
            'public_id' => (string) Str::uuid(),
            'email_digest' => $emailLogin->digest('member@example.test'),
            'email' => 'member@example.test',
            'password' => Hash::make('StrongPass#2026'),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
        ])->save();

        $this->post('/account/email/password-login', [
            'email' => 'member@example.test',
            'password' => 'WrongPass#0000',
        ])->assertRedirect(route('account.login', ['channel' => 'email', 'method' => 'password']))
            ->assertSessionHasErrors('password');
        $this->assertGuest('customer');

        $this->post('/account/email/password-login', [
            'email' => 'MEMBER@example.test',
            'password' => 'StrongPass#2026',
        ])->assertRedirect(route('account.index'));
        $this->assertAuthenticatedAs($account, 'customer');
    }

    public function test_google_oauth_falls_back_gracefully_when_unconfigured(): void
    {
        config(['services.google.enabled' => false]);

        $this->get('/account/google/redirect')
            ->assertRedirect(route('account.login'))
            ->assertSessionHasErrors('google');
    }

    public function test_google_oauth_creates_account_reuses_identity_and_links_existing_mobile_account(): void
    {
        config([
            'services.google.enabled' => true,
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'https://novinpaypos.test/account/google/callback',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-access-token'], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-sub-12345',
                'email' => 'ali@gmail.com',
                'email_verified' => true,
                'name' => 'علی رضایی',
            ], 200),
        ]);

        // 1. Redirect sets cryptographic state.
        $redirect = $this->get('/account/google/redirect');
        $redirect->assertRedirect();
        $location = (string) $redirect->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        $state = session('customer_google_state');
        $this->assertIsString($state);

        // 2. Tampered state is rejected.
        $this->withSession(['customer_google_state' => $state])
            ->get('/account/google/callback?state=invalid-state&code=abc')
            ->assertRedirect(route('account.login'))
            ->assertSessionHasErrors('google');

        // 3. Valid callback creates and authenticates new account.
        $this->withSession(['customer_google_state' => $state, 'customer_google_linking' => false])
            ->get('/account/google/callback?state='.$state.'&code=valid-code')
            ->assertRedirect(route('account.index'));

        $this->assertAuthenticated('customer');
        $this->assertDatabaseCount('customer_accounts', 1);
        $account = CustomerAccount::query()->sole();
        $this->assertSame('ali@gmail.com', $account->email);
        $this->assertSame('علی رضایی', $account->name);

        // 4. Second login with same Google sub reuses exact same account.
        auth('customer')->logout();
        $this->withSession(['customer_google_state' => $state, 'customer_google_linking' => false])
            ->get('/account/google/callback?state='.$state.'&code=valid-code')
            ->assertRedirect(route('account.index'));
        $this->assertDatabaseCount('customer_accounts', 1);
    }

    public function test_google_oauth_links_to_authenticated_mobile_account(): void
    {
        config([
            'services.google.enabled' => true,
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'https://novinpaypos.test/account/google/callback',
        ]);

        $mobileAccount = new CustomerAccount;
        $mobileAccount->forceFill([
            'public_id' => (string) Str::uuid(),
            'phone' => '09120001122',
            'phone_digest' => RepairIdentity::digest('customer-account-phone', '09120001122'),
            'verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
        ])->save();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-access-token-2'], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-sub-99999',
                'email' => 'mobile-owner@gmail.com',
                'email_verified' => true,
                'name' => 'صاحب موبایل',
            ], 200),
        ]);

        $this->actingAs($mobileAccount, 'customer')
            ->withSession([
                'customer_session_version' => 1,
                'customer_google_state' => 'link-state-123',
                'customer_google_linking' => true,
            ])
            ->get('/account/google/callback?state=link-state-123&code=link-code')
            ->assertRedirect(route('account.index', ['section' => 'security']))
            ->assertSessionHas('success');

        $mobileAccount->refresh();
        $this->assertSame('09120001122', $mobileAccount->phone);
        $this->assertSame('mobile-owner@gmail.com', $mobileAccount->email);
        $this->assertNotNull($mobileAccount->google_id_digest);
    }
}
