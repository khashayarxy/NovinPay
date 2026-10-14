@extends('layouts.admin')

@section('title', 'مدیریت محصولات')

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold">مدیریت محصولات</h1>
            <p class="mt-1 text-sm text-slate-600">
                محصولات منتشرشده در وب‌سایت عمومی نمایش داده می‌شوند.
            </p>
        </div>
        <a href="{{ route('admin.products.create') }}" class="btn btn-primary">افزودن محصول</a>
    </div>

    @if ($products->isEmpty())
        <div class="card mt-6 p-8 text-center">
            <p class="text-sm text-slate-600">هنوز محصولی ثبت نشده است.</p>
            <a href="{{ route('admin.products.create') }}" class="btn btn-primary mt-4">افزودن اولین محصول</a>
        </div>
    @else
        <div class="card mt-6 overflow-x-auto">
            <table class="w-full min-w-[46rem] text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-right">
                        <th scope="col" class="px-4 py-3 font-semibold text-slate-700">محصول</th>
                        <th scope="col" class="px-4 py-3 font-semibold text-slate-700">دسته‌بندی</th>
                        <th scope="col" class="px-4 py-3 font-semibold text-slate-700">قیمت</th>
                        <th scope="col" class="px-4 py-3 font-semibold text-slate-700">وضعیت</th>
                        <th scope="col" class="px-4 py-3 font-semibold text-slate-700"><span
                                class="sr-only">عملیات</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($products as $product)
                        <tr>
                            <td class="px-4 py-3">
                                <p class="font-medium text-slate-900">{{ $product->name }}</p>
                                <p class="mt-0.5 text-xs text-slate-500" dir="ltr">{{ $product->slug }}</p>
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $product->category->label() }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-slate-600">
                                @if ($product->price !== null)
                                    {{ \App\Support\FaNumber::digits(number_format($product->price)) }}
                                    <span class="text-xs text-slate-500">تومان</span>
                                @else
                                    <span class="text-xs text-slate-500">تماس بگیرید</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
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
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('admin.products.edit', $product) }}"
                                        class="font-medium text-brand-700 hover:underline">ویرایش</a>
                                    <form method="POST" action="{{ route('admin.products.destroy', $product) }}"
                                        data-confirm="محصول «{{ $product->name }}» حذف شود؟ این عملیات قابل بازگشت نیست.">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="font-medium text-red-600 hover:underline">حذف</button>
                                    </form>
                                </div>
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
