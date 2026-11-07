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
        $this->get('/account')->assertRedirect('/account/login');
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
        $this->get('/account')->assertRedirect('/account/login');
    }
}
