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

    public function test_login_page_shows_unified_identifier_input_password_link_and_google_button(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('شماره موبایل یا ایمیل')
            ->assertSee('ارسال کد تأیید')
            ->assertSee('ورود با رمز عبور')
            ->assertSee('ورود با حساب گوگل');

        $this->get('/login?method=password')
            ->assertOk()
            ->assertSee('شماره موبایل یا ایمیل')
            ->assertSee('فراموشی رمز عبور')
            ->assertSee('ورود به پنل کاربری')
            ->assertSee('ورود با رمز یکبار مصرف');

        $this->get('/login?method=forgot')
            ->assertOk()
            ->assertSee('فراموشی رمز عبور')
            ->assertSee('شماره موبایل یا ایمیل')
            ->assertSee('ارسال کد بازیابی')
            ->assertSee('بازگشت به صفحه ورود | ثبت‌نام');
    }

    public function test_unified_start_endpoint_auto_detects_email_and_sends_email_otp(): void
    {
        Mail::fake();
        config(['customer.email_auth_enabled' => true]);

        $this->post('/account/start', ['identifier' => 'unified@example.com'])
            ->assertRedirect(route('account.login', ['channel' => 'email']));

        Mail::assertSent(UnifiedNotificationMail::class, fn (UnifiedNotificationMail $mail) => $mail->hasTo('unified@example.com'));
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

    public function test_google_authenticated_customer_without_phone_can_view_update_profile_and_set_initial_password(): void
    {
        $googleAccount = new CustomerAccount;
        $googleAccount->forceFill([
            'public_id' => (string) Str::uuid(),
            'name' => 'کاربر گوگل',
            'phone' => null,
            'phone_digest' => null,
            'email' => 'google.profile@example.com',
            'email_digest' => RepairIdentity::digest('customer-account-email', 'google.profile@example.com'),
            'email_verified_at' => now(),
            'google_id_digest' => RepairIdentity::digest('customer-account-google', 'google-sub-profile-500'),
            'verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
            'password' => null,
        ])->save();

        foreach (['overview', 'repairs', 'orders', 'payments', 'delivery', 'profile', 'addresses', 'security'] as $section) {
            $this->actingAs($googleAccount, 'customer')
                ->withSession(['customer_session_version' => 1])
                ->get(route('account.index', ['section' => $section]))
                ->assertOk();
        }

        $this->actingAs($googleAccount, 'customer')
            ->withSession(['customer_session_version' => 1])
            ->get(route('account.index', ['section' => 'profile']))
            ->assertOk()
            ->assertSee('کاربر گوگل')
            ->assertSee('google.profile@example.com');

        $this->actingAs($googleAccount, 'customer')
            ->withSession(['customer_session_version' => 1])
            ->post(route('account.profile'), ['name' => 'نام جدید گوگل'])
            ->assertRedirect(route('account.index', ['section' => 'profile']))
            ->assertSessionHas('success');

        $googleAccount->refresh();
        $this->assertSame('نام جدید گوگل', $googleAccount->name);

        $this->actingAs($googleAccount, 'customer')
            ->withSession(['customer_session_version' => 1])
            ->get(route('account.index', ['section' => 'security']))
            ->assertOk()
            ->assertSee('انتخاب رمز عبور')
            ->assertDontSee('برای انتخاب رمز، از حساب خارج شوید')
            ->assertDontSee('name="current_password"', false);

        $newPassword = 'GoogleInitialPass#2026';

        $this->actingAs($googleAccount, 'customer')
            ->withSession(['customer_session_version' => 1])
            ->post(route('account.password.update'), [
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ])
            ->assertRedirect(route('account.index', ['section' => 'security']))
            ->assertSessionHas('success');

        $googleAccount->refresh();
        $this->assertNotNull($googleAccount->password);
        $this->assertSame(2, $googleAccount->session_version);

        // Once a password exists, changing it without current_password must be rejected.
        $this->actingAs($googleAccount, 'customer')
            ->withSession(['customer_session_version' => $googleAccount->session_version])
            ->post(route('account.password.update'), [
                'password' => 'AnotherPassword#2026',
                'password_confirmation' => 'AnotherPassword#2026',
            ])
            ->assertSessionHasErrors('current_password');

        // Logout and verify password login works with the Google account's email.
        $this->post(route('account.logout'))->assertRedirect(route('account.login'));

        $this->post(route('account.password.login'), [
            'identifier' => 'google.profile@example.com',
            'password' => $newPassword,
        ])->assertRedirect(route('account.index'));

        $this->assertAuthenticatedAs($googleAccount, 'customer');
    }

    public function test_address_validation_preserves_old_input_and_renders_persian_inline_errors_with_iran_locations(): void
    {
        $account = new CustomerAccount;
        $account->forceFill([
            'public_id' => (string) Str::uuid(),
            'name' => 'مشتری آدرس',
            'phone' => '09123334455',
            'phone_digest' => RepairIdentity::digest('customer-account-phone', '09123334455'),
            'verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
        ])->save();

        $this->actingAs($account, 'customer')
            ->withSession(['customer_session_version' => 1])
            ->post(route('account.address'), [
                'address_form_key' => 'new',
                'recipient' => 'رضا محمدی',
                'phone' => '09123334455',
                'province' => 'تهران',
                'city' => 'شهریار',
                'address' => 'کوتاه',
                'postal_code' => '1234567890',
            ])
            ->assertRedirect(route('account.index', ['section' => 'addresses']))
            ->assertSessionHasErrors('address')
            ->assertSessionHasInput('recipient', 'رضا محمدی')
            ->assertSessionHasInput('province', 'تهران')
            ->assertSessionHasInput('city', 'شهریار');

        $response = $this->actingAs($account, 'customer')
            ->withSession(['customer_session_version' => 1])
            ->get(route('account.index', ['section' => 'addresses']));

        $response->assertOk()
            ->assertSee('رضا محمدی')
            ->assertSee('تهران')
            ->assertSee('شهریار')
            ->assertSee('آدرس کامل')
            ->assertDontSee('address نباید کمتر از')
            ->assertSee('id="iran-locations-data"', false);
    }
}
