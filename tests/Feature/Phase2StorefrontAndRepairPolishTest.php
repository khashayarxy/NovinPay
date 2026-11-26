<?php

namespace Tests\Feature;

use App\Models\CustomerAccount;
use App\Models\RepairRequest;
use App\Models\User;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase2StorefrontAndRepairPolishTest extends TestCase
{
    use RefreshDatabase;

    private function createVerifiedCustomer(string $phone = '09123456789'): CustomerAccount
    {
        $account = new CustomerAccount;
        $account->public_id = (string) Str::uuid();
        $account->phone = $phone;
        $account->phone_digest = RepairIdentity::digest('customer-account-phone', $phone);
        $account->verified_at = now();
        $account->is_active = true;
        $account->session_version = 1;
        $account->save();

        return $account->fresh();
    }

    public function test_repair_confirmation_renders_unified_split_card_for_all_handover_modes(): void
    {
        foreach ([
            'self_ship' => 'اطلاعات گیرنده جهت ارسال',
            'in_person' => 'مسیریابی با:',
            'doorstep_pickup' => 'درخواست دریافت با پیک نوین‌پی ثبت شده است',
        ] as $handover => $expectedGuidance) {
            $response = $this->withSession([
                'repair_reference' => '87654321',
                'repair_handover' => $handover,
            ])->get(route('repair.confirmation'));

            $response->assertOk();
            $response->assertSee('repair-split-card', false);
            $response->assertSee('repair-confirm-main', false);
            $response->assertSee('repair-confirm-aside', false);
            $response->assertSee('87654321');
            $response->assertSee('پیگیری وضعیت تعمیر');
            $response->assertSee('تماس با نوین‌پی');
            $response->assertSee($expectedGuidance);
        }
    }

    public function test_repair_courier_step_has_other_person_checkbox_and_persists_pickup_notes_to_admin_and_telegram(): void
    {
        config([
            'observability.telegram_topics.enabled' => true,
            'observability.telegram_topics.bot_token' => '123456:TEST_BOT_TOKEN_SECRET',
            'observability.telegram_topics.chat_id' => '-100999888777',
            'observability.telegram_topics.threads.repairs' => 13,
        ]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

        $customer = $this->createVerifiedCustomer();
        $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1]);

        $page = $this->get(route('repair'));
        $page->assertOk();
        $page->assertSee('فرد دیگری دستگاه را به پیک تحویل می‌دهد');
        $page->assertSee('data-other-person-toggle', false);
        $page->assertSee('data-other-person-field', false);
        $page->assertSee('repair-rtl-phone-input', false);
        $page->assertSee('توضیحات برای پیک (اختیاری)');
        $page->assertSee('name="pickup_notes"', false);

        $token = session('repair_submission_token');
        $this->post(route('repair.store'), [
            'customer_name' => 'سارا محمدی',
            'device_brand' => 'Newland',
            'device_model' => 'ME31',
            'issue' => 'چاپگر رسید را کمرنگ چاپ می‌کند و نیاز به سرویس دارد.',
            'province' => 'تهران',
            'city' => 'شهریار',
            'handover_method' => 'doorstep_pickup',
            'pickup_address' => 'شهریار، خیابان ولیعصر، کوچه چهارم، پلاک ۱۰',
            'pickup_notes' => 'ساعت ۱۶ تا ۱۹ مراجعه شود، زنگ واحد ۳',
            'other_person_handover' => '1',
            'responsive_phone' => '۰۹۳۵۱۱۱۲۲۳۳',
            'submission_token' => $token,
        ])->assertRedirect(route('repair.confirmation'));

        $repair = RepairRequest::query()->sole();
        $parsed = $repair->parsedIssue();
        $this->assertSame('09351112233', $parsed['responsive_phone']);
        $this->assertSame('ساعت ۱۶ تا ۱۹ مراجعه شود، زنگ واحد ۳', $parsed['pickup_notes']);

        // Customer account repair page displays pickup_notes
        $this->get(route('account.repairs.request', $repair->tracking_reference))
            ->assertOk()
            ->assertSee('توضیحات برای پیک')
            ->assertSee('ساعت ۱۶ تا ۱۹ مراجعه شود، زنگ واحد ۳');

        // Telegram repairs topic includes courier address, alternate phone, and pickup_notes
        Http::assertSent(function (HttpRequest $request): bool {
            if (($request['message_thread_id'] ?? null) !== 13) {
                return false;
            }
            $text = (string) ($request['text'] ?? '');

            return str_contains($text, 'توضیحات پیک: ساعت ۱۶ تا ۱۹ مراجعه شود، زنگ واحد ۳')
                && str_contains($text, 'تلفن هماهنگی: 09351112233');
        });

        // Admin repair request detail page displays structured courier notes & alternate phone
        $owner = User::factory()->superAdmin()->create();
        $this->actingAs($owner, 'web')->withSession(['admin_session_version' => 1]);
        $this->get(route('admin.repairs.show', $repair))
            ->assertOk()
            ->assertSee('توضیحات برای پیک')
            ->assertSee('ساعت ۱۶ تا ۱۹ مراجعه شود، زنگ واحد ۳')
            ->assertSee('شماره تماس تحویل‌دهنده');
    }

    public function test_method_specific_repair_created_sms_patterns_fallback_to_generic_and_use_dedicated_when_configured(): void
    {
        config([
            'notifications.pattern_sms.enabled' => true,
            'notifications.pattern_sms.provider' => 'ippanel',
            'customer.sms_provider' => 'ippanel',
            'customer.ippanel.enabled' => true,
            'customer.ippanel.api_key' => 'test-ippanel-key',
            'customer.ippanel.sender' => '+983000505',
            'customer.ippanel.pattern_code' => 'otpOnly',
        ]);
        Http::fake(['https://edge.ippanel.com/v1/api/send' => Http::response(['meta' => ['status' => true], 'data' => ['message_outbox_ids' => [901]]])]);

        $customer = $this->createVerifiedCustomer('09127778899');
        $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1]);

        // Case 1: Method-specific patterns unset -> automatically fall back to generic repair_created (309p3u2jo5b7qds)
        config([
            'notifications.pattern_sms.patterns' => [
                'repair_created' => '309p3u2jo5b7qds',
                'repair_created_in_person' => '',
                'repair_created_postal' => '',
                'repair_created_courier' => '',
            ],
        ]);
        Cache::store('database')->flush();

        $this->get(route('repair'));
        $this->post(route('repair.store'), [
            'customer_name' => 'مشتری پستی فالبک',
            'device_brand' => 'PAX',
            'device_model' => 'S90',
            'issue' => 'دستگاه روشن نمی‌شود و باتری شارژ نگه نمی‌دارد.',
            'province' => 'فارس',
            'city' => 'شیراز',
            'handover_method' => 'self_ship',
            'submission_token' => session('repair_submission_token'),
        ])->assertRedirect(route('repair.confirmation'));

        Http::assertSent(fn (HttpRequest $req) => $req['code'] === '309p3u2jo5b7qds');

        // Case 2: Dedicated method-specific patterns configured -> each handover method uses its own pattern code
        config([
            'notifications.pattern_sms.patterns' => [
                'repair_created' => '309p3u2jo5b7qds',
                'repair_created_in_person' => 'patInPerson111',
                'repair_created_postal' => 'patPostal222',
                'repair_created_courier' => 'patCourier333',
            ],
        ]);

        foreach ([
            ['in_person', 'تهران', 'شهریار', [], 'patInPerson111'],
            ['self_ship', 'اصفهان', 'اصفهان', [], 'patPostal222'],
            ['doorstep_pickup', 'تهران', 'شهریار', ['pickup_address' => 'شهریار، میدان فرمانداری، پلاک ۵'], 'patCourier333'],
        ] as [$method, $prov, $city, $extra, $expectedCode]) {
            Cache::flush();
            Cache::store('database')->flush();
            $this->get(route('repair'));
            $this->post(route('repair.store'), array_merge([
                'customer_name' => 'مشتری تست الگو',
                'device_brand' => 'Newland',
                'device_model' => 'ME31',
                'issue' => 'صفحه نمایش خطوط عمودی دارد و کیپد کار نمی‌کند.',
                'province' => $prov,
                'city' => $city,
                'handover_method' => $method,
                'submission_token' => session('repair_submission_token'),
            ], $extra))->assertRedirect(route('repair.confirmation'));

            Http::assertSent(fn (HttpRequest $req) => $req['code'] === $expectedCode);
        }
    }
}
