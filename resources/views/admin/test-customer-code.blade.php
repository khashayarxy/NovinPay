@extends('layouts.admin')
@section('title', 'کد تأیید آزمایشی')
@section('content')
<section class="admin-panel">
    <h1>کد تأیید آزمایشی</h1>
    <p>این کد را فقط در صفحهٔ ورود مشتری وارد کنید. پس از استفاده یا پایان اعتبار، کد قابل استفاده نیست.</p>
    <p class="my-4 text-3xl font-bold"><bdi dir="ltr">{{ $code }}</bdi></p>
    <a class="btn btn-secondary" href="{{ route('admin.test-customers.index') }}">بازگشت به حساب‌های آزمایشی</a>
</section>
@endsection
