<?php

namespace App\Http\Requests;

use App\Support\ArticleInput;
use Illuminate\Support\Facades\Gate;

final class ArticleRequest extends PrivateRepairFormRequest
{
    public function operation(): string
    {
        return $this->route()->defaults['operation'] ?? '';
    }

    public function authorize(): bool
    {
        Gate::authorize(ArticleInput::permission($this->operation()));
        if (! str_starts_with($this->operation(), 'category.')) {
            Gate::authorize('articles.view');
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ArticleInput::normalize($this->all()));
    }

    public function rules(): array
    {
        return ArticleInput::rules($this->operation());
    }

    public function attributes(): array
    {
        return ['title' => 'عنوان', 'summary' => 'خلاصه', 'content' => 'متن ساختاریافته', 'slug' => 'نامک', 'name' => 'نام دسته', 'version' => 'نسخه', 'category_id' => 'دسته', 'confirmed' => 'تأیید انتشار', 'reviewed_version_id' => 'نسخهٔ بازبینی‌شده', 'is_active' => 'وضعیت دسته'];
    }
}
