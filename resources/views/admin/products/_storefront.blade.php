@php
    $details = $product->product_details ?? [];
    $hasVariantValues = filled(old('variant_family', $product->variant_family)) || filled(old('color_swatch', $product->color_swatch)) || $errors->hasAny(['variant_family', 'color_swatch']);
@endphp
<section class="admin-section admin-advanced-details space-y-4" >
    <h2>گزینه‌های رنگ محصول (اختیاری)</h2>
    <p class="text-xs leading-6 text-slate-500">برای اتصال رنگ‌های یک مدل، شناسهٔ گروه یکسان وارد کنید. هر رنگ، محصول و موجودی مستقل خود را دارد.</p>
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
</section>
<section class="admin-section admin-advanced-details space-y-4">
    <h2>توضیحات و گروه‌های مشخصات فنی (اختیاری)</h2>
    <p class="text-xs leading-6 text-slate-500">هر ویژگی را در یک سطر با قالب «عنوان: مقدار» بنویسید. موارد تأییدنشده را خالی بگذارید.</p>
    <div>
        <label class="label" for="key_features">ویژگی‌های کلیدی تکمیلی در مشخصات</label>
        <textarea class="input" id="key_features" name="key_features" rows="3" maxlength="500">{{ old('key_features', $details['key_features'] ?? '') }}</textarea>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        @foreach (\App\Models\Product::SPEC_GROUPS as $key => $label)
            <div>
                <label class="label" for="group-{{ $key }}">{{ $label }}</label>
                @if ($key === 'services')
                    <p class="text-xs text-slate-500">این ردیف‌ها در پنل خرید نمایش داده می‌شوند، اگر فهرست خدمات همراه خالی باشد.</p>
                @endif
                <textarea class="input" id="group-{{ $key }}" name="groups[{{ $key }}]" rows="3" maxlength="1500">{{ old('groups.'.$key, $details['groups'][$key] ?? '') }}</textarea>
            </div>
        @endforeach
        @foreach (\App\Models\Product::DETAIL_SECTIONS as $key => $label)
            <div>
                <label class="label" for="content-{{ $key }}">{{ $label }}</label>
                @if ($key === 'faq')
                    <p class="text-xs text-slate-500">پرسش را در یک سطر و پاسخ را زیر آن بنویسید. بین هر پرسش و پاسخ بعدی یک سطر خالی بگذارید.</p>
                @endif
                <textarea class="input" id="content-{{ $key }}" name="content[{{ $key }}]" rows="3" maxlength="2500">{{ old('content.'.$key, $details['content'][$key] ?? '') }}</textarea>
            </div>
        @endforeach
    </div>
</section>
<section class="admin-section admin-advanced-details space-y-4">
    <h2>سئو، منبع فنی و محصولات مرتبط (اختیاری)</h2>
    <div>
        <label class="label" for="verification_notes">یادداشت بررسی مشخصات (فقط پنل مدیریت)</label>
        <textarea class="input" id="verification_notes" name="verification_notes" rows="4" maxlength="2500">{{ old('verification_notes', $details['verification_notes'] ?? '') }}</textarea>
    </div>
    @foreach (['technical_source' => 'نشانی منبع فنی (HTTPS)', 'related_slugs' => 'نامک محصولات مرتبط با فاصله یا ویرگول', 'seo_title' => 'عنوان برای نتایج جست‌وجو', 'seo_description' => 'توضیح برای نتایج جست‌وجو'] as $field => $label)
        <div>
            <label class="label" for="{{ $field }}">{{ $label }}</label>
            <input class="input" id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $details[$field] ?? '') }}">
        </div>
    @endforeach
</section>
