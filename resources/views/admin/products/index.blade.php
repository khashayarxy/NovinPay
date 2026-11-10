@extends('layouts.admin')

@section('title', 'مدیریت محصولات')

@section('content')
    <header class="admin-page-heading">
        <h1>محصولات</h1>
        @can('products.manage')<a href="{{ route('admin.products.create') }}" class="btn btn-primary">افزودن محصول</a>@endcan
    </header>
    @if ($products->isEmpty())
        <section class="admin-panel"><x-admin.empty-state title="هنوز محصولی ثبت نشده است" icon="shopping-bag">محصولات کاتالوگ پس از ثبت در این فهرست نمایش داده می‌شوند.</x-admin.empty-state></section>
    @else
        <div class="admin-panel admin-collection admin-table-wrap" role="region" aria-label="محصولات" tabindex="0">
            <table class="admin-table">
                <thead>
                    <tr class="border-b border-slate-200 text-right">
                        <th scope="col">محصول</th>
                        <th scope="col">دسته‌بندی</th>
                        <th scope="col">قیمت</th>
                        <th scope="col">وضعیت</th>
                        <th scope="col"><span
                                class="sr-only">عملیات</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($products as $product)
                        <tr>
                            <td>
                                <p class="font-medium text-slate-900 break-words">{{ $product->name }}</p>
                                <p class="mt-0.5 text-xs text-slate-500" dir="ltr">{{ $product->slug }}</p>
                            </td>
                            <td>{{ $product->category->label() }}</td>
                            <td>
                                @if ($product->price !== null)
                                    {{ \App\Support\FaNumber::digits(number_format($product->price)) }}
                                    <span class="text-xs text-slate-500">تومان</span>
                                @else
                                    <span class="text-xs text-slate-500">تماس بگیرید</span>
                                @endif
                            </td>
                            <td>
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
                            </td>
                            <td>
                                @can('products.manage')<div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('admin.products.edit', $product) }}"
                                        class="font-medium text-brand-700 hover:underline">ویرایش</a>
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
