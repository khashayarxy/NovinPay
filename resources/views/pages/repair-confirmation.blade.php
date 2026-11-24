@extends('layouts.app')
@section('title', 'تأیید ثبت درخواست تعمیر')
@section('content')
<div class="np-container public-section repair-confirm-page" data-repair-confirmed>
    <section class="public-panel public-confirmation repair-confirm-card" aria-labelledby="confirmation-title">
        <span class="public-icon public-icon--large"><x-home.icon name="check" /></span>
        <h1 id="confirmation-title">درخواست تعمیر شما با موفقیت ثبت شد</h1>
        <p class="repair-confirm-lead">کد پیگیری زیر را نگه دارید. در تمام مراحل بررسی و تحویل، با این کد و شماره موبایل خود می‌توانید وضعیت تعمیر را ببینید.</p>
        <div class="repair-confirmation-code">
            <span class="repair-confirmation-code__label">کد پیگیری</span>
            <p class="public-reference" id="repair-reference-code" dir="ltr">{{ $reference }}</p>
            <button type="button" class="repair-confirmation-code__copy" data-copy-account="repair-reference-code" aria-describedby="repair-copy-status" hidden>کپی کد پیگیری</button>
            <p class="repair-confirmation-code__status" id="repair-copy-status" role="status"></p>
        </div>
        <div class="public-notice repair-confirm-notice">
            <h2>{{ \App\Enums\RepairRequestStatus::OnlineSubmitted->label() }}</h2>
            <ol class="repair-confirm-steps">
                <li>کد پیگیری را یادداشت یا کپی کنید.</li>
                @if (session('repair_handover') === 'doorstep_pickup')
                    <li>همکاران ما برای هماهنگی زمان مراجعهٔ پیک با شماره تماس شما ارتباط می‌گیرند.</li>
                @elseif (session('repair_handover') === 'self_ship')
                    <li>دستگاه را ایمن بسته‌بندی کرده، کد پیگیری را داخل بسته بگذارید و هنگام ارسال با پست/تیپاکس حتماً «بیمهٔ مرسوله» را فعال کنید.</li>
                @else
                    <li>دستگاه را به همراه لوازم انتخاب‌شده حضوری تحویل دهید یا پس از هماهنگی با ما ارسال کنید.</li>
                @endif
                <li>پس از عیب‌یابی فنی، هزینه و زمان تعمیر با پیامک به شما اعلام می‌شود.</li>
            </ol>
        </div>
        <div class="public-actions repair-confirm-actions">
            <a href="{{ route('repair.track') }}" class="np-button np-button--primary">پیگیری وضعیت <x-home.icon name="arrow-left" /></a>
            <a href="{{ route('account.index', ['section' => 'repairs']) }}" class="np-button np-button--outline">تعمیرات در حساب کاربری</a>
            <a href="{{ config('business.phone_uri') }}" class="np-button np-button--outline">تماس با نوین‌پی</a>
        </div>
    </section>
</div>
@endsection
