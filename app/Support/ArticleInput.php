<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;

final class ArticleInput
{
    public static function rules(string $action): array
    {
        $version = ['version' => ['required', 'integer', 'min:1']];
        $slug = ['required', 'string', 'max:120', 'regex:/\A[\pL\pN]+(?:-[\pL\pN]+)*\z/u'];

        return match ($action) {
            'category.create' => ['name' => ['required', 'string', 'max:120'], 'slug' => $slug, 'is_active' => ['required', 'boolean']],
            'category.update' => $version + ['name' => ['required', 'string', 'max:120'], 'slug' => $slug, 'is_active' => ['required', 'boolean']],
            'create','save' => ($action === 'save' ? $version : ['slug' => $slug]) + ['category_id' => ['required', 'integer', 'min:1'], 'title' => ['required', 'string', 'max:180'], 'summary' => ['required', 'string', 'max:500'], 'content' => ['required', 'string', 'max:40000']],
            'publish','unpublish' => $version + ['reviewed_version_id' => ['required', 'integer', 'min:1'], 'confirmed' => ['required', 'accepted']],
            default => ['invalid' => ['required', 'prohibited']],
        };
    }

    public static function validate(string $action, array $data): array
    {
        return Validator::make(self::normalize($data), self::rules($action))->validate();
    }

    public static function normalize(array $data): array
    {
        if (isset($data['slug']) && is_string($data['slug'])) {
            $data['slug'] = mb_strtolower(preg_replace('/\s+/u', '-', SearchNormalizer::normalize(trim($data['slug']))));
        }

        return $data;
    }

    public static function permission(string $action): string
    {
        return match ($action) {
            'create','save' => 'articles.manage','publish','unpublish' => 'articles.publish','category.create','category.update' => 'article_categories.manage',default => 'articles.invalid'
        };
    }
}
