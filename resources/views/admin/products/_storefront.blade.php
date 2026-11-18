@php($details = $product->product_details ?? [])
<details class="admin-section space-y-4" open>
    <summary class="cursor-pointer font-semibold">فروش و مشخصات تکمیلی</summary>
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="label" for="currency">واحد قیمت</label>
            <select class="input" name="currency" id="currency"><option value="TOMAN">تومان</option></select>
        </div>
        <div>
            <input type="hidden" name="online_purchasing_enabled" value="0">
            <label class="flex items-center gap-2">
                <input type="checkbox" name="online_purchasing_enabled" value="1" @checked(old('online_purchasing_enabled', $product->exists && $product->online_purchasing_enabled))>
                فعال‌سازی خرید آنلاین
            </label>
            <p class="text-sm text-slate-600">خرید آنلاین به قیمت معتبر و موجودی آزاد در انبار نیاز دارد.</p>
        </div>
        <div>
            <input type="hidden" name="sale_paused" value="0">
            <label class="flex items-center gap-2">
                <input type="checkbox" name="sale_paused" value="1" @checked(old('sale_paused', $product->salePaused()))>
                توقف موقت فروش
            </label>
            <p class="text-sm text-slate-600">صفحه و قیمت منتشرشده باقی می‌مانند. افزودن به سبد و اقدام هماهنگی خرید غیرفعال می‌شوند.</p>
        </div>
        @if (isset($matchingInventory))
            <div>
                <p class="label">انبار مرتبط با این گزینه</p>
                @if ($matchingInventory->isEmpty())
                    <p class="text-sm text-slate-600">برای این محصول و وضعیت کالا، رکورد انبار متصل نیست.</p>
                @elseif ($matchingInventory->count() !== 1)
                    <p class="text-sm text-slate-600">بیش از یک رکورد انبار متصل است. موجودی قابل فروش تا تعیین رکورد درست تأیید نمی‌شود.</p>
                @else
                    <p class="text-sm text-slate-600">موجودی آزاد انبار: {{ \App\Support\FaNumber::digits($matchingInventory->first()->available) }} عدد</p>
                @endif
            </div>
        @endif
        @foreach (['brand' => 'برند', 'model' => 'مدل', 'color' => 'رنگ', 'warranty_months' => 'مدت گارانتی به ماه'] as $field => $label)
            <div>
                <label class="label" for="{{ $field }}">{{ $label }}</label>
                <input class="input" id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $product->{$field}) }}"
                    @if ($field === 'warranty_months') type="number" min="1" max="120" @else maxlength="100" @endif>
            </div>
        @endforeach
    </div>
</details>
<details class="admin-section space-y-4" open>
    <summary class="cursor-pointer font-semibold">گزینه‌های رنگ محصول</summary>
    <p class="text-sm text-slate-600">برای اتصال رنگ‌های یک مدل، شناسهٔ گروه یکسان وارد کنید. هر رنگ، محصول و موجودی مستقل خود را دارد.</p>
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
</details>
<details class="admin-section space-y-4">
    <summary class="cursor-pointer font-semibold">توضیحات و گروه‌های مشخصات فنی</summary>
    <p class="text-sm text-slate-600">هر ویژگی را در یک سطر با قالب «عنوان: مقدار» بنویسید. موارد تأییدنشده را خالی بگذارید.</p>
    <div>
        <label class="label" for="key_features">ویژگی‌های کلیدی تکمیلی در مشخصات</label>
        <textarea class="input" id="key_features" name="key_features" rows="3" maxlength="500">{{ old('key_features', $details['key_features'] ?? '') }}</textarea>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        @foreach (\App\Models\Product::SPEC_GROUPS as $key => $label)
            <div>
                <label class="label" for="group-{{ $key }}">{{ $label }}</label>
                @if ($key === 'services')
                    <p class="text-sm text-slate-600">این ردیف‌ها در پنل خرید نمایش داده می‌شوند، اگر فهرست خدمات همراه خالی باشد.</p>
                @endif
                <textarea class="input" id="group-{{ $key }}" name="groups[{{ $key }}]" rows="3" maxlength="1500">{{ old('groups.'.$key, $details['groups'][$key] ?? '') }}</textarea>
            </div>
        @endforeach
        @foreach (\App\Models\Product::DETAIL_SECTIONS as $key => $label)
            <div>
                <label class="label" for="content-{{ $key }}">{{ $label }}</label>
                @if ($key === 'faq')
                    <p class="text-sm text-slate-600">پرسش را در یک سطر و پاسخ را زیر آن بنویسید. بین هر پرسش و پاسخ بعدی یک سطر خالی بگذارید.</p>
                @endif
                <textarea class="input" id="content-{{ $key }}" name="content[{{ $key }}]" rows="3" maxlength="2500">{{ old('content.'.$key, $details['content'][$key] ?? '') }}</textarea>
            </div>
        @endforeach
    </div>
</details>
<details class="admin-section space-y-4">
    <summary class="cursor-pointer font-semibold">منبع فنی، محصولات مرتبط و جست‌وجو</summary>
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
</details>
