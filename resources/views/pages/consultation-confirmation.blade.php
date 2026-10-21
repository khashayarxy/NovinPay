@extends('layouts.app')
@section('title', 'درخواست مشاوره ثبت شد')
@section('content')
<div class="np-container public-section"><section class="public-panel public-confirmation"><span class="public-icon"><x-home.icon name="shield-check"/></span><h1 class="text-2xl font-bold mt-4">درخواست مشاوره ثبت شد</h1><p class="mt-4">پرسش شما برای بررسی همکاران نوین‌پی ثبت شده است. زمان مشخصی برای پاسخ تضمین نمی‌شود.</p><p class="public-hint">این فرم، پذیرش دستگاه یا سفارش خرید نیست. اطلاعات شخصی درخواست در این صفحه نمایش داده نمی‌شود.</p><a class="np-button np-button--primary mt-6" href="{{ route('home') }}">بازگشت به صفحهٔ اصلی</a></section></div>
@endsection
