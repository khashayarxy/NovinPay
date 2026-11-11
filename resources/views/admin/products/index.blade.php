@extends('layouts.admin')

@section('title', 'مدیریت محصولات و موجودی')

@section('content')
    <header class="admin-page-heading">
        <h1>مدیریت محصولات و موجودی</h1>
        @can('products.manage')<a href="{{ route('admin.products.create') }}" class="btn btn-primary">افزودن محصول</a>@endcan
    </header>
    <x-admin.catalog-navigation />
    @if ($products->isEmpty())
        <section class="admin-panel"><x-admin.empty-state title="هنوز محصولی ثبت نشده است" icon="shopping-bag" /></section>
    @else
        <div class="admin-panel admin-collection admin-table-wrap" role="region" aria-label="محصولات" tabindex="0">
            <table class="admin-table admin-compact-table admin-catalog-table" role="table">
                <thead role="rowgroup">
                    <tr role="row">
                        <th scope="col" role="columnheader">محصول</th>
                        <th scope="col" role="columnheader">دسته‌بندی</th>
                        <th scope="col" role="columnheader">قیمت</th>
                        <th scope="col" role="columnheader">وضعیت کاتالوگ</th>
                        @if($stockByProduct !== null)<th scope="col" role="columnheader">موجودی فیزیکی</th>@endif
                        <th scope="col" role="columnheader"><span
                                class="sr-only">عملیات</span></th>
                    </tr>
                </thead>
                <tbody role="rowgroup">
                    @foreach ($products as $product)
                        <tr role="row">
                            <td role="cell">
                                <strong class="admin-catalog-name">{{ $product->name }}</strong>
                                <span class="admin-table-meta"><bdi dir="ltr">{{ $product->slug }}</bdi></span>
                            </td>
                            <td role="cell"><span class="admin-mobile-cell-label" aria-hidden="true">دسته‌بندی</span>{{ $product->category->label() }}</td>
                            <td role="cell">
                                <span class="admin-mobile-cell-label" aria-hidden="true">قیمت</span>
                                @if ($product->price !== null)
                                    {{ \App\Support\FaNumber::digits(number_format($product->price)) }}
                                    <span class="text-xs text-slate-500">تومان</span>
                                @else
                                    <span class="text-xs text-slate-500">تماس بگیرید</span>
                                @endif
                            </td>
                            <td role="cell">
                                <span class="admin-mobile-cell-label" aria-hidden="true">وضعیت کاتالوگ</span>
                                <div class="flex flex-wrap gap-1.5">
                                    @if ($product->is_published)
                                        <span class="badge badge-success">منتشرشده</span>
                                    @else
                                        <span class="badge badge-warning">پیش‌نویس</span>
                                    @endif
                                    @if ($product->is_sample)
                                        <span class="badge badge-sample">داده نمونه</span>
                                    @endif
                                </div>
                                <small class="admin-table-meta">{{ $product->stock_status->label() }}</small>
                            </td>
                            @if($stockByProduct !== null)
                                @php($stock = $stockByProduct->get($product->id))
                                <td role="cell">
                                    <span class="admin-mobile-cell-label" aria-hidden="true">موجودی فیزیکی</span>
                                    @if($stock)
                                        <div>آزاد <strong>{{ \App\Support\FaNumber::digits($stock->physical - $stock->reserved) }}</strong></div>
                                        <small class="admin-table-meta">کل {{ \App\Support\FaNumber::digits($stock->physical) }}</small>
                                        @if($stock->low)<small class="commerce-low">نیاز به تأمین</small>@endif
                                        <a class="admin-text-link" href="{{ route('admin.inventory.index', ['product' => $product->id]) }}">جزئیات موجودی</a>
                                    @else
                                        <span class="admin-table-meta">موجودی تعریف نشده</span>
                                    @endif
                                </td>
                            @endif
                            <td role="cell">
                                @can('products.manage')<div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('admin.products.edit', $product) }}"
                                        class="admin-text-link">ویرایش</a>
                                    <details class="admin-row-actions"><summary>بیشتر</summary>
                                    <form method="POST" action="{{ route('admin.products.destroy', $product) }}"
                                        data-confirm="محصول «{{ $product->name }}» حذف شود؟ این عملیات قابل بازگشت نیست.">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="font-medium text-red-600 hover:underline">حذف</button>
                                    </form></details>
                                </div>@else<span class="text-xs text-slate-500">فقط مشاهده</span>@endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($products->hasPages())
            <div class="mt-6">
                {{ $products->links() }}
            </div>
        @endif
    @endif
@endsection
