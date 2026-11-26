<?php

namespace Tests\Feature;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\StockStatus;
use App\Http\Controllers\Admin\ProductFeedbackController;
use App\Models\AdminAccount;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Models\InventoryItem;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\ProductFeedback;
use App\Models\User;
use App\Services\ShoppingCart;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase26PaymentRetryStickyBuyAndFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Customer $customer;

    private CustomerAccount $account;

    private CustomerAddress $address;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('https://novinpaypos.test');
        URL::forceScheme('https');

        config([
            'app.url' => 'https://novinpaypos.test',
            'payments.zibal.enabled' => true,
            'payments.zibal.live_requests' => true,
            'payments.zibal.sandbox' => false,
            'payments.zibal.merchant' => 'merchant-phase26-test',
            'payments.zibal.callback_url' => 'https://novinpaypos.test/payments/zibal/callback',
            'payments.zibal.api_base_url' => 'https://gateway.zibal.ir',
            'payments.test_product.slug' => 'zibal-gateway-test-5000',
            'payments.test_product.allowed_phones' => ['09123334455'],
        ]);

        $this->owner = User::factory()->superAdmin()->create();
        if (! AdminAccount::query()->where('user_id', $this->owner->id)->exists()) {
            AdminAccount::forceCreate([
                'user_id' => $this->owner->id,
                'role' => 'super_admin',
                'owner_slot' => 1,
                'is_active' => true,
            ]);
        }

        $this->customer = Customer::forceCreate([
            'public_id' => (string) Str::uuid(),
            'name' => 'خریدار تست فاز ۲.۶',
            'contact_phone' => '09123334455',
            'name_digest' => bin2hex(random_bytes(32)),
            'phone_digest' => RepairIdentity::digest('phone', '09123334455'),
            'identity_digest' => bin2hex(random_bytes(32)),
            'is_active' => true,
        ]);

        $this->account = CustomerAccount::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $this->customer->id,
            'phone' => '09123334455',
            'phone_digest' => RepairIdentity::digest('phone', '09123334455'),
            'name' => 'خریدار تست فاز ۲.۶',
            'national_code' => '0012345679',
            'verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
        ]);

        $this->address = CustomerAddress::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_account_id' => $this->account->id,
            'recipient' => 'خریدار تست فاز ۲.۶',
            'phone' => '09123334455',
            'province' => 'تهران',
            'city' => 'شهریار',
            'address' => 'خیابان ولیعصر، پلاک ۱۲',
            'postal_code' => '3351612345',
        ]);

        $this->product = Product::forceCreate([
            'name' => 'Newland ME31',
            'slug' => 'newland-me31-phase26',
            'category' => ProductCategory::PosTerminal,
            'brand' => 'Newland',
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::InStock,
            'price' => 4_500_000,
            'currency' => 'TOMAN',
            'online_purchasing_enabled' => true,
            'is_published' => true,
        ]);

        InventoryItem::forceCreate([
            'public_id' => (string) Str::uuid(),
            'product_id' => $this->product->id,
            'sku' => 'SKU-ME31-P26',
            'name' => 'Newland ME31',
            'condition' => ProductCondition::New->value,
            'serialized' => false,
            'on_hand' => 5,
            'reserved' => 0,
            'version' => 1,
        ]);

        $this->artisan('novinpay:provision-payment-test-product', ['--apply' => true])->assertExitCode(0);
    }

    public function test_cancelled_pickup_and_courier_orders_retry_cleanly_without_409_and_protect_settled_orders(): void
    {
        $requestSeq = Http::sequence()
            ->push(['result' => 100, 'trackId' => 91000001], 200)
            ->push(['result' => 100, 'trackId' => 91000002], 200)
            ->push(['result' => 100, 'trackId' => 91000011], 200)
            ->push(['result' => 100, 'trackId' => 91000012], 200);

        Http::fake([
            'https://gateway.zibal.ir/v1/request' => $requestSeq,
            'https://gateway.zibal.ir/v1/verify' => function ($request) {
                $track = (int) ($request['trackId'] ?? 0);
                if (in_array($track, [91000001, 91000011], true)) {
                    return Http::response(['result' => 202], 200);
                }
                $attempt = PaymentAttempt::query()->where('track_id', (string) $track)->firstOrFail();

                return Http::response([
                    'result' => 100,
                    'status' => 1,
                    'amount' => $attempt->amount_rial,
                    'orderId' => $attempt->public_id,
                    'trackId' => $track,
                    'paidAt' => '2026-11-26T07:30:00',
                ], 200);
            },
            'https://gateway.zibal.ir/v1/inquiry' => function ($request) {
                $track = (int) ($request['trackId'] ?? 0);
                $attempt = PaymentAttempt::query()->where('track_id', (string) $track)->firstOrFail();

                return Http::response([
                    'result' => 100,
                    'status' => 3,
                    'amount' => $attempt->amount_rial,
                    'orderId' => $attempt->public_id,
                    'trackId' => $track,
                ], 200);
            },
        ]);

        foreach (['pickup', 'courier'] as $index => $deliveryMethod) {
            [$proof, $revision, $quote] = $this->seedCartItem($this->product);

            $firstTrack = 91000001 + ($index * 10);
            $secondTrack = 91000002 + ($index * 10);

            $checkoutPayload = [
                'cart_context' => $proof,
                'cart_revision' => $revision,
                'operation_key' => (string) Str::uuid(),
                'quote' => $quote,
                'method' => $deliveryMethod,
                'payment_method' => 'zibal',
            ];
            if ($deliveryMethod !== 'pickup') {
                $checkoutPayload['address'] = $this->address->public_id;
            }

            $this->post('https://novinpaypos.test/cart/checkout', $checkoutPayload)->assertRedirect();

            $cancelledAttempt = PaymentAttempt::query()->where('track_id', (string) $firstTrack)->sole();

            // Simulate user cancelling on Zibal callback (status = 3)
            $this->get('https://novinpaypos.test/payments/zibal/callback?success=0&status=3&trackId='.$firstTrack.'&orderId='.$cancelledAttempt->public_id)
                ->assertRedirect(route('payments.result', $cancelledAttempt->public_id));

            $cancelledAttempt->refresh();
            $this->assertSame('failed', $cancelledAttempt->state);

            // Cancelled result page shows exact Phase 2.6 copy, tel: support link, and retry form
            $this->get(route('payments.result', $cancelledAttempt->public_id))
                ->assertOk()
                ->assertSee('انصراف از پرداخت')
                ->assertSee('سبد خرید شما محفوظ است و هر زمان مایل بودید می‌توانید مجدد اقدام به پرداخت نمایید.')
                ->assertDontSee('هیچ مبلغی از حساب شما کسر نشده')
                ->assertSee('href="'.config('business.phone_uri').'"', false)
                ->assertSee(route('payments.retry', $cancelledAttempt->public_id), false);

            // Clicking "تلاش مجدد برای پرداخت" initiates a fresh Zibal request and redirects without 409
            $retryRes = $this->post(route('payments.retry', $cancelledAttempt->public_id));
            $this->assertSame(303, $retryRes->getStatusCode(), "Retry failed for delivery method {$deliveryMethod}");

            $freshAttempt = PaymentAttempt::query()
                ->where('financial_document_id', $cancelledAttempt->financial_document_id)
                ->where('id', '>', $cancelledAttempt->id)
                ->sole();

            $this->assertSame('pending', $freshAttempt->state);
            $this->assertSame((string) $secondTrack, $freshAttempt->track_id);
            $retryRes->assertRedirect(route('payments.gateway', $freshAttempt->public_id));

            // Following the gateway redirect renders the forward page pointing to Zibal start URL (200 OK, no 409)
            $this->get(route('payments.gateway', $freshAttempt->public_id))
                ->assertOk()
                ->assertSee('https://gateway.zibal.ir/start/'.$secondTrack, false);

            // Settle the fresh attempt and verify retrying either attempt does NOT create a duplicate payment
            $this->get('https://novinpaypos.test/payments/zibal/callback?success=1&status=2&trackId='.$secondTrack.'&orderId='.$freshAttempt->public_id)
                ->assertRedirect(route('payments.result', $freshAttempt->public_id));

            $this->assertSame('settled', $freshAttempt->fresh()->state);

            // Success result page shows concise green box copy without redundant prefix
            $this->get(route('payments.result', $freshAttempt->public_id))
                ->assertOk()
                ->assertSee('مراحل آماده‌سازی و ارسال از طریق پیامک و صفحهٔ پیگیری سفارش اطلاع‌رسانی می‌شود.')
                ->assertDontSee('سفارش شما ثبت و تأیید شد؛');

            $attemptCountBefore = PaymentAttempt::query()->where('financial_document_id', $freshAttempt->financial_document_id)->count();

            // Retrying the old cancelled attempt OR the settled attempt redirects to settled result without calling Zibal
            $this->post(route('payments.retry', $cancelledAttempt->public_id))
                ->assertRedirect(route('payments.result', $freshAttempt->public_id));
            $this->post(route('payments.retry', $freshAttempt->public_id))
                ->assertRedirect(route('payments.result', $freshAttempt->public_id));

            $this->assertSame($attemptCountBefore, PaymentAttempt::query()->where('financial_document_id', $freshAttempt->financial_document_id)->count());
        }
    }

    public function test_payment_stylesheets_and_product_sticky_bar_markup_match_phase26_specs(): void
    {
        $payCss = (string) file_get_contents(public_path('pay/assets/pay.css'));
        $flowCss = (string) file_get_contents(public_path('css/payment-flow.css'));

        $this->assertStringNotContainsString('IRANYekanXVF', $payCss);
        $this->assertStringNotContainsString('monospace', $payCss);
        $this->assertStringContainsString('justify-content: space-between;', $payCss);
        $this->assertStringContainsString('font-variant-numeric: tabular-nums;', $payCss);
        $this->assertStringNotContainsString('IRANYekanXVF', $flowCss);

        $productPage = $this->get(route('products.show', $this->product))->assertOk();
        $productPage
            ->assertSee('product-detail-page', false)
            ->assertSee('store-mobile-cart', false)
            ->assertDontSee('store-mobile-search', false)
            ->assertDontSee('id="store-quantity"', false)
            ->assertSee('افزودن به سبد خرید')
            ->assertSee('مشاهده سبد خرید')
            ->assertSee('data-dock-in-cart', false)
            ->assertDontSee('np-drawer-categories', false);

        // Non-product pages retain mobile search icon in header
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('store-mobile-search', false)
            ->assertDontSee('store-mobile-cart', false)
            ->assertDontSee('np-drawer-categories', false);
    }

    public function test_allowlisted_customer_and_admin_can_comment_and_reply_on_private_test_product_while_others_get_404(): void
    {
        $testProduct = Product::query()->where('slug', 'zibal-gateway-test-5000')->firstOrFail();

        // 1. Non-allowlisted customer cannot post a comment on the private test product (404)
        $outsider = CustomerAccount::forceCreate([
            'public_id' => (string) Str::uuid(),
            'phone' => '09351112233',
            'phone_digest' => RepairIdentity::digest('customer-account-phone', '09351112233'),
            'name' => 'کاربر عادی',
            'verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
        ]);

        $this->actingAs($outsider, 'customer')
            ->withSession(['customer_session_version' => 1])
            ->post(route('products.feedback.store', $testProduct), [
                'kind' => 'review',
                'body' => 'تلاش برای ثبت نظر غیرمجاز روی محصول تست',
            ])
            ->assertNotFound();

        $this->assertSame(0, ProductFeedback::query()->where('product_id', $testProduct->id)->count());

        // 2. Allowlisted customer can view the private test product and submit a review without 404
        $this->actingAs($this->account, 'customer')
            ->withSession(['customer_session_version' => 1])
            ->post(route('products.feedback.store', $testProduct), [
                'kind' => 'review',
                'body' => 'تست ثبت دیدگاه روی محصول خصوصی درگاه زیبال با موفقیت انجام شد.',
            ])
            ->assertRedirect(route('products.show', $testProduct))
            ->assertSessionHas('feedback_status');

        $feedback = ProductFeedback::query()->where('product_id', $testProduct->id)->sole();
        $this->assertSame('pending', $feedback->status);

        // 3. Authenticated admin can view the private test product page, inspect its feedback in admin panel, and post an approved reply
        $this->actingAs($this->owner, 'web')
            ->get(route('products.show', $testProduct))
            ->assertOk()
            ->assertSee($testProduct->name);

        $this->actingAs($this->owner, 'web')
            ->withSession(['admin_password_confirmed_at' => now()->timestamp])
            ->get(route('admin.products.feedback.index', $testProduct))
            ->assertOk()
            ->assertSee('تست ثبت دیدگاه روی محصول خصوصی درگاه زیبال با موفقیت انجام شد.');

        $this->actingAs($this->owner, 'web')
            ->withSession(['admin_password_confirmed_at' => now()->timestamp])
            ->patch(route('admin.products.feedback.update', [$testProduct, $feedback]), [
                'status' => 'approved',
                'staff_reply' => 'پاسخ پشتیبانی نوین‌پی ثبت و تأیید شد.',
                'revision' => ProductFeedbackController::revision($feedback),
            ])
            ->assertRedirect();

        $feedback->refresh();
        $this->assertSame('approved', $feedback->status);
        $this->assertSame('پاسخ پشتیبانی نوین‌پی ثبت و تأیید شد.', $feedback->staff_reply);

        // 4. Approved review & admin reply render on the private test product page for allowlisted customer, while guests still get 404
        $this->actingAs($this->account, 'customer')
            ->withSession(['customer_session_version' => 1])
            ->get(route('products.show', $testProduct))
            ->assertOk()
            ->assertSee('تست ثبت دیدگاه روی محصول خصوصی درگاه زیبال با موفقیت انجام شد.')
            ->assertSee('پاسخ پشتیبانی نوین‌پی ثبت و تأیید شد.');

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get(route('products.show', $testProduct))->assertNotFound();
    }

    private function seedCartItem(Product $product): array
    {
        $this->actingAs($this->account, 'customer')
            ->withSession(['customer_session_version' => 1])
            ->withUnencryptedCookie(ShoppingCart::DEVICE_COOKIE, str_repeat('b', 64));

        $cart = $this->get('https://novinpaypos.test/cart')->assertOk();

        $this->post('https://novinpaypos.test/cart/products/'.$product->slug, [
            'cart_context' => $cart->viewData('proof'),
            'cart_revision' => $cart->viewData('revision'),
            'quantity' => 1,
        ])->assertRedirect();

        $checkout = $this->get('https://novinpaypos.test/cart/checkout')->assertOk();

        return [
            $checkout->viewData('proof'),
            $checkout->viewData('revision'),
            $checkout->viewData('quote'),
        ];
    }
}
