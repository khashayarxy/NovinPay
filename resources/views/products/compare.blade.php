@extends('layouts.app')
@section('title', 'مقایسهٔ محصولات')
@section('content')
    <div class="np-container public-section">
        <a class="public-inline-link" href="{{ route('products.index') }}">بازگشت به فروشگاه</a>
        <h1 class="my-4 text-2xl font-bold">مقایسهٔ محصولات</h1>
        @if ($products->count() < 2)
            <p>برای مقایسه، دست‌کم دو محصول از فروشگاه انتخاب کنید.</p>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($products as $product)
                    <div class="min-w-0">
                        <x-product-card :product="$product" :sale="$sales[$product->id]" />
                        <dl class="card mt-3 grid gap-2 p-4 text-sm">
                            @foreach (['برند' => $product->brand, 'مدل' => $product->model, 'رنگ' => $product->color, 'گارانتی' => $product->warranty_months ? \App\Support\FaNumber::digits($product->warranty_months).' ماه' : null] as $key => $value)
                                <div class="flex flex-wrap justify-between gap-2"><dt>{{ $key }}</dt><dd><bdi>{{ $value ?? 'ثبت نشده' }}</bdi></dd></div>
                            @endforeach
                        </dl>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endsection
