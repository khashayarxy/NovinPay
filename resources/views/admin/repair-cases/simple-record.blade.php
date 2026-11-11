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
    $toman = static fn ($amount) => $amount === null ? 'تعیین نشده' : \App\Support\FaNumber::digits(number_format($amount, 0, '.', '٬')).' تومان';
@endphp
<header class="admin-page-heading">
    <div>
        <h1>پرونده تعمیر <bdi>{{ \App\Support\FaNumber::digits($case->tracking_reference) }}</bdi></h1>
        <p>زمان پذیرش: {{ \App\Support\FinanceTime::display($case->received_at) }}</p>
    </div>
    <x-admin.status :tone="in_array($status, ['ready', 'delivered'], true) ? 'success' : ($status === 'repairing' ? 'info' : 'neutral')">{{ $case->status->label() }}</x-admin.status>
    <a class="btn btn-secondary" href="{{ route('admin.repair-cases.index') }}">پرونده‌های تعمیر</a>
</header>
<x-admin.errors />
@if($customer)
<section class="admin-panel mb-3" aria-labelledby="repair-device-context">
    <div class="admin-panel-heading"><h2 id="repair-device-context">اطلاعات پذیرش</h2></div>
    <div class="simple-repair-details">
        <section><h3>مشتری</h3><dl class="admin-record-facts">
            <div><dt>نام</dt><dd>{{ $case->customer_name }}</dd></div>
            <div><dt>شماره موبایل</dt><dd><bdi>{{ \App\Support\FaNumber::digits($case->contact_phone) }}</bdi></dd></div>
        </dl>
        @can('customers.view')
            @if($case->customer_id)<a class="admin-text-link" href="{{ route('admin.customers.show', $case->customer) }}">پرونده مشتری</a>
            @else @can('customers.link')<a class="admin-text-link" href="{{ route('admin.customers.index') }}">اتصال به پرونده مشتری</a>@endcan @endif
        @endcan
        @if($case->onlineRequest)
            @can('viewCustomerData', $case->onlineRequest)
                <p><a class="admin-text-link" href="{{ route('admin.repairs.show', $case->onlineRequest) }}">درخواست آنلاین مرتبط <bdi>{{ \App\Support\FaNumber::digits($case->onlineRequest->tracking_reference) }}</bdi></a></p>
            @endcan
        @endif
        </section>
        <section><h3>دستگاه</h3><dl class="admin-record-facts">
            <div><dt>برند و مدل</dt><dd>@if($case->device_brand)<bdi>{{ $case->device_brand->value }}</bdi> · @endif{{ $case->device_model }}</dd></div>
            @if($case->device_serial)<div><dt>شماره سریال</dt><dd><bdi>{{ $case->device_serial }}</bdi></dd></div>@endif
            <div><dt>شرح مشکل دستگاه</dt><dd class="whitespace-pre-line">{{ $case->issue }}</dd></div>
            <div><dt>وضعیت ظاهری</dt><dd class="whitespace-pre-line">{{ $case->physical_condition }}</dd></div>
        </dl></section>
    </div>
</section>
<div class="admin-record-layout repair-management-layout">
    <div class="admin-record-work">
        @unless($case->status->closed())
        @can('repairs.transition')
        <form method="post" action="{{ route('admin.repair-cases.record', $case) }}" class="admin-panel admin-form" data-simple-repair>
            @csrf
            <input type="hidden" name="version" value="{{ $case->version }}">
            <div class="admin-panel-heading"><h2>وضعیت تعمیر</h2></div>
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
                        <label class="admin-checkbox"><input type="checkbox" name="clear_delivery_carrier" value="1" @disabled(!$shipping)> پاک کردن شرکت ارسال ذخیره‌شده</label>
                        @endif
                    </div>
                </div>
            </fieldset>
            <div class="admin-form-footer"><button class="btn btn-primary" type="submit">ذخیره تغییرات</button></div>
        </form>
        @endcan
        @endunless
        <details class="admin-panel mt-3" open>
            <summary>سوابق پرونده</summary>
            <ol class="repair-history">
            @forelse($events as $event)
                @php
                    $from = \App\Enums\RepairStage::tryFrom((string) $event->from_status);
                    $to = \App\Enums\RepairStage::tryFrom((string) $event->to_status);
                    $change = $event->subject_type === 'repair_revision' ? $revisions->get($event->subject_id)?->payload : null;
                @endphp
                <li>
                    <p><strong>{{ \App\Enums\AdminAction::tryFrom($event->action)?->label() ?? 'به‌روزرسانی پرونده' }}</strong>
                        · {{ \App\Support\FinanceTime::display($event->created_at) }}
                        @if($event->actor) · {{ $event->actor->name }} @endif
                    </p>
                    @if($to && $from !== $to)<p>{{ $from ? $from->label().' ← ' : '' }}{{ $to->label() }}</p>@endif
                    @if($change)
                        @if(($change['before']['repair_notes'] ?? null) !== ($change['after']['repair_notes'] ?? null) && !empty($change['after']['repair_notes']))
                            <p class="whitespace-pre-line">{{ $change['after']['repair_notes'] }}</p>
                        @endif
                        @if($showMoney && ($change['before']['repair_price_toman'] ?? null) !== ($change['after']['repair_price_toman'] ?? null))
                            <p>هزینه تعمیر: {{ $toman($change['before']['repair_price_toman'] ?? null) }} ← {{ $toman($change['after']['repair_price_toman'] ?? null) }}</p>
                        @endif
                        @if(($change['before']['delivery_method'] ?? null) !== ($change['after']['delivery_method'] ?? null) || ($change['before']['delivery_carrier'] ?? null) !== ($change['after']['delivery_carrier'] ?? null))
                            <p>روش تحویل به‌روز شد.@if(($change['after']['delivery_method'] ?? null) === 'shipping') ارسال{{ isset($carriers[$change['after']['delivery_carrier'] ?? '']) ? ' با '.$carriers[$change['after']['delivery_carrier']] : '' }}@elseif(($change['after']['delivery_method'] ?? null) === 'in_person') حضوری@endif</p>
                        @endif
                        @if($showMoney && ($change['before']['settlement_method'] ?? null) !== ($change['after']['settlement_method'] ?? null))
                            <p>روش پرداخت ثبت‌شده به‌روز شد.</p>
                        @endif
                    @endif
                </li>
            @empty
                <li>هنوز سابقه‌ای برای این پرونده ثبت نشده است.</li>
            @endforelse
            </ol>
            {{ $events->links() }}
        </details>
    </div>
    <aside class="admin-record-context">
        @if($showMoney)
        <section class="admin-panel mb-3" aria-labelledby="repair-finance">
            <h2 id="repair-finance">هزینه و تسویه</h2>
            <dl class="admin-record-facts">
                <div><dt>هزینه تعمیر</dt><dd>{{ $toman($price) }}</dd></div>
                @if($price === null && $case->currentEstimate)<div><dt>برآورد قبلی</dt><dd>{{ $toman($case->currentEstimate->total()) }}</dd></div>@endif
                @can('finance.view')
                    <div><dt>تسویه شد</dt><dd>{{ $settled ? 'پرداخت کامل در سند ثبت شده است.' : ($repairDocument ? 'پرداخت کامل در سند ثبت نشده است.' : 'هنوز سند مالی صادر نشده است.') }}</dd></div>
                    @if($case->settlement_method)<div><dt>روش ثبت‌شده</dt><dd>{{ $case->settlement_method === 'online' ? 'اینترنتی' : 'حضوری' }}</dd></div>@endif
                @endcan
            </dl>
            @can('finance.view')
            @can('repairs.transition')
            @if($settled && !$case->status->closed())
                <form method="post" action="{{ route('admin.repair-cases.record', $case) }}" class="admin-form">
                    @csrf
                    <input type="hidden" name="version" value="{{ $case->version }}">
                    <input type="hidden" name="intent" value="settlement">
                    <label class="label" for="settlement-method">روش پرداخت ثبت‌شده در سند</label>
                    <select id="settlement-method" name="settlement_method" class="input" required>
                        <option value="">انتخاب روش پرداخت</option>
                        <option value="in_person" @selected($case->settlement_method === 'in_person')>حضوری</option>
                        <option value="online" @selected($case->settlement_method === 'online')>اینترنتی</option>
                    </select>
                    <button class="btn btn-secondary" type="submit">ذخیره روش پرداخت</button>
                </form>
            @endif
            @endcan
            @endcan
        </section>
        @endif
        <section class="admin-panel mb-3" aria-labelledby="repair-handover">
            <h2 id="repair-handover">تحویل دستگاه</h2>
            <p>{{ $shipping ? 'ارسال' : ($case->delivery_method === 'in_person' ? 'حضوری' : 'روش تحویل مشخص نشده است.') }}
                @if($shipping && isset($carriers[$case->delivery_carrier])) · {{ $carriers[$case->delivery_carrier] }} @endif
            </p>
            @if($case->delivery)
                <p>{{ \App\Support\FinanceTime::display($case->delivery->delivered_at) }}</p>
                @if($case->delivery->confirmation_reference)<p>مرجع تحویل: <bdi>{{ $case->delivery->confirmation_reference }}</bdi></p>@endif
            @endif
            @can('repairs.deliver')
            @if(in_array($status, ['ready', 'return_pending'], true))
                @if($shipping && !$case->delivery_carrier)
                    <p class="admin-hint">پیش از ثبت ارسال، شرکت ارسال را در پرونده ذخیره کنید.</p>
                @else
                    <form method="post" action="{{ route('admin.repair-cases.record', $case) }}" class="admin-form">
                        @csrf
                        <input type="hidden" name="version" value="{{ $case->version }}">
                        <input type="hidden" name="intent" value="handover">
                        @if($shipping)
                            <x-operation-field name="shipment_tracking" label="کد پیگیری ارسال" :maxlength="200"/>
                            @if($case->customer_account_id && $repairDocument)
                                <x-operation-field name="current_password" label="رمز عبور فعلی" type="password" :maxlength="255"/>
                            @endif
                        @endif
                        <label class="admin-checkbox"><input type="checkbox" name="handover_confirmed" value="1" required> {{ $shipping ? 'دستگاه به شرکت ارسال تحویل داده شد.' : 'دستگاه حضوری تحویل داده شد.' }}</label>
                        <button class="btn btn-secondary" type="submit">{{ $shipping ? 'ثبت ارسال انجام‌شده' : 'ثبت تحویل حضوری' }}</button>
                    </form>
                @endif
            @endif
            @endcan
        </section>
        <section class="admin-panel" aria-labelledby="repair-documents">
            <h2 id="repair-documents">اسناد تعمیر</h2>
            <nav class="admin-actions" aria-labelledby="repair-documents">
                @can('operate', [$case, 'print'])
                    <a class="btn btn-secondary" href="{{ route('admin.repair-cases.document', [$case, 'intake']) }}">رسید پذیرش</a>
                    <a class="btn btn-secondary" href="{{ route('admin.repair-cases.document', [$case, 'summary']) }}">صورتحساب تعمیر</a>
                    @if($case->delivery)<a class="btn btn-secondary" href="{{ route('admin.repair-cases.document', [$case, 'delivery']) }}">رسید تحویل</a>@endif
                @endcan
                @can('finance.view')<a class="btn btn-secondary" href="{{ route('admin.finance.sources.show', ['type' => 'repair', 'reference' => $case->public_id]) }}">سند مالی</a>@endcan
            </nav>
        </section>
    </aside>
</div>
@else
<p>برای مشاهده اطلاعات مشتری، دسترسی لازم است.</p>
@endif
@endsection
