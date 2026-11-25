<?php

namespace Tests\Feature;

use App\Enums\RepairBrand;
use App\Enums\RepairRequestStatus;
use App\Enums\RepairStage;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Models\FinancialDocument;
use App\Models\PaymentAttempt;
use App\Models\RepairCase;
use App\Models\RepairRequest;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\CustomerLogin;
use App\Services\ZibalPayments;
use App\Support\FaNumber;
use App\Support\PaymentDate;
use App\Support\RepairIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountRecoveryPaymentUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        URL::forceRootUrl('https://novinpaypos.test');
        URL::forceScheme('https');
    }

    public function test_recovery_otp_box_and_recovered_list_show_device_brand_model_and_jalali_date(): void
    {
        $phone = '09123334455';
        $digest = RepairIdentity::digest('phone', $phone);

        $repair = new RepairRequest;
        $repair->forceFill([
            'tracking_reference' => '92991001',
            'customer_name' => 'مشتری تست',
            'contact_phone' => $phone,
            'phone_digest' => $digest,
            'device_brand' => RepairBrand::Pax,
            'device_model' => 'Z9',
            'issue' => 'مشکل چاپگر دستگاه',
            'status' => RepairRequestStatus::OnlineSubmitted,
            'submission_digest' => hash('sha256', 'recovery-ux-1'),
        ])->save();

        $login = $this->partialMock(CustomerLogin::class);
        $login->shouldReceive('issue')->once()->with($phone, \Mockery::type('callable'))
            ->andReturnUsing(function (string $p, callable $reserve): string {
                $reserve('recovery-challenge-1');

                return 'recovery-challenge-1';
            });

        $this->post(route('repair.track.recovery.send'), ['contact_phone' => $phone])
            ->assertRedirect(route('repair.track.recovery'));

        $this->get(route('repair.track.recovery'))
            ->assertOk()
            ->assertSee('repair-recovery-phone-bar', false)
            ->assertSee('اصلاح شماره موبایل')
            ->assertSee('repair-recovery-code-input', false);

        $this->withSession([
            'repair_recovery_phone' => [
                'digest' => $digest,
                'expires' => now()->addMinutes(10)->timestamp,
            ],
        ])
            ->get(route('repair.track.recovery'))
            ->assertOk()
            ->assertSee(FaNumber::digits('92991001'))
            ->assertSee('PAX Z9')
            ->assertSee('np-latin-code', false)
            ->assertSee('بازیابی با شماره دیگر')
            ->assertSee(PaymentDate::formatPaidAt(null, $repair->created_at) ? explode(' | ', PaymentDate::formatPaidAt(null, $repair->created_at))[0] : '');

        // Revisiting /repair/track/recovery fresh clears the one-time recovery result.
        $this->get(route('repair.track.recovery'))
            ->assertOk()
            ->assertDontSee(FaNumber::digits('92991001'))
            ->assertSee('دریافت کد تأیید');
    }

    public function test_account_overview_counts_active_requests_standardizes_numerals_and_polishes_tabs(): void
    {
        $owner = User::factory()->superAdmin()->create();
        $account = $this->createAccount('09124445566');
        $customer = $this->createCustomer('09124445566');
        $account->forceFill(['customer_id' => $customer->id])->save();

        $phoneDigest = RepairIdentity::digest('phone', '09124445566');
        $openReq1 = new RepairRequest;
        $openReq1->forceFill([
            'tracking_reference' => '92991002',
            'customer_account_id' => $account->id,
            'customer_id' => $customer->id,
            'customer_name' => 'مشتری تست',
            'contact_phone' => '09124445566',
            'phone_digest' => $phoneDigest,
            'device_brand' => RepairBrand::Pax,
            'device_model' => 'Z9',
            'issue' => 'مشکل آنتن',
            'status' => RepairRequestStatus::OnlineSubmitted,
            'submission_digest' => hash('sha256', 'acct-open-1'),
        ])->save();

        $openReq2 = new RepairRequest;
        $openReq2->forceFill([
            'tracking_reference' => '92991003',
            'customer_account_id' => $account->id,
            'customer_id' => $customer->id,
            'customer_name' => 'مشتری تست',
            'contact_phone' => '09124445566',
            'phone_digest' => $phoneDigest,
            'device_brand' => RepairBrand::Newland,
            'device_model' => 'ME31',
            'issue' => 'باتری ضعیف',
            'status' => RepairRequestStatus::OnlineSubmitted,
            'submission_digest' => hash('sha256', 'acct-open-2'),
        ])->save();

        $deliveredCase = new RepairCase;
        $deliveredCase->forceFill([
            'public_id' => (string) Str::uuid(),
            'tracking_reference' => '92991004',
            'tracking_digest' => RepairIdentity::digest('tracking', '92991004'),
            'intake_digest' => hash('sha256', 'acct-delivered-1'),
            'customer_account_id' => $account->id,
            'customer_id' => $customer->id,
            'customer_name' => 'مشتری تست',
            'contact_phone' => '09124445566',
            'phone_digest' => $phoneDigest,
            'device_brand' => RepairBrand::Szzt,
            'device_model' => 'KS8223',
            'issue' => 'تست',
            'physical_condition' => 'سالم',
            'accessories' => 'ندارد',
            'status' => RepairStage::Delivered,
            'received_at' => now()->subDays(5),
            'received_by' => $owner->id,
        ])->save();

        $order = new SalesOrder;
        $order->forceFill([
            'public_id' => '80706975-1111-4444-8888-1234567890ab',
            'customer_id' => $customer->id,
            'customer_account_id' => $account->id,
            'checkout_key' => (string) Str::uuid(),
            'checkout_digest' => hash('sha256', 'acct-order-1'),
            'status' => 'confirmed',
            'currency' => 'TOMAN',
            'total' => '850000.00',
            'version' => 1,
        ])->save();

        $address = new CustomerAddress;
        $address->forceFill([
            'public_id' => (string) Str::uuid(),
            'customer_account_id' => $account->id,
            'recipient' => 'خشایار',
            'phone' => '09124445566',
            'province' => 'تهران',
            'city' => 'باغستان',
            'address' => 'بلوار ولیعصر، کوچه گلستان، پلاک ۱۲، واحد ۳',
            'postal_code' => '3351612345',
        ])->save();

        $this->actingAs($account, 'customer')->withSession(['customer_session_version' => $account->session_version]);

        $overview = $this->get(route('account.index'))
            ->assertOk()
            ->assertSee('همه سفارش‌ها')
            ->assertSee('همه تعمیرات')
            ->assertSee(FaNumber::digits('80706975'))
            ->assertSee('PAX Z9')
            ->assertSee('Newland ME31')
            ->assertSee('np-account-row__chevron', false);

        $this->assertSame(2, $overview->viewData('counts')['active']);
        $this->assertSame(2, $overview->viewData('counts')['requests']);

        $this->get(route('account.index', ['section' => 'reviews']))
            ->assertOk()
            ->assertSee('مشاهده محصولات ←')
            ->assertDontSee('<a class="np-account-link" href="'.route('products.index').'">فروشگاه</a>', false);

        $this->get(route('account.index', ['section' => 'addresses']))
            ->assertOk()
            ->assertSee('تهران، باغستان')
            ->assertDontSee('· تهران، باغستان')
            ->assertSee('بلوار ولیعصر، کوچه گلستان')
            ->assertSee('np-account-address__chevron', false);

        $this->get(route('account.index', ['section' => 'profile']))
            ->assertOk()
            ->assertSee('تغییر شماره موبایل');
    }

    public function test_payment_result_and_order_detail_display_jalali_payment_date_and_time(): void
    {
        User::factory()->superAdmin()->create();
        $account = $this->createAccount('09125556677');
        $customer = $this->createCustomer('09125556677');
        $account->forceFill(['customer_id' => $customer->id])->save();

        $order = new SalesOrder;
        $order->forceFill([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'customer_account_id' => $account->id,
            'checkout_key' => (string) Str::uuid(),
            'checkout_digest' => hash('sha256', 'paid-at-order'),
            'status' => 'draft',
            'currency' => 'TOMAN',
            'total' => '850000.00',
            'version' => 1,
        ])->save();

        config([
            'app.url' => 'https://novinpaypos.test',
            'payments.zibal.enabled' => true,
            'payments.zibal.live_requests' => true,
            'payments.zibal.sandbox' => false,
            'payments.zibal.merchant' => 'zibal-test-merchant',
            'payments.zibal.callback_url' => 'https://novinpaypos.test/payments/zibal/callback',
            'payments.zibal.api_base_url' => 'https://gateway.zibal.ir',
            'payments.zibal.min_rial' => 1000,
            'payments.zibal.max_rial' => 1000000000,
        ]);

        Http::fake([
            'https://gateway.zibal.ir/v1/request' => Http::response(['result' => 100, 'trackId' => 778899001], 200),
            'https://gateway.zibal.ir/v1/verify' => function ($request) {
                $attempt = PaymentAttempt::query()->where('track_id', '778899001')->sole();

                return Http::response([
                    'result' => 100,
                    'status' => 1,
                    'amount' => 8_500_000,
                    'orderId' => $attempt->public_id,
                    'paidAt' => '2026-11-24T17:25:00+03:30',
                ], 200);
            },
        ]);

        $attempt = app(ZibalPayments::class)->startForOrderCheckout(
            $account,
            $order,
            'pickup',
            null,
            (string) Str::uuid()
        );

        $expectedDisplay = PaymentDate::formatPaidAt('2026-11-24T17:25:00+03:30');
        $this->assertSame('۱۴۰۵/۰۹/۰۳ | ۱۷:۲۵', $expectedDisplay);

        $this->actingAs($account, 'customer')->withSession(['customer_session_version' => $account->session_version]);

        $this->get(route('payments.callback', ['trackId' => '778899001', 'orderId' => $attempt->public_id]))
            ->assertRedirect(route('payments.result', $attempt->public_id));

        $this->get(route('payments.result', $attempt->public_id))
            ->assertOk()
            ->assertSee('پرداخت با موفقیت انجام شد')
            ->assertSee('تاریخ و ساعت پرداخت')
            ->assertSee($expectedDisplay);

        $this->get(route('account.orders.show', $order->public_id))
            ->assertOk()
            ->assertSee('مسیر سفارش')
            ->assertSee('np-timeline--horizontal')
            ->assertSee('پرداخت‌شده')
            ->assertDontSee('سند تسویه شده است.')
            ->assertSee('تاریخ و ساعت پرداخت')
            ->assertSee($expectedDisplay)
            ->assertSee('<bdi class="np-latin" dir="ltr">'.strtoupper(substr($order->public_id, 0, 8)).'</bdi>', false);

        $this->assertNull(CustomerLogin::localDevOtpFor('non-local-challenge'));
    }

    public function test_signed_repair_payment_result_falls_back_to_verified_at_when_provider_paid_at_missing(): void
    {
        $owner = User::factory()->superAdmin()->create();
        $account = $this->createAccount('09126667788');
        $customer = $this->createCustomer('09126667788');
        $account->forceFill(['customer_id' => $customer->id])->save();
        $case = new RepairCase;
        $case->forceFill([
            'public_id' => (string) Str::uuid(),
            'tracking_reference' => '92991099',
            'tracking_digest' => RepairIdentity::digest('tracking', '92991099'),
            'intake_digest' => hash('sha256', 'repair-link-92991099'),
            'customer_id' => $customer->id,
            'customer_name' => 'مشتری تعمیر',
            'contact_phone' => '09126667788',
            'phone_digest' => RepairIdentity::digest('phone', '09126667788'),
            'device_brand' => RepairBrand::Newpos,
            'device_model' => '9220',
            'issue' => 'خرابی صفحه نمایش',
            'physical_condition' => 'سالم',
            'accessories' => 'ندارد',
            'status' => RepairStage::Ready,
            'received_at' => now()->subDay(),
            'received_by' => $owner->id,
        ])->save();

        $doc = new FinancialDocument;
        $doc->forceFill([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'repair_case_id' => $case->id,
            'source_version' => 1,
            'source_amount_minor' => 25_000_000,
            'charged_minor' => 25_000_000,
            'paid_minor' => 25_000_000,
            'version' => 2,
            'created_by' => $owner->id,
            'created_at' => now(),
        ])->save();

        $verifiedAt = CarbonImmutable::parse('2026-11-24T13:55:00Z');
        $attempt = new PaymentAttempt;
        $attempt->forceFill([
            'public_id' => (string) Str::uuid(),
            'financial_document_id' => $doc->id,
            'customer_account_id' => $account->id,
            'operation_key' => (string) Str::uuid(),
            'amount_minor' => 25_000_000,
            'amount_rial' => 2_500_000,
            'document_version' => 1,
            'merchant_digest' => hash_hmac('sha256', 'zibal-test-merchant', config('app.key')),
            'track_id' => '554433221',
            'state' => 'settled',
            'verified_at' => $verifiedAt,
        ])->save();

        $url = URL::temporarySignedRoute('payments.repair.link.result', now()->addHours(2), [
            'repair' => $case->public_id,
            'payment' => $attempt->public_id,
        ]);

        $this->get($url)
            ->assertOk()
            ->assertSee('تاریخ و ساعت پرداخت')
            ->assertSee('۱۴۰۵/۰۹/۰۳ | ۱۷:۲۵');
    }

    private function createAccount(string $phone): CustomerAccount
    {
        $account = new CustomerAccount;
        $account->forceFill([
            'public_id' => (string) Str::uuid(),
            'phone' => $phone,
            'phone_digest' => RepairIdentity::digest('customer-account-phone', $phone),
            'verified_at' => now(),
        ])->save();

        return $account->refresh();
    }

    private function createCustomer(string $phone): Customer
    {
        $customer = new Customer;
        $customer->forceFill([
            'public_id' => (string) Str::uuid(),
            'name' => 'مشتری آزمایشی',
            'contact_phone' => $phone,
            'phone_digest' => RepairIdentity::digest('phone', $phone),
            'name_digest' => RepairIdentity::digest('customer-name', 'مشتری آزمایشی'),
            'identity_digest' => RepairIdentity::digest('customer-identity', $phone),
            'is_active' => true,
            'version' => 1,
        ])->save();

        return $customer->refresh();
    }
}
