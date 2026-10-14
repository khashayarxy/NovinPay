@php
    $isPublishedChecked = old('is_published', $product->exists ? $product->is_published : true);
    $isSampleChecked = old('is_sample', $product->is_sample ?? false);
    $existingSpecs = $product->specifications ?? [];
    $specBlankRows = 3;
    $specNextIndex = count($existingSpecs) + $specBlankRows;
    $specRowIndex = 0;
@endphp

<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="space-y-6">
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

    <div class="card space-y-5 p-5 sm:p-6">
        <h2 class="text-base font-semibold">اطلاعات اصلی</h2>

        <div>
            <label class="label" for="name">نام محصول <span class="text-red-600"
                    aria-hidden="true">*</span></label>
            <input class="input" type="text" id="name" name="name" value="{{ old('name', $product->name) }}"
                required maxlength="255">
            @error('name')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="label" for="slug">اسلاگ</label>
            <input class="input" type="text" id="slug" name="slug"
                value="{{ old('slug', $product->slug) }}" maxlength="255" dir="ltr"
                aria-describedby="slug-hint" placeholder="novin-pos">
            <p class="mt-1 text-xs leading-6 text-slate-500" id="slug-hint">
                برای نمایش در آدرس صفحه. خالی بگذارید تا از نام محصول ساخته شود؛ فقط حروف کوچک انگلیسی، اعداد و خط
                تیره مجاز است.
            </p>
            @error('slug')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="label" for="summary">خلاصه</label>
            <input class="input" type="text" id="summary" name="summary"
                value="{{ old('summary', $product->summary) }}" maxlength="255">
            @error('summary')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="label" for="description">توضیحات</label>
            <textarea class="input" id="description" name="description" rows="6">{{ old('description', $product->description) }}</textarea>
            @error('description')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="card space-y-5 p-5 sm:p-6">
        <h2 class="text-base font-semibold">دسته‌بندی و وضعیت</h2>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label class="label" for="category">دسته‌بندی <span class="text-red-600"
                        aria-hidden="true">*</span></label>
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
                <label class="label" for="condition">نوع کالا <span class="text-red-600"
                        aria-hidden="true">*</span></label>
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
            </div>

            <div>
                <label class="label" for="stock_status">وضعیت موجودی <span class="text-red-600"
                        aria-hidden="true">*</span></label>
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
        </div>

        <div class="space-y-3 border-t border-slate-100 pt-5">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_published" value="1" class="size-4 rounded border-slate-300"
                    @checked($isPublishedChecked)>
                <span>منتشر شود (نمایش در وب‌سایت عمومی)</span>
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_sample" value="1" class="size-4 rounded border-slate-300"
                    @checked($isSampleChecked)>
                <span>داده نمونه (با نشان «داده نمونه» نمایش داده شود)</span>
            </label>
        </div>
    </div>

    <div class="card space-y-5 p-5 sm:p-6">
        <h2 class="text-base font-semibold">مشخصات فنی</h2>
        <p class="text-xs leading-6 text-slate-500">هر ردیف یک «برچسب و مقدار» است؛ ردیف‌های خالی نادیده گرفته
            می‌شوند.</p>

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

    <div class="card space-y-4 p-5 sm:p-6">
        <h2 class="text-base font-semibold">تصویر محصول</h2>

        @if ($product->image_path)
            <div class="flex items-center gap-4">
                <img src="{{ asset('storage/' . $product->image_path) }}" alt="تصویر فعلی {{ $product->name }}"
                    class="size-20 rounded-lg border border-slate-200 object-cover">
                <p class="text-xs leading-6 text-slate-500">با انتخاب فایل جدید، تصویر فعلی جایگزین می‌شود.</p>
            </div>
        @endif

        <div>
            <label class="label" for="image">تصویر جدید</label>
            <input class="input" type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp"
                aria-describedby="image-hint">
            <p class="mt-1 text-xs leading-6 text-slate-500" id="image-hint">
                فرمت‌های JPEG، PNG یا WebP حداکثر ۲ مگابایت.
            </p>
            @error('image')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <button type="submit" class="btn btn-primary">{{ $submitLabel }}</button>
        <a href="{{ route('admin.products.index') }}" class="btn btn-secondary">انصراف</a>
    </div>
</form>
