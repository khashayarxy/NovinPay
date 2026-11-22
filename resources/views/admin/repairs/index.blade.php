@extends('layouts.admin')
@section('title', 'درخواست‌های آنلاین تعمیر')
@section('content')
<header class="admin-page-heading"><div><h1>درخواست‌های آنلاین تعمیر</h1><p>ثبت فرم آنلاین به معنی دریافت فیزیکی دستگاه نیست.</p></div><span class="admin-status">{{ \App\Support\FaNumber::digits($repairs->total()) }} درخواست</span></header>
@if($repairs->isEmpty())<div class="admin-panel admin-empty"><x-home.icon name="tool" /><h2>هنوز درخواست آنلاینی ثبت نشده است</h2></div>@else
<div class="admin-panel admin-table-wrap" role="region" aria-label="درخواست‌های آنلاین" tabindex="0"><table class="admin-table admin-table--cards"><thead><tr><th scope="col">شمارهٔ داخلی</th><th scope="col">برند</th><th scope="col">وضعیت</th><th scope="col">زمان ثبت</th><th scope="col">عملیات</th></tr></thead><tbody>
@foreach($repairs as $repair)<tr><td data-label="شمارهٔ داخلی">{{ \App\Support\FaNumber::digits($repair->id) }}</td><td data-label="برند"><bdi>{{ $repair->device_brand->value }}</bdi></td><td data-label="وضعیت"><span class="badge badge-neutral">{{ $repair->physical_case_exists ? 'پذیرش ثبت شده' : 'در انتظار پذیرش' }}</span></td><td data-label="زمان ثبت"><x-finance-time :value="$repair->created_at" /></td><td data-label="عملیات"><a href="{{ route('admin.repairs.show', $repair) }}" class="admin-text-link">مشاهدهٔ جزئیات</a></td></tr>@endforeach
</tbody></table></div><div class="mt-6">{{ $repairs->links() }}</div>@endif
@endsection
