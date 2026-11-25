<?php

namespace Tests\Feature;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\StockStatus;
use App\Events\OrderPaid;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Models\InventoryItem;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\ShoppingCart;
use App\Support\CustomerTimeline;
use App\Support\FaNumber;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultiStepCheckoutZibalTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Customer $customer;

    private CustomerAccount $account;

    private Product $product;

    private CustomerAddress $address;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://novinpaypos.test',
            'payments.zibal.enabled' => true,
            'payments.zibal.live_requests' => true,
            'payments.zibal.sandbox' => false,
            'payments.zibal.merchant' => 'merchant-live-checkout-01',
            'payments.zibal.callback_url' => 'https://novinpaypos.test/payments/zibal/callback',
            'payments.zibal.api_base_url' => 'https://gateway.zibal.ir',
        ]);

        $this->owner = User::factory()->superAdmin()->create();

        $this->customer = Customer::forceCreate([
            'public_id' => (string) Str::uuid(),
            'name' => 'خریدار تست زیبال',
            'contact_phone' => '09123334455',
            'name_digest' => bin2hex(random_bytes(32)),
            'phone_digest' => RepairIdentity::digest('phone', '09123334455'),
            'identity_digest' => bin2hex(random_bytes(32)),
            'is_active' => true,
        ]);

        $this->account = CustomerAccount::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $this->customer->id,
            'phone_digest' => RepairIdentity::digest('phone', '09123334455'),
            'phone' => '09123334455',
            'name' => 'خریدار تست زیبال',
            'verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
        ]);

        $this->address = CustomerAddress::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_account_id' => $this->account->id,
            'recipient' => 'خریدار تست زیبال',
            'phone' => '09123334455',
            'province' => 'تهران',
            'city' => 'شهریار',
            'address' => 'بلوار ولیعصر پلاک ۲۴',
            'postal_code' => '3351611111',
        ]);

        $category = ProductCategory::storefrontCases()[0];

        $this->product = Product::forceCreate([
            'name' => 'کارتخوان سیار PAX S910',
            'slug' => 'pax-s910-zibal-test',
            'category' => $category,
            'brand' => 'PAX',
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::InStock,
            'price' => 4500000, // 4,500,000 Toman = 45,000,000 Rial
            'is_published' => true,
        ]);

        InventoryItem::forceCreate([
            'public_id' => (string) Str::uuid(),
            'product_id' => $this->product->id,
            'sku' => 'SKU-PAX-S910-NEW',
            'name' => 'PAX S910',
            'condition' => ProductCondition::New->value,
            'serialized' => false,
            'on_hand' => 5,
            'reserved' => 0,
            'version' => 1,
        ]);
    }

    public function test_checkout_renders_four_steps_interactive_map_and_step4_only_submit_button(): void
    {
        $this->seedCartItem();

        config(['services.neshan.web_key' => 'web.synthetic_neshan_key_123']);

        $response = $this->get('https://novinpaypos.test/cart/checkout')->assertOk();
        $html = $response->getContent();
        $response->assertSee('novalidate', false)
            ->assertSee('data-checkout-step="1"', false)
            ->assertSee('data-checkout-step="2"', false)
            ->assertSee('data-checkout-step="3"', false)
            ->assertSee('data-checkout-step="4"', false)
            ->assertSee('data-checkout-map', false)
            ->assertSee('data-neshan-key="web.synthetic_neshan_key_123"', false)
            ->assertSee('© نقشه نشان')
            ->assertSee('تحویل حضوری در فروشگاه نوین پی')
            ->assertDontSee('نوینپِی')
            ->assertSee('پست')
            ->assertSee('تیپاکس')
            ->assertSee('پیک')
            ->assertSee('پرداخت آنلاین با درگاه زیبال')
            ->assertSee('name="payment_method" value="zibal"', false)
            ->assertSee('images/zibal-trust-light.svg')
            ->assertSee('پرداخت آنلاین با زیبال');

        $appJs = (string) file_get_contents(resource_path('js/app.js'));
        $this->assertStringNotContainsString('tile.openstreetmap.org', $appJs);
        $this->assertStringNotContainsString('server.arcgisonline.com', $appJs);
        $this->assertStringNotContainsString('basemaps.cartocdn.com', $appJs);
        $this->assertStringContainsString('static.neshan.org/sdk/leaflet', $appJs);

        foreach (['pay/assets/vpn-warning.css', 'css/payment-flow.css'] as $paymentCssPath) {
            $css = (string) file_get_contents(public_path($paymentCssPath));
            $this->assertStringNotContainsString('IRANYekanXVF', $css);
            $this->assertStringNotContainsString('monospace', $css);
            $this->assertStringContainsString('DanaFaNum-Regular.woff2', $css);
            $this->assertStringContainsString('DanaFaNum-Medium.woff2', $css);
            $this->assertStringContainsString('DanaFaNum-Bold.woff2', $css);
        }

        $appCss = (string) file_get_contents(resource_path('css/app.css'));
        $this->assertStringNotContainsString('IRANYekan', $appCss);
        $this->assertStringNotContainsString('font-variation-settings', $appCss);
        $this->assertStringContainsString('DanaFaNum-Regular.woff2', $appCss);
        $this->assertStringContainsString('DanaFaNum-Medium.woff2', $appCss);
        $this->assertStringContainsString('DanaFaNum-Bold.woff2', $appCss);

        $homeCss = (string) file_get_contents(resource_path('css/home.css'));
        $this->assertStringContainsString('flex-direction: row;', $homeCss);
        $this->assertStringContainsString('.np-footer__tagline', $homeCss);
        $this->assertStringContainsString('width: 54px; height: 54px;', $homeCss);
        $this->assertStringNotContainsString('.np-footer__brand { flex-direction: column;', $homeCss);
        $this->assertStringNotContainsString('letter-spacing: -1.3px', $homeCss);
        $this->assertStringNotContainsString('letter-spacing: -1.2px', $homeCss);
        $this->assertStringContainsString('class="np-footer__tagline"', $html);

        $publicCss = (string) file_get_contents(resource_path('css/public.css'));
        $this->assertStringNotContainsString('letter-spacing: -.7px', $publicCss);

        $accountCss = (string) file_get_contents(resource_path('css/customer-account.css'));
        $this->assertStringNotContainsString('letter-spacing: -.015em', $accountCss);

        $this->assertStringNotContainsString('tracking-tight', $appCss);
        $this->assertStringNotContainsString('.skip-link:focus,', $appCss);
        $this->assertStringContainsString('.skip-link:focus-visible', $appCss);

        // Confirm submit button is ONLY inside Step 4 and NOT in the left-side order summary aside.
        $doc = new \DOMDocument;
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($doc);
        $this->assertSame(1, $xpath->query('//div[@data-checkout-step="4"]//button[@data-checkout-submit]')->length);
        $this->assertSame(0, $xpath->query('//aside[contains(@class,"commerce-summary")]//button[@data-checkout-submit]')->length);
        $this->assertSame('مشخصات', trim((string) $xpath->query('//li[@data-step-indicator="1"]//span[contains(@class,"checkout-step__label")]')->item(0)?->textContent));
        $this->assertSame('آدرس', trim((string) $xpath->query('//li[@data-step-indicator="2"]//span[contains(@class,"checkout-step__label")]')->item(0)?->textContent));
        $this->assertSame('نحوه ارسال', trim((string) $xpath->query('//li[@data-step-indicator="3"]//span[contains(@class,"checkout-step__label")]')->item(0)?->textContent));
        $this->assertSame('پرداخت', trim((string) $xpath->query('//li[@data-step-indicator="4"]//span[contains(@class,"checkout-step__label")]')->item(0)?->textContent));

        // Map appears before province/city inputs inside Step 2
        $this->assertLessThan(strpos($html, 'id="checkout-province"'), strpos($html, 'data-checkout-map'));

        // #iran-locations-data contains valid raw JSON (not JSON.parse('...'))
        $jsonScript = (string) $xpath->query('//script[@id="iran-locations-data"]')->item(0)?->textContent;
        $decodedLocations = json_decode($jsonScript, true);
        $this->assertIsArray($decodedLocations);
        $this->assertArrayHasKey('تهران', $decodedLocations);
        $this->assertContains('شهریار', $decodedLocations['تهران']);

        // When Zibal is disabled, online option is hidden and request mode is default.
        config(['payments.zibal.enabled' => false]);
        $disabled = $this->get('https://novinpaypos.test/cart/checkout')->assertOk();
        $disabled->assertDontSee('name="payment_method" value="zibal"', false)
            ->assertSee('ثبت درخواست سفارش')
            ->assertSee('پرداخت آنلاین فعلاً در دسترس نیست');
    }

    public function test_unlinked_account_and_high_value_pos_checkout_with_inline_profile_and_map_succeeds_without_403(): void
    {
        Event::fake([OrderPaid::class]);

        // Simulate a newly registered Google/OTP account with customer_id = null (previously caused HTTP 403).
        $unlinkedAccount = CustomerAccount::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_id' => null,
            'phone_digest' => null,
            'phone' => null,
            'email_digest' => hash_hmac('sha256', 'google-user@example.com', config('app.key')),
            'email' => 'google-user@example.com',
            'name' => 'کاربر گوگل',
            'verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
        ]);

        // High-value POS terminal: 10,500,000 Toman = 105,000,000 Rial
        $this->product->price = 10500000;
        $this->product->save();

        $this->actingAs($unlinkedAccount, 'customer')
            ->withSession(['customer_session_version' => 1])
            ->withUnencryptedCookie(ShoppingCart::DEVICE_COOKIE, str_repeat('b', 64));

        $cart = $this->get('https://novinpaypos.test/cart')->assertOk();
        $this->post('https://novinpaypos.test/cart/products/'.$this->product->slug, [
            'cart_context' => $cart->viewData('proof'),
            'cart_revision' => $cart->viewData('revision'),
            'quantity' => 1,
        ])->assertRedirect();

        $checkout = $this->get('https://novinpaypos.test/cart/checkout')->assertOk();

        Http::fake([
            'https://gateway.zibal.ir/v1/request' => Http::response(['result' => 100, 'trackId' => 998877665], 200),
        ]);

        $res = $this->post('https://novinpaypos.test/cart/checkout', [
            'cart_context' => $checkout->viewData('proof'),
            'cart_revision' => $checkout->viewData('revision'),
            'operation_key' => (string) Str::uuid(),
            'quote' => $checkout->viewData('quote'),
            'full_name' => 'مهدی کریمی',
            'mobile' => '۰۹۱۲۹۸۷۶۵۴۳',
            'national_code' => '۰۰۱۲۳۴۵۶۷۹',
            'email' => 'google-user@example.com',
            'address_mode' => 'new',
            'province' => 'تهران',
            'city' => 'شهریار',
            'street_address' => 'خیابان ولیعصر، کوچه بهار، پلاک ۱۵، واحد ۲',
            'postal_code' => '۳۳۵۱۶۱۲۳۴۵',
            'latitude' => '35.659700',
            'longitude' => '51.059200',
            'method' => 'post',
            'payment_method' => 'zibal',
        ]);

        $attempt = PaymentAttempt::query()->sole();
        $this->assertSame(105000000, $attempt->amount_rial);
        $this->assertSame('pending', $attempt->state);
        $res->assertRedirect('https://novinpaypos.test/payments/'.$attempt->public_id.'/connect');

        $unlinkedAccount->refresh();
        $this->assertNotNull($unlinkedAccount->customer_id);
        $this->assertSame('مهدی کریمی', $unlinkedAccount->name);
        $this->assertSame('0012345679', $unlinkedAccount->national_code);

        $savedAddr = CustomerAddress::query()->where('customer_account_id', $unlinkedAccount->id)->sole();
        $this->assertEqualsWithDelta(35.6597, $savedAddr->latitude, 0.0001);
        $this->assertEqualsWithDelta(51.0592, $savedAddr->longitude, 0.0001);
    }

    public function test_checkout_returns_fluent_persian_inline_validation_errors(): void
    {
        [$proof, $revision, $quote] = $this->seedCartItem();

        $res = $this->from('https://novinpaypos.test/cart/checkout')
            ->post('https://novinpaypos.test/cart/checkout', [
                'cart_context' => $proof,
                'cart_revision' => $revision,
                'operation_key' => (string) Str::uuid(),
                'quote' => $quote,
                'full_name' => 'ع',
                'mobile' => '09123',
                'national_code' => '1111111111',
                'address_mode' => 'new',
                'province' => '',
                'city' => '',
                'street_address' => 'کوتاه',
                'postal_code' => '1234',
                'method' => 'tipax',
                'payment_method' => 'zibal',
            ]);

        $res->assertRedirect('https://novinpaypos.test/cart/checkout')
            ->assertSessionHasErrors([
                'full_name' => 'نام و نام خانوادگی باید حداقل ۲ کاراکتر باشد.',
                'mobile' => 'لطفاً شماره موبایل معتبر ۱۱ رقمی وارد کنید (مانند ۰۹۱۲۳۴۵۶۷۸۹).',
                'national_code' => 'کد ملی واردشده معتبر نیست.',
                'province' => 'لطفاً استان محل تحویل را انتخاب کنید.',
                'city' => 'لطفاً شهر محل تحویل را انتخاب کنید.',
                'street_address' => 'آدرس دقیق باید حداقل ۱۰ کاراکتر باشد.',
                'postal_code' => 'کد پستی باید دقیقاً ۱۰ رقم بدون خط تیره باشد.',
            ]);
    }

    public function test_checkout_with_zibal_initiates_gateway_redirect_and_settles_on_callback(): void
    {
        $this->customer->forceFill(['contact_phone' => '09000000000'])->save();
        config([
            'notifications.pattern_sms.provider' => 'ippanel',
            'notifications.pattern_sms.enabled' => true,
            'customer.ippanel.enabled' => true,
            'customer.ippanel.api_key' => 'synthetic-key',
            'customer.ippanel.sender' => '+983000505',
        ]);
        Cache::flush();
        config([
            'notifications.telegram.enabled' => true,
            'notifications.telegram.bot_token' => '123456:TEST-CHECKOUT-TG',
            'notifications.telegram.chat_id' => '-10077889900',
            'notifications.telegram.api_base' => 'https://api.telegram.org',
        ]);

        [$proof, $revision, $quote] = $this->seedCartItem();

        Http::fake([
            'https://gateway.zibal.ir/v1/request' => Http::response(['result' => 100, 'trackId' => 246813579], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $res = $this->post('https://novinpaypos.test/cart/checkout', [
            'cart_context' => $proof,
            'cart_revision' => $revision,
            'operation_key' => (string) Str::uuid(),
            'quote' => $quote,
            'method' => 'tipax',
            'address' => $this->address->public_id,
            'payment_method' => 'zibal',
        ]);

        $attempt = PaymentAttempt::query()->sole();
        $order = SalesOrder::query()->sole();
        $this->assertSame('draft', $order->status);
        $this->assertSame('در انتظار پرداخت', CustomerTimeline::orderStatus($order));
        $this->assertSame(45000000, $attempt->amount_rial);
        $this->assertSame('246813579', $attempt->track_id);
        $this->assertSame('pending', $attempt->state);
        $res->assertRedirect('https://novinpaypos.test/payments/'.$attempt->public_id.'/connect');

        // Unpaid order creation & gateway redirect must NEVER send a Telegram order notification.
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));

        // Before payment verification, unpaid draft order must NOT clutter the customer's orders tab or show as confirmed,
        // while direct order view still shows accurate pending payment status without .۰۰ decimals.
        $this->get('https://novinpaypos.test/account?section=orders')
            ->assertOk()
            ->assertDontSee('در انتظار پرداخت')
            ->assertDontSee('تأییدشده')
            ->assertDontSee('.۰۰');
        $this->get(route('account.orders.show', $order->public_id))
            ->assertOk()
            ->assertSee('در انتظار پرداخت')
            ->assertDontSee('تأییدشده')
            ->assertSee('۴,۵۰۰,۰۰۰ تومان')
            ->assertDontSee('.۰۰');

        Http::fake([
            'https://gateway.zibal.ir/v1/verify' => Http::response([
                'result' => 100,
                'status' => 1,
                'amount' => 45000000,
                'orderId' => $attempt->public_id,
                'trackId' => 246813579,
                'paidAt' => '2026-11-23T02:20:00',
            ], 200),
            'https://edge.ippanel.com/v1/api/send' => Http::response(['meta' => ['status' => true]]),
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->get('https://novinpaypos.test/payments/zibal/callback?trackId=246813579&orderId='.$attempt->public_id)
            ->assertRedirect('https://novinpaypos.test/payments/'.$attempt->public_id.'/result');

        // Duplicate callback replay must NOT send a second Telegram notification.
        $this->get('https://novinpaypos.test/payments/zibal/callback?trackId=246813579&orderId='.$attempt->public_id)
            ->assertRedirect('https://novinpaypos.test/payments/'.$attempt->public_id.'/result');

        $this->assertSame('settled', $attempt->fresh()->state);
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame('تأییدشده', CustomerTimeline::orderStatus($order->fresh()));
        // Paid order settlement must automatically reserve/decrement available stock.
        $inventory = InventoryItem::query()->where('product_id', $this->product->id)->sole();
        $this->assertSame(1, $inventory->reserved);
        $this->assertSame(4, $inventory->available);
        $shortCode = strtoupper(substr($order->public_id, 0, 8));
        $this->get('https://novinpaypos.test/account?section=orders')
            ->assertOk()
            ->assertSee(FaNumber::digits($shortCode))
            ->assertSee('تأییدشده')
            ->assertDontSee('اسناد و پرداخت‌ها')
            ->assertDontSee('ارسال و تحویل');
        $sms = Http::recorded(fn ($request) => $request->url() === 'https://edge.ippanel.com/v1/api/send')->sole()[0];
        $this->assertSame('mfae2yqje8msw9e', $sms['code']);
        $this->assertSame(['+989123334455'], $sms['recipients']);
        $this->assertSame([
            'order_code' => strtoupper(substr($order->public_id, 0, 8)),
            'name' => $this->customer->name,
            'product' => $this->product->name,
        ], $sms['params']);
        $this->get(route('payments.result', $attempt->public_id))->assertOk()
            ->assertSee('پیگیری سفارش')->assertSee(route('account.orders.show', $order->public_id));
        $this->get(route('account.orders.show', $order->public_id))->assertOk()
            ->assertSee('کد <bdi class="np-latin" dir="ltr">'.$shortCode.'</bdi>', false)
            ->assertDontSee($order->public_id)
            ->assertDontSee('موجودی رزرو شد')
            ->assertDontSee('مشاهده سند و رسیدها')
            ->assertDontSee('جزئیات تحویل')
            ->assertDontSee('np-location')
            ->assertSee('data-stage="complete"', false)
            ->assertSee('data-stage="current"', false)
            ->assertSee('aria-current="step"', false)
            ->assertSee('data-stage="upcoming"', false)
            ->assertSee('آماده‌سازی سفارش');

        $telegramRequests = Http::recorded(fn ($req) => str_contains($req->url(), 'api.telegram.org'))->values();
        $this->assertCount(1, $telegramRequests);
        $this->assertStringContainsString('پرداخت آنلاین سفارش تأیید شد', (string) ($telegramRequests[0][0]['text'] ?? ''));

        // Cart is automatically cleared after verified Zibal settlement.
        $this->assertSame(0, app(ShoppingCart::class)->count(request()));
    }

    public function test_checkout_with_zibal_gateway_rejection_preserves_order_and_shows_retry(): void
    {
        [$proof, $revision, $quote] = $this->seedCartItem();

        Http::fake([
            'https://gateway.zibal.ir/v1/request' => Http::response(['result' => 105], 200),
        ]);

        $res = $this->post('https://novinpaypos.test/cart/checkout', [
            'cart_context' => $proof,
            'cart_revision' => $revision,
            'operation_key' => (string) Str::uuid(),
            'quote' => $quote,
            'method' => 'pickup',
            'payment_method' => 'zibal',
        ]);

        $attempt = PaymentAttempt::query()->sole();
        $this->assertSame('failed', $attempt->state);
        $res->assertRedirect('https://novinpaypos.test/payments/'.$attempt->public_id.'/result');

        $result = $this->get('https://novinpaypos.test/payments/'.$attempt->public_id.'/result')
            ->assertOk()
            ->assertSee('pay/assets/pay.css?v=', false)
            ->assertSee('t-red')
            ->assertSee('تراکنش ناموفق')
            ->assertSee('۴٬۵۰۰٬۰۰۰ تومان')
            ->assertDontSee('.۰۰')
            ->assertSee(route('payments.retry', $attempt->public_id))
            ->assertSeeInOrder(['تلاش مجدد برای پرداخت', 'بازگشت به سبد خرید']);

        $order = SalesOrder::query()->sole();
        $this->assertSame('پرداخت ناموفق؛ در انتظار پرداخت', CustomerTimeline::orderStatus($order));
        $this->get('https://novinpaypos.test/account?section=orders')
            ->assertOk()
            ->assertDontSee('پرداخت ناموفق؛ در انتظار پرداخت')
            ->assertDontSee('تأییدشده');
        $this->get(route('account.orders.show', $order->public_id))
            ->assertOk()
            ->assertSee('پرداخت ناموفق؛ در انتظار پرداخت')
            ->assertDontSee('تأییدشده');
    }

    public function test_vpn_warning_renders_for_non_iran_ip_and_browser_navigations_while_iran_ip_forwards(): void
    {
        $data = json_decode((string) file_get_contents(config('payments.connectivity.ranges_path')), true, 512, JSON_THROW_ON_ERROR);
        $this->travelTo(Carbon::parse($data['month'].'-15'));

        [$proof, $revision, $quote] = $this->seedCartItem();

        Http::fake([
            'https://gateway.zibal.ir/v1/request' => Http::response(['result' => 100, 'trackId' => 777888999], 200),
        ]);

        $this->post('https://novinpaypos.test/cart/checkout', [
            'cart_context' => $proof,
            'cart_revision' => $revision,
            'operation_key' => (string) Str::uuid(),
            'quote' => $quote,
            'method' => 'pickup',
            'payment_method' => 'zibal',
        ])->assertRedirect();

        $attempt = PaymentAttempt::query()->sole();
        $connectUrl = 'https://novinpaypos.test/payments/'.$attempt->public_id.'/connect';

        // 1. Direct foreign VPN IP (e.g. 193.46.81.204) shows the custom VPN warning page
        $this->withServerVariables(['REMOTE_ADDR' => '193.46.81.204'])
            ->get($connectUrl)
            ->assertOk()
            ->assertSee('اتصال از خارج ایران تشخیص داده شد.')
            ->assertSeeInOrder(['تلاش مجدد برای پرداخت', 'بازگشت به سبد خرید'])
            ->assertSee('data-verify-split="0"', false);

        // 2. Reverse-proxied foreign VPN IP via SERVER_ADDR + X-Forwarded-For shows the VPN warning page
        $this->withServerVariables([
            'REMOTE_ADDR' => '93.126.6.137',
            'SERVER_ADDR' => '93.126.6.137',
            'HTTP_X_FORWARDED_FOR' => '193.46.81.204',
        ])
            ->get($connectUrl)
            ->assertOk()
            ->assertSee('اتصال از خارج ایران تشخیص داده شد.')
            ->assertSeeInOrder(['تلاش مجدد برای پرداخت', 'بازگشت به سبد خرید'])
            ->assertSee('data-verify-split="0"', false);

        // 3. Real browser top-level navigation (Sec-Fetch-Mode: navigate) on Iran IP renders connect shell with split-tunnel verification enabled
        $this->flushHeaders()
            ->withServerVariables(['REMOTE_ADDR' => '5.160.0.1', 'SERVER_ADDR' => '', 'HTTP_X_FORWARDED_FOR' => ''])
            ->withHeaders(['Sec-Fetch-Mode' => 'navigate'])
            ->get($connectUrl)
            ->assertOk()
            ->assertSee('data-verify-split="1"', false)
            ->assertSeeInOrder(['تلاش مجدد برای پرداخت', 'بازگشت به سبد خرید']);

        // 4. Direct non-navigation Iran IP check forwards cleanly to Zibal
        $this->flushHeaders()
            ->withServerVariables(['REMOTE_ADDR' => '5.160.0.1', 'SERVER_ADDR' => '', 'HTTP_X_FORWARDED_FOR' => ''])
            ->get($connectUrl)
            ->assertOk()
            ->assertSee('0;url=https://gateway.zibal.ir/start/777888999');
    }

    public function test_unified_cart_hides_internal_scopes_allows_authenticated_quantity_increase_and_returns_ajax_html(): void
    {
        $this->actingAs($this->account, 'customer')
            ->withSession(['customer_session_version' => 1])
            ->withUnencryptedCookie(ShoppingCart::DEVICE_COOKIE, str_repeat('a', 64));

        $initial = $this->get('https://novinpaypos.test/cart')->assertOk();

        $ajaxAdd = $this->withHeaders(['X-Storefront-Cart-Html' => '1'])
            ->postJson('https://novinpaypos.test/cart/products/'.$this->product->slug, [
                'cart_context' => $initial->viewData('proof'),
                'cart_revision' => $initial->viewData('revision'),
                'quantity' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('count', 1);

        $this->assertNotEmpty($ajaxAdd->json('proof'));
        $this->assertNotEmpty($ajaxAdd->json('revision'));
        $this->assertStringContainsString('data-storefront-cart', (string) $ajaxAdd->json('html'));
        $this->assertStringNotContainsString('این دستگاه', (string) $ajaxAdd->json('html'));
        $this->assertStringNotContainsString('مدیریت سبدهای جداگانه', (string) $ajaxAdd->json('html'));
        $this->assertStringNotContainsString('حداکثر قابل انتخاب در این نما', (string) $ajaxAdd->json('html'));

        // Authenticated user can increase quantity in the cart drawer up to available stock (5)
        $this->postJson('https://novinpaypos.test/cart/products/'.$this->product->slug.'/quantity', [
            'cart_context' => $ajaxAdd->json('proof'),
            'cart_revision' => $ajaxAdd->json('revision'),
            'quantity' => 2,
        ])->assertOk()->assertJsonPath('count', 2);
    }

    public function test_reverse_geocode_draft_persistence_and_login_otp_button_loader_and_webotp(): void
    {
        $this->seedCartItem();

        // 1. Reverse geocoding degrades gracefully when NESHAN_SERVICE_KEY is empty
        config(['services.neshan.service_key' => '']);
        $this->getJson('https://novinpaypos.test/cart/checkout/reverse-geocode?lat=35.6597&lng=51.0592')
            ->assertOk()
            ->assertExactJson(['ok' => false, 'province' => null, 'city' => null, 'address' => null]);

        // Reverse geocoding suggests the editable street address along with the matched city.
        config(['services.neshan.service_key' => 'service.synthetic_neshan_key_456']);
        Http::fake([
            'https://api.neshan.org/v5/reverse*' => Http::response([
                'status' => 'OK',
                'state' => 'استان تهران',
                'city' => 'شهریار',
                'county' => 'شهرستان شهریار',
                'formatted_address' => 'استان تهران، شهریار، بلوار ولیعصر',
            ], 200),
        ]);

        $this->getJson('https://novinpaypos.test/cart/checkout/reverse-geocode?lat=35.6597&lng=51.0592')
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'province' => 'تهران',
                'city' => 'شهریار',
                'address' => 'استان تهران، شهریار، بلوار ولیعصر',
            ]);

        // 3. Draft persistence endpoint saves personal details, address, and map coordinates immediately on step advance
        $draftRes = $this->postJson('https://novinpaypos.test/cart/checkout/draft', [
            'full_name' => 'خریدار ذخیره خودکار',
            'mobile' => '۰۹۱۲۳۳۳۴۴۵۵',
            'national_code' => '۰۰۱۲۳۴۵۶۷۹',
            'email' => 'saved-draft@example.com',
            'address_mode' => 'new',
            'province' => 'تهران',
            'city' => 'شهریار',
            'street_address' => 'شهریار، خیابان ولیعصر، کوچه گلستان، پلاک ۱۲',
            'postal_code' => '۳۳۵۱۶۱۲۳۴۵',
            'latitude' => '35.659700',
            'longitude' => '51.059200',
            'method' => 'tipax',
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertNotEmpty($draftRes->json('address_id'));
        $this->assertSame('خریدار ذخیره خودکار', $this->account->fresh()->name);
        $this->assertSame('0012345679', $this->account->fresh()->national_code);

        // Returning to /cart/checkout automatically prefills saved details, province, city, street, postal code, and coordinates
        $revisit = $this->get('https://novinpaypos.test/cart/checkout')->assertOk();
        $revisit->assertSee('خریدار ذخیره خودکار')
            ->assertSee('0012345679')
            ->assertSee('شهریار، خیابان ولیعصر، کوچه گلستان، پلاک ۱۲')
            ->assertSee('3351612345')
            ->assertSee('35.659700');

        // 4. Verify customer-otp.js uses inline 3-dot loader (no gray top status notification) and supports .np-otp-boxes + WebOTP
        $otpJs = (string) file_get_contents(public_path('js/customer-otp.js'));
        $this->assertStringContainsString('np-btn-dots', $otpJs);
        $this->assertStringNotContainsString("notify('در حال بررسی درخواست…')", $otpJs);
        $this->assertStringContainsString('.np-otp, .np-otp-boxes', $otpJs);
        $this->assertStringContainsString("transport: ['sms']", $otpJs);
    }

    private function seedCartItem(): array
    {
        $this->actingAs($this->account, 'customer')
            ->withSession(['customer_session_version' => 1])
            ->withUnencryptedCookie(ShoppingCart::DEVICE_COOKIE, str_repeat('a', 64));

        $cart = $this->get('https://novinpaypos.test/cart')->assertOk();

        $this->post('https://novinpaypos.test/cart/products/'.$this->product->slug, [
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
