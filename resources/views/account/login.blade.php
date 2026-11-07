@extends('layouts.app')
@section('title', 'ورود به حساب کاربری')
@section('content')
<div class="container py-12"><section class="public-panel mx-auto max-w-lg"><h1>ورود به حساب کاربری</h1><p>با کد تأیید پیامکی وارد شوید؛ برای ثبت‌نام نیز همین مسیر را دنبال کنید.</p><x-admin.errors />
@if(session('success'))<p role="status">{{ session('success') }}</p>@endif
<form method="post" action="{{ route('account.otp') }}" class="mt-6 space-y-4">@csrf<label class="label" for="account-phone">شماره موبایل</label><input class="input" id="account-phone" type="tel" name="phone" dir="ltr" autocomplete="tel" inputmode="tel" maxlength="40" required><button class="btn btn-primary">ارسال کد تأیید</button></form>
@if(session('customer_challenge'))<form method="post" action="{{ route('account.verify') }}" class="mt-6 space-y-4">@csrf<label class="label" for="account-code">کد تأیید شش‌رقمی</label><input class="input" id="account-code" name="code" dir="ltr" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required><p>کد تا سه دقیقه معتبر است.</p><button class="btn btn-primary">ورود</button></form>@endif
</section></div>
@endsection
