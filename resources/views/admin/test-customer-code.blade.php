@extends('layouts.admin')
@section('title', 'کد تأیید آزمایشی')
@section('content')
<header class="admin-page-heading">
    <h1>کد تأیید آزمایشی</h1>
    <a class="admin-text-link" href="{{ route('admin.test-customers.index') }}">حساب‌های آزمایشی</a>
</header>
<section class="admin-panel admin-password-form">
    <p class="text-2xl font-bold"><bdi dir="ltr">{{ $code }}</bdi></p>
    <p class="admin-hint">کد یک‌بارمصرف را در صفحهٔ ورود مشتری وارد کنید.</p>
</section>
@endsection
