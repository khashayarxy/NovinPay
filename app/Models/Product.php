<?php

namespace App\Models;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\StockStatus;
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
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($term) {
            $builder->where('name', 'like', "%{$term}%")
                ->orWhere('summary', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%");
        });
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
