@extends('layouts.admin')
@section('title', 'پرونده تعمیر')
@section('content')
@php
    $price = $case->repair_price_toman;
    $settled = $repairDocument && $repairDocument->charged_minor > 0 && $repairDocument->charged_minor === $repairDocument->paid_minor;
    $status = $case->status->value;
    $stages = ['received' => 'پذیرش‌شده', 'repairing' => 'در حال تعمیر', 'ready' => 'آماده تحویل', 'return_pending' => 'تعمیرنشده / لغوشده'];
    $carriers = ['post' => 'پست', 'tipax' => 'تیپاکس', 'courier' => 'پیک'];
    $shipping = $case->delivery_method === 'shipping';
    $canEditCost = auth()->user()->can('repairs.estimate') && !$hasRepairDocument;
    $showMoney = auth()->user()->can('finance.view') || auth()->user()->can('repairs.estimate');
    $hasWorkForm = !$case->status->closed() && auth()->user()->can('repairs.transition');
    $rawRef = $customer ? (string) $case->tracking_reference : (string) $case->case_number;
    $displayRef = preg_match('/\A[0-9]+\z/', $rawRef)
        ? \App\Support\FaNumber::digits($rawRef)
        : (strlen($rawRef) > 12 ? substr($rawRef, 0, 10).'…' : $rawRef);
    $canPrintDocs = auth()->user()->can('operate', [$case, 'print']);
    $canViewFinance = auth()->user()->can('finance.view');
    $adminAcc = \App\Support\AdminAccess::account(auth()->user());
    $canIssueInvoice = $adminAcc && ($adminAcc->hasFullAccess() || in_array('finance.charge', $adminAcc->permissions ?? [], true));
@endphp
<a class="admin-back-link" href="{{ route('admin.repair-cases.index') }}">← پرونده‌های تعمیر</a>
<header class="admin-page-heading repair-case-header">
    <div class="repair-case-header__meta">
        <div class="repair-case-header__title-row">
            <h1>پرونده تعمیر <bdi dir="ltr" class="repair-code-chip" title="{{ $rawRef }}">{{ $displayRef }}</bdi></h1>
            <x-admin.status :tone="in_array($status, ['ready', 'delivered'], true) ? 'success' : ($status === 'repairing' ? 'info' : 'neutral')">{{ $case->status->label() }}</x-admin.status>
        </div>
        @if($customer && $case->onlineRequest)
            @can('viewCustomerData', $case->onlineRequest)
                <p class="repair-case-header__sub">
                    <a class="admin-text-link" href="{{ route('admin.repairs.show', $case->onlineRequest) }}">درخواست آنلاین <bdi dir="ltr">{{ \App\Support\FaNumber::digits($case->onlineRequest->tracking_reference) }}</bdi></a>
                </p>
            @endcan
        @endif
    </div>
    @if($canPrintDocs || $canViewFinance)
        <div class="admin-actions">
            <details class="admin-more-menu">
                <summary>
                    <span>چاپ و اسناد</span>
                    <svg class="admin-more-menu__chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5.5 7.5 4.5 4.5 4.5-4.5"/></svg>
                </summary>
                <div class="admin-more-menu__panel">
                    @if($canPrintDocs)
                        <a href="{{ route('admin.repair-cases.document', [$case, 'intake']) }}">رسید پذیرش</a>
                        <a href="{{ route('admin.repair-cases.document', [$case, 'summary']) }}">صورتحساب تعمیر</a>
                        @if($case->delivery)<a href="{{ route('admin.repair-cases.document', [$case, 'delivery']) }}">رسید تحویل</a>@endif
                    @endif
                    @if($canViewFinance)
                        <a href="{{ route('admin.finance.sources.show', ['type' => 'repair', 'reference' => $case->public_id]) }}">پرونده مالی کامل</a>
                    @endif
                </div>
            </details>
        </div>
    @endif
</header>
<x-admin.errors />
@php
    $simpleOrder = ['received' => 1, 'repairing' => 2, 'ready' => 3, 'delivered' => 4];
    $currentRank = $simpleOrder[$status] ?? 0;
@endphp
<ol class="admin-step-strip admin-step-strip--4 mb-4" aria-label="مراحل پرونده تعمیر">
    @foreach (['received' => 'پذیرش‌شده', 'repairing' => 'در حال تعمیر', 'ready' => 'آماده تحویل', 'delivered' => 'تحویل‌شده'] as $stepKey => $stepLabel)
        @php
            $stepRank = $simpleOrder[$stepKey];
            $stepState = ($currentRank > $stepRank || ($status === 'delivered' && $stepRank === 4))
                ? 'done'
                : ($currentRank === $stepRank ? 'active' : 'upcoming');
        @endphp
        <li @class(['admin-step-strip__item', 'is-done' => $stepState === 'done', 'is-active' => $stepState === 'active']) @if($stepState === 'done') data-step-done="1" @elseif($stepState === 'active') aria-current="step" @endif>
            <span class="admin-step-strip__dot" aria-hidden="true">
                @if($stepState === 'done')
                    ✓
                @else
                    {{ \App\Support\FaNumber::digits($stepRank) }}
                @endif
            </span>
            <span>{{ $stepLabel }}</span>
        </li>
    @endforeach
</ol>
@if($customer)
@php
    $onlineParsed = $case->onlineRequest ? $case->onlineRequest->parsedIssue() : null;
    $activationDocStatus = $case->activation_docs_status;
    $activationDocLabel = $activationDocStatus ? (\App\Models\SalesOrder::DOCUMENT_STATUSES[$activationDocStatus] ?? 'در انتظار ارسال') : null;
@endphp
<section class="admin-panel repair-hero-card mb-4" aria-labelledby="repair-device-context">
    <div class="admin-panel-heading">
        <h2 id="repair-device-context">اطلاعات پذیرش</h2>
        <div class="flex flex-wrap items-center gap-3">
            @if($activationDocStatus !== null)
                <x-admin.status :tone="$activationDocStatus === 'approved' ? 'success' : ($activationDocStatus === 'pending_review' ? 'info' : 'warning')">مدارک فعال‌سازی: {{ $activationDocLabel }}</x-admin.status>
            @elseif(auth()->user()->can('repairs.transition'))
                <form method="post" action="{{ route('admin.repair-cases.request-activation-docs', $case) }}" class="inline">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm">درخواست ارسال مدارک فعال‌سازی</button>
                </form>
            @endif
            @can('customers.view')
                @if($case->customer_id)
                    <a class="admin-text-link" href="{{ route('admin.customers.show', $case->customer) }}">پرونده مشتری ←</a>
                @else
                    @can('customers.link')<a class="admin-text-link" href="{{ route('admin.customers.index') }}">اتصال به پرونده مشتری ←</a>@endcan
                @endif
            @endcan
        </div>
    </div>
    <dl class="repair-hero-meta">
        <div>
            <dt>مشتری</dt>
            <dd><strong class="repair-hero-name">{{ $case->customer_name }}</strong></dd>
        </div>
        <div>
            <dt>شماره موبایل</dt>
            <dd><a class="repair-phone-chip" href="tel:{{ $case->contact_phone }}" dir="ltr"><bdi>{{ \App\Support\FaNumber::digits($case->contact_phone) }}</bdi></a></dd>
        </div>
        <div>
            <dt>زمان پذیرش</dt>
            <dd><x-finance-time :value="$case->received_at" /></dd>
        </div>
        <div>
            <dt>برند و مدل دستگاه</dt>
            <dd>@if($case->device_brand)<bdi dir="ltr">{{ $case->device_brand->value }}</bdi> · @endif{{ $case->device_model }}</dd>
        </div>
        @if($case->device_serial)
        <div>
            <dt>شماره سریال</dt>
            <dd><bdi dir="ltr" class="repair-code-chip">{{ $case->device_serial }}</bdi></dd>
        </div>
        @endif
        @if($case->accessories)
        <div>
            <dt>لوازم همراه</dt>
            <dd>{{ $case->accessories }}</dd>
        </div>
        @endif
        @if(!empty($onlineParsed['location']))
        <div>
            <dt>استان و شهر</dt>
            <dd>{{ $onlineParsed['location'] }}</dd>
        </div>
        @endif
        @if(!empty($onlineParsed['handover_label']))
        <div>
            <dt>نحوه تحویل اولیه</dt>
            <dd>{{ $onlineParsed['handover_label'] }}</dd>
        </div>
        @endif
        @if(!empty($onlineParsed['activation_status']))
        <div>
            <dt>وضعیت فعال‌سازی</dt>
            <dd>{{ $onlineParsed['activation_status'] }}</dd>
        </div>
        @endif
        @if(!empty($onlineParsed['tax_file_status']))
        <div>
            <dt>پرونده مالیاتی</dt>
            <dd>{{ $onlineParsed['tax_file_status'] }}</dd>
        </div>
        @endif
    </dl>
    <dl class="repair-callout-grid">
        <div class="repair-callout">
            <dt>شرح مشکل دستگاه</dt>
            <dd class="whitespace-pre-line">{{ $case->issue }}</dd>
        </div>
        <div class="repair-callout">
            <dt>وضعیت ظاهری</dt>
            <dd class="whitespace-pre-line">{{ $case->physical_condition }}</dd>
        </div>
    </dl>
</section>

<div @class(['admin-record-layout repair-management-layout' => $hasWorkForm, 'repair-summary-grid' => !$hasWorkForm])>
    @if($hasWorkForm)
    <div class="admin-record-work">
        <form method="post" action="{{ route('admin.repair-cases.record', $case) }}" class="admin-panel admin-form" data-simple-repair>
            @csrf
            <input type="hidden" name="version" value="{{ $case->version }}">
            <div class="admin-panel-heading"><h2>وضعیت، هزینه و تحویل</h2></div>
            <div class="admin-form-grid">
                <div><label class="label" for="repair-status">وضعیت فعلی</label>
                    <select class="input" id="repair-status" name="status">
                        @unless(array_key_exists($status, $stages))<option value="{{ $status }}" selected>{{ $case->status->label() }}</option>@endunless
                        @foreach($stages as $value => $label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                @if($canEditCost)
                <div data-repair-cost @if($status !== 'ready') hidden @endif>
                    <x-admin-money name="repair_cost" label="هزینه نهایی تعمیر" :amount="$price" :disabled="$status !== 'ready'"/>
                </div>
                @endif
            </div>
            <x-operation-field name="repair_notes" label="یادداشت تعمیر (اختیاری)" kind="textarea" :required="false" :value="$case->repair_notes ?? ''"/>
            <fieldset class="repair-delivery-options">
                <legend>روش تحویل</legend>
                <div class="admin-form-grid">
                    <div><label class="label" for="delivery-method">روش برنامه‌ریزی‌شده</label>
                        <select class="input" id="delivery-method" name="delivery_method">
                            <option value="" @selected($case->delivery_method === null)>مشخص نشده</option>
                            <option value="in_person" @selected($case->delivery_method === 'in_person')>حضوری</option>
                            <option value="shipping" @selected($shipping)>ارسال</option>
                        </select>
                    </div>
                    <div data-carrier-options @unless($shipping) hidden @endunless>
                        <label class="label" for="delivery-carrier">شرکت ارسال (اختیاری تا زمان ارسال)</label>
                        <select class="input" id="delivery-carrier" name="delivery_carrier" @disabled(!$shipping)>
                            <option value="" @selected(!$case->delivery_carrier)>انتخاب نشده</option>
                            @foreach($carriers as $value => $label)<option value="{{ $value }}" @selected($case->delivery_carrier === $value)>{{ $label }}</option>@endforeach
                        </select>
                        @if($case->delivery_carrier)
                        <label class="admin-checkbox mt-2"><input type="checkbox" name="clear_delivery_carrier" value="1" @disabled(!$shipping)> پاک کردن شرکت ارسال ذخیره‌شده</label>
                        @endif
                    </div>
                </div>
            </fieldset>
            <div class="admin-form-footer"><button class="btn btn-primary" type="submit">ذخیره تغییرات</button></div>
        </form>
    </div>
    @endif

    <aside @class(['admin-record-context' => $hasWorkForm, 'contents' => !$hasWorkForm])>
        @if($showMoney)
        <section class="admin-panel" aria-labelledby="repair-finance">
            <div class="admin-panel-heading">
                <h2 id="repair-finance">هزینه و تسویه</h2>
            </div>
            @php
                $paymentAt = $latestPayment?->created_at;
                $paymentRef = $latestPayment?->paymentAttempt?->track_id ?: ($latestPayment?->reference ?? null);
                $paymentMethodLabel = match (true) {
                    $case->settlement_method === 'online' || ($latestPayment && $latestPayment->payment_attempt_id !== null) => 'اینترنتی (درگاه زیبال)',
                    $case->settlement_method === 'in_person' || $latestPayment !== null => 'حضوری در فروشگاه',
                    default => null,
                };
            @endphp
            <dl class="admin-kv-list">
                <div><dt>هزینه تعمیر</dt><dd><strong>{{ \App\Support\AdminRepairMoney::display($price) }}</strong></dd></div>
                @if($price === null && $case->currentEstimate)<div><dt>برآورد قبلی</dt><dd>{{ \App\Support\AdminRepairMoney::display($case->currentEstimate->total()) }}</dd></div>@endif
                @can('finance.view')
                    <div>
                        <dt>وضعیت تسویه</dt>
                        <dd>
                            @if($settled)
                                <x-admin.status tone="success">تسویه شد</x-admin.status>
                            @elseif($repairDocument)
                                <x-admin.status tone="warning">در انتظار تسویه</x-admin.status>
                            @else
                                <x-admin.status tone="neutral">فاکتور صادر نشده</x-admin.status>
                            @endif
                        </dd>
                    </div>
                    @if($paymentAt)
                        <div><dt>زمان پرداخت</dt><dd><x-finance-time :value="$paymentAt" /></dd></div>
                    @endif
                    @if($paymentMethodLabel)
                        <div><dt>روش پرداخت</dt><dd>{{ $paymentMethodLabel }}</dd></div>
                    @endif
                    @if($paymentRef)
                        <div><dt>کد رهگیری پرداخت</dt><dd><bdi dir="ltr">{{ $paymentRef }}</bdi></dd></div>
                    @endif
                @endcan
            </dl>
            @if($canIssueInvoice && $price !== null && $price > 0 && !$repairDocument && in_array($status, ['ready', 'delivered'], true))
                <details class="admin-accordion mt-3">
                    <summary>
                        <span>صدور فاکتور تعمیر ({{ \App\Support\AdminRepairMoney::display($price) }})</span>
                        <svg class="admin-accordion__chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5.5 7.5 4.5 4.5 4.5-4.5"/></svg>
                    </summary>
                    <div class="admin-accordion__body">
                        <form method="post" action="{{ route('admin.finance.issue.store') }}" class="admin-form">
                            @csrf
                            <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <input type="hidden" name="source_type" value="repair">
                            <input type="hidden" name="source" value="{{ $case->public_id }}">
                            <input type="hidden" name="source_version" value="{{ $case->version }}">
                            <x-operation-field name="reason" label="توضیح صدور فاکتور" kind="textarea" :maxlength="1500" value="صدور فاکتور تعمیر دستگاه" />
                            <x-operation-field name="password" label="رمز عبور فعلی شما" type="password" />
                            <button class="btn btn-primary btn-sm" type="submit">صدور فاکتور</button>
                        </form>
                    </div>
                </details>
            @endif
            @can('finance.receive')
                @if($repairDocument && $repairDocument->charged_minor > $repairDocument->paid_minor)
                    @php
                        $remainingTomanInput = str_replace(',', '', \App\Support\FinanceMoney::toman($repairDocument->charged_minor - $repairDocument->paid_minor));
                    @endphp
                    <details class="admin-accordion mt-3">
                        <summary>
                            <span>ثبت دریافت وجه در فروشگاه</span>
                            <svg class="admin-accordion__chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5.5 7.5 4.5 4.5 4.5-4.5"/></svg>
                        </summary>
                        <div class="admin-accordion__body">
                            <form method="post" action="{{ route('admin.payments.store') }}" class="admin-form">
                                @csrf
                                <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                <input type="hidden" name="document" value="{{ $repairDocument->public_id }}">
                                <x-operation-field name="amount" inputmode="decimal" label="مبلغ دریافتی (تومان)" :value="$remainingTomanInput" />
                                <x-operation-field name="password" label="رمز عبور فعلی شما" type="password" />
                                <label class="admin-checkbox"><input type="checkbox" name="funds_confirmed" value="1" required> دریافت واقعی وجه در فروشگاه را تأیید می‌کنم.</label>
                                <button class="btn btn-primary btn-sm" type="submit">ثبت دریافت وجه</button>
                            </form>
                        </div>
                    </details>
                @endif
            @endcan
            @can('finance.view')
            @can('repairs.transition')
            @if($settled && !$case->status->closed())
                <form method="post" action="{{ route('admin.repair-cases.record', $case) }}" class="admin-form mt-3">
                    @csrf
                    <input type="hidden" name="version" value="{{ $case->version }}">
                    <input type="hidden" name="intent" value="settlement">
                    <label class="label" for="settlement-method">روش پرداخت ثبت‌شده در سند</label>
                    <select id="settlement-method" name="settlement_method" class="input" required>
                        <option value="">انتخاب روش پرداخت</option>
                        <option value="in_person" @selected($case->settlement_method === 'in_person')>حضوری</option>
                        <option value="online" @selected($case->settlement_method === 'online')>اینترنتی</option>
                    </select>
                    <button class="btn btn-secondary btn-sm" type="submit">ذخیره روش پرداخت</button>
                </form>
            @endif
            @endcan
            @endcan
        </section>
        @include('admin.repair-cases._payment-link-card')
        @endif

        <section class="admin-panel" aria-labelledby="repair-handover">
            <div class="admin-panel-heading">
                <h2 id="repair-handover">تحویل دستگاه</h2>
                <x-admin.status :tone="$case->delivery ? 'success' : 'neutral'">{{ $case->delivery ? 'تحویل‌شده' : 'در انتظار تحویل' }}</x-admin.status>
            </div>
            <dl class="admin-kv-list">
                <div>
                    <dt>وضعیت تحویل</dt>
                    <dd>{{ $case->delivery ? 'تحویل‌شده' : 'در انتظار تحویل' }}</dd>
                </div>
                <div>
                    <dt>روش تحویل</dt>
                    <dd>{{ $shipping ? 'ارسال' : ($case->delivery_method === 'in_person' ? 'حضوری' : 'مشخص نشده') }}@if($shipping && isset($carriers[$case->delivery_carrier])) · {{ $carriers[$case->delivery_carrier] }}@endif</dd>
                </div>
                @if($case->delivery)
                    <div><dt>زمان تحویل</dt><dd><x-finance-time :value="$case->delivery->delivered_at" /></dd></div>
                    @if($case->delivery->recipient_name)
                        <div><dt>تحویل‌گیرنده</dt><dd>{{ $case->delivery->recipient_name }}</dd></div>
                    @endif
                    @if($case->delivery->confirmation_reference)
                        <div><dt>کد پیگیری / مرجع تحویل</dt><dd><bdi dir="ltr">{{ $case->delivery->confirmation_reference }}</bdi></dd></div>
                    @endif
                @endif
                @if(!empty($deliveryPlan?->address_snapshot))
                    @php
                        $snap = is_array($deliveryPlan->address_snapshot) ? $deliveryPlan->address_snapshot : [];
                        $shipAddr = trim(implode('، ', array_filter([$snap['province'] ?? null, $snap['city'] ?? null, $snap['address'] ?? null])));
                    @endphp
                    @if($shipAddr !== '')
                        <div><dt>نشانی ارسال</dt><dd>{{ $shipAddr }}</dd></div>
                    @endif
                @endif
            </dl>
            @can('repairs.deliver')
            @if(in_array($status, ['ready', 'return_pending'], true))
                @if($shipping && !$case->delivery_carrier)
                    <p class="admin-hint">پیش از ثبت ارسال، شرکت ارسال را در پرونده ذخیره کنید.</p>
                @else
                    <form method="post" action="{{ route('admin.repair-cases.record', $case) }}" class="admin-form mt-3">
                        @csrf
                        <input type="hidden" name="version" value="{{ $case->version }}">
                        <input type="hidden" name="intent" value="handover">
                        @if($shipping)
                            <x-operation-field name="shipment_tracking" label="کد پیگیری ارسال" :maxlength="200"/>
                        @endif
                        @if($case->customer_account_id && $repairDocument)
                            <x-operation-field name="current_password" label="رمز عبور فعلی" type="password" :maxlength="255"/>
                        @endif
                        <label class="admin-checkbox"><input type="checkbox" name="handover_confirmed" value="1" required> {{ $shipping ? 'دستگاه به شرکت ارسال تحویل داده شد.' : 'دستگاه حضوری تحویل داده شد.' }}</label>
                        <button class="btn btn-secondary" type="submit">{{ $shipping ? 'ثبت ارسال انجام‌شده' : 'ثبت تحویل حضوری' }}</button>
                    </form>
                @endif
            @endif
            @endcan
        </section>
    </aside>
</div>

<section class="admin-panel mt-4 repair-history-details">
    <div class="admin-panel-heading">
        <h2>سوابق پرونده <span class="admin-muted">({{ \App\Support\FaNumber::digits($events->total()) }} مورد)</span></h2>
    </div>
    <ol class="repair-compact-timeline">
    @forelse($events as $event)
        @php
            $from = \App\Enums\RepairStage::tryFrom((string) $event->from_status);
            $to = \App\Enums\RepairStage::tryFrom((string) $event->to_status);
            $change = $event->subject_type === 'repair_revision' ? $revisions->get($event->subject_id)?->payload : null;
        @endphp
        <li class="repair-compact-timeline__item">
            <span class="repair-compact-timeline__dot" aria-hidden="true"></span>
            <div class="repair-compact-timeline__body">
                <div class="repair-compact-timeline__head">
                    <strong>{{ \App\Enums\AdminAction::tryFrom($event->action)?->label() ?? 'به‌روزرسانی پرونده' }}</strong>
                    @if($to && $from !== $to)
                        <span class="repair-compact-timeline__transition">{{ $from ? $from->label().' ← ' : '' }}{{ $to->label() }}</span>
                    @endif
                    <span class="repair-compact-timeline__meta">
                        <x-finance-time :value="$event->created_at" />@if($event->actor) · {{ $event->actor->name }}@endif
                    </span>
                </div>
                @if($change)
                    <div class="repair-compact-timeline__notes">
                        @if(($change['before']['repair_notes'] ?? null) !== ($change['after']['repair_notes'] ?? null) && !empty($change['after']['repair_notes']))
                            <p class="whitespace-pre-line">{{ $change['after']['repair_notes'] }}</p>
                        @endif
                        @if($showMoney && ($change['before']['repair_price_toman'] ?? null) !== ($change['after']['repair_price_toman'] ?? null))
                            <p>هزینه تعمیر: {{ \App\Support\AdminRepairMoney::display($change['before']['repair_price_toman'] ?? null) }} ← {{ \App\Support\AdminRepairMoney::display($change['after']['repair_price_toman'] ?? null) }}</p>
                        @endif
                        @if(($change['before']['delivery_method'] ?? null) !== ($change['after']['delivery_method'] ?? null) || ($change['before']['delivery_carrier'] ?? null) !== ($change['after']['delivery_carrier'] ?? null))
                            <p>روش تحویل: @if(($change['after']['delivery_method'] ?? null) === 'shipping') ارسال{{ isset($carriers[$change['after']['delivery_carrier'] ?? '']) ? ' با '.$carriers[$change['after']['delivery_carrier']] : '' }}@elseif(($change['after']['delivery_method'] ?? null) === 'in_person') حضوری@else مشخص نشده@endif</p>
                        @endif
                        @if($showMoney && ($change['before']['settlement_method'] ?? null) !== ($change['after']['settlement_method'] ?? null))
                            <p>روش پرداخت ثبت‌شده به‌روز شد.</p>
                        @endif
                    </div>
                @endif
            </div>
        </li>
    @empty
        <li class="admin-muted">هنوز سابقه‌ای برای این پرونده ثبت نشده است.</li>
    @endforelse
    </ol>
    {{ $events->links() }}
</section>
@else
<section class="admin-panel"><p>برای مشاهده اطلاعات مشتری، دسترسی لازم است.</p></section>
@endif
@endsection
