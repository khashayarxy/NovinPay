@extends('layouts.admin')
@section('title', 'درخواست‌های آنلاین تعمیر')
@section('content')
<header class="admin-page-heading"><div><p class="admin-eyebrow">نمای فقط‌خواندنی</p><h1>درخواست‌های آنلاین تعمیر</h1><p>ثبت فرم آنلاین به معنی دریافت فیزیکی دستگاه نیست.</p></div><span class="admin-status">{{ \App\Support\FaNumber::digits($repairs->total()) }} درخواست</span></header>
<div class="admin-notice"><h2>بدون پذیرش یا تغییر وضعیت</h2><p>این بخش فقط اطلاعات درخواست اولیه را نمایش می‌دهد؛ هزینه، دریافت فیزیکی، تعمیر یا تحویل دستگاه در این مرحله ثبت نمی‌شود.</p></div>
@if($repairs->isEmpty())<div class="admin-panel admin-empty"><x-home.icon name="tool" /><h2>هنوز درخواست آنلاینی ثبت نشده است</h2><p>درخواست‌های ثبت‌شده در فرم عمومی در این بخش ظاهر می‌شوند.</p></div>@else
<div class="admin-panel admin-table-wrap" role="region" aria-label="درخواست‌های آنلاین" tabindex="0"><table class="admin-table"><thead><tr><th scope="col">شمارهٔ داخلی</th><th scope="col">برند</th><th scope="col">وضعیت</th><th scope="col">ثبت (UTC)</th><th scope="col">عملیات</th></tr></thead><tbody>
@foreach($repairs as $repair)<tr><td>{{ \App\Support\FaNumber::digits($repair->id) }}</td><td><bdi>{{ $repair->device_brand->value }}</bdi></td><td><span class="badge badge-neutral">درخواست آنلاین</span><p>دریافت فیزیکی تأیید نشده</p></td><td><time datetime="{{ $repair->created_at->toIso8601String() }}" dir="ltr">{{ \App\Support\FaNumber::digits($repair->created_at->format('Y/m/d H:i')) }}</time></td><td><a href="{{ route('admin.repairs.show', $repair) }}" class="admin-text-link">مشاهدهٔ جزئیات</a></td></tr>@endforeach
</tbody></table></div><div class="mt-6">{{ $repairs->links() }}</div>@endif
@endsection
