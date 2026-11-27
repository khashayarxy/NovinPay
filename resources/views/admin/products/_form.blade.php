@php
    $isPublishedChecked = old('is_published', $product->exists ? $product->is_published : true);
    $isSampleChecked = old('is_sample', $product->is_sample ?? false);
    $existingSpecs = $product->specifications ?? [];
    $specBlankRows = 3;
    $specNextIndex = count($existingSpecs) + $specBlankRows;
    $specRowIndex = 0;
    $hasAdvancedErrors = $errors->hasAny(['slug', 'is_sample', 'currency', 'historical_price_date']);
    $hasGalleryErrors = $errors->hasAny(['image_alt', 'gallery_order']) || $errors->get('gallery_images*') || $errors->get('gallery_alts*');
@endphp

<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="admin-panel admin-form-surface space-y-5" autocomplete="off">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    @if ($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 p-4" role="alert" tabindex="-1" id="error-summary">
            <p class="text-sm font-semibold text-red-800">برخی از ورودی‌ها نامعتبر هستند:</p>
            <ul class="mt-2 list-disc space-y-1 pe-5 text-sm text-red-700">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="admin-section space-y-4">
        <h2 class="text-base font-semibold">اطلاعات اصلی</h2>

        <div>
            <label class="label" for="name">نام محصول <span class="text-red-600" aria-hidden="true">*</span></label>
            <input class="input" type="text" id="name" name="name" value="{{ old('name', $product->name) }}"
                required maxlength="255">
            @error('name')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="label" for="summary">خلاصه کوتاه</label>
            <input class="input" type="text" id="summary" name="summary"
                value="{{ old('summary', $product->summary) }}" maxlength="255">
            @error('summary')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="label" for="description">توضیحات</label>
            <textarea class="input" id="description" name="description" rows="5">{{ old('description', $product->description) }}</textarea>
            @error('description')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="admin-section space-y-4">
        <h2 class="text-base font-semibold">قیمت، دسته‌بندی و فروش</h2>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="category">دسته‌بندی <span class="text-red-600" aria-hidden="true">*</span></label>
                <select class="input" id="category" name="category" required>
                    <option value="">انتخاب کنید</option>
                    @foreach (\App\Enums\ProductCategory::cases() as $option)
                        <option value="{{ $option->value }}"
                            @selected(old('category', $product->category?->value) === $option->value)>
                            {{ $option->label() }}
                        </option>
                    @endforeach
                </select>
                @error('category')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="label" for="condition">نوع کالا <span class="text-red-600" aria-hidden="true">*</span></label>
                <select class="input" id="condition" name="condition" required>
                    <option value="">انتخاب کنید</option>
                    @foreach (\App\Enums\ProductCondition::cases() as $option)
                        <option value="{{ $option->value }}"
                            @selected(old('condition', $product->condition?->value) === $option->value)>
                            {{ $option->label() }}
                        </option>
                    @endforeach
                </select>
                @error('condition')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="label" for="price">قیمت (تومان)</label>
                <input class="input" type="number" id="price" name="price" min="0" step="1" inputmode="numeric"
                    value="{{ old('price', $product->price) }}">
                <p class="mt-1 text-xs text-slate-500">خالی بگذارید تا «تماس بگیرید» نمایش داده شود.</p>
                @error('price')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
                @if ($product->price_changed_at)
                    <p class="mt-1 text-xs text-slate-500">آخرین تغییر قیمت: {{ \App\Support\FinanceTime::persianDate($product->price_changed_at) ?? \App\Support\FinanceTime::display($product->price_changed_at, false) }}</p>
                @endif
            </div>

            <div>
                <label class="label" for="stock_status">وضعیت موجودی <span class="text-red-600" aria-hidden="true">*</span></label>
                <select class="input" id="stock_status" name="stock_status" required>
                    <option value="">انتخاب کنید</option>
                    @foreach (\App\Enums\StockStatus::cases() as $option)
                        <option value="{{ $option->value }}"
                            @selected(old('stock_status', $product->stock_status?->value) === $option->value)>
                            {{ $option->label() }}
                        </option>
                    @endforeach
                </select>
                @error('stock_status')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            @foreach (['brand' => 'برند', 'model' => 'مدل', 'color' => 'رنگ', 'warranty_months' => 'مدت گارانتی (ماه)'] as $field => $label)
                <div>
                    <label class="label" for="{{ $field }}">{{ $label }}</label>
                    <input class="input" id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $product->{$field}) }}"
                        @if ($field === 'warranty_months') type="number" min="1" max="120" @else maxlength="100" @endif>
                </div>
            @endforeach
        </div>

        <div class="admin-toggle-grid">
            <div>
                <input type="hidden" name="is_published" value="0">
                <label class="admin-checkbox">
                    <input type="checkbox" name="is_published" value="1" class="size-4 rounded border-slate-300"
                        @checked($isPublishedChecked)>
                    <span>منتشر شود (نمایش در فروشگاه)</span>
                </label>
            </div>
            <div>
                <input type="hidden" name="online_purchasing_enabled" value="0">
                <label class="admin-checkbox">
                    <input type="checkbox" name="online_purchasing_enabled" value="1" class="size-4 rounded border-slate-300"
                        @checked(old('online_purchasing_enabled', $product->exists && $product->online_purchasing_enabled))>
                    <span>فعال‌سازی خرید آنلاین</span>
                </label>
            </div>
            <div>
                <input type="hidden" name="sale_paused" value="0">
                <label class="admin-checkbox">
                    <input type="checkbox" name="sale_paused" value="1" class="size-4 rounded border-slate-300"
                        @checked(old('sale_paused', $product->salePaused()))>
                    <span>توقف موقت فروش</span>
                </label>
            </div>
        </div>

        @if (isset($matchingInventory))
            <div class="admin-inline-association">
                <span>وضعیت انبار مرتبط:</span>
                <strong>
                    @if ($matchingInventory->isEmpty())
                        رکورد انبار متصل نیست
                    @elseif ($matchingInventory->count() !== 1)
                        بیش از یک رکورد انبار متصل است (نیاز به بررسی)
                    @else
                        موجودی آزاد: {{ \App\Support\FaNumber::digits($matchingInventory->first()->available) }} عدد
                    @endif
                </strong>
            </div>
        @endif
    </div>

    <div class="admin-section space-y-4">
        <h2 class="text-base font-semibold">تصویر محصول</h2>

        @if ($product->image_path)
            <div class="flex items-center gap-4">
                <img src="{{ route('products.media', basename($product->image_path)) }}"
                    alt="{{ $product->primaryImage()['alt'] ?? 'تصویر فعلی '.$product->name }}" width="80" height="80"
                    class="size-20 rounded-xl border border-slate-200 object-contain bg-white p-1">
                <p class="text-xs leading-6 text-slate-500">با انتخاب فایل جدید، تصویر فعلی جایگزین می‌شود.</p>
            </div>
        @endif

        <div>
            <label class="label" for="image">تصویر اصلی محصول</label>
            <div class="np-file-picker" data-np-file-picker>
                <input class="sr-only" type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp"
                    aria-describedby="image-hint" data-np-file-input>
                <label for="image" class="np-file-picker__btn">انتخاب تصویر</label>
                <span class="np-file-picker__name" data-np-file-name data-empty-label="فایلی انتخاب نشده است">فایلی انتخاب نشده است</span>
            </div>
            <p class="mt-1 text-xs leading-6 text-slate-500" id="image-hint">
                فرمت‌های JPEG، PNG یا WebP حداکثر ۲ مگابایت.
            </p>
            @error('image')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <details class="admin-accordion" @if ($hasGalleryErrors || !empty($product->galleryImages())) open @endif>
            <summary>
                <span>گالری تصاویر و متن جایگزین (اختیاری)</span>
                <svg class="admin-accordion__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </summary>
            <div class="admin-accordion__body space-y-4">
                <div>
                    <label class="label" for="image_alt">متن جایگزین تصویر اصلی</label>
                    <input class="input" id="image_alt" name="image_alt" type="text" maxlength="160"
                        value="{{ old('image_alt', $product->primaryImage()['alt'] ?? '') }}">
                    @error('image_alt')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                @if ($product->galleryImages())
                    <div class="flex flex-wrap gap-3" aria-label="تصاویر فعلی گالری">
                        @foreach ($product->galleryImages() as $image)
                            <img src="{{ route('products.media', basename($image['path'])) }}"
                                alt="{{ $image['alt'] ?? 'تصویر '.$product->name }}" loading="lazy" width="80" height="80"
                                class="size-20 rounded-xl border border-slate-200 object-contain bg-white p-1">
                        @endforeach
                    </div>
                    @if (count($product->galleryImages()) === 2)
                        <input type="hidden" name="gallery_revision" value="{{ $product->galleryRevision() }}">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="gallery_order" value="reverse" @checked(old('gallery_order') === 'reverse')>
                            جای دو تصویر فعلی گالری عوض شود
                        </label>
                        @error('gallery_order')<p role="alert" class="text-sm text-red-600">{{ $message }}</p>@enderror
                    @endif
                @endif
                <fieldset class="space-y-3">
                    <legend class="label">تصاویر گالری</legend>
                    <p class="text-xs leading-6 text-slate-500">تا دو تصویر تکمیلی انتخاب کنید.</p>
                    @for ($index = 0; $index < 2; $index++)
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="label" for="gallery-image-{{ $index }}">تصویر گالری {{ \App\Support\FaNumber::digits($index + 1) }}</label>
                                <div class="np-file-picker" data-np-file-picker>
                                    <input class="sr-only" type="file" id="gallery-image-{{ $index }}" name="gallery_images[{{ $index }}]"
                                        accept="image/jpeg,image/png,image/webp" data-np-file-input>
                                    <label for="gallery-image-{{ $index }}" class="np-file-picker__btn">انتخاب تصویر</label>
                                    <span class="np-file-picker__name" data-np-file-name data-empty-label="فایلی انتخاب نشده است">فایلی انتخاب نشده است</span>
                                </div>
                            </div>
                            <div>
                                <label class="label" for="gallery-alt-{{ $index }}">متن جایگزین تصویر گالری {{ \App\Support\FaNumber::digits($index + 1) }}</label>
                                <input class="input" type="text" id="gallery-alt-{{ $index }}" name="gallery_alts[{{ $index }}]"
                                    maxlength="160" value="{{ old('gallery_alts.'.$index) }}">
                            </div>
                        </div>
                    @endfor
                    @foreach ($errors->get('gallery_images*') + $errors->get('gallery_alts*') as $messages)
                        @foreach ($messages as $message)
                            <p class="text-xs text-red-600">{{ $message }}</p>
                        @endforeach
                    @endforeach
                </fieldset>
            </div>
        </details>
    </div>

    <details class="admin-accordion" @if (!empty($existingSpecs) || $errors->hasAny(['spec_labels', 'spec_values'])) open @endif>
        <summary>
            <span>جدول مشخصات فنی (اختیاری)</span>
            <svg class="admin-accordion__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </summary>
        <div class="admin-accordion__body space-y-3">
            <p class="text-xs leading-6 text-slate-500">هر ردیف یک «برچسب و مقدار» است؛ ردیف‌های خالی نادیده گرفته می‌شوند.</p>

        <div id="spec-rows" class="space-y-3" data-spec-next-index="{{ $specNextIndex }}">
            @foreach ($existingSpecs as $label => $value)
                <div data-spec-row class="grid gap-3 sm:grid-cols-[1fr_1.5fr_auto] sm:items-end">
                    <div>
                        <label class="label" for="spec-label-{{ $specRowIndex }}">برچسب</label>
                        <input class="input" type="text" id="spec-label-{{ $specRowIndex }}" name="spec_labels[]"
                            value="{{ $label }}" maxlength="100">
                    </div>
                    <div>
                        <label class="label" for="spec-value-{{ $specRowIndex }}">مقدار</label>
                        <input class="input" type="text" id="spec-value-{{ $specRowIndex }}" name="spec_values[]"
                            value="{{ $value }}" maxlength="200">
                    </div>
                    <button type="button" class="btn btn-secondary sm:mb-0.5" data-remove-spec-row>
                        حذف ردیف
                    </button>
                </div>
                @php $specRowIndex++ @endphp
            @endforeach

            @for ($i = 0; $i < $specBlankRows; $i++)
                <div data-spec-row class="grid gap-3 sm:grid-cols-[1fr_1.5fr_auto] sm:items-end">
                    <div>
                        <label class="label" for="spec-label-{{ $specRowIndex }}">برچسب</label>
                        <input class="input" type="text" id="spec-label-{{ $specRowIndex }}" name="spec_labels[]"
                            value="" maxlength="100">
                    </div>
                    <div>
                        <label class="label" for="spec-value-{{ $specRowIndex }}">مقدار</label>
                        <input class="input" type="text" id="spec-value-{{ $specRowIndex }}" name="spec_values[]"
                            value="" maxlength="200">
                    </div>
                    <button type="button" class="btn btn-secondary sm:mb-0.5" data-remove-spec-row>
                        حذف ردیف
                    </button>
                </div>
                @php $specRowIndex++ @endphp
            @endfor
        </div>

        @error('spec_labels')
            <p class="text-xs text-red-600">{{ $message }}</p>
        @enderror
        @error('spec_values')
            <p class="text-xs text-red-600">{{ $message }}</p>
        @enderror

        <template id="spec-row-template">
            <div data-spec-row class="grid gap-3 sm:grid-cols-[1fr_1.5fr_auto] sm:items-end">
                <div>
                    <label class="label" for="spec-label-__INDEX__">برچسب</label>
                    <input class="input" type="text" id="spec-label-__INDEX__" name="spec_labels[]" value=""
                        maxlength="100">
                </div>
                <div>
                    <label class="label" for="spec-value-__INDEX__">مقدار</label>
                    <input class="input" type="text" id="spec-value-__INDEX__" name="spec_values[]" value=""
                        maxlength="200">
                </div>
                <button type="button" class="btn btn-secondary sm:mb-0.5" data-remove-spec-row>
                    حذف ردیف
                </button>
            </div>
        </template>

            <button type="button" class="btn btn-secondary" data-add-spec-row>افزودن ردیف مشخصات</button>
        </div>
    </details>

    @include('admin.products._storefront')

    <details class="admin-accordion" @if ($hasAdvancedErrors) open @endif>
        <summary>
            <span>تنظیمات پیشرفته (نشانی اسلاگ، داده نمونه و تاریخ قیمت)</span>
            <svg class="admin-accordion__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </summary>
        <div class="admin-accordion__body grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="slug">اسلاگ (نشانی دستی)</label>
                <input class="input" type="text" id="slug" name="slug"
                    value="{{ old('slug', $product->slug) }}" maxlength="255" dir="ltr"
                    aria-describedby="slug-hint" placeholder="novin-pos">
                <p class="mt-1 text-xs leading-6 text-slate-500" id="slug-hint">
                    اگر خالی بماند، به‌صورت خودکار از نام محصول ساخته می‌شود.
                </p>
                @error('slug')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label class="label" for="currency">واحد قیمت</label>
                <select class="input" name="currency" id="currency"><option value="TOMAN">تومان</option></select>
            </div>
            @if (!$product->price_changed_at && $product->exists && \App\Support\ProductSale::priceConfirmed($product))
                <div class="sm:col-span-2">
                    <label class="label" for="historical_price_date">تاریخ ثبت قیمت فعلی (اختیاری)</label>
                    <input class="input" id="historical_price_date" name="historical_price_date" dir="ltr" maxlength="10"
                        placeholder="۱۴۰۵/۰۸/۱۰" value="{{ old('historical_price_date') }}" aria-describedby="price-date-help">
                    <p id="price-date-help" class="mt-1 text-xs text-slate-500">تغییرات قیمت جدید به‌طور خودکار تاریخ می‌خورند.</p>
                </div>
            @endif
            <div class="sm:col-span-2">
                <input type="hidden" name="is_sample" value="0">
                <label class="admin-checkbox">
                    <input type="checkbox" name="is_sample" value="1" class="size-4 rounded border-slate-300"
                        @checked($isSampleChecked)>
                    <span>داده نمونه (با نشان «داده نمونه» نمایش داده شود)</span>
                </label>
            </div>
        </div>
    </details>

    <div class="admin-form-footer">
        <button type="submit" class="btn btn-primary">{{ $submitLabel }}</button>
        <a href="{{ route('admin.products.index') }}" class="btn btn-secondary">انصراف</a>
    </div>
</form>
