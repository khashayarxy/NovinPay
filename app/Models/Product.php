<?php

namespace App\Models;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\StockStatus;
use App\Support\PaymentTestProduct;
use App\Support\SearchNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $attributes = ['currency' => 'TOMAN', 'online_purchasing_enabled' => true];

    public const DETAIL_SECTIONS = [
        'advantages' => 'خدمات همراه در بخش خرید', 'design' => 'طراحی و کاربری',
        'requirements' => 'اتصال و راه‌اندازی', 'printer' => 'چاپ رسید',
        'battery' => 'باتری و قابلیت حمل', 'uses' => 'مناسب چه کسب‌وکارهایی است؟',
        'limitations' => 'نکات پیش از خرید', 'services' => 'گارانتی و خدمات',
        'purchase' => 'وضعیت خرید', 'faq' => 'پرسش‌های متداول',
    ];

    public static function specificationRows(?string $text): array
    {
        $rows = [];
        foreach (preg_split('/\R/u', trim($text ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $line) {
            [$label, $value] = array_pad(array_map('trim', explode(':', $line, 2)), 2, '');
            if ($label !== '' && $value !== '') {
                $rows[] = [$label, $value];
            }
        }

        return $rows;
    }

    public const SPEC_GROUPS = [
        'general' => 'عمومی', 'hardware' => 'سخت‌افزار', 'display' => 'نمایشگر',
        'connectivity' => 'ارتباطات', 'payment' => 'قابلیت‌های پرداخت', 'power' => 'باتری و شارژ',
        'printer' => 'چاپگر', 'physical' => 'مشخصات فیزیکی', 'services' => 'فروش و خدمات',
    ];

    protected $fillable = [
        'name',
        'slug',
        'summary',
        'description',
        'category',
        'condition',
        'price',
        'stock_status',
        'image_path',
        'media_details',
        'currency', 'online_purchasing_enabled', 'brand', 'model', 'color', 'warranty_months', 'product_details',
        'variant_family', 'color_swatch',
        'is_sample',
        'is_published',
        'specifications',
    ];

    protected static function booted(): void
    {
        // Keep the derived search haystack in sync with the display fields so
        // search matches regardless of Persian/Arabic digit or letter variant.
        static::saving(function (Product $product): void {
            if ($product->isDirty('price')) {
                $product->price_changed_at = $product->price > 0 ? now()->utc() : null;
            }
            $product->search_text = SearchNormalizer::forProduct(
                implode(' ', array_filter([$product->name, $product->brand, $product->model])),
                $product->summary,
                $product->description,
            );
        });
    }

    protected function casts(): array
    {
        return [
            'category' => ProductCategory::class,
            'condition' => ProductCondition::class,
            'stock_status' => StockStatus::class,
            'price' => 'integer',
            'price_changed_at' => 'immutable_datetime',
            'is_sample' => 'boolean',
            'is_published' => 'boolean',
            'specifications' => 'array',
            'media_details' => 'array',
            'online_purchasing_enabled' => 'boolean',
            'warranty_months' => 'integer',
            'product_details' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function primaryImage(): ?array
    {
        if (! $this->image_path) {
            return null;
        }

        return ['path' => $this->image_path] + ($this->media_details['primary'] ?? []);
    }

    public function galleryImages(): array
    {
        return $this->media_details['gallery'] ?? [];
    }

    public function galleryRevision(): string
    {
        return hash('sha256', json_encode($this->galleryImages(), JSON_THROW_ON_ERROR));
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function salePaused(): bool
    {
        return (bool) ($this->product_details['sale_paused'] ?? false);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = SearchNormalizer::normalize(trim($term));

        if ($term === '') {
            return $query;
        }

        // Escape LIKE wildcards so user input is matched literally. The '!'
        // escape character is used instead of a backslash because SQLite has
        // no default ESCAPE clause while MySQL treats '\' specially inside
        // string literals — '!' is portable across both and vanishingly rare
        // in Persian catalogue text.
        $pattern = '%'.strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';

        return $query->whereRaw("search_text LIKE ? ESCAPE '!'", [$pattern]);
    }

    public function scopeCategory(Builder $query, ProductCategory $category): Builder
    {
        return $query->where('category', $category->value);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function isPrivatePaymentTest(): bool
    {
        return PaymentTestProduct::isTestProduct($this);
    }

    public function scopeVisibleTo(Builder $query, ?CustomerAccount $account = null): Builder
    {
        $slug = PaymentTestProduct::slug();
        if ($slug === '' || PaymentTestProduct::canAccess($account)) {
            return $query;
        }

        return $query->where('slug', '!=', $slug);
    }

    public function scopeExcludePrivatePaymentTest(Builder $query): Builder
    {
        $slug = PaymentTestProduct::slug();

        return $slug === '' ? $query : $query->where('slug', '!=', $slug);
    }
}
