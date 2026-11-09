<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Models\RepairRequest;
use App\Models\User;
use App\Services\CustomerAccountLink;
use App\Services\CustomerLogin;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerAccountTest extends TestCase
{
    use RefreshDatabase;

    private function otp(string $phone = '09000000000'): array
    {
        config(['customer.sms_enabled' => true, 'customer.sms_api_key' => 'test-placeholder', 'customer.otp_template' => 1, 'customer.otp_parameter' => 'CODE', 'customer.approved_template_ids' => [1]]);
        Http::preventStrayRequests();
        Http::fake(['api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageId' => 123]])]);
        $challenge = app(CustomerLogin::class)->issue($phone);
        $code = Http::recorded()[0][0]['parameters'][0]['value'];

        return [$challenge, $code];
    }

    private function loginCustomer(): CustomerAccount
    {
        [$challenge, $code] = $this->otp();
        $account = app(CustomerLogin::class)->verify($challenge, $code);
        $this->actingAs($account, 'customer')->withSession(['customer_session_version' => $account->session_version]);

        return $account;
    }

    public function test_disabled_sms_never_makes_external_requests(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->post('/account/otp', ['phone' => '09000000000'])->assertStatus(503);
        Http::assertNothingSent();
        $this->assertDatabaseCount('customer_login_challenges', 0);
    }

    public function test_allowlisted_otp_is_private_and_does_not_enter_the_existing_phone_account(): void
    {
        $phone = '09000000000';
        [$realChallenge, $realCode] = $this->otp($phone);
        $real = app(CustomerLogin::class)->verify($realChallenge, $realCode);
        config(['customer.sms_enabled' => false, 'customer.test_otp_enabled' => true, 'customer.test_otp_phones' => [$phone]]);
        Http::fake();
        $this->get('/account/login')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->post('/account/start', ['phone' => '09000000001'])->assertRedirect('/login')->assertSessionHasErrors('code');
        $this->assertNull(app(CustomerLogin::class)->privateTestCode('09000000001'));
        $this->post('/account/start', ['phone' => '۰۹۰۰۰۰۰۰۰۰۰'])->assertRedirect('/login');
        $code = app(CustomerLogin::class)->privateTestCode($phone);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertStringNotContainsString('09000000000', $this->get('/login')->assertOk()->getContent());
        $this->post('/account/verify', ['code' => $code])->assertRedirect('/account?section=security');
        $test = Auth::guard('customer')->user();
        $this->assertNotSame($real->id, $test->id);
        $this->assertNull($test->verified_at);
        $this->assertNull(app(CustomerLogin::class)->privateTestCode($phone));
        $this->post('/account/profile', ['name' => 'Synthetic tester'])->assertRedirect();
        $this->assertNull($test->fresh()->customer_id);
        $this->get('/account?section=security')->assertOk()->assertSee('فعلاً نمی‌خواهم');
        $this->post('/account/password-skip')->assertRedirect('/account');
        $this->assertNull($test->fresh()->password);
        $this->assertDatabaseCount('customers', 0);
        config(['customer.test_otp_enabled' => false]);
        $this->get('/account')->assertRedirect('/login');
        Http::assertNothingSent();
    }

    public function test_password_setup_login_change_and_recovery_use_customer_session_only(): void
    {
        [$challenge, $code] = $this->otp();
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin, 'web');
        $this->withSession(['customer_challenge' => $challenge, 'customer_intended' => '/cart'])
            ->post('/account/verify', ['code' => $code])->assertRedirect('/account?section=security');
        $original = 'synthetic-passphrase-1';
        $changed = 'synthetic-passphrase-2';
        $this->from('/account?section=security')->post('/account/password', ['password' => $original, 'password_confirmation' => 'different'])
            ->assertSessionHasErrors('password')->assertSessionMissing('_old_input.password');
        $this->post('/account/password', ['password' => $original, 'password_confirmation' => $original])->assertRedirect('/cart');
        $account = CustomerAccount::firstOrFail();
        $this->assertTrue(Hash::check($original, $account->password));
        $this->assertSame(2, $account->session_version);
        $this->post('/account/logout')->assertRedirect('/login');
        $this->assertAuthenticatedAs($admin, 'web');
        $this->assertGuest('customer');

        config(['customer.sms_enabled' => false]);
        $this->post('/account/start', ['phone' => $account->phone])->assertRedirect('/login')->assertSessionHasErrors('code');
        $this->post('/account/password-login', ['password' => $original])->assertRedirect('/account');
        $this->post('/account/password', ['password' => $changed, 'password_confirmation' => $changed, 'current_password' => 'wrong password'])
            ->assertSessionHasErrors('current_password');
        $this->post('/account/password', ['password' => $changed, 'password_confirmation' => $changed, 'current_password' => $original])
            ->assertRedirect('/account?section=security');
        $this->post('/account/logout')->assertRedirect('/login');
        $this->travel(1)->minutes();
        [$challenge, $code] = $this->otp();
        $this->withSession(['customer_challenge' => $challenge])->post('/account/verify', ['code' => $code, 'recover' => '1'])
            ->assertRedirect('/account?section=security');
        $this->post('/account/password', ['password' => $original, 'password_confirmation' => $original])->assertRedirect('/account?section=security');
        $this->assertTrue(Hash::check($original, $account->fresh()->password));
        $this->assertSame(4, $account->fresh()->session_version);
    }

    public function test_malformed_otp_input_is_rejected_without_provider_requests(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->from('/account/login')->post('/account/verify', ['code' => ['123456']])->assertRedirect('/account/login')->assertSessionHasErrors('code');
        Http::assertNothingSent();
        $this->assertDatabaseCount('customer_accounts', 0);
    }

    public function test_otp_is_single_use_and_encrypted_with_bounded_attempts(): void
    {
        [$challenge, $code] = $this->otp();
        $this->assertNotSame('09000000000', DB::table('customer_login_challenges')->value('phone'));
        $account = app(CustomerLogin::class)->verify($challenge, $code);
        $this->assertSame('09000000000', $account->phone);
        $this->assertNull($account->customer_id);
        try {
            app(CustomerLogin::class)->verify($challenge, $code);
            $this->fail('OTP replay accepted');
        } catch (ValidationException) {
            $this->assertDatabaseCount('customer_accounts', 1);
        }
    }

    public function test_wrong_codes_consume_attempts_and_correct_code_fails_after_limit(): void
    {
        [$challenge, $code] = $this->otp();
        $wrongCode = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            try {
                app(CustomerLogin::class)->verify($challenge, $wrongCode);
                $this->fail('Wrong OTP accepted');
            } catch (ValidationException) {
                $this->assertSame($i + 1, DB::table('customer_login_challenges')->value('attempts'));
            }
        }
        $this->expectException(ValidationException::class);
        app(CustomerLogin::class)->verify($challenge, $code);
    }

    public function test_expired_otp_and_session_revocation_are_enforced(): void
    {
        [$challenge, $code] = $this->otp();
        $this->travel(4)->minutes();
        try {
            app(CustomerLogin::class)->verify($challenge, $code);
            $this->fail('Expired OTP accepted');
        } catch (ValidationException) {
            $this->assertDatabaseCount('customer_accounts', 0);
        }
        $this->travelBack();
        $account = app(CustomerLogin::class)->verify($challenge, $code);
        $this->actingAs($account, 'customer')->withSession(['customer_session_version' => 1]);
        $this->get('/account')->assertOk();
        DB::table('customer_accounts')->where('id', $account->id)->update(['session_version' => 2]);
        Auth::guard('customer')->forgetUser();
        $this->get('/account')->assertRedirect('/login');
    }

    public function test_customer_can_register_owned_repair_but_cannot_supply_another_phone(): void
    {
        $account = $this->loginCustomer();
        $this->get('/repair')->assertOk();
        $token = session('repair_submission_token');
        $this->post('/repair', ['submission_token' => $token, 'customer_name' => 'Synthetic Customer', 'contact_phone' => '09999999999', 'device_brand' => 'PAX', 'device_model' => 'SYNTHETIC', 'issue' => 'Synthetic device problem'])->assertRedirect();
        $repair = RepairRequest::firstOrFail();
        $this->assertSame($account->id, $repair->customer_account_id);
        $this->assertSame('09000000000', $repair->contact_phone);
        $this->get('/account')->assertOk()->assertSee('SYNTHETIC');
        $path = '/account/repairs/requests/'.$repair->tracking_reference;
        $this->get($path)->assertOk()->assertSee('SYNTHETIC')->assertSee('data-stage="current"', false);
        [$challenge, $code] = $this->otp('09000000001');
        $other = app(CustomerLogin::class)->verify($challenge, $code);
        $this->actingAs($other, 'customer')->withSession(['customer_session_version' => 1]);
        $this->get($path)->assertNotFound();
    }

    public function test_addresses_are_private_and_customer_guard_cannot_enter_admin(): void
    {
        $this->loginCustomer();
        $this->post('/account/addresses', ['recipient' => 'SYNTHETIC', 'phone' => '09000000000', 'province' => 'تهران', 'city' => 'شهریار', 'address' => 'SYNTHETIC PRIVATE ADDRESS', 'postal_code' => '1234567890'])->assertRedirect();
        $this->assertNotSame('SYNTHETIC PRIVATE ADDRESS', DB::table('customer_addresses')->value('address'));
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/account')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_addresses_can_only_be_edited_and_removed_by_their_owner(): void
    {
        $account = $this->loginCustomer();
        $data = ['recipient' => 'SYNTHETIC', 'phone' => '09000000000', 'province' => 'تهران', 'city' => 'شهریار', 'address' => 'SYNTHETIC PRIVATE ADDRESS', 'postal_code' => '1234567890'];
        $this->post('/account/addresses', $data)->assertRedirect();
        $address = CustomerAddress::firstOrFail();
        $this->post('/account/addresses/'.$address->public_id, ['address' => 'SYNTHETIC UPDATED ADDRESS'] + $data)->assertRedirect();
        $this->assertSame('SYNTHETIC UPDATED ADDRESS', $address->fresh()->address);
        $other = CustomerAccount::query()->insertGetId(['public_id' => (string) Str::uuid(), 'phone_digest' => hash('sha256', 'another-account'), 'phone' => encrypt('09000000001'), 'verified_at' => now()]);
        $this->actingAs(CustomerAccount::findOrFail($other), 'customer')->withSession(['customer_session_version' => 1]);
        $this->post('/account/addresses/'.$address->public_id, $data)->assertNotFound();
        $this->post('/account/addresses/'.$address->public_id.'/remove')->assertNotFound();
        $this->assertSame('SYNTHETIC UPDATED ADDRESS', $address->fresh()->address);
        $this->actingAs($account->fresh(), 'customer')->withSession(['customer_session_version' => 1]);
        $this->post('/account/addresses/'.$address->public_id.'/remove')->assertRedirect();
        $this->assertDatabaseCount('customer_addresses', 0);
    }

    public function test_historical_crm_data_requires_explicit_identity_review_and_link_revokes_session(): void
    {
        $account = $this->loginCustomer();
        $customer = new Customer;
        foreach (['public_id' => (string) Str::uuid(), 'name' => 'OLD PRIVATE CUSTOMER', 'contact_phone' => '09000000000', 'phone_digest' => RepairIdentity::digest('customer-phone', '09000000000'), 'name_digest' => hash('sha256', 'name'), 'identity_digest' => hash('sha256', 'identity'), 'is_active' => true] as $key => $value) {
            $customer->$key = $value;
        }
        $customer->save();
        $this->post('/account/profile', ['name' => 'NEW VERIFIED NAME'])->assertRedirect();
        $this->assertNull($account->fresh()->customer_id);
        $this->assertDatabaseCount('customers', 1);
        $this->get('/account')->assertDontSee('OLD PRIVATE CUSTOMER');
        $owner = User::factory()->superAdmin()->create();
        app(CustomerAccountLink::class)->link($owner, $account->public_id, $customer->public_id, 'password');
        $this->assertSame($customer->id, $account->fresh()->customer_id);
        Auth::guard('customer')->forgetUser();
        $this->get('/account')->assertRedirect('/login');
    }
}
