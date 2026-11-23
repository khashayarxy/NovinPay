@extends('layouts.app')
@section('title', 'بازیابی کد پیگیری')
@section('content')
<div class="np-container public-section repair-track-page">
    <div class="repair-track-shell">
        <header class="store-page-intro"><h1>بازیابی کد پیگیری</h1></header>
        <section class="public-panel">
            <x-form-errors />
            @if ($verified)
                <h2>کدهای پیگیری تعمیر</h2>
                <ul class="mt-4 space-y-2">
                    @forelse ($codes as $code)
                        <li><a class="public-inline-link" href="{{ route('repair.track.recovered', ['reference' => $code]) }}">پیگیری تعمیر <bdi dir="ltr">{{ \App\Support\FaNumber::digits($code) }}</bdi></a></li>
                    @empty
                        <li>تعمیر یا درخواستی برای این شماره ثبت نشده است.</li>
                    @endforelse
                </ul>
            @else
                @if ($challenge)
                    <form class="public-form" method="post" action="{{ route('repair.track.recovery.verify') }}">
                        @csrf
                        <x-repair-field name="code" label="کد تأیید پیامکی">
                            <input class="input" id="code" name="code" dir="ltr" inputmode="numeric" autocomplete="one-time-code" minlength="6" maxlength="6" required>
                        </x-repair-field>
                        <button class="np-button np-button--primary">تأیید و نمایش کدهای پیگیری</button>
                    </form>
                @endif
                <form class="public-form mt-4" method="post" action="{{ route('repair.track.recovery.send') }}">
                    @csrf
                    <x-repair-field name="contact_phone" label="شماره موبایل">
                        <input class="input" id="contact_phone" name="contact_phone" type="tel" dir="ltr" maxlength="40" autocomplete="tel" required>
                    </x-repair-field>
                    <button class="np-button {{ $challenge ? 'np-button--outline' : 'np-button--primary' }}">{{ $challenge ? 'درخواست کد تازه' : 'دریافت کد تأیید' }}</button>
                </form>
            @endif
            <a class="public-inline-link mt-4" href="{{ route('repair.track') }}">بازگشت به پیگیری تعمیر</a>
        </section>
    </div>
</div>
@endsection
