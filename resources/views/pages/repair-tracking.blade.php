@extends('layouts.app')
@section('title', 'پیگیری درخواست')
@section('content')
<div class="np-container public-section repair-track-page">
    <div @class(['repair-track-layout', 'repair-entry-layout--single' => $matched === true])>
        <section class="public-panel repair-track-panel" aria-labelledby="tracking-title">
            <header class="repair-card-head">
                <div class="repair-card-head__row">
                    <div>
                        <h1 id="tracking-title">پیگیری درخواست تعمیر</h1>
                        @if ($matched !== true)
                            <p class="mt-1 text-sm text-slate-600">کد پیگیری هشت‌رقمی و شماره موبایل ثبت‌شده را وارد کنید.</p>
                        @endif
                    </div>
                </div>
            </header>

            <x-form-errors />

            @if (! empty($statusBanner ?? null))
                <div class="public-notice public-notice--success mb-4" role="status">
                    <p class="m-0 font-semibold">{{ $statusBanner }}</p>
                </div>
            @endif

            @if ($matched === false)
                <div class="public-notice public-notice--error" role="status">
                    <h2>درخواستی با این کد و شماره پیدا نشد.</h2>
                    <p>کد و شماره را بررسی کنید و دوباره تلاش کنید.</p>
                </div>
            @elseif ($matched === true)
                <div class="repair-track-result" role="status">
                    @if (!empty($trackSummary))
                        <dl class="repair-track-summary" aria-label="خلاصه درخواست">
                            <div>
                                <dt>کد پیگیری</dt>
                                <dd dir="ltr" class="repair-track-summary__code">{{ $trackSummary['code'] }}</dd>
                            </div>
                            <div>
                                <dt>دستگاه</dt>
                                <dd>{{ \App\Support\LatinText::html($trackSummary['device']) }}</dd>
                            </div>
                            <div>
                                <dt>نحوه تحویل</dt>
                                <dd data-track-current-delivery>{{ $trackSummary['delivery'] }}</dd>
                            </div>
                        </dl>
                    @endif

                    <div class="repair-confirm-stepper">
                        <x-account.timeline :steps="$timeline" orientation="horizontal" />
                    </div>

                    <div class="repair-track-active-box">
                        <strong>{{ ($physicalMatch ?? false) ? ($physicalStatus ?? 'وضعیت فعلی تعمیر') : \App\Enums\RepairRequestStatus::OnlineSubmitted->label() }}</strong>
                        <p>{{ $nextAction }}</p>
                    </div>
                </div>

                @if ($canChangeHandover ?? false)
                    <div id="repair-track-handover-panel" class="repair-track-inline-handover" @unless($openHandover ?? false) hidden @endunless>
                        <form method="post" action="{{ route('repair.track.handover') }}" class="public-form" data-repair-handover-form data-public-submit-loading>
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="track_grant" value="{{ $trackGrant }}">

                            <h2 class="repair-track-inline-handover__title">تغییر نحوهٔ تحویل دستگاه به نوین‌پی</h2>
                            <div class="np-handover-modal__options" role="radiogroup" aria-label="انتخاب نحوه تحویل">
                                <label class="repair-choice-card repair-choice-card--handover-modal">
                                    <input type="radio" name="handover_method" value="self_ship" data-handover-radio @checked(($currentHandover ?? '') === 'self_ship')>
                                    <span>خودم ارسال میکنم (پست یا تیپاکس)</span>
                                </label>
                                <label class="repair-choice-card repair-choice-card--handover-modal">
                                    <input type="radio" name="handover_method" value="in_person" data-handover-radio @checked(($currentHandover ?? '') === 'in_person')>
                                    <span>خودم حضوری میارم (فروشگاه شهریار)</span>
                                </label>
                                @if ($courierEligible ?? false)
                                    <label class="repair-choice-card repair-choice-card--handover-modal">
                                        <input type="radio" name="handover_method" value="doorstep_pickup" data-handover-radio @checked(($currentHandover ?? '') === 'doorstep_pickup')>
                                        <span>به پیک شما تحویل میدم (ویژه شهر تحت پوشش)</span>
                                    </label>
                                @endif
                            </div>

                            <div class="space-y-3" data-handover-pickup-fields @if(($currentHandover ?? '') !== 'doorstep_pickup') hidden @endif>
                                <div class="public-field">
                                    <div class="np-float-field" data-float-field>
                                        <textarea id="track_pickup_address" name="pickup_address" class="input" rows="2" maxlength="400" placeholder="خیابان، کوچه، پلاک و واحد">{{ $draftPickupAddress ?? '' }}</textarea>
                                        <label for="track_pickup_address">نشانی دقیق جهت دریافت با پیک <span class="required-mark" aria-hidden="true">*</span></label>
                                    </div>
                                </div>
                                <div class="public-field">
                                    <div class="np-float-field" data-float-field>
                                        <input type="tel" id="track_responsive_phone" name="responsive_phone" value="{{ $draftResponsivePhone ?? '' }}" class="input" dir="ltr" inputmode="numeric" data-digits-only="11" maxlength="11" placeholder="۰۹۱۲۳۴۵۶۷۸۹">
                                        <label for="track_responsive_phone">شماره تماس هماهنگی پیک (اختیاری)</label>
                                    </div>
                                </div>
                            </div>

                            <div class="repair-track-btn-row">
                                <button type="submit" class="np-button np-button--primary repair-track-action-btn" data-loading-label="در حال ذخیره...">ذخیره نحوهٔ تحویل</button>
                                <button type="button" class="np-button np-button--outline repair-track-action-btn" data-toggle-handover-editor aria-controls="repair-track-handover-panel" aria-expanded="{{ ($openHandover ?? false) ? 'true' : 'false' }}">انصراف</button>
                            </div>
                        </form>
                    </div>
                @endif

                <div class="repair-track-btn-row repair-track-toggle-row">
                    @if ($canChangeHandover ?? false)
                        <button type="button"
                                class="np-button np-button--primary repair-track-action-btn"
                                data-toggle-handover-editor
                                aria-controls="repair-track-handover-panel"
                                aria-expanded="{{ ($openHandover ?? false) ? 'true' : 'false' }}">تغییر نحوهٔ تحویل</button>
                    @endif
                    <button type="button"
                            class="np-button np-button--outline repair-track-action-btn"
                            data-toggle-track-search
                            aria-controls="repair-track-search-panel"
                            aria-expanded="false">پیگیری کد دیگر</button>
                    <a class="np-button np-button--outline repair-track-action-btn" href="{{ route('repair') }}">ثبت درخواست تعمیر</a>
                </div>
            @endif

            <div id="repair-track-search-panel" @class(['repair-track-collapsed-panel' => $matched === true]) @if($matched === true) hidden @endif>
                <form method="post" action="{{ route('repair.track.store') }}" class="public-form" data-public-submit-loading>
                    @csrf
                    <x-repair-field name="tracking_reference" label="کد پیگیری">
                        <input id="tracking_reference" name="tracking_reference" value="{{ old('tracking_reference', $trackingCode ?? '') }}" class="input public-code-input" dir="ltr" inputmode="numeric" data-digits-only="8" autocomplete="off" spellcheck="false" required minlength="8" maxlength="8" placeholder="۱۲۳۴۵۶۷۸" aria-invalid="{{ $errors->has('tracking_reference') ? 'true' : 'false' }}" aria-describedby="tracking_reference-feedback">
                        <x-slot:after>
                            <p class="repair-field-helper-link"><a class="public-inline-link" href="{{ route('repair.track.recovery') }}">فراموشی کد پیگیری؟</a></p>
                        </x-slot:after>
                    </x-repair-field>
                    <x-repair-field name="contact_phone" label="شماره موبایل">
                        <input type="tel" id="contact_phone" name="contact_phone" class="input" dir="ltr" inputmode="numeric" data-digits-only="11" autocomplete="tel" required maxlength="11" placeholder="۰۹۱۲۳۴۵۶۷۸۹" aria-invalid="{{ $errors->has('contact_phone') ? 'true' : 'false' }}" aria-describedby="contact_phone-feedback">
                    </x-repair-field>
                    <div class="repair-track-btn-row">
                        <button type="submit" class="np-button np-button--primary repair-track-action-btn" data-loading-label="در حال ارسال...">بررسی وضعیت</button>
                        <a class="np-button np-button--outline repair-track-action-btn" href="{{ route('repair') }}">ثبت درخواست تعمیر</a>
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
