@extends('layouts.app')
@section('title', 'پیگیری تعمیر')
@section('content')
@php
    $deviceLabel = \App\Support\RepairIdentity::deviceLabel($repair->device_brand, $repair->device_model);
    $parsed = ! $case && method_exists($repair, 'parsedIssue') ? $repair->parsedIssue() : null;
    $canEditHandover = ! $case && $repair->status === \App\Enums\RepairRequestStatus::OnlineSubmitted;
    $currentHandover = old('handover_method', $parsed['handover_key'] ?? 'self_ship');
    $isRepairSettled = $case && $document && $document->charged_minor > 0 && $document->paid_minor >= $document->charged_minor;
@endphp
<div @class(['np-account np-account-detail np-container', 'np-account-repair-tracking' => request()->routeIs('account.repairs.request')])>
    <div class="np-detail-topbar">
        <a class="np-account-link" href="{{ route('account.index', ['section' => 'repairs']) }}">بازگشت به تعمیرات</a>
        @if($isRepairSettled)
            <a class="np-button np-button--outline np-detail-receipt-btn" href="{{ route('account.repairs.receipt', $repair->public_id) }}">مشاهده و چاپ رسید</a>
        @endif
    </div>
    <header class="np-account-banner">
        <div>
            <p class="np-banner-code-row">
                <span>پیگیری تعمیر <bdi dir="ltr">{{ \App\Support\FaNumber::digits($repair->tracking_reference) }}</bdi></span>
                <button type="button" class="np-banner-copy-btn" data-copy-code="{{ $repair->tracking_reference }}" aria-label="کپی کد پیگیری {{ $repair->tracking_reference }}">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                    <span data-copy-code-label>کپی</span>
                </button>
            </p>
            <h1>@if(preg_match('/[A-Za-z]/', $deviceLabel))<bdi class="np-latin-code" dir="ltr">{{ $deviceLabel }}</bdi>@else{{ $deviceLabel }}@endif</h1>
            <p>{{ $case ? \App\Support\CustomerTimeline::repairStatus($repair, $plan) : 'درخواست ثبت شد؛ دستگاه هنوز دریافت نشده است.' }}</p>
        </div>
    </header>

    @if (session('status'))
        <p class="np-account-notice" role="status">{{ session('status') }}</p>
    @endif
    <x-form-errors />

    <div class="np-account-order-layout">
        <section class="np-account-card np-account-order-stepper">
            <h2>مسیر تعمیر</h2>
            <x-account.timeline :steps="$timeline" orientation="horizontal" />
        </section>

        <div class="np-account-order-columns">
            <section class="np-account-card np-repair-device-card">
                <h2>مشخصات دستگاه</h2>
                <dl class="np-account-facts">
                    <div><dt>برند</dt><dd>{{ $repair->device_brand?->value ?? 'ثبت نشده' }}</dd></div>
                    <div><dt>{{ $case && !$repair->onlineRequest ? 'تاریخ پذیرش' : 'تاریخ درخواست' }}</dt><dd><x-finance-time :value="$case ? ($repair->onlineRequest?->created_at ?? $repair->received_at) : $repair->created_at" /></dd></div>
                    @if ($parsed && ! empty($parsed['serial']))
                        <div><dt>شماره سریال</dt><dd><bdi class="np-latin" dir="ltr">{{ $parsed['serial'] }}</bdi></dd></div>
                    @elseif ($case && ! empty($repair->device_serial))
                        <div><dt>شماره سریال</dt><dd><bdi class="np-latin" dir="ltr">{{ $repair->device_serial }}</bdi></dd></div>
                    @endif
                    <div><dt>شرح مشکل</dt><dd class="whitespace-pre-line">{{ $parsed ? $parsed['problem'] : $repair->issue }}</dd></div>
                    @if ($parsed && ! empty($parsed['accessories']))
                        <div><dt>لوازم همراه</dt><dd>{{ $parsed['accessories'] }}</dd></div>
                    @endif
                    @if ($parsed && ! empty($parsed['location']))
                        <div><dt>استان و شهر</dt><dd>{{ $parsed['location'] }}</dd></div>
                    @endif
                    @if ($parsed && ! empty($parsed['handover_label']))
                        <div><dt>نحوه تحویل</dt><dd>{{ $parsed['handover_label'] }}</dd></div>
                    @endif
                    @if ($parsed && ! empty($parsed['pickup_address']))
                        <div><dt>نشانی تحویل در محل</dt><dd>{{ $parsed['pickup_address'] }}</dd></div>
                    @endif
                    @if($case && $repair->currentEstimate)
                        <div><dt>برآورد تعمیر</dt><dd>{{ \App\Support\FaNumber::digits(number_format($repair->currentEstimate->total())) }} تومان</dd></div>
                        @if($repair->currentEstimate->customer_summary)
                            <div><dt>نتیجه بررسی و برآورد</dt><dd class="whitespace-pre-line">{{ $repair->currentEstimate->customer_summary }}</dd></div>
                        @endif
                        <div><dt>وضعیت برآورد</dt><dd>{{ ['approved' => 'تأیید شده', 'rejected' => 'رد شده', 'pending' => 'در انتظار پاسخ'][$repair->currentEstimate->decisionStatus()] ?? 'نامشخص' }}</dd></div>
                    @endif
                    <div><dt>هزینه تعمیر</dt><dd><span class="np-repair-device-cost">{{ \App\Support\AdminRepairMoney::display($case ? $repair->repair_price_toman : null) }}</span></dd></div>
                </dl>
            </section>

            <div class="np-account-detail-side">
                @if ($canEditHandover)
                    <section class="np-account-card">
                        <h2>گام بعدی و نحوهٔ تحویل دستگاه</h2>
                        @if ($currentHandover === 'self_ship')
                            <p>لطفاً دستگاه را با بسته‌بندی ایمن به نشانی زیر ارسال کنید و کد پیگیری را داخل بسته قرار دهید:</p>
                            <dl class="np-account-facts">
                                <div><dt>آدرس گیرنده</dt><dd>{{ config('business.address') }}</dd></div>
                                <div><dt>شماره تماس</dt><dd><a href="{{ config('business.phone_uri') }}" dir="ltr">{{ \App\Support\FaNumber::digits(config('business.phone')) }}</a></dd></div>
                                <div><dt>کد پستی</dt><dd class="np-fact-rtl-num"><bdi dir="ltr">۳۳۵۱۶۱۱۸۲۸</bdi></dd></div>
                            </dl>
                        @elseif ($currentHandover === 'in_person')
                            <p>لطفاً دستگاه را همراه با کد پیگیری به فروشگاه نوین‌پی تحویل دهید:</p>
                            <x-repair.store-delivery />
                        @else
                            <p>درخواست دریافت با پیک نوین‌پی ثبت شده است. همکاران ما برای هماهنگی زمان مراجعهٔ پیک با شما تماس می‌گیرند.</p>
                            @if (! empty($parsed['pickup_address']) || ! empty($parsed['pickup_notes']))
                                <dl class="np-account-facts">
                                    @if (! empty($parsed['pickup_address']))
                                        <div><dt>نشانی ثبت‌شده</dt><dd>{{ $parsed['pickup_address'] }}</dd></div>
                                    @endif
                                    @if (! empty($parsed['pickup_notes']))
                                        <div><dt>توضیحات برای پیک</dt><dd>{{ $parsed['pickup_notes'] }}</dd></div>
                                    @endif
                                </dl>
                            @endif
                        @endif

                        @php
                            $availableHandoverMethods = ($courierEligible ?? false)
                                ? \App\Models\RepairRequest::HANDOVER_METHODS
                                : collect(\App\Models\RepairRequest::HANDOVER_METHODS)->except('doorstep_pickup')->all();
                            $handoverEditorOpen = $errors->hasAny(['handover_method', 'pickup_address', 'responsive_phone']);
                        @endphp
                        <div style="margin-top: 14px;">
                            <button type="button" class="np-button np-button--outline" data-open-handover-modal aria-controls="repair-handover-modal" aria-haspopup="dialog">تغییر نحوهٔ تحویل</button>
                        </div>

                        <dialog id="repair-handover-modal" class="np-handover-modal" data-handover-modal @if($handoverEditorOpen) data-auto-open="1" @endif aria-labelledby="handover-modal-title">
                            <div class="np-handover-modal__head">
                                <h3 id="handover-modal-title">تغییر نحوهٔ تحویل دستگاه</h3>
                                <button type="button" class="np-handover-modal__close" data-close-handover-modal aria-label="بستن پنجره">×</button>
                            </div>
                            <form method="post" action="{{ route('account.repairs.request.handover', $repair->tracking_reference) }}" class="public-form np-handover-modal__body" data-repair-handover-form data-reverse-geocode-url="{{ route('repair.reverse-geocode') }}">
                                @csrf
                                @method('PATCH')
                                <div class="np-handover-modal__options">
                                    @foreach ($availableHandoverMethods as $key => $label)
                                        <label class="repair-choice-card repair-choice-card--handover-modal">
                                            <input type="radio" name="handover_method" value="{{ $key }}" @checked($currentHandover === $key) data-handover-radio>
                                            <span>{{ $key === 'self_ship' ? 'خودم ارسال می‌کنم' : $label }}</span>
                                        </label>
                                    @endforeach
                                </div>

                                @if ($courierEligible ?? false)
                                    <div data-handover-pickup-fields @if($currentHandover !== 'doorstep_pickup') hidden @endif>
                                        <div class="checkout-map repair-pickup-map" data-repair-modal-map data-neshan-key="{{ $neshanWebKey ?? '' }}" data-default-lat="{{ old('pickup_latitude', $parsed['pickup_lat'] ?? '35.6597') ?: '35.6597' }}" data-default-lng="{{ old('pickup_longitude', $parsed['pickup_lng'] ?? '51.0592') ?: '51.0592' }}">
                                            <div class="checkout-map__header">
                                                <span>موقعیت تحویل روی نقشه</span>
                                                <div class="checkout-map__controls">
                                                    <button type="button" class="checkout-map__btn" data-map-locate title="موقعیت فعلی من">موقعیت من</button>
                                                    <button type="button" class="checkout-map__btn" data-map-zoom="in" aria-label="بزرگ‌نمایی">+</button>
                                                    <button type="button" class="checkout-map__btn" data-map-zoom="out" aria-label="کوچک‌نمایی">−</button>
                                                </div>
                                            </div>
                                            <div class="checkout-map__viewport" data-map-viewport tabindex="0" role="application" aria-label="انتخاب موقعیت روی نقشه">
                                                <div class="checkout-map__tiles" data-map-tiles></div>
                                                <div class="checkout-map__pin" data-map-fallback-pin aria-hidden="true"></div>
                                            </div>
                                            <div class="checkout-map__footer">
                                                <span class="checkout-map__coords" data-map-coords dir="ltr">{{ ($parsed['pickup_lat'] ?? '') && ($parsed['pickup_lng'] ?? '') ? ($parsed['pickup_lat'].', '.$parsed['pickup_lng']) : '۳۵.۶۵۹۷, ۵۱.۰۵۹۲' }}</span>
                                                <span class="checkout-map__attr">© نقشه نشان</span>
                                            </div>
                                            <input type="hidden" name="pickup_latitude" value="{{ old('pickup_latitude', $parsed['pickup_lat'] ?? '') }}" data-handover-lat data-map-lat>
                                            <input type="hidden" name="pickup_longitude" value="{{ old('pickup_longitude', $parsed['pickup_lng'] ?? '') }}" data-handover-lng data-map-lng>
                                        </div>
                                        <x-repair-field name="pickup_address" label="نشانی کامل تحویل به پیک">
                                            <textarea id="pickup_address" name="pickup_address" class="input" rows="2" maxlength="400" placeholder="خیابان، کوچه، پلاک، واحد" data-handover-address>{{ old('pickup_address', $parsed['pickup_address'] ?? '') }}</textarea>
                                        </x-repair-field>
                                        <x-repair-field name="responsive_phone" label="شماره تماس پاسخگو (اختیاری)" :required="false">
                                            <input id="responsive_phone" name="responsive_phone" type="tel" class="input" dir="ltr" inputmode="numeric" data-digits-only="11" maxlength="11" placeholder="۰۹۱۲۳۴۵۶۷۸۹" value="{{ old('responsive_phone', $parsed['responsive_phone'] ?? '') }}">
                                        </x-repair-field>
                                    </div>
                                @endif

                                <div class="np-handover-modal__actions">
                                    <button type="submit" class="np-button np-button--primary">ذخیرهٔ نحوهٔ تحویل</button>
                                    <button type="button" class="np-button np-button--outline" data-close-handover-modal>انصراف</button>
                                </div>
                            </form>
                        </dialog>
                    </section>
                @else
                    <x-account.settlement
                        :document="$document"
                        :plan="$plan"
                        :paid-at-display="$paidAtDisplay ?? null"
                        :repair="$case ? $repair : null"
                        :payment-transactions="$paymentTransactions ?? collect()"
                        :receipt-url="$isRepairSettled ? route('account.repairs.receipt', $repair->public_id) : null"
                    />
                @endif
            </div>
        </div>
        @if($case && $repair->activation_docs_status !== null)
            <x-orders.activation-documents
                :slots="app(\App\Services\ActivationDocumentsService::class)->configuredSlots()"
                :submit-action="route('account.repairs.documents.store', $repair->public_id)"
                :documents-status="$repair->activation_docs_status"
                :revision-reason="$repair->activation_docs_revision_reason"
            />
        @endif
    </div>
</div>
@endsection
