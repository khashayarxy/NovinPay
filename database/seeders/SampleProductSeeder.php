<?php

namespace Database\Seeders;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\StockStatus;
use App\Models\Product;
use Illuminate\Database\Seeder;

class SampleProductSeeder extends Seeder
{
    /**
     * Clearly labelled sample rows for local development only.
     *
     * Every row is flagged is_sample = true and is rendered with a visible
     * "داده نمونه" badge. This seeder is intentionally not called from
     * DatabaseSeeder: a fresh install starts with an empty catalogue.
     */
    public function run(): void
    {
        $samples = [
            [
                'name' => 'دستگاه کارتخوان ثابت (نمونه)',
                'slug' => 'sample-pos-fixed',
                'summary' => 'دستگاه کارتخوان ثابت مناسب فروشگاه و پیشخوان — داده نمونه',
                'description' => 'این رکورد صرفاً داده نمونه برای توسعه وب‌سایت است و موجودی یا قیمت واقعی محسوب نمی‌شود. دستگاه‌های کارتخوان ثابت معمولاً روی پیشخوان نصب می‌شوند و برای اتصال به شبکه و چاپ رسید طراحی شده‌اند.',
                'category' => ProductCategory::PosTerminal,
                'condition' => ProductCondition::New,
                'price' => 12000000,
                'stock_status' => StockStatus::InStock,
                'specifications' => ['نوع اتصال' => 'شبکه و کابل برق', 'چاپ رسید' => 'دارد', 'نحوه نصب' => 'ثابت روی پیشخوان'],
            ],
            [
                'name' => 'دستگاه کارتخوان سیار (نمونه)',
                'slug' => 'sample-pos-mobile',
                'summary' => 'دستگاه کارتخوان سیار با باتری داخلی — داده نمونه',
                'description' => 'این رکورد صرفاً داده نمونه برای توسعه وب‌سایت است. دستگاه‌های کارتخوان سیار برای کسب‌وکارهایی که جابه‌جایی دارند مناسب هستند و با باتری داخلی کار می‌کنند.',
                'category' => ProductCategory::PosTerminal,
                'condition' => ProductCondition::New,
                'price' => 18500000,
                'stock_status' => StockStatus::Limited,
                'specifications' => ['نوع اتصال' => 'بی‌سیم', 'چاپ رسید' => 'دارد', 'نحوه نصب' => 'قابل حمل'],
            ],
            [
                'name' => 'دستگاه کارتخوان کارکرده (نمونه)',
                'slug' => 'sample-pos-used',
                'summary' => 'دستگاه کارتخوان با وضعیت کارکرده — داده نمونه',
                'description' => 'این رکورد صرفاً داده نمونه برای توسعه وب‌سایت است. کالای کارکرده ممکن است علائم استفاده ظاهری داشته باشد و پیش از تحویل توسط کارشناسان بررسی می‌شود.',
                'category' => ProductCategory::PosTerminal,
                'condition' => ProductCondition::Used,
                'price' => 6500000,
                'stock_status' => StockStatus::InStock,
                'specifications' => ['نوع اتصال' => 'شبکه و کابل برق', 'وضعیت ظاهری' => 'علائم استفاده'],
            ],
            [
                'name' => 'پرینتر حرارتی رسید (نمونه)',
                'slug' => 'sample-receipt-printer',
                'summary' => 'پرینتر حرارتی برای چاپ رسید — داده نمونه',
                'description' => 'این رکورد صرفاً داده نمونه برای توسعه وب‌سایت است. پرینترهای حرارتی رسید برای چاپ سریع رسید بدون نیاز به کارتریج جوهر استفاده می‌شوند.',
                'category' => ProductCategory::Printer,
                'condition' => ProductCondition::New,
                'price' => 4200000,
                'stock_status' => StockStatus::InStock,
                'specifications' => ['نوع چاپ' => 'حرارتی', 'عرض کاغذ' => '۵۷ یا ۸۰ میلی‌متر'],
            ],
            [
                'name' => 'کابل تغذیه دستگاه کارتخوان (نمونه)',
                'slug' => 'sample-power-cable',
                'summary' => 'کابل تغذیه و شارژ دستگاه — داده نمونه',
                'description' => 'این رکورد صرفاً داده نمونه برای توسعه وب‌سایت است.',
                'category' => ProductCategory::Peripheral,
                'condition' => ProductCondition::New,
                'price' => 450000,
                'stock_status' => StockStatus::InStock,
                'specifications' => ['سازگاری' => 'مدل‌های متداول کارتخوان'],
            ],
            [
                'name' => 'کیف ضربه‌گیر دستگاه (نمونه)',
                'slug' => 'sample-carry-case',
                'summary' => 'کیف محافظ برای حمل دستگاه — داده نمونه',
                'description' => 'این رکورد صرفاً داده نمونه برای توسعه وب‌سایت است.',
                'category' => ProductCategory::Peripheral,
                'condition' => ProductCondition::New,
                'price' => 380000,
                'stock_status' => StockStatus::OutOfStock,
                'specifications' => ['جنس' => 'پارچه‌ای با لایه ضربه‌گیر'],
            ],
            [
                'name' => 'رول کاغذ حرارتی ۵۷ میلی‌متری (نمونه)',
                'slug' => 'sample-thermal-paper',
                'summary' => 'بسته رول کاغذ حرارتی برای چاپ رسید — داده نمونه',
                'description' => 'این رکورد صرفاً داده نمونه برای توسعه وب‌سایت است. کاغذ حرارتی پرکاربردترین لوازم مصرفی دستگاه‌های کارتخوان و پرینترهای رسید است.',
                'category' => ProductCategory::Consumable,
                'condition' => ProductCondition::New,
                'price' => 250000,
                'stock_status' => StockStatus::InStock,
                'specifications' => ['عرض' => '۵۷ میلی‌متر', 'تعداد در بسته' => '۱۰ عدد'],
            ],
        ];

        foreach ($samples as $sample) {
            Product::updateOrCreate(
                ['slug' => $sample['slug']],
                $sample + ['image_path' => null, 'is_sample' => true],
            );
        }
    }
}
