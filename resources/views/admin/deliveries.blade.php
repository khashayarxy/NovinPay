@extends('layouts.admin')
@section('title', 'مدیریت ارسال و تحویل')
@section('content')
<header class="admin-page-heading">
    <div>
        <h1>مدیریت ارسال و تحویل</h1>
        <p>برای مشاهدهٔ نشانی و ثبت مراحل آماده‌سازی یا تحویل هر سفارش، روی «مدیریت ارسال» کلیک کنید.</p>
    </div>
    <span class="admin-status">{{ \App\Support\FaNumber::digits($plans->total()) }} مورد در انتظار</span>
</header>
<x-admin.errors />

@if($plans->isEmpty())
    <x-admin.empty-state title="درخواست تحویلی در انتظار نیست" icon="truck" class="admin-panel">سفارش‌ها و تعمیرات آمادهٔ ارسال یا تحویل حضوری در این بخش نمایش داده می‌شوند.</x-admin.empty-state>
@else
    <div class="admin-delivery-list">
        @foreach($plans as $plan)
            @php
                $ctx = $planContext[$plan->id] ?? [];
                $methodLabels = [
                    'pickup' => 'تحویل حضوری',
                    'post' => 'پست',
                    'tipax' => 'تیپاکس پس‌کرایه',
                    'courier' => 'پیک پس‌کرایه',
                ];
                $activeMethod = $plan->requested_method ?? $plan->method;
                $methodLabel = $methodLabels[$activeMethod] ?? $activeMethod;
                $address = $plan->requested_address ?? $plan->address_snapshot;
                $formattedAddress = \App\Support\DeliveryAddress::format($address);
                $stageLabel = $plan->requested_method
                    ? 'در انتظار تأیید روش'
                    : (!empty($ctx['pickup_ready_done']) ? 'آمادهٔ تحویل حضوری' : (!empty($ctx['preparing_done']) ? 'در حال آماده‌سازی' : 'روش تأییدشده'));
                $stageTone = $plan->requested_method ? 'warning' : 'info';
            @endphp
            <article class="admin-panel admin-delivery-card">
                <div class="admin-delivery-card__header">
                    <div class="admin-delivery-card__main">
                        <div class="admin-delivery-card__top">
                            <span class="admin-delivery-card__title">{{ $ctx['code'] ?? ('تحویل #'.\App\Support\FaNumber::digits($plan->id)) }}</span>
                            @if(!empty($ctx['customer_name']))
                                <span class="text-sm font-medium text-slate-700">· {{ $ctx['customer_name'] }}</span>
                            @endif
                            <x-admin.status tone="neutral">{{ $methodLabel }}</x-admin.status>
                            <x-admin.status :tone="$stageTone">{{ $stageLabel }}</x-admin.status>
                            @if(isset($ctx['is_settled']))
                                <x-admin.status :tone="$ctx['is_settled'] ? 'success' : 'warning'">
                                    {{ $ctx['is_settled'] ? 'تسویه‌شده' : 'دارای مانده' }}
                                </x-admin.status>
                            @endif
                        </div>
                        <div class="admin-delivery-card__sub">
                            @if(!empty($ctx['items_summary']))
                                <span>{{ \App\Support\LatinText::html($ctx['items_summary']) }}</span>
                            @endif
                            @if(!empty($ctx['customer_phone']))
                                <span>موبایل: <a class="repair-phone-link" href="tel:{{ \App\Support\FaNumber::normalizeDigits($ctx['customer_phone']) }}"><bdi dir="ltr">{{ \App\Support\FaNumber::digits($ctx['customer_phone']) }}</bdi></a></span>
                            @endif
                            @if(!empty($ctx['date']))
                                <span>تاریخ: <x-finance-time :value="$ctx['date']" :with-time="false" /></span>
                            @endif
                        </div>
                    </div>
                    <div class="admin-delivery-card__actions">
                        <button type="button"
                                class="btn btn-secondary btn-sm"
                                data-toggle-panel="delivery-panel-{{ $plan->id }}"
                                data-toggle-group="deliveries"
                                aria-expanded="false"
                                aria-controls="delivery-panel-{{ $plan->id }}">
                            مدیریت ارسال
                        </button>
                    </div>
                </div>

                <div id="delivery-panel-{{ $plan->id }}" class="admin-delivery-card__body" hidden>
                    @if($address || !empty($ctx['source_url']) || !empty($ctx['document_url']))
                        <div class="admin-info-card">
                            @if($address)
                                <p>
                                    <strong>گیرنده:</strong> {{ $address['recipient'] ?? ($ctx['customer_name'] ?? '—') }}
                                    @if(!empty($address['phone']))
                                        · <a class="repair-phone-link" href="tel:{{ \App\Support\FaNumber::normalizeDigits($address['phone']) }}"><bdi dir="ltr">{{ \App\Support\FaNumber::digits($address['phone']) }}</bdi></a>
                                    @endif
                                </p>
                                @if($formattedAddress !== '')
                                    <p><strong>نشانی:</strong> {{ $formattedAddress }}</p>
                                @endif
                                @if(!empty($address['postal_code']))
                                    <p><strong>کد پستی:</strong> <bdi class="np-latin" dir="ltr">{{ \App\Support\FaNumber::digits($address['postal_code']) }}</bdi></p>
                                @endif
                            @endif
                            <div class="admin-actions mt-2">
                                @if(!empty($ctx['source_url']))
                                    <a class="admin-text-link" href="{{ $ctx['source_url'] }}">مشاهدهٔ پرونده/سفارش ←</a>
                                @endif
                                @if(!empty($ctx['document_url']))
                                    <a class="admin-text-link" href="{{ $ctx['document_url'] }}">سند مالی ←</a>
                                @endif
                            </div>
                        </div>
                    @endif

                    @if($plan->requested_method)
                        @if($plan->requested_method === 'post')
                            <div class="admin-info-card admin-info-card--warning">
                                <h3>نیاز به هماهنگی هزینهٔ پست</h3>
                                <p>استعلام خودکار هزینهٔ پست هنوز فعال نیست؛ بنابراین امکان تأیید سیستمی روش پستی در این مرحله وجود ندارد. با مشتری تماس بگیرید تا روش ارسال (تیپاکس، پیک یا تحویل حضوری) را از حساب کاربری خود تغییر دهد.</p>
                                <div class="admin-actions mt-2">
                                    @if(!empty($ctx['customer_phone']))
                                        <a class="btn btn-primary btn-sm" href="tel:{{ \App\Support\FaNumber::normalizeDigits($ctx['customer_phone']) }}">تماس با مشتری</a>
                                    @endif
                                    @if(!empty($ctx['source_url']))
                                        <a class="btn btn-secondary btn-sm" href="{{ $ctx['source_url'] }}">مشاهدهٔ جزئیات</a>
                                    @endif
                                </div>
                            </div>
                        @else
                            <div class="admin-delivery-step">
                                <h3>گام ۱: تأیید روش تحویل ({{ $methodLabel }})</h3>
                                <p class="admin-hint">پس از تأیید، امکان ثبت آماده‌سازی و تحویل نهایی فعال می‌شود.</p>
                                <form class="admin-form" method="post" action="{{ route('admin.deliveries.confirm', $plan->public_id) }}">
                                    @csrf
                                    <input type="hidden" name="version" value="{{ $plan->version }}">
                                    <input type="hidden" name="operation_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                                    <button class="btn btn-primary" type="submit">تأیید روش تحویل</button>
                                </form>
                            </div>
                        @endif
                    @else
                        <div class="admin-delivery-steps">
                            @if($plan->sales_order_id)
                                <div class="admin-delivery-step">
                                    <h3>وضعیت آماده‌سازی و اطلاع‌رسانی</h3>
                                    <p class="admin-hint">با ثبت هر مرحله، پیامک اطلاع‌رسانی برای مشتری ارسال می‌شود.</p>
                                    <form class="admin-actions" method="post" action="{{ route('admin.deliveries.order-progress', $plan->public_id) }}">
                                        @csrf
                                        <input type="hidden" name="version" value="{{ $plan->version }}">
                                        <button class="btn btn-secondary" type="submit" name="progress" value="preparing">
                                            {{ !empty($ctx['preparing_done']) ? '✓ شروع آماده‌سازی ثبت شده' : 'ثبت شروع آماده‌سازی' }}
                                        </button>
                                        @if($plan->method === 'pickup')
                                            <button class="btn btn-secondary" type="submit" name="progress" value="pickup_ready">
                                                {{ !empty($ctx['pickup_ready_done']) ? '✓ آمادگی تحویل ثبت شده' : 'ثبت آمادگی تحویل حضوری' }}
                                            </button>
                                        @endif
                                    </form>
                                </div>
                            @endif

                            <div class="admin-delivery-step">
                                <h3>ثبت تحویل نهایی</h3>
                                @if(isset($ctx['is_settled']) && !$ctx['is_settled'])
                                    <div class="admin-info-card admin-info-card--warning">
                                        <p>ماندهٔ سند مالی ({{ \App\Support\FinanceMoney::persianToman($ctx['outstanding_minor'] ?? 0) }} تومان) پیش از تحویل باید تسویه شود.</p>
                                        @if(!empty($ctx['document_url']))
                                            <a class="admin-text-link mt-1" href="{{ $ctx['document_url'] }}">ثبت تسویه در سند مالی ←</a>
                                        @endif
                                    </div>
                                @else
                                    <form class="admin-form" method="post" action="{{ route('admin.deliveries.dispatch', $plan->public_id) }}">
                                        @csrf
                                        <input type="hidden" name="version" value="{{ $plan->version }}">
                                        <x-operation-field name="recipient" label="نام تحویل‌گیرنده یا مسئول شرکت حمل" :value="$address['recipient'] ?? ($ctx['customer_name'] ?? '')" :maxlength="100"/>
                                        <x-operation-field name="tracking" label="کد رهگیری شرکت حمل یا مرجع تحویل حضوری" :maxlength="200"/>
                                        <label class="admin-checkbox"><input type="checkbox" name="handover_confirmed" value="1" required> دستگاه واقعاً تحویل مشتری یا شرکت حمل داده شد.</label>
                                        <button class="btn btn-primary" type="submit">ثبت تحویل واقعی</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            </article>
        @endforeach
    </div>
    <div class="mt-4">{{ $plans->links() }}</div>
@endif
@endsection
