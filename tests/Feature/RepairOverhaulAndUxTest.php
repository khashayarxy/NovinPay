<?php

namespace Tests\Feature;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\StockStatus;
use App\Models\CustomerAccount;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductFeedback;
use App\Models\RepairRequest;
use App\Models\User;
use App\Services\CommerceManagement;
use App\Services\CustomerLogin;
use App\Services\ShoppingCart;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RepairOverhaulAndUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function createCustomer(string $phone = '09123456789'): CustomerAccount
    {
        $account = new CustomerAccount;
        $account->public_id = (string) Str::uuid();
        $account->phone = $phone;
        $account->phone_digest = RepairIdentity::digest('customer-account-phone', $phone);
        $account->verified_at = now();
        $account->save();

        return $account->fresh();
    }

    public function test_repair_page_shows_no_friction_stepper_guidance_preview_and_inline_otp_auth(): void
    {
        $account = $this->createCustomer();

        $response = $this->get('/repair')
            ->assertOk()
            ->assertSee('data-repair-stepper', false)
            ->assertSee('data-repair-map', false)
            ->assertSee('© نقشه نشان')
            ->assertSee('data-is-guest="1"', false)
            ->assertSee('data-repair-inline-auth', false)
            ->assertSee('data-upgrade-estimate', false)
            ->assertSee('خودم ارسال میکنم')
            ->assertSee('خودم حضوری میارم')
            ->assertSee('به پیک شما تحویل میدم')
            ->assertSee('نشانی دقیق ارسال پستی و راهنمای بسته‌بندی بلافاصله پس از ثبت نهایی نمایش داده می‌شود.')
            ->assertSee('مسیریابی با:')
            ->assertDontSee('repair-overview-strip')
            ->assertDontSee('repair-entry-notes')
            ->assertDontSee('به شماره موبایل خود دسترسی ندارید؟')
            ->assertDontSee('repair-photo-grid');

        $csp = (string) $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertStringContainsString('https://*.neshan.org', $csp);
        $this->assertStringNotContainsString('openstreetmap', $csp);
        $this->assertStringNotContainsString('arcgisonline', $csp);
        $this->assertStringNotContainsString('cartocdn', $csp);

        $login = $this->partialMock(CustomerLogin::class);
        $login->shouldReceive('issue')->once()->with('09123456789', \Mockery::type('callable'))
            ->andReturnUsing(function (string $phone, callable $reserve): string {
                $reserve('inline-challenge');

                return 'inline-challenge';
            });
        $login->shouldReceive('verify')->once()->with('inline-challenge', '654321')->andReturn($account);

        $this->postJson(route('repair.auth.send'), ['contact_phone' => '۰۹۱۲۳۴۵۶۷۸۹'])
            ->assertOk()
            ->assertJson(['ok' => true, 'phone' => '۰۹۱۲۳۴۵۶۷۸۹']);

        $this->postJson(route('repair.auth.verify'), ['code' => '۶۵۴۳۲۱'])
            ->assertOk()
            ->assertJsonStructure(['ok', 'redirect', 'csrf_token', 'submission_token', 'phone'])
            ->assertJson(['ok' => true, 'phone' => '۰۹۱۲۳۴۵۶۷۸۹']);

        $this->assertAuthenticatedAs($account, 'customer');
    }

    public function test_repair_doorstep_pickup_is_gated_by_approved_courier_zones_and_stores_4_side_compressed_photos(): void
    {
        Storage::fake('local');
        $account = $this->createCustomer();
        $this->actingAs($account, 'customer')->withSession(['customer_session_version' => 1]);

        $this->get('/repair')->assertOk();
        $token = session('repair_submission_token');

        // Reject doorstep pickup in an unsupported city.
        $this->post('/repair', [
            'customer_name' => 'مشتری آزمایشی',
            'device_brand' => 'PAX',
            'device_model' => 'S90',
            'issue' => 'دستگاه روشن نمی‌شود و نیاز به بررسی فنی دارد.',
            'province' => 'اصفهان',
            'city' => 'اصفهان',
            'handover_method' => 'doorstep_pickup',
            'pickup_address' => 'اصفهان، خیابان چهارباغ عباسی، پلاک ۱۲',
            'submission_token' => $token,
        ])->assertRedirect(route('repair'))->assertSessionHasErrors('handover_method');
        $this->assertDatabaseCount('repair_requests', 0);

        // Allow doorstep pickup in approved city (تهران / شهریار) with 4-side photos.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAEklEQVR42mP8/5+hngEIGGEMAAo5BAJ1m78eAAAAAElFTkSuQmCC');
        $this->post('/repair', [
            'customer_name' => 'مشتری شهریار',
            'device_brand' => 'PAX',
            'device_model' => 'S90',
            'issue' => 'درخواست ارتقای ماژول 2G به 4G و بررسی سلامت باتری.',
            'province' => 'تهران',
            'city' => 'شهریار',
            'handover_method' => 'doorstep_pickup',
            'pickup_address' => 'شهریار، بلوار ولیعصر، کوچه دوم، پلاک ۱۸',
            'pickup_latitude' => '35.659700',
            'pickup_longitude' => '51.059200',
            'responsive_phone' => '۰۹۳۶۲۸۹۶۳۷۳',
            'device_photos' => [
                'front' => UploadedFile::fake()->createWithContent('front.png', $png),
                'back' => UploadedFile::fake()->createWithContent('back.png', $png),
                'right' => UploadedFile::fake()->createWithContent('right.png', $png),
                'left' => UploadedFile::fake()->createWithContent('left.png', $png),
            ],
            'submission_token' => $token,
        ])->assertRedirect(route('repair.confirmation'));

        $repair = RepairRequest::query()->sole();
        $this->assertStringContainsString('تهران - شهریار', $repair->issue);
        $this->assertStringContainsString('به پیک شما تحویل میدم', $repair->issue);
        $this->assertStringContainsString('شهریار، بلوار ولیعصر', $repair->issue);
        $this->assertStringContainsString('09362896373', $repair->issue);

        $sidePhotos = $repair->existingSidePhotos();
        $this->assertSame(['front', 'back', 'right', 'left'], array_keys($sidePhotos));
        foreach ($sidePhotos as $path) {
            Storage::disk('local')->assertExists($path);
        }

        // Admin can view links and download each compressed side photo.
        $this->actingAs(User::factory()->superAdmin()->create(), 'web')
            ->withSession(['admin_session_version' => 1]);

        $this->get(route('admin.repairs.show', $repair))
            ->assertOk()
            ->assertSee('نمای جلو')
            ->assertSee('نمای پشت')
            ->assertSee('سمت راست')
            ->assertSee('سمت چپ');

        $this->get(route('admin.repairs.photo', [$repair, 'side' => 'front']))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_account_reviews_section_and_simplified_orders_render_cleanly(): void
    {
        $account = $this->createCustomer();
        $product = Product::query()->create([
            'slug' => 'pax-s90-test-review',
            'name' => 'کارتخوان PAX S90',
            'category' => ProductCategory::PosTerminal,
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::InStock,
            'price' => 4500000,
            'price_changed_at' => now(),
            'is_published' => true,
            'is_sample' => false,
        ]);

        $feedback = new ProductFeedback;
        $feedback->product_id = $product->id;
        $feedback->customer_account_id = $account->id;
        $feedback->review_owner_id = $account->id;
        $feedback->kind = 'review';
        $feedback->status = 'approved';
        $feedback->rating = 5;
        $feedback->body = 'آنتن‌دهی عالی و شارژدهی بسیار مناسب در استفاده روزانه.';
        $feedback->verified_buyer = true;
        $feedback->save();

        $this->actingAs($account, 'customer')->withSession(['customer_session_version' => 1]);

        $this->get('/account?section=orders')
            ->assertOk()
            ->assertSee('سابقه سفارش‌ها')
            ->assertSee('دیدگاه‌ها');

        $this->get('/account?section=reviews')
            ->assertOk()
            ->assertSee('دیدگاه‌ها و امتیازهای شما')
            ->assertSee('کارتخوان PAX S90')
            ->assertSee('آنتن‌دهی عالی و شارژدهی بسیار مناسب');
    }

    public function test_product_page_exposes_rtl_dock_inline_stepper_and_added_bar_and_cart_json_quantities(): void
    {
        $product = Product::query()->create([
            'slug' => 'newland-me31-ux-test',
            'name' => 'کارتخوان Newland ME31',
            'category' => ProductCategory::PosTerminal,
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::InStock,
            'price' => 5200000,
            'online_purchasing_enabled' => true,
            'is_published' => true,
            'is_sample' => false,
        ]);
        $product->price_changed_at = now();
        $product->save();
        $owner = User::factory()->superAdmin()->create();
        $commerce = app(CommerceManagement::class);
        $itemId = $commerce->execute($owner, 'item.create', [
            'operation_key' => (string) Str::uuid(),
            'sku' => 'ME31-UX-1',
            'name' => 'ME31',
            'condition' => ProductCondition::New->value,
            'serialized' => false,
            'product_id' => $product->id,
            'low_stock_threshold' => 0,
            'reason' => 'Product UX test',
        ]);
        $item = InventoryItem::findOrFail($itemId);
        $commerce->execute($owner, 'item.receive', [
            'operation_key' => (string) Str::uuid(),
            'version' => $item->version,
            'quantity' => 5,
            'reason' => 'Product UX test',
        ], $item);

        $cookie = str_repeat('b', 64);

        $this->withCookie(ShoppingCart::DEVICE_COOKIE, $cookie)
            ->get(route('products.show', $product))
            ->assertOk()
            ->assertSeeInOrder(['product-dock-action', 'افزودن به سبد خرید', 'class="price"'], false)
            ->assertSee('data-inline-stepper', false)
            ->assertSee('کالا اضافه شد!')
            ->assertSee('برو به سبد خرید');

        $cartData = $this->withCookie(ShoppingCart::DEVICE_COOKIE, $cookie)
            ->get(route('cart.index'))->assertOk()->original->getData();
        $this->withCookie(ShoppingCart::DEVICE_COOKIE, $cookie)
            ->withHeaders(['Accept' => 'application/json', 'X-Storefront-Cart-Html' => '1'])
            ->post(route('cart.add', $product), [
                'quantity' => 1,
                'cart_context' => $cartData['proof'],
                'cart_revision' => $cartData['revision'],
            ])
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('quantities.newland-me31-ux-test', 1);
    }

    public function test_repair_track_page_has_guide_card_text_link_and_no_location_map(): void
    {
        $this->get(route('repair.track'))
            ->assertOk()
            ->assertSee('repair-track-layout', false)
            ->assertSee('مراحل و راهنمای پیگیری تعمیر')
            ->assertSee('class="public-inline-link" href="'.route('repair').'"', false)
            ->assertDontSee('np-location');
    }

    public function test_support_chat_widget_shell_contains_faq_tab_and_phone_without_extra_messengers(): void
    {
        $channels = config('business.channels');
        $this->assertArrayNotHasKey('telegram', $channels);
        $this->assertArrayNotHasKey('whatsapp', $channels);
        $this->assertArrayNotHasKey('bale', $channels);
        $this->assertSame('09362896373', $channels['phone']);

        $html = file_get_contents(resource_path('support-chat/support-client/widget-v2.html'));
        $this->assertStringContainsString('data-support-tab="faq"', $html);
        $this->assertStringContainsString('سوالات متداول', $html);
        $this->assertStringContainsString('supportFaqSearch', $html);
        $this->assertStringNotContainsString('supportPhoneRow', $html);
        $this->assertStringNotContainsString('supportChannels', $html);
    }
}
