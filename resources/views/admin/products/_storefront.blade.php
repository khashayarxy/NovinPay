@php
    $details = $product->product_details ?? [];
    $hasVariantValues = filled(old('variant_family', $product->variant_family)) || filled(old('color_swatch', $product->color_swatch)) || $errors->hasAny(['variant_family', 'color_swatch']);
    $hasSpecGroupValues = filled(old('key_features', $details['key_features'] ?? null))
        || !empty(array_filter($details['groups'] ?? []))
        || !empty(array_filter($details['content'] ?? []))
        || $errors->hasAny(['key_features', 'groups', 'content']);
    $hasSeoValues = filled(old('verification_notes', $details['verification_notes'] ?? null))
        || filled(old('seo_title', $details['seo_title'] ?? null))
        || filled(old('seo_description', $details['seo_description'] ?? null))
        || $errors->hasAny(['verification_notes', 'technical_source', 'related_slugs', 'seo_title', 'seo_description']);
@endphp

<details class="admin-accordion" @if ($hasVariantValues) open @endif>
    <summary>
        <span>گزینه‌های رنگ محصول (اختیاری)</span>
        <svg class="admin-accordion__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
    </summary>
    <div class="admin-accordion__body space-y-3">
        <p class="text-xs leading-6 text-slate-500">برای اتصال رنگ‌های یک مدل، شناسهٔ گروه یکسان وارد کنید.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="variant_family">شناسهٔ گروه رنگ</label>
                <input class="input" id="variant_family" name="variant_family" dir="ltr" maxlength="64"
                    value="{{ old('variant_family', $product->variant_family) }}" placeholder="newland-me31">
            </div>
            <div>
                <label class="label" for="color_swatch">کد رنگ نمونه</label>
                <input class="input" id="color_swatch" name="color_swatch" dir="ltr" maxlength="7"
                    value="{{ old('color_swatch', $product->color_swatch) }}" placeholder="#171b21">
            </div>
        </div>
    </div>
</details>

<details class="admin-accordion" @if ($hasSpecGroupValues) open @endif>
    <summary>
        <span>بخش‌های تکمیلی مشخصات و سؤالات متداول (اختیاری)</span>
        <svg class="admin-accordion__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
    </summary>
    <div class="admin-accordion__body space-y-4">
        <p class="text-xs leading-6 text-slate-500">هر ویژگی را در یک سطر با قالب «عنوان: مقدار» بنویسید.</p>
        <div>
            <label class="label" for="key_features">ویژگی‌های کلیدی تکمیلی</label>
            <textarea class="input" id="key_features" name="key_features" rows="3" maxlength="500">{{ old('key_features', $details['key_features'] ?? '') }}</textarea>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach (\App\Models\Product::SPEC_GROUPS as $key => $label)
                <div>
                    <label class="label" for="group-{{ $key }}">{{ $label }}</label>
                    <textarea class="input" id="group-{{ $key }}" name="groups[{{ $key }}]" rows="3" maxlength="1500">{{ old('groups.'.$key, $details['groups'][$key] ?? '') }}</textarea>
                </div>
            @endforeach
            @foreach (\App\Models\Product::DETAIL_SECTIONS as $key => $label)
                <div>
                    <label class="label" for="content-{{ $key }}">{{ $label }}</label>
                    @if ($key === 'faq')
                        <p class="text-xs text-slate-500">پرسش در یک سطر و پاسخ در سطر بعد؛ بین هر مورد یک سطر خالی بگذارید.</p>
                    @endif
                    <textarea class="input" id="content-{{ $key }}" name="content[{{ $key }}]" rows="3" maxlength="2500">{{ old('content.'.$key, $details['content'][$key] ?? '') }}</textarea>
                </div>
            @endforeach
        </div>
    </div>
</details>

<details class="admin-accordion" @if ($hasSeoValues) open @endif>
    <summary>
        <span>سئو و محصولات مرتبط (اختیاری)</span>
        <svg class="admin-accordion__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
    </summary>
    <div class="admin-accordion__body space-y-4">
        <div>
            <label class="label" for="verification_notes">یادداشت داخلی مشخصات (فقط پنل مدیریت)</label>
            <textarea class="input" id="verification_notes" name="verification_notes" rows="3" maxlength="2500">{{ old('verification_notes', $details['verification_notes'] ?? '') }}</textarea>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach (['technical_source' => 'نشانی منبع فنی (HTTPS)', 'related_slugs' => 'اسلاگ محصولات مرتبط (با ویرگول)', 'seo_title' => 'عنوان سئو', 'seo_description' => 'توضیحات متا (سئو)'] as $field => $label)
                <div>
                    <label class="label" for="{{ $field }}">{{ $label }}</label>
                    <input class="input" id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $details[$field] ?? '') }}">
                </div>
            @endforeach
        </div>
    </div>
</details>
