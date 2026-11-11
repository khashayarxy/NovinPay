@extends('layouts.app')
@section('title', 'درخواست آنلاین ثبت شد')
@section('content')
<div class="np-container public-section">
    <section class="public-panel public-confirmation" aria-labelledby="confirmation-title">
        <span class="public-icon public-icon--large"><x-home.icon name="check" /></span>
        <p class="np-eyebrow">ثبت اولیه انجام شد</p>
        <h1 id="confirmation-title">درخواست آنلاین شما ثبت شد</h1>
        <p>کد پیگیری را ذخیره کنید. برای پیگیری به این کد و شماره موبایل ثبت‌شده نیاز دارید.</p>
        <p class="public-reference" dir="ltr">{{ $reference }}</p>
        <div class="public-notice"><h2>{{ \App\Enums\RepairRequestStatus::OnlineSubmitted->label() }}</h2><p>ثبت درخواست به معنی تحویل دستگاه، تأیید هزینه یا شروع تعمیر نیست. برای هماهنگی مراجعه با ما تماس بگیرید.</p></div>
        <div class="public-actions"><a href="{{ route('repair.track') }}" class="np-button np-button--primary">پیگیری درخواست</a><a href="{{ config('business.phone_uri') }}" class="np-button np-button--outline">تماس با نوین‌پی</a></div>
    </section>
</div>
@endsection
