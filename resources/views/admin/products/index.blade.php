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
                        @php($stock = $stockByProduct !== null ? $stockByProduct->get($product->id) : null)
                        <tr role="row">
                            <td role="cell">
                                <strong class="admin-catalog-name">{{ \App\Support\LatinText::html($product->name) }}</strong>
                                <span class="admin-table-meta np-latin" dir="ltr">{{ $product->slug }}</span>
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
                                    @if ($product->isPrivatePaymentTest())
                                        <span class="badge badge-sample">تست خصوصی</span>
                                    @endif
                                    @if ($product->is_sample)
                                        <span class="badge badge-sample">داده نمونه</span>
                                    @endif
                                    @if ($product->stock_status !== \App\Enums\StockStatus::InStock)
                                        <span class="badge badge-warning">{{ $product->stock_status->label() }}</span>
                                    @endif
                                </div>
                            </td>
                            @if($stockByProduct !== null)
                                <td role="cell">
                                    <span class="admin-mobile-cell-label" aria-hidden="true">موجودی فیزیکی</span>
                                    @if($stock)
                                        <div>موجودی فیزیکی: <strong>{{ \App\Support\FaNumber::digits($stock->physical) }} عدد</strong> <span class="admin-table-meta">({{ \App\Support\FaNumber::digits($stock->physical - $stock->reserved) }} آزاد)</span></div>
                                        @if($stock->low)<small class="commerce-low">نیاز به تأمین</small>@endif
                                    @else
                                        <span class="admin-table-meta">موجودی تعریف نشده</span>
                                    @endif
                                </td>
                            @endif
                            <td role="cell">
                                @can('products.manage')
                                    <div class="admin-row-actions-bar">
                                        <a href="{{ route('admin.products.edit', $product) }}" class="btn btn-secondary btn-sm">ویرایش</a>
                                        <a href="{{ route('admin.products.feedback.index', $product) }}" class="btn btn-secondary btn-sm">دیدگاه‌ها</a>
                                        @if($stockByProduct !== null && $stock)
                                            <a href="{{ route('admin.inventory.index', ['product' => $product->id]) }}" class="btn btn-secondary btn-sm">جزئیات موجودی</a>
                                        @endif
                                        <form method="POST" action="{{ route('admin.products.destroy', $product) }}"
                                            data-confirm="محصول «{{ $product->name }}» حذف شود؟ این عملیات قابل بازگشت نیست.">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn-danger-ghost">حذف</button>
                                        </form>
                                    </div>
                                @else
                                    @if($stockByProduct !== null && $stock)
                                        <a href="{{ route('admin.inventory.index', ['product' => $product->id]) }}" class="btn btn-secondary btn-sm">جزئیات موجودی</a>
                                    @else
                                        <span class="text-xs text-slate-500">فقط مشاهده</span>
                                    @endif
                                @endcan
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
