<?php

namespace Tests\Feature;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\StockStatus;
use App\Mail\UnifiedNotificationMail;
use App\Models\Consultation;
use App\Models\Product;
use App\Services\Notifications\UnifiedNotifier;
use App\Services\Observability\BackupReporter;
use App\Services\Observability\ObservabilityEvents;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class Phase6SeoPerfSecurityErrorsEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_indexable_pages_have_self_canonicals_and_sitemap_coverage_while_parameterized_urls_stay_clean(): void
    {
        config(['app.url' => 'https://novinpaypos.com']);

        $product = Product::create([
            'name' => 'کارتخوان سیار نیولند ME31',
            'slug' => 'newland-me31',
            'summary' => 'کارتخوان سیار نسل چهارم',
            'category' => ProductCategory::PosTerminal,
            'condition' => ProductCondition::New,
            'stock_status' => StockStatus::InStock,
            'is_published' => true,
            'is_sample' => false,
            'price' => 8_500_000,
        ]);

        foreach ([
            '/' => ['https://novinpaypos.com/', 'index, follow'],
            '/products' => ['https://novinpaypos.com/products', 'index, follow'],
            '/products/newland-me31' => ['https://novinpaypos.com/products/newland-me31', 'index, follow'],
            '/contact' => ['https://novinpaypos.com/contact', 'index, follow'],
            '/terms' => ['https://novinpaypos.com/terms', 'noindex, nofollow'],
            '/privacy' => ['https://novinpaypos.com/privacy', 'noindex, nofollow'],
        ] as $uri => [$expectedCanonical, $expectedRobots]) {
            $html = $this->get($uri)->assertOk()->getContent();
            $xpath = $this->xpath($html);
            $this->assertSame($expectedCanonical, $xpath->query('//link[@rel="canonical"]')->item(0)?->getAttribute('href'), "Canonical mismatch on {$uri}");
            $this->assertSame($expectedRobots, $xpath->query('//meta[@name="robots"]')->item(0)?->getAttribute('content'), "Robots mismatch on {$uri}");
        }

        // Product comparison has canonical + noindex, follow
        $compareHtml = $this->get('/product-comparison?products[0]=newland-me31')->assertOk()->getContent();
        $compareXpath = $this->xpath($compareHtml);
        $this->assertSame('https://novinpaypos.com/product-comparison', $compareXpath->query('//link[@rel="canonical"]')->item(0)?->getAttribute('href'));
        $this->assertSame('noindex, follow', $compareXpath->query('//meta[@name="robots"]')->item(0)?->getAttribute('content'));

        // Explicit ?page=1 301-redirects cleanly; invalid page=0 is 404; out-of-range page=99 stays 200 with noindex, follow + root canonical
        $this->get('/products?page=1')->assertStatus(301)->assertHeader('Location', route('products.index'));
        $this->get('/products?page=0')->assertNotFound();
        $outOfRangeHtml = $this->get('/products?page=99')->assertOk()->getContent();
        $outOfRangeXpath = $this->xpath($outOfRangeHtml);
        $this->assertSame('https://novinpaypos.com/products', $outOfRangeXpath->query('//link[@rel="canonical"]')->item(0)?->getAttribute('href'));
        $this->assertSame('noindex, follow', $outOfRangeXpath->query('//meta[@name="robots"]')->item(0)?->getAttribute('content'));

        // Sitemap pages includes only indexable public routes
        $sitemapPages = $this->get('/sitemap-pages-1.xml')->assertOk()->getContent();
        foreach (['/', '/products', '/contact'] as $publicPath) {
            $this->assertStringContainsString('https://novinpaypos.com'.$publicPath.'</loc>', $sitemapPages);
        }
        $this->assertStringNotContainsString('/terms', $sitemapPages);
        $this->assertStringNotContainsString('/privacy', $sitemapPages);
    }

    public function test_lcp_preload_precedes_fonts_and_sized_map_and_hero_variants_are_optimized(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $imgPreloadPos = strpos($html, 'rel="preload" as="image"');
        $fontPreloadPos = strpos($html, 'as="font"');
        $this->assertNotFalse($imgPreloadPos);
        $this->assertNotFalse($fontPreloadPos);
        $this->assertLessThan($fontPreloadPos, $imgPreloadPos, 'LCP hero preload must precede font preloads in <head>');

        foreach ([320, 400, 480, 640, 853] as $w) {
            $file = public_path("images/home/novinpay-terminal-clean-{$w}.avif");
            $this->assertFileExists($file);
        }
        $this->assertLessThan(12_000, filesize(public_path('images/home/novinpay-terminal-clean-400.avif')));
        $this->assertLessThan(18_000, filesize(public_path('images/home/novinpay-terminal-clean-640.avif')));

        $gmapWebp = public_path('brand/maps/google-maps-2026-28.webp');
        $this->assertFileExists($gmapWebp);
        $this->assertLessThan(2_000, filesize($gmapWebp));
        $this->assertStringContainsString('google-maps-2026-28.webp', $html);
        $this->assertStringNotContainsString('media="(max-width: 850px)"', $html);
    }

    public function test_branded_error_pages_render_rtl_dark_mode_and_recovery_actions_safely(): void
    {
        request()->attributes->set('incident_id', 'E261127-CAFE01');

        $expected = [
            'errors.403' => ['خطای ۴۰۳', 'دسترسی به این بخش مجاز نیست'],
            'errors.404' => ['خطای ۴۰۴', 'صفحه‌ای که دنبال آن بودید پیدا نشد'],
            'errors.419' => ['خطای ۴۱۹', 'نشست شما منقضی شده است'],
            'errors.429' => ['خطای ۴۲۹', 'کمی بعد دوباره تلاش کنید'],
            'errors.500' => ['خطای ۵۰۰', 'خطایی در سرور رخ داد', 'E261127-CAFE01', 'کد پیگیری خطا'],
            'errors.503' => ['در حال تعمیرات و به‌روزرسانی سایت', 'نوین‌پی'],
        ];

        foreach ($expected as $viewName => $snippets) {
            $rendered = view($viewName)->render();
            $this->assertStringContainsString('lang="fa"', $rendered, $viewName);
            $this->assertStringContainsString('dir="rtl"', $rendered, $viewName);
            $this->assertStringContainsString('noindex, nofollow', $rendered, $viewName);
            $this->assertStringContainsString('prefers-color-scheme', $rendered, "{$viewName} must support dark mode");
            foreach ($snippets as $snippet) {
                $this->assertStringContainsString($snippet, $rendered, "Missing [{$snippet}] in {$viewName}");
            }
        }
    }

    public function test_branded_rtl_email_template_admin_events_daily_digest_critical_alerts_and_cli_test_work(): void
    {
        Mail::fake();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
        Cache::flush();

        config([
            'notifications.email.enabled' => true,
            'notifications.admin.emails' => ['ops@novinpaypos.com', 'owner@novinpaypos.com'],
        ]);

        // 1. Render HTML + Plain-Text fallback directly
        $mailable = new UnifiedNotificationMail(
            mailSubject: 'تست قالب برندینگ نوین‌پی',
            heading: 'سفارش جدید ثبت شد',
            intro: 'جزئیات سفارش ثبت‌شده را در جدول زیر مشاهده فرمایید.',
            fields: [
                'کد سفارش' => 'NP123456',
                'مبلغ کل' => '۸٬۵۰۰٬۰۰۰ تومان',
                'وضعیت' => 'پرداخت موفق',
            ],
            actionLabel: 'مشاهده سفارش در پنل',
            actionUrl: 'https://novinpaypos.com/admin/orders',
            statusBadge: 'پرداخت موفق',
            statusTone: 'success',
        );
        $html = $mailable->render();
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('پرداخت موفق', $html);
        $this->assertStringContainsString('NP123456', $html);
        $this->assertStringContainsString('مشاهده سفارش در پنل', $html);
        $this->assertStringContainsString(config('business.name'), $html);

        $plainText = view('emails.notification_text', [
            'mailSubject' => $mailable->mailSubject,
            'heading' => $mailable->heading,
            'intro' => $mailable->intro,
            'fields' => $mailable->fields,
            'actionLabel' => $mailable->actionLabel,
            'actionUrl' => $mailable->actionUrl,
            'statusBadge' => $mailable->statusBadge,
            'footerNote' => null,
        ])->render();
        $this->assertStringContainsString('[پرداخت موفق]', $plainText);
        $this->assertStringContainsString('• کد سفارش: NP123456', $plainText);

        // 2. Consultation admin email
        $consultation = Consultation::forceCreate([
            'public_id' => '11111111-2222-3333-4444-555555555555',
            'submission_digest' => str_repeat('a', 64),
            'phone_digest' => str_repeat('b', 64),
            'customer_name' => 'سارا محمدی',
            'contact_phone' => '09123456789',
            'topic' => 'purchase',
            'question' => 'کدام کارتخوان برای سوپرمارکت مناسب است؟',
            'status' => 'new',
            'version' => 1,
        ]);
        app(UnifiedNotifier::class)->consultationSubmitted($consultation);
        Mail::assertSent(UnifiedNotificationMail::class, fn (UnifiedNotificationMail $m) => $m->hasTo('ops@novinpaypos.com') && $m->statusBadge === 'درخواست مشاوره');

        // 3. Daily Summary Digest email
        $this->artisan('novinpay:telegram-daily-digest', ['--date' => '2026-11-26'])
            ->expectsOutputToContain('email_sent=true')
            ->assertExitCode(0);
        Mail::assertSent(UnifiedNotificationMail::class, fn (UnifiedNotificationMail $m) => str_contains($m->mailSubject, 'گزارش جمع‌بندی روزانه نوین‌پی'));

        // 4. Critical Operational Alerts (SMS failure & Backup failure) + deduplication
        Mail::fake();
        app(ObservabilityEvents::class)->sms('otp', 'insufficient_credit', 'ippanel', 'customer_otp');
        app(ObservabilityEvents::class)->sms('otp', 'insufficient_credit', 'ippanel', 'customer_otp'); // duplicate suppressed
        Mail::assertSent(UnifiedNotificationMail::class, 2); // 1 event * 2 admin recipients

        Mail::fake();
        app(BackupReporter::class)->report(['status' => 'failed', 'note' => 'Disk full']);
        Mail::assertSent(UnifiedNotificationMail::class, fn (UnifiedNotificationMail $m) => $m->statusBadge === 'هشدار بکاپ' && $m->statusTone === 'danger');

        // 5. CLI novinpay:notify-test --channel=email
        Mail::fake();
        $this->artisan('novinpay:notify-test', ['--channel' => 'email'])
            ->expectsOutputToContain('[email] Test notification delivered successfully to 2 recipient(s).')
            ->assertExitCode(0);
        Mail::assertSent(UnifiedNotificationMail::class, 2);

        config(['notifications.admin.emails' => []]);
        $this->artisan('novinpay:notify-test', ['--channel' => 'email'])
            ->expectsOutputToContain('[email] Channel is not enabled or NOTIFICATIONS_ADMIN_EMAILS is empty.')
            ->assertExitCode(1);
    }

    private function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($doc);
    }
}
