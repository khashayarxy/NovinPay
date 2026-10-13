@extends('layouts.app')

@section('title', $product->name)
@section('meta_description', \Illuminate\Support\Str::limit(strip_tags($product->summary ?? $product->description ?? ''), 150))

@section('content')
    <div class="container-page section">
        <nav aria-label="مسیر صفحه" class="text-sm text-slate-500">
            <ol class="flex flex-wrap items-center gap-2">
                <li><a href="{{ route('home') }}" class="hover:text-brand-700">خانه</a></li>
                <li aria-hidden="true">/</li>
                <li><a href="{{ route('products.index') }}" class="hover:text-brand-700">محصولات</a></li>
                <li aria-hidden="true">/</li>
                <li class="text-slate-700">{{ $product->name }}</li>
            </ol>
        </nav>

        <div class="mt-6 grid gap-8 lg:grid-cols-2">
            <div class="card overflow-hidden">
                <div class="aspect-square bg-slate-100">
                    <x-product-image :product="$product" />
                </div>
                @unless ($product->image_path)
                    <p class="border-t border-slate-100 px-4 py-3 text-center text-xs text-slate-500">
                        تصویر واقعی کالا هنوز ثبت نشده است.
                    </p>
                @endunless
            </div>

            <div>
                <div class="flex flex-wrap gap-2">
                    <span class="badge {{ $product->condition->value === 'new' ? 'badge-success' : 'badge-warning' }}">
                        وضعیت: {{ $product->condition->label() }}
                    </span>
                    <span
                        class="badge {{ $product->stock_status->value === 'out_of_stock' ? 'badge-warning' : 'badge-success' }}">
                        {{ $product->stock_status->label() }}
                    </span>
                    <span class="badge badge-neutral">{{ $product->category->label() }}</span>
                    @if ($product->is_sample)
                        <span class="badge badge-sample">داده نمونه</span>
                    @endif
                </div>

                <h1 class="mt-4 text-2xl font-bold leading-9 tracking-tight sm:text-3xl">{{ $product->name }}</h1>

                @if ($product->summary)
                    <p class="mt-3 text-sm leading-8 text-slate-600 sm:text-base">{{ $product->summary }}</p>
                @endif

                <div class="mt-6 rounded-xl border border-slate-200 bg-white p-5">
                    <p class="text-xs text-slate-500">قیمت</p>
                    <p class="mt-1 text-2xl font-bold text-slate-900">
                        @if ($product->price !== null)
                            {{ \App\Support\FaNumber::digits(number_format($product->price)) }}
                            <span class="text-sm font-normal text-slate-500">تومان</span>
                        @else
                            <span class="text-base font-semibold text-slate-700">تماس بگیرید</span>
                        @endif
                    </p>
                    <p class="mt-3 text-xs leading-6 text-slate-500">
                        خرید آنلاین در این نسخه فعال نیست؛ برای استعلام قیمت و شرایط خرید با ما تماس بگیرید.
                    </p>
                </div>

                @if ($product->is_sample)
                    <p class="mt-4 rounded-lg border border-dashed border-sky-300 bg-sky-50 px-4 py-3 text-xs leading-6 text-sky-800">
                        این کالا داده نمونه برای نمایش وب‌سایت است و موجودی یا قیمت واقعی محسوب نمی‌شود.
                    </p>
                @endif

                <div class="mt-6 flex flex-wrap gap-3">
                    <a href="{{ route('contact') }}" class="btn btn-primary">استعلام و خرید</a>
                    <a href="{{ route('repair') }}" class="btn btn-secondary">درخواست تعمیر دستگاه</a>
                </div>
            </div>
        </div>

        <div class="mt-12 grid gap-8 lg:grid-cols-2">
            <section class="card p-6" aria-labelledby="description-heading">
                <h2 id="description-heading" class="text-lg font-semibold">توضیحات</h2>
                <div class="prose-persian mt-4 text-sm">
                    {!! nl2br(e($product->description ?: 'توضیحاتی برای این کالا ثبت نشده است.')) !!}
                </div>
            </section>

            <section class="card p-6" aria-labelledby="specs-heading">
                <h2 id="specs-heading" class="text-lg font-semibold">مشخصات فنی</h2>
                @if ($product->specifications && count($product->specifications) > 0)
                    <dl class="mt-4 divide-y divide-slate-100 text-sm">
                        @foreach ($product->specifications as $label => $value)
                            <div class="flex items-start justify-between gap-4 py-3">
                                <dt class="text-slate-500">{{ $label }}</dt>
                                <dd class="text-start font-medium text-slate-800">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @else
                    <p class="mt-4 text-sm text-slate-600">مشخصات فنی برای این کالا ثبت نشده است.</p>
                @endif
            </section>
        </div>
    </div>
@endsection
