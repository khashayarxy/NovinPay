<?php

namespace App\Models;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\StockStatus;
use App\Support\SearchNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
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
        'is_sample',
        'is_published',
        'specifications',
    ];

    protected static function booted(): void
    {
        // Keep the derived search haystack in sync with the display fields so
        // search matches regardless of Persian/Arabic digit or letter variant.
        static::saving(function (Product $product): void {
            $product->search_text = SearchNormalizer::forProduct(
                $product->name,
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
            'is_sample' => 'boolean',
            'is_published' => 'boolean',
            'specifications' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
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
}
