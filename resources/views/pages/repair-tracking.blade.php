@extends('layouts.app')
@section('title', 'پیگیری درخواست')
@section('content')
<div class="np-container public-section repair-track-page">
    <div @class(['repair-track-layout', 'repair-entry-layout--single' => $matched === true])>
        <section class="public-panel" aria-labelledby="tracking-title">
            <header class="store-page-intro">
                <h1 id="tracking-title">پیگیری درخواست تعمیر</h1>
                <p>کد پیگیری هشت‌رقمی و شماره موبایل ثبت‌شده را وارد کنید.</p>
            </header>
            <x-form-errors />
            @if ($matched === false)
                <div class="public-notice public-notice--error" role="status"><h2>درخواستی با این کد و شماره پیدا نشد.</h2><p>کد و شماره را بررسی کنید و دوباره تلاش کنید.</p></div>
            @elseif ($matched === true)
                <div class="public-notice public-notice--success" role="status">
                    @if (!empty($trackSummary))
                        <dl class="repair-track-summary" aria-label="خلاصه درخواست">
                            <div>
                                <dt>کد پیگیری</dt>
                                <dd dir="ltr">{{ $trackSummary['code'] }}</dd>
                            </div>
                            <div>
                                <dt>دستگاه</dt>
                                <dd>{{ \App\Support\LatinText::html($trackSummary['device']) }}</dd>
                            </div>
                            <div>
                                <dt>نحوه تحویل</dt>
                                <dd>{{ $trackSummary['delivery'] }}</dd>
                            </div>
                        </dl>
                    @endif
                    <x-account.timeline :steps="$timeline" orientation="horizontal" />
                    <div class="repair-track-active-box">
                        <strong>{{ ($physicalMatch ?? false) ? ($physicalStatus ?? 'وضعیت فعلی تعمیر') : \App\Enums\RepairRequestStatus::OnlineSubmitted->label() }}</strong>
                        <p>{{ $nextAction }}</p>
                    </div>
                </div>
                <div class="repair-track-toggle-row">
                    <button type="button"
                            class="np-button np-button--outline"
                            data-toggle-track-search
                            aria-controls="repair-track-search-panel"
                            aria-expanded="false">پیگیری کد دیگر</button>
                    <a class="public-inline-link" href="{{ route('repair') }}">ثبت درخواست تعمیر <x-home.icon name="arrow-left" /></a>
                </div>
            @endif

            <div id="repair-track-search-panel" @class(['repair-track-collapsed-panel' => $matched === true]) @if($matched === true) hidden @endif>
                <form method="post" action="{{ route('repair.track.store') }}" class="public-form">
                    @csrf
                    <x-repair-field name="tracking_reference" label="کد پیگیری">
                        <input id="tracking_reference" name="tracking_reference" value="{{ old('tracking_reference', $trackingCode ?? '') }}" class="input public-code-input" dir="ltr" inputmode="numeric" autocomplete="off" spellcheck="false" required minlength="8" maxlength="8" placeholder="۱۲۳۴۵۶۷۸" aria-invalid="{{ $errors->has('tracking_reference') ? 'true' : 'false' }}" aria-describedby="tracking_reference-feedback">
                        <p class="repair-field-helper-link"><a class="public-inline-link" href="{{ route('repair.track.recovery') }}">فراموشی کد پیگیری؟</a></p>
                    </x-repair-field>
                    <x-repair-field name="contact_phone" label="شماره موبایل">
                        <input type="tel" id="contact_phone" name="contact_phone" class="input" dir="ltr" inputmode="tel" autocomplete="off" required maxlength="40" placeholder="۰۹۱۲۳۴۵۶۷۸۹" aria-invalid="{{ $errors->has('contact_phone') ? 'true' : 'false' }}" aria-describedby="contact_phone-feedback">
                    </x-repair-field>
                    <div class="repair-entry-actions repair-track-actions">
                        <button type="submit" class="np-button np-button--primary">بررسی وضعیت <x-home.icon name="arrow-left" /></button>
                        <a class="public-inline-link" href="{{ route('repair') }}">ثبت درخواست تعمیر <x-home.icon name="arrow-left" /></a>
                    </div>
                </form>

                @if ($matched === true)
                    <aside class="repair-track-guide mt-6" aria-label="راهنمای وضعیت‌های تعمیر">
                        <h2>مراحل و راهنمای پیگیری تعمیر</h2>
                        <ol class="repair-track-guide__steps">
                            <li>
                                <span class="repair-track-guide__num">۱</span>
                                <div><strong>ثبت آنلاین و کد پیگیری:</strong> پس از ثبت فرم، کد ۸ رقمی به شما داده می‌شود و در تمام مراحل معتبر است.</div>
                            </li>
                            <li>
                                <span class="repair-track-guide__num">۲</span>
                                <div><strong>دریافت و عیب‌یابی فنی:</strong> پس از تحویل حضوری، ارسال پستی یا دریافت با پیک، دستگاه بررسی می‌شود.</div>
                            </li>
                            <li>
                                <span class="repair-track-guide__num">۳</span>
                                <div><strong>اعلام هزینه و تأیید شما:</strong> مبلغ دقیق و زمان تعمیر پیامک می‌شود و تنها با تأیید شما کار شروع می‌گردد.</div>
                            </li>
                            <li>
                                <span class="repair-track-guide__num">۴</span>
                                <div><strong>تعمیر، تست و تحویل نهایی:</strong> پس از اتمام تست، دستگاه آماده تحویل یا ارسال مجدد می‌شود.</div>
                            </li>
                        </ol>
                    </aside>
                @endif
            </div>
        </section>

        @if ($matched !== true)
            <aside class="repair-track-guide" aria-label="راهنمای وضعیت‌های تعمیر">
                <h2>مراحل و راهنمای پیگیری تعمیر</h2>
                <ol class="repair-track-guide__steps">
                    <li>
                        <span class="repair-track-guide__num">۱</span>
                        <div><strong>ثبت آنلاین و کد پیگیری:</strong> پس از ثبت فرم، کد ۸ رقمی به شما داده می‌شود و در تمام مراحل معتبر است.</div>
                    </li>
                    <li>
                        <span class="repair-track-guide__num">۲</span>
                        <div><strong>دریافت و عیب‌یابی فنی:</strong> پس از تحویل حضوری، ارسال پستی یا دریافت با پیک، دستگاه بررسی می‌شود.</div>
                    </li>
                    <li>
                        <span class="repair-track-guide__num">۳</span>
                        <div><strong>اعلام هزینه و تأیید شما:</strong> مبلغ دقیق و زمان تعمیر پیامک می‌شود و تنها با تأیید شما کار شروع می‌گردد.</div>
                    </li>
                    <li>
                        <span class="repair-track-guide__num">۴</span>
                        <div><strong>تعمیر، تست و تحویل نهایی:</strong> پس از اتمام تست، دستگاه آماده تحویل یا ارسال مجدد می‌شود.</div>
                    </li>
                </ol>
            </aside>
        @endif
    </div>
</div>
@endsection
