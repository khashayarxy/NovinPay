<?php

namespace App\Console\Commands;

use App\Enums\ProductCategory;
use App\Models\Product;
use App\Support\PaymentTestProduct;
use Illuminate\Console\Command;

class SyncPos4gProductsCommand extends Command
{
    protected $signature = 'novinpay:sync-pos-4g {--apply : Persist changes to the database}';

    protected $description = 'Ensure all POS terminal products include (4G) in their title and updated 4G FAQs.';

    public const UPDATED_POS_FAQ = <<<'FAQ'
آیا دستگاه‌های کارتخوان آکبند و استوک نوین‌پی از شبکه 4G پشتیبانی می‌کنند؟
بله. تمامی دستگاه‌های کارتخوان عرضه‌شده در نوین‌پی—چه مدل‌های آکبند و چه مدل‌های استوک—مجهز به ماژول فعال 4G هستند و پیش از تحویل تست شبکه می‌شوند تا با قطعی یا کندی شبکه‌های 2G/3G مواجه نشوید.

آیا برای استفاده از کارتخوان به پوشش شبکه نیاز دارم؟
بله. کیفیت اتصال به پوشش شبکهٔ سیم‌کارت در محل استفاده بستگی دارد و دستگاه با سیم‌کارت‌های متداول اپراتورهای کشور سازگار است.

از چه کاغذی برای چاپ رسید استفاده کنم؟
رول کاغذ حرارتی با عرض ۵۸ میلی‌متر (قطر حداکثر ۴۰ میلی‌متر) مناسب است.

وضعیت گارانتی و فعال‌سازی دستگاه چگونه است؟
تمامی دستگاه‌های کارتخوان همراه با ۶ ماه گارانتی نوین‌پی و خدمات رایگان فعال‌سازی و اتصال به پروندهٔ مالیاتی تحویل می‌شوند.
FAQ;

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $testSlug = PaymentTestProduct::slug();

        $products = Product::query()
            ->where('category', ProductCategory::PosTerminal)
            ->when($testSlug !== '', fn ($q) => $q->where('slug', '!=', $testSlug))
            ->get();

        $updated = 0;
        foreach ($products as $product) {
            $changed = false;
            $name = (string) $product->name;
            if (! str_contains($name, '4G')) {
                if (str_ends_with($name, '(نمونه)')) {
                    $name = trim(mb_substr($name, 0, -mb_strlen('(نمونه)'))).' (4G) (نمونه)';
                } else {
                    $name = trim($name).' (4G)';
                }
                $product->name = $name;
                $changed = true;
            }

            $details = is_array($product->product_details) ? $product->product_details : [];
            $content = is_array($details['content'] ?? null) ? $details['content'] : [];
            $faq = (string) ($content['faq'] ?? '');
            if ($faq === '' || str_contains($faq, 'قابل تحویل تأیید شده است') || str_contains($faq, 'نسخهٔ سخت‌افزاری')) {
                $content['faq'] = self::UPDATED_POS_FAQ;
                $details['content'] = $content;
                $product->product_details = $details;
                $changed = true;
            }

            if ($changed) {
                $updated++;
                if ($apply) {
                    $product->save();
                }
                $this->line(($apply ? 'Updated: ' : 'Would update: ')."{$product->slug} => {$product->name}");
            }
        }

        if (! $apply) {
            $this->info("Dry run complete. {$updated} product(s) would be updated. Pass --apply to save.");
        } else {
            $this->info("Synced {$updated} POS product(s).");
        }

        return self::SUCCESS;
    }
}
