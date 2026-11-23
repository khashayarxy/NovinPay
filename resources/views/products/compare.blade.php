@extends('layouts.app')
@section('title', 'مقایسهٔ محصولات')
@section('content')
<div class="np-container public-section">
    <a class="public-inline-link" href="{{ route('products.index') }}">بازگشت به فروشگاه</a>
    <h1 class="my-4 text-2xl font-bold">مقایسهٔ محصولات</h1>
    @if ($products->count() < 4 && $choices->isNotEmpty())
        <form class="public-form mb-6 max-w-lg" action="{{ route('products.compare') }}">
            @foreach ($products as $product)
                <input type="hidden" name="products[]" value="{{ $product->slug }}">
            @endforeach
            <label for="compare-add">افزودن محصول به مقایسه</label>
            <select class="input" id="compare-add" name="add" required>
                <option value="">محصول را انتخاب کنید</option>
                @foreach ($choices as $option)
                    <option value="{{ $option->slug }}">{{ $option->name }}</option>
                @endforeach
            </select>
            <button class="btn btn-primary">افزودن محصول</button>
        </form>
    @endif
    @if ($products->isNotEmpty())
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="جدول مقایسه">
            <table class="w-full text-start [&_th]:text-start [&_td]:min-w-40 [&_td]:p-3 [&_th]:p-3">
                <thead><tr class="border-b">
                    <th scope="col">مشخصات فنی</th>
                    @foreach ($products as $product)
                        <th scope="col">
                            <a class="public-inline-link" href="{{ route('products.show', $product) }}">{{ $product->name }}</a>
                            @if ($products->count() > 1)
                                <a class="block text-sm" href="{{ route('products.compare', ['products' => $products->where('id', '!=', $product->id)->pluck('slug')->values()->all()]) }}" aria-label="حذف {{ $product->name }} از مقایسه">حذف</a>
                            @endif
                        </th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @foreach ($specs->flatMap(fn (array $rows) => array_keys($rows))->unique() as $label)
                        <tr class="border-b">
                            <th scope="row">{{ $label }}</th>
                            @foreach ($products as $product)
                                <td><bdi>{{ $specs[$product->slug][$label] ?? 'ثبت نشده' }}</bdi></td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
