<?php

namespace Tests\Feature;

use App\Models\CustomerAccount;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\User;
use App\Services\CommerceManagement;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class PremiumStorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('https://novinpaypos.test');
        URL::forceScheme('https');
    }

    public function test_shared_design_applies_to_product_routes_and_keeps_me31_offer_data(): void
    {
        $product = $this->product(attributes: ['color' => 'مشکی', 'color_swatch' => '#131820']);
        $product->forceFill(['price_changed_at' => null])->save();
        $this->get(route('products.show', $product))->assertOk()
            ->assertSee('storefront-premium.css', false)->assertSee('mobile-bottom-nav', false)
            ->assertSee('۱۰٬۵۰۰٬۰۰۰')->assertSee('۶ ماه گارانتی نوین‌پی')->assertSee('استوک')
            ->assertSee('aria-label="مشکی"', false)->assertSee('aria-current="true"', false)
            ->assertSee('رنگ: <strong>مشکی</strong>', false)
            ->assertSee('موجودی تأیید نشده')->assertDontSee('تماس برای هماهنگی خرید')
            ->assertDontSee('آخرین به‌روزرسانی قیمت')->assertDontSee('id="product-question"', false)
            ->assertDontSee('action="'.route('cart.add', $product).'"', false)
            ->assertSee('105000000')->assertSee('UsedCondition')
            ->assertDontSee('"availability"', false);
        $sparse = $this->product('peripheral-without-specs', ['category' => 'peripheral', 'price' => null,
            'color' => null, 'warranty_months' => null, 'product_details' => ['sale_paused' => true,
                'groups' => ['hardware' => 'سطر بدون مقدار', 'services' => 'خدمات: بررسی فنی']]]);
        $this->get(route('products.show', $sparse))->assertOk()
            ->assertSee('storefront-premium.css')->assertSee('mobile-bottom-nav')
            ->assertSee('data-mobile-header', false)->assertSee('قیمت اعلام نشده')->assertSee('فروش موقتاً متوقف است')
            ->assertSee('بررسی فنی')->assertSee('تصویر واقعی کالا هنوز ثبت نشده است.')
            ->assertDontSee('href="#product-specs"', false)->assertDontSee('id="product-specs"', false)
            ->assertDontSee('id="product-description"', false)->assertDontSee('id="product-faq"', false)
            ->assertDontSee('data-product-sections', false)->assertDontSee('id="product-related"', false)
            ->assertDontSee('data-gallery-open', false)->assertDontSee('product-color-picker', false)
            ->assertDontSee('افزودن به سبد خرید');
        $this->get(route('home'))->assertOk()->assertDontSee('storefront-premium.css');
        $this->get(route('products.index'))->assertOk()->assertDontSee('storefront-premium.css');
        $this->post(route('cart.add', $product), ['quantity' => 1])->assertConflict();
        $product->forceFill(['price_changed_at' => '2026-11-17 08:00:00'])->save();
        $this->get(route('products.show', $product))->assertOk()
            ->assertSee('آخرین به‌روزرسانی قیمت')->assertSee('2026-11-17T08:00:00+00:00');
    }

    public function test_published_unavailable_variants_remain_selectable_without_a_fake_offer(): void
    {
        $product = $this->product('fixture-black', ['variant_family' => 'fixture-family', 'color' => 'مشکی',
            'image_path' => 'products/fixture-black.webp',
            'media_details' => ['gallery' => [['path' => 'products/fixture-black-side.webp']]],
            'product_details' => ['content' => ['uses' => 'کاربری ثبت‌شده برای دستگاه']]]);
        $other = $this->product('fixture-blue', ['variant_family' => 'fixture-family', 'color' => 'آبی',
            'stock_status' => 'out_of_stock', 'price' => 12000000, 'image_path' => 'products/fixture-blue.webp']);
        $this->product('fixture-hidden', ['variant_family' => 'fixture-family', 'color' => 'قرمز', 'is_published' => false]);
        $this->get(route('products.show', $product))->assertOk()->assertSee(route('products.show', $other), false)
            ->assertSee('مشاهدهٔ رنگ آبی')->assertSee('ناموجود')->assertDontSee('fixture-hidden')
            ->assertSee('fixture-black.webp')->assertSee('fixture-black-side.webp')->assertDontSee('fixture-blue.webp')
            ->assertSee('data-gallery-choice', false)->assertSee('کاربری ثبت‌شده برای دستگاه')
            ->assertSee('۶ ماه گارانتی')->assertDontSee('۶ ماه گارانتی نوین‌پی');
        $this->get(route('products.show', $other))->assertOk()->assertSee('۱۲٬۰۰۰٬۰۰۰')
            ->assertSee('fixture-blue.webp')->assertDontSee('fixture-black.webp')->assertDontSee('افزودن به سبد خرید')
            ->assertSee('data-gallery-open', false)->assertDontSee('data-gallery-choice', false);
        $this->post(route('cart.add', $other), ['quantity' => 1])->assertConflict();
    }

    public function test_real_online_eligibility_retains_existing_cart_submission(): void
    {
        $product = $this->product('fixture-online', ['stock_status' => 'in_stock', 'online_purchasing_enabled' => true]);
        $owner = User::factory()->superAdmin()->create();
        $commerce = app(CommerceManagement::class);
        $id = $commerce->execute($owner, 'item.create', ['operation_key' => (string) Str::uuid(),
            'sku' => 'UI-FIXTURE', 'name' => 'UI fixture stock', 'condition' => 'used', 'serialized' => false,
            'product_id' => $product->id, 'low_stock_threshold' => 0, 'reason' => 'Isolated UI validation']);
        $item = InventoryItem::findOrFail($id);
        $commerce->execute($owner, 'item.receive', ['operation_key' => (string) Str::uuid(),
            'version' => $item->version, 'quantity' => 2, 'reason' => 'Isolated UI validation'], $item);
        $this->get(route('products.show', $product))->assertOk()
            ->assertSee('action="'.route('cart.add', $product).'"', false)->assertSee('افزودن به سبد خرید');
        $this->post(route('cart.add', $product), ['quantity' => 1])->assertRedirect(route('cart.index'));
        $this->assertSame([$product->slug => 1], session('store_cart'));
        $this->post(route('cart.add', $product), ['quantity' => 1])->assertRedirect(route('cart.index'));
        $this->assertSame([$product->slug => 2], session('store_cart'));
        $this->postJson(route('cart.add', $product), ['quantity' => 1])->assertConflict();
        $this->assertSame([$product->slug => 2], session('store_cart'));
        $this->postJson(route('cart.update', $product), ['quantity' => 3])->assertConflict();
        $this->assertSame([$product->slug => 2], session('store_cart'));
        $this->postJson(route('cart.update', $product), ['quantity' => 1])->assertOk()->assertExactJson(['count' => 1]);
        $this->postJson(route('cart.update', $product), ['quantity' => 2])->assertOk()->assertExactJson(['count' => 2]);
        $this->post(route('cart.remove', $product->slug))->assertRedirect();
        $this->assertSame([], session('store_cart'));
        $this->get(route('cart.index'))->assertOk()->assertSee('مشاهدهٔ محصولات');
        $product->update(['stock_status' => 'limited']);
        $this->get(route('products.show', $product))->assertOk()->assertSee('موجودی محدود')
            ->assertSee('max="2"', false)->assertSee('form="product-cart-form"', false);
        $product->update(['stock_status' => 'unverified']);
        $this->post(route('cart.add', $product), ['quantity' => 1])->assertConflict();
    }

    public function test_account_panel_uses_native_session_and_revocation_state(): void
    {
        $product = $this->product();
        $account = new CustomerAccount;
        $account->forceFill(['public_id' => (string) Str::uuid(), 'phone' => '09000000001',
            'phone_digest' => RepairIdentity::digest('customer-account-phone', '09000000001'), 'verified_at' => now()])->save();
        $account->refresh();
        $this->actingAs($account, 'customer')->withSession(['customer_session_version' => $account->session_version]);
        $this->get(route('products.show', $product))->assertOk()->assertSee('data-customer-state="authenticated"', false)
            ->assertSee('داشبورد حساب')->assertSee('سفارش‌های من')->assertSee(route('account.logout'), false)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('customer.header'))->assertOk()->assertSee('data-customer-state="authenticated"', false);
        $this->withSession(['customer_session_version' => $account->session_version + 1]);
        $this->get(route('products.show', $product))->assertOk()->assertSee('data-customer-state="guest"', false)
            ->assertDontSee('data-customer-state="authenticated"', false);
    }

    public function test_sheet_source_contains_real_session_items_totals_and_actions(): void
    {
        $this->get(route('cart.index'))->assertOk()->assertSee('data-cart-empty="true"', false);
        $product = $this->product('fixture-terminal', ['name' => 'کارتخوان آزمایشی ایزوله', 'price' => 25000]);
        $this->withSession(['store_cart' => [$product->slug => 2]])->get(route('cart.index'))
            ->assertOk()->assertSee('data-cart-empty="false"', false)->assertSee($product->name)
            ->assertSee('۲ عدد')->assertSee('۵۰٬۰۰۰ تومان')->assertSee(route('cart.remove', $product->slug), false);
        $this->post(route('cart.remove', $product->slug))->assertRedirect();
        $this->get(route('cart.index'))->assertOk()->assertSee('data-cart-empty="true"', false)->assertDontSee($product->name);
    }

    private function product(string $slug = 'newland-me31-black', array $attributes = []): Product
    {
        return Product::create($attributes + ['slug' => $slug, 'name' => 'کارتخوان آزمایشی', 'category' => 'pos_terminal',
            'condition' => 'used', 'stock_status' => 'unverified', 'is_published' => true, 'is_sample' => false,
            'price' => 10500000, 'currency' => 'TOMAN', 'warranty_months' => 6]);
    }
}
