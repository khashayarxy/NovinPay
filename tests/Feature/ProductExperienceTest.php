<?php

namespace Tests\Feature;

use App\Enums\AdminAction;
use App\Http\Controllers\Admin\ProductFeedbackController;
use App\Models\CustomerAccount;
use App\Models\Product;
use App\Models\ProductFeedback;
use App\Models\User;
use App\Support\ProductSale;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_color_navigation_keeps_variant_offers_isolated_and_hides_unpublished_options(): void
    {
        $black = $this->product('black-terminal', ['name' => 'Terminal', 'variant_family' => 'same-terminal',
            'color' => 'مشکی', 'color_swatch' => '#131820', 'price' => 10500000, 'online_purchasing_enabled' => false]);
        $blue = $this->product('blue-terminal', ['name' => 'Terminal', 'variant_family' => 'same-terminal',
            'color' => 'آبی', 'color_swatch' => '#1849a9', 'price' => 12000000, 'condition' => 'new']);
        $this->product('red-terminal', ['variant_family' => 'same-terminal', 'color' => 'قرمز', 'stock_status' => 'out_of_stock']);
        $hidden = $this->product('private-terminal', ['variant_family' => 'same-terminal', 'color' => 'سبز', 'is_published' => false]);
        $this->product('other-terminal', ['variant_family' => 'another-model', 'color' => 'سفید']);

        $this->get(route('products.show', $black))->assertOk()
            ->assertSee('رنگ: <strong>مشکی</strong>', false)->assertSee('aria-current="true"', false)
            ->assertSee('aria-label="مشاهدهٔ رنگ آبی"', false)->assertSee('data-color-unavailable', false)
            ->assertSee('/products/red-terminal', false)->assertDontSee('سبز')
            ->assertSee('۱۰٬۵۰۰٬۰۰۰')->assertSee('موجودی تأیید نشده')->assertDontSee('استعلام موجودی')
            ->assertSee('"inProductGroupWithID":"same-terminal"', false);
        $this->get('https://store.example/products/'.$blue->slug)->assertOk()
            ->assertSee('رنگ: <strong>آبی</strong>', false)->assertSee('۱۲٬۰۰۰٬۰۰۰')
            ->assertSee('<link rel="canonical" href="'.rtrim(config('app.url'), '/').'/products/blue-terminal">', false)
            ->assertDontSee('۱۰٬۵۰۰٬۰۰۰');
        $this->get(route('products.show', $hidden))->assertNotFound();
        $this->post(route('cart.add', $black), ['quantity' => 1])->assertConflict();
    }

    public function test_staging_keeps_public_canonical_and_private_draft_technical_notes(): void
    {
        $product = $this->product('reference', [
            'price' => 10500000,
            'product_details' => [
                'key_features' => "نمایشگر: رنگی\nچاپگر: حرارتی داخلی",
                'content' => ['faq' => "چاپگر دارد؟\nچاپگر حرارتی داخلی دارد.\n\nگارانتی دارد؟\n۶ ماه گارانتی دارد."],
                'verification_notes' => 'DRAFT-TECHNICAL-NOTE',
            ],
        ]);

        $this->get('https://novinpaypos.test/products/'.$product->slug)->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertSee('<link rel="canonical" href="https://novinpaypos.com/products/reference">', false)
            ->assertSee('spec-groups')->assertSee('product-faq')->assertSee('چاپگر دارد؟')
            ->assertSee('105000000')->assertDontSee('DRAFT-TECHNICAL-NOTE');
        $this->get('https://store.example/products/'.$product->slug)->assertOk()->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_review_sort_and_distribution_exclude_pending_and_ineligible_ratings(): void
    {
        $product = $this->product('reference');
        foreach ([1, 3, 4, 4, 5, 5, 5] as $index => $rank) {
            $account = $this->account('0900000000'.($index + 1));
            $entry = new ProductFeedback;
            $entry->forceFill([
                'product_id' => $product->id,
                'customer_account_id' => $account->id,
                'review_owner_id' => $account->id,
                'kind' => 'review',
                'status' => $index === 6 ? 'pending' : 'approved',
                'body' => 'review-entry-'.$index,
                'rating' => $rank,
                'verified_buyer' => $index < 5 || $index === 6,
            ])->save();
        }

        $this->get(route('products.show', [$product, 'review_sort' => 'highest']))->assertOk()
            ->assertSeeInOrder(['review-entry-4', 'review-entry-0', 'review-entry-5'])
            ->assertDontSee('review-entry-6')->assertSee('توزیع امتیاز خریداران')
            ->assertSee('۳٫۴ از ۵')->assertSee('"ratingCount":5', false);
        $this->get(route('products.show', [$product, 'review_sort' => 'lowest']))->assertOk()
            ->assertSeeInOrder(['review-entry-0', 'review-entry-4', 'review-entry-5']);
        $this->get(route('products.show', [$product, 'review_sort' => ['invalid']]))->assertOk()
            ->assertSeeInOrder(['review-entry-5', 'review-entry-4', 'review-entry-0']);
    }

    public function test_sales_status_and_related_products_match_public_eligibility(): void
    {
        $product = $this->product('reference', [
            'price' => 10500000, 'brand' => 'Newland', 'warranty_months' => 6,
            'product_details' => ['groups' => ['printer' => 'عرض کاغذ: ۵۸ میلی‌متر']],
        ]);
        $this->product('other-terminal', ['price' => 12000000]);
        $this->product('hidden-terminal', ['is_published' => false]);
        $this->product('sample-terminal', ['is_sample' => true]);
        $this->get(route('products.show', $product))->assertOk()->assertSee('۱۰٬۵۰۰٬۰۰۰')
            ->assertSee('موجودی تأیید نشده')->assertDontSee('استعلام موجودی')->assertDontSee('استعلام قیمت')
            ->assertDontSee('خرید محصول')->assertSee('۶ ماه گارانتی')
            ->assertSee('۵۸ میلی‌متر')->assertSee('other-terminal')
            ->assertDontSee('hidden-terminal')->assertDontSee('sample-terminal');
        $product->update(['price' => null]);
        $this->get(route('products.show', $product))->assertOk()->assertSee('استعلام قیمت');
        $product->update(['stock_status' => 'out_of_stock']);
        $this->get(route('products.show', $product))->assertOk()->assertSee('ناموجود')->assertDontSee('خرید محصول');
        $product->update(['price' => 10000, 'stock_status' => 'in_stock', 'online_purchasing_enabled' => false]);
        $this->assertSame(ProductSale::Offline, ProductSale::resolve($product, true));
        $product->update(['online_purchasing_enabled' => true]);
        $this->assertSame(ProductSale::Online, ProductSale::resolve($product, true));
        $this->assertSame(ProductSale::AwaitingStock, ProductSale::resolve($product, null));
        $this->assertSame(ProductSale::OutOfStock, ProductSale::resolve($product, false));
        $this->get(route('products.compare', ['products' => ['reference', 'other-terminal', 'hidden-terminal']]))
            ->assertOk()->assertSee('noindex, follow')->assertSee('other-terminal')->assertDontSee('hidden-terminal');
        $product->update(['is_published' => false]);
        $this->get(route('products.show', $product))->assertNotFound();
    }

    public function test_feedback_requires_verified_session_and_moderation_with_no_nonbuyer_rating(): void
    {
        $product = $this->product('reference');
        $store = route('products.feedback.store', $product);
        $public = route('products.show', $product);
        $this->post($store, ['kind' => 'review', 'body' => 'شایستهٔ بررسی'])->assertRedirect(route('account.login'));
        $account = $this->account('09000000001');
        $this->actingAs($account, 'customer')->withSession(['customer_session_version' => $account->session_version]);
        $this->from($public)->post($store, ['kind' => 'review', 'body' => 'شایستهٔ بررسی', 'rating' => 5])
            ->assertSessionHasErrors('rating');
        $this->post($store, ['kind' => 'review', 'body' => 'متن آزمایشی برای بررسی', 'verified_buyer' => true])
            ->assertRedirect($public);
        $this->post($store, ['kind' => 'review', 'body' => 'نوشتهٔ بازبینی‌شده <b>برای بررسی</b>'])
            ->assertRedirect($public);
        $this->post($store, ['kind' => 'question', 'body' => 'پرسش آزمایشی دربارهٔ فعال‌سازی'])
            ->assertRedirect($public);
        $this->assertDatabaseCount('product_feedback', 2);
        $review = ProductFeedback::where('kind', 'review')->sole();
        $question = ProductFeedback::where('kind', 'question')->sole();
        $this->assertFalse($review->verified_buyer);
        $this->assertNull($review->rating);
        $other = $this->account('09000000002');
        $this->actingAs($other, 'customer')->withSession(['customer_session_version' => $other->session_version]);
        $this->get($public)->assertOk()->assertDontSee('نوشتهٔ بازبینی‌شده')->assertDontSee('پرسش آزمایشی');
        $moderation = route('admin.products.feedback.update', [$product, $review]);
        $this->actingAs(User::factory()->create(), 'web')->patch($moderation, ['status' => 'approved'])->assertForbidden();
        $this->actingAs(User::factory()->superAdmin()->create(), 'web');
        foreach ([$review, $question] as $entry) {
            $url = route('admin.products.feedback.update', [$product, $entry]);
            $this->patch($url, ['status' => 'approved', 'revision' => str_repeat('0', 64)])->assertConflict();
            $this->patch($url, ['status' => 'approved', 'staff_reply' => 'پاسخ آزمایشی از فروشگاه',
                'revision' => ProductFeedbackController::revision($entry)])->assertRedirect();
        }
        $this->assertDatabaseHas('admin_activities', ['action' => AdminAction::ProductFeedbackModerated->value, 'target_id' => $review->id]);
        $this->get($public)->assertOk()->assertSee('نوشتهٔ بازبینی‌شده')->assertDontSee('پرسش آزمایشی')
            ->assertSee('پاسخ رسمی نوین‌پی')->assertDontSee('<b>برای بررسی</b>', false)->assertDontSee('aggregateRating');
        $this->actingAs($other, 'customer')->withSession(['customer_session_version' => $other->session_version]);
        $report = route('products.feedback.report', [$product, $review]);
        $this->post($report, ['reason' => 'spam'])->assertRedirect($public);
        $this->post($report, ['reason' => 'spam'])->assertRedirect($public);
        $this->assertDatabaseCount('product_feedback_reports', 1);
    }

    private function product(string $slug, array $attributes = []): Product
    {
        return Product::create($attributes + [
            'name' => $slug, 'slug' => $slug, 'condition' => 'used', 'stock_status' => 'unverified',
            'category' => 'pos_terminal', 'is_published' => true, 'is_sample' => false,
        ]);
    }

    private function account(string $phone): CustomerAccount
    {
        $account = new CustomerAccount;
        $account->forceFill(['public_id' => (string) Str::uuid(), 'phone' => $phone,
            'phone_digest' => RepairIdentity::digest('customer-account-phone', $phone), 'verified_at' => now()])->save();

        return $account->refresh();
    }
}
