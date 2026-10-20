@extends('layouts.app')
@section('title', 'کمی بعد دوباره تلاش کنید')
@section('content')
    <div class="np-container public-section">
        <x-page-heading eyebrow="محدودیت موقت" title="کمی بعد دوباره تلاش کنید" icon="clock" lead="تعداد تلاش‌ها از حد مجاز گذشته است. پس از پایان زمان انتظار، دوباره از فرم استفاده کنید." />
        <div class="public-actions"><a href="{{ route('repair') }}" class="np-button np-button--primary">بازگشت به فرم تعمیر</a><a href="{{ route('repair.track') }}" class="np-button np-button--outline">پیگیری درخواست</a></div>
    </div>
@endsection
