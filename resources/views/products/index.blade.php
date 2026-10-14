@extends('layouts.app')

@section('title', 'محصولات')

@section('content')
    <div class="container-page section">
        <header>
            <h1 class="section-title">محصولات</h1>
            <p class="section-lead">
                فهرست دستگاه‌های کارتخوان و لوازم جانبی. جستجو و دسته‌بندی بر اساس داده‌های ثبت‌شده در وب‌سایت
                انجام می‌شود.
            </p>
        </header>

        <form method="get" action="{{ route('products.index') }}" class="card mt-8 p-4 sm:p-5">
            @if ($errors->any())
                <p id="error-summary" role="alert" tabindex="-1"
                    class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    {{ $errors->first() }}
                </p>
            @endif

            <div class="grid gap-4 md:grid-cols-[1fr_auto_auto] md:items-end">
                <div>
                    <label for="q" class="label">جستجو</label>
                    <input type="search" id="q" name="q" value="{{ $term }}" class="input"
                        placeholder="نام یا توضیحات محصول" maxlength="100">
                </div>

                <div>
                    <label for="category" class="label">دسته‌بندی</label>
                    <select id="category" name="category" class="input md:w-56">
                        <option value="">همه دسته‌بندی‌ها</option>
                        @foreach ($categories as $item)
                            <option value="{{ $item->value }}" @selected($category?->value === $item->value)>
                                {{ $item->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary">اعمال فیلتر</button>
                    <a href="{{ route('products.index') }}" class="btn btn-secondary">پاک کردن</a>
                </div>
            </div>
        </form>

        <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
            <h2 class="sr-only">فهرست محصولات</h2>
            <p class="text-sm text-slate-600">
                @if ($products->total() === 0)
                    هیچ محصولی یافت نشد
                @else
                    {{ \App\Support\FaNumber::digits($products->total()) }} محصول یافت شد
                @endif
            </p>
            @if ($hasFilters)
                <p class="text-xs text-slate-500">نتایج بر اساس فیلترهای اعمال‌شده نمایش داده می‌شوند.</p>
            @endif
        </div>

        <div class="mt-6">
            @if ($products->isEmpty())
                @if ($hasFilters)
                    <x-empty-state title="محصولی مطابق جستجوی شما پیدا نشد"
                        message="عبارت جستجو یا دسته‌بندی انتخاب‌شده را تغییر دهید و دوباره تلاش کنید.">
                        <a href="{{ route('products.index') }}" class="btn btn-secondary">نمایش همه محصولات</a>
                    </x-empty-state>
                @else
                    <x-empty-state title="هنوز محصولی ثبت نشده است"
                        message="کاتالوگ محصولات خالی است. اطلاعات کالاها پس از تأیید نهایی در این بخش قرار می‌گیرد.">
                        <a href="{{ route('services') }}" class="btn btn-secondary">آشنایی با خدمات</a>
                    </x-empty-state>
                @endif
            @else
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($products as $product)
                        <x-product-card :product="$product" />
                    @endforeach
                </div>

                @if ($products->hasPages())
                    <div class="mt-10">
                        {{ $products->links() }}
                    </div>
                @endif
            @endif
        </div>
    </div>
@endsection
