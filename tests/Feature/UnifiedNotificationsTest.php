<?php

namespace Tests\Feature;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\RepairBrand;
use App\Enums\StockStatus;
use App\Mail\UnifiedNotificationMail;
use App\Models\Consultation;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\RepairCase;
use App\Models\RepairRequest;
use App\Models\User;
use App\Services\Notifications\UnifiedNotifier;
use App\Services\SimpleRepairRecords;
use App\Services\StoreCheckout;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnifiedNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function enableAllChannels(string $telegramBase = 'https://api.telegram.org'): void
    {
        config([
            'customer.ippanel.enabled' => true,
            'customer.ippanel.api_key' => 'test-ippanel-key',
            'customer.ippanel.sender' => '+983000505',
            'notifications.admin.phones' => ['09121112233'],
            'notifications.admin.emails' => ['admin@novinpaypos.test'],
            'notifications.sms.enabled' => true,
            'notifications.pattern_sms.enabled' => false,
            'notifications.sms.patterns' => [
                'admin_order_placed' => 'patAdminOrder',
                'admin_repair_submitted' => 'patAdminRepair',
                'admin_consultation_submitted' => 'patAdminConsult',
                'admin_chat_first_message' => 'patAdminChatFirst',
                'customer_order_placed' => 'patCustOrderPlaced',
                'customer_order_shipped' => 'patCustOrderShipped',
                'customer_repair_submitted' => 'patCustRepairSub',
                'customer_repair_status' => 'patCustRepairStatus',
                'customer_consultation_submitted' => 'patCustConsult',
            ],
            'notifications.email.enabled' => true,
            'notifications.bale.enabled' => true,
            'notifications.bale.bot_token' => 'bale-test-token',
            'notifications.bale.chat_id' => '10001',
            'notifications.bale.api_base' => 'https://tapi.bale.ai',
            'notifications.telegram.enabled' => true,
            'notifications.telegram.bot_token' => 'tg-test-token',
            'notifications.telegram.chat_id' => '-100200300',
            'notifications.telegram.api_base' => $telegramBase,
        ]);
    }

    private function fakeSuccessTransports(): void
    {
        Mail::fake();
        Http::fake([
            'https://edge.ippanel.com/*' => Http::response(['meta' => ['status' => true], 'data' => ['message_outbox_ids' => [9001]]], 200),
            'https://tapi.bale.ai/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 2]], 200),
            'https://tg-proxy.example.test/*' => Http::response(['ok' => true, 'result' => ['message_id' => 3]], 200),
        ]);
    }

    public function test_order_placed_notifies_admins_on_all_channels_and_customer_on_sms_once(): void
    {
        $this->enableAllChannels();
        $this->fakeSuccessTransports();

        $customer = new Customer;
        $customer->forceFill([
            'public_id' => (string) Str::uuid(),
            'name' => 'مشتری سفارش',
            'name_digest' => RepairIdentity::digest('customer-name', 'مشتری سفارش'),
            'contact_phone' => '09123334455',
            'phone_digest' => RepairIdentity::digest('customer-phone', '09123334455'),
            'identity_digest' => RepairIdentity::digest('customer-identity', 'مشتری سفارش|09123334455'),
            'is_active' => true,
            'version' => 1,
        ])->save();

        $account = new CustomerAccount;
        $account->forceFill([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'name' => 'مشتری سفارش',
            'phone' => '09123334455',
            'phone_digest' => RepairIdentity::digest('customer-account-phone', '09123334455'),
            'verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
        ])->save();

        $product = Product::create([
            'name' => 'کارتخوان تست',
            'slug' => 'pos-notify-test',
            'category' => ProductCategory::PosTerminal,
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::InStock,
            'price' => 4500000,
            'is_published' => true,
            'is_sample' => false,
            'online_purchasing_enabled' => true,
        ]);

        $item = new InventoryItem;
        $item->forceFill([
            'public_id' => (string) Str::uuid(),
            'product_id' => $product->id,
            'sku' => 'SKU-NOTIFY-1',
            'name' => 'کارتخوان تست',
            'condition' => ProductCondition::New->value,
            'on_hand' => 10,
            'reserved' => 0,
            'serialized' => false,
            'version' => 1,
        ])->save();

        $checkoutKey = (string) Str::uuid();
        $checkout = app(StoreCheckout::class);
        $order = $checkout->create($account, ['pos-notify-test' => 1], $checkoutKey);

        // Idempotent replay must NOT resend notifications.
        $checkout->create($account, ['pos-notify-test' => 1], $checkoutKey);

        Mail::assertSent(UnifiedNotificationMail::class, 1);
        Mail::assertSent(UnifiedNotificationMail::class, fn (UnifiedNotificationMail $mail) => $mail->hasTo('admin@novinpaypos.test'));

        // Unpaid order creation must NOT send Telegram or Bale notifications (those fire only after verified payment settlement).
        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://edge.ippanel.com/v1/api/send'
            && $req['code'] === 'patAdminOrder' && $req['recipients'] === ['+989121112233']);
        Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://edge.ippanel.com/v1/api/send'
            && $req['code'] === 'patCustOrderPlaced' && $req['recipients'] === ['+989123334455']);
        Http::assertNotSent(fn (HttpRequest $req) => str_contains($req->url(), 'api.telegram.org'));
        Http::assertNotSent(fn (HttpRequest $req) => str_contains($req->url(), 'tapi.bale.ai'));

        // Also verify orderShipped sends customer SMS + Email.
        app(UnifiedNotifier::class)->orderShipped($order, 'TIPAX-9988', 'buyer@example.test');
        Mail::assertSent(UnifiedNotificationMail::class, fn (UnifiedNotificationMail $mail) => $mail->hasTo('buyer@example.test'));
        Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://edge.ippanel.com/v1/api/send'
            && $req['code'] === 'patCustOrderShipped' && $req['params']['tracking'] === 'TIPAX-9988');
    }

    public function test_repair_request_and_status_update_dispatch_admin_and_customer_notifications(): void
    {
        $this->enableAllChannels();
        config([
            'observability.telegram_topics.enabled' => true,
            'observability.telegram_topics.bot_token' => 'tg-topics-token',
            'observability.telegram_topics.chat_id' => '-1005775833170',
            'observability.telegram_topics.api_base' => 'https://api.telegram.org',
            'observability.telegram_topics.threads' => ['repairs' => 13],
        ]);
        $this->fakeSuccessTransports();

        $account = new CustomerAccount;
        $account->forceFill([
            'public_id' => (string) Str::uuid(),
            'name' => 'مشتری تعمیر',
            'phone' => '09127778899',
            'phone_digest' => RepairIdentity::digest('customer-account-phone', '09127778899'),
            'verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
        ])->save();

        $token = bin2hex(random_bytes(32));
        $this->actingAs($account, 'customer')
            ->withSession(['customer_session_version' => 1, 'repair_submission_token' => $token])
            ->post('/repair', [
                'submission_token' => $token,
                'customer_name' => 'مشتری تعمیر',
                'contact_phone' => '09127778899',
                'device_brand' => RepairBrand::Pax->value,
                'device_model' => 'S90',
                'issue' => 'دستگاه روشن نمی‌شود',
            ])->assertRedirect(route('repair.confirmation'));

        $this->assertDatabaseCount('repair_requests', 1);
        Mail::assertSent(UnifiedNotificationMail::class, fn (UnifiedNotificationMail $mail) => $mail->hasTo('admin@novinpaypos.test'));
        Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://edge.ippanel.com/v1/api/send'
            && $req['code'] === 'patAdminRepair' && $req['recipients'] === ['+989121112233']);
        Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://edge.ippanel.com/v1/api/send'
            && $req['code'] === 'patCustRepairSub' && $req['recipients'] === ['+989127778899']);
        Http::assertSent(fn (HttpRequest $req) => str_starts_with($req->url(), 'https://tapi.bale.ai/'));
        Http::assertNotSent(fn (HttpRequest $req) => ($req['chat_id'] ?? null) === '-100200300');
        Http::assertSent(fn (HttpRequest $req) => str_starts_with($req->url(), 'https://api.telegram.org/bottg-topics-token/sendMessage')
            && ($req['chat_id'] ?? null) === '-1005775833170'
            && ($req['message_thread_id'] ?? null) === 13);

        // Now verify repair case status update to 'ready' with cost notifies customer.
        $admin = User::factory()->superAdmin()->create();
        $intakeToken = bin2hex(random_bytes(32));
        $this->actingAs($admin, 'web')
            ->withSession(['repair_intake_token' => $intakeToken])
            ->post('/admin/repairs/intake', [
                'simple_intake' => '1',
                'submission_token' => $intakeToken,
                'customer_name' => 'مشتری تعمیر',
                'contact_phone' => '09127778899',
                'device_model' => 'PAX S90',
                'device_serial' => '',
                'issue' => 'خرابی برد',
                'physical_condition' => 'سالم',
            ])->assertRedirect();

        $case = RepairCase::query()->sole();
        app(SimpleRepairRecords::class)->save($admin, $case, [
            'version' => $case->version,
            'status' => 'ready',
            'repair_cost' => '450000',
            'delivery_method' => 'in_person',
        ]);

        Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://edge.ippanel.com/v1/api/send'
            && $req['code'] === 'patCustRepairStatus'
            && $req['recipients'] === ['+989127778899']
            && ($req['params']['cost'] ?? null) === '450000');
    }

    public function test_consultation_request_notifies_admins_and_customer(): void
    {
        $this->enableAllChannels('https://tg-proxy.example.test');
        $this->fakeSuccessTransports();

        $token = bin2hex(random_bytes(32));
        $this->withSession(['consultation_token' => $token])
            ->post('/consultation', [
                'submission_token' => $token,
                'customer_name' => 'متقاضی مشاوره',
                'contact_phone' => '09125556677',
                'topic' => array_key_first(Consultation::TOPICS),
                'question' => 'کدام مدل آنتن‌دهی بهتری دارد؟',
            ])->assertRedirect(route('consultation.confirmation'));

        $this->assertDatabaseCount('consultations', 1);
        Mail::assertSent(UnifiedNotificationMail::class, fn (UnifiedNotificationMail $mail) => $mail->hasTo('admin@novinpaypos.test'));
        Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://edge.ippanel.com/v1/api/send'
            && $req['code'] === 'patAdminConsult' && $req['recipients'] === ['+989121112233']);
        Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://edge.ippanel.com/v1/api/send'
            && $req['code'] === 'patCustConsult' && $req['recipients'] === ['+989125556677']);
        Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://tapi.bale.ai/botbale-test-token/sendMessage');
        // Confirms optional Telegram proxy/worker base URL works.
        Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://tg-proxy.example.test/bottg-test-token/sendMessage');
    }

    public function test_support_chat_sends_bale_and_telegram_always_but_sms_only_on_first_message_per_conversation(): void
    {
        $this->enableAllChannels();
        $this->fakeSuccessTransports();

        $notifier = app(UnifiedNotifier::class);

        // First message in conv-alpha -> Bale + Telegram + 1 SMS
        $notifier->supportChatMessage('conv-alpha', 'سلام، درباره کارتخوان سوال دارم', 'علی');
        // Second and third messages in conv-alpha -> Bale + Telegram ONLY (no extra SMS!)
        $notifier->supportChatMessage('conv-alpha', 'آیا مدل سیار موجود دارید؟', 'علی');
        $notifier->supportChatMessage('conv-alpha', 'قیمتش چقدر است؟', 'علی');
        // First message in a DIFFERENT conversation conv-beta -> Bale + Telegram + 1 SMS
        $notifier->supportChatMessage('conv-beta', 'سلام، دستگاه من ارور می‌دهد', 'زهرا');

        $this->assertSame(2, DB::table('support_chat_notifications')->count());

        $sent = Http::recorded();
        $smsCount = 0;
        $baleCount = 0;
        $telegramCount = 0;
        foreach ($sent as [$request]) {
            if ($request->url() === 'https://edge.ippanel.com/v1/api/send' && $request['code'] === 'patAdminChatFirst') {
                $smsCount++;
            } elseif (str_starts_with($request->url(), 'https://tapi.bale.ai/')) {
                $baleCount++;
            } elseif (str_starts_with($request->url(), 'https://api.telegram.org/')) {
                $telegramCount++;
            }
        }

        $this->assertSame(2, $smsCount, 'IPPanel SMS must be sent exactly once per conversation.');
        $this->assertSame(4, $baleCount, 'Bale must be notified on every customer chat message.');
        $this->assertSame(4, $telegramCount, 'Telegram must be notified on every customer chat message.');
    }

    public function test_third_party_notification_failures_never_break_business_flows(): void
    {
        $this->enableAllChannels();

        Http::fake([
            'https://edge.ippanel.com/*' => Http::response(['meta' => ['status' => false]], 500),
            'https://tapi.bale.ai/*' => Http::response(['ok' => false], 502),
            'https://api.telegram.org/*' => fn () => throw new ConnectionException('Telegram timeout'),
        ]);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP connection refused'));

        $token = bin2hex(random_bytes(32));
        $this->withSession(['consultation_token' => $token])
            ->post('/consultation', [
                'submission_token' => $token,
                'customer_name' => 'مشتری مقاوم در برابر خطا',
                'contact_phone' => '09128889900',
                'topic' => array_key_first(Consultation::TOPICS),
                'question' => 'آیا خطای نوتیفیکیشن جلوی ثبت را می‌گیرد؟',
            ])->assertRedirect(route('consultation.confirmation'));

        $this->assertDatabaseCount('consultations', 1);
        $this->assertSame('مشتری مقاوم در برابر خطا', Consultation::query()->sole()->customer_name);

        // Also verify repair request still commits even if every channel fails.
        $account = new CustomerAccount;
        $account->forceFill([
            'public_id' => (string) Str::uuid(),
            'name' => 'مشتری تعمیر مقاوم',
            'phone' => '09129990011',
            'phone_digest' => RepairIdentity::digest('customer-account-phone', '09129990011'),
            'verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
        ])->save();

        $repairToken = bin2hex(random_bytes(32));
        $this->actingAs($account, 'customer')
            ->withSession(['customer_session_version' => 1, 'repair_submission_token' => $repairToken])
            ->post('/repair', [
                'submission_token' => $repairToken,
                'customer_name' => 'مشتری تعمیر مقاوم',
                'contact_phone' => '09129990011',
                'device_brand' => RepairBrand::Newland->value,
                'device_model' => 'ME31',
                'issue' => 'خرابی چاپگر',
            ])->assertRedirect(route('repair.confirmation'));

        $this->assertDatabaseCount('repair_requests', 1);
        $this->assertSame('مشتری تعمیر مقاوم', RepairRequest::query()->sole()->customer_name);
    }
}
