@extends('layouts.admin')
@section('title', 'پروفایل مشتری')
@section('content')
@php
    $canOperate = $contact && $customer->is_active && !$customer->merged_into_id && (auth()->user()->can('customers.link') || ($notes && auth()->user()->can('customers.manage')));
    $hasSideContent = $canOperate || ($contact && $duplicates->isNotEmpty());
    $shortCustId = strtoupper(substr((string) $customer->public_id, 0, 8));
@endphp
<header class="admin-page-heading">
    <div>
        <span class="admin-eyebrow">پروفایل مشتری · نسخهٔ {{ \App\Support\FaNumber::digits($customer->version) }}</span>
        <div class="repair-case-header__title-row">
            <h1>{{ $contact ? $customer->name : 'پروفایل با اطلاعات محدود' }}</h1>
            <x-admin.status :tone="$customer->merged_into_id ? 'info' : ($customer->is_active ? 'success' : 'warning')">
                {{ $customer->merged_into_id ? 'تطبیق‌یافته' : ($customer->is_active ? 'فعال' : 'بایگانی‌شده') }}
            </x-admin.status>
        </div>
        <div class="admin-id-row">
            <span class="admin-id-chip" title="{{ $customer->public_id }}">#{{ $shortCustId }}</span>
            <button type="button" class="admin-copy-mini" data-copy-code="{{ $customer->public_id }}"><span data-copy-code-label>کپی شناسه کامل</span></button>
        </div>
    </div>
    <div class="admin-actions">
        @can('finance.view')
            @can('finance.balances')
                <a class="btn btn-secondary btn-sm" href="{{ route('admin.finance.customers.show',$customer) }}">مانده و گردش مالی</a>
            @endcan
        @endcan
        @if($contact && $customer->is_active && !$customer->merged_into_id)
            @can('orders.create')
                @can('orders.customer_data')
                    <a class="btn btn-primary btn-sm" href="{{ route('admin.orders.create',['customer'=>$customer->public_id]) }}">+ سفارش جدید</a>
                @endcan
            @endcan
        @endif
        @if(!$customer->merged_into_id)
            @can('customers.manage')
                @can('customers.contact')
                    <a class="btn btn-secondary btn-sm" href="{{ route('admin.customers.edit', $customer) }}">ویرایش / بایگانی</a>
                @endcan
            @endcan
        @endif
        <a class="btn btn-secondary btn-sm" href="{{ route('admin.customers.index') }}">دفتر مشتریان</a>
    </div>
</header>
<x-admin.errors/>

<nav class="admin-actions mb-3" aria-label="میانبرهای پروفایل">
    <a class="admin-text-link" href="#customer-history">سوابق مرتبط ↓</a>
    @if($canOperate)<a class="admin-text-link" href="#customer-actions">عملیات پروفایل ↓</a>@endif
</nav>

<div @class(['crm-two-column' => $hasSideContent, 'repair-main-stack' => !$hasSideContent])>
    <div class="repair-main-stack">
        <section class="admin-panel">
            <div class="admin-panel-heading">
                <h2>اطلاعات جاری مشتری</h2>
            </div>
            @if($contact)
                <dl class="admin-record-facts">
                    <div><dt>نام ثبت‌شده</dt><dd><strong>{{ $customer->name }}</strong></dd></div>
                    <div><dt>شماره موبایل</dt><dd><a class="repair-phone-chip" href="tel:{{ $customer->contact_phone }}" dir="ltr"><bdi>{{ \App\Support\FaNumber::digits($customer->contact_phone) }}</bdi></a></dd></div>
                </dl>
            @else
                <p class="admin-hint">نام، شماره و یادداشت‌ها برای این حساب نمایش داده نمی‌شوند.</p>
            @endif
            @if($customer->merged_into_id)
                <div class="admin-info-card mt-3">
                    <a class="admin-text-link" href="{{ route('admin.customers.show', $customer->mergedInto) }}">این پروفایل تطبیق یافته است؛ مشاهدهٔ پروفایل مقصد ←</a>
                </div>
            @endif
            @if($predecessors->isNotEmpty())
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach($predecessors as $previous)
                        <a class="btn btn-secondary btn-sm" href="{{ route('admin.customers.show', $previous) }}" title="{{ $previous->public_id }}">
                            سابقهٔ تطبیق‌یافته: <span class="np-latin" dir="ltr">#{{ strtoupper(substr((string) $previous->public_id, 0, 8)) }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        @if($contact && $customer->is_active && !$customer->merged_into_id)
            @can('customers.link')
                <section class="admin-panel">
                    <div class="admin-panel-heading">
                        <h2>اتصال حساب کاربری آنلاین</h2>
                        @if($accountIdentifier)
                            <span class="admin-id-chip" dir="ltr" title="{{ $accountIdentifier }}">متصل: {{ strlen($accountIdentifier) > 12 ? strtoupper(substr($accountIdentifier, 0, 8)).'…' : $accountIdentifier }}</span>
                        @endif
                    </div>
                    <form class="admin-form" method="post" action="{{ route('admin.customer-accounts.link') }}">
                        @csrf
                        <input type="hidden" name="customer" value="{{ $customer->public_id }}">
                        <div class="admin-form-grid">
                            <x-operation-field name="account" label="شناسهٔ حساب اعلام‌شده توسط مشتری" :maxlength="36"/>
                            <x-operation-field name="password" label="رمز عبور فعلی شما" type="password"/>
                        </div>
                        <label class="admin-checkbox"><input type="checkbox" name="identity_confirmed" value="1" required> هویت مشتری و اجازهٔ نمایش این سوابق را مستقل از شماره موبایل بررسی کرده‌ام.</label>
                        <div><button class="btn btn-primary btn-sm" type="submit">تأیید اتصال سوابق</button></div>
                    </form>
                </section>
            @endcan
        @endif

        <section class="admin-panel" id="customer-history">
            <div class="admin-panel-heading">
                <h2>سوابق مرتبط</h2>
            </div>
            @if($orders)
                <h3 class="mt-2">سفارش‌های فروش</h3>
                @forelse($orders as $record)
                    <div class="crm-related">
                        <div>
                            <strong>سفارش #{{ \App\Support\FaNumber::digits($record->id) }} · {{ \App\Models\SalesOrder::STATUSES[$record->status] }}</strong>
                            <p class="admin-muted">{{ \App\Support\FinanceMoney::persianOrderToman($record->total) }} تومان</p>
                        </div>
                        <a class="btn btn-secondary btn-sm" href="{{ route('admin.orders.show', $record) }}">مشاهدهٔ سفارش</a>
                    </div>
                @empty
                    <p class="admin-hint">سفارش مرتبطی وجود ندارد.</p>
                @endforelse
                {{ $orders->links() }}
            @endif

            @if($requests)
                <h3 class="mt-4">درخواست آنلاین تعمیر</h3>
                @forelse($requests as $record)
                    <div class="crm-related">
                        <div>
                            <strong>{{ $record->device_brand->value }}</strong>
                            <p class="admin-muted">درخواست شمارهٔ {{ \App\Support\FaNumber::digits($record->id) }} · ثبت آنلاین</p>
                        </div>
                        <a class="btn btn-secondary btn-sm" href="{{ route('admin.repairs.show', $record) }}">درخواست آنلاین</a>
                    </div>
                @empty
                    <p class="admin-hint">درخواست آنلاین مرتبطی وجود ندارد.</p>
                @endforelse
                {{ $requests->links() }}
            @endif

            @if($cases)
                <h3 class="mt-4">پذیرش فیزیکی تعمیر</h3>
                @forelse($cases as $record)
                    <div class="crm-related">
                        <div>
                            <strong>{{ $record->device_brand?->value ?? 'دستگاه' }} · {{ $record->status->label() }}</strong>
                            <span class="admin-id-chip mt-1" title="{{ $record->public_id }}">#{{ strtoupper(substr((string) $record->public_id, 0, 8)) }}</span>
                        </div>
                        <a class="btn btn-secondary btn-sm" href="{{ route('admin.repair-cases.show', $record) }}">پروندهٔ تعمیر</a>
                    </div>
                @empty
                    <p class="admin-hint">پذیرش فیزیکی مرتبطی وجود ندارد.</p>
                @endforelse
                {{ $cases->links() }}
            @endif

            @if($consultations)
                <h3 class="mt-4">مشاوره‌ها</h3>
                @forelse($consultations as $record)
                    <div class="crm-related">
                        <div>
                            <strong>{{ \App\Models\Consultation::TOPICS[$record->topic] }}</strong>
                            <p class="admin-muted">{{ \App\Models\Consultation::STATUSES[$record->status] }}</p>
                        </div>
                        <a class="btn btn-secondary btn-sm" href="{{ route('admin.consultations.show', $record) }}">درخواست مشاوره</a>
                    </div>
                @empty
                    <p class="admin-hint">مشاورهٔ مرتبطی وجود ندارد.</p>
                @endforelse
                {{ $consultations->links() }}
            @endif

            @if(!$orders && !$requests && !$cases && !$consultations)
                <p class="admin-hint">مشاهدهٔ سوابق به مجوز سفارش، تعمیرات یا مشاوره نیاز دارد.</p>
            @endif
        </section>

        @if($notes)
            <section class="admin-panel">
                <h2>یادداشت‌ها و تاریخچهٔ خصوصی</h2>
                @forelse($notes as $note)
                    <article class="crm-history">
                        <p class="whitespace-pre-line">{{ $note->body }}</p>
                        <small>{{ $note->actor->name }} · <x-finance-time :value="$note->created_at" /> · {{ ['profile'=>'پروفایل', 'link'=>'اتصال سابقه', 'reconciliation'=>'تطبیق هویت', 'note'=>'یادداشت'][$note->kind] }}</small>
                    </article>
                @empty
                    <p class="admin-hint">یادداشتی ثبت نشده است.</p>
                @endforelse
                {{ $notes->links() }}
            </section>
        @endif
    </div>

    <aside class="repair-main-stack" id="customer-actions">
        @if($contact && $duplicates->isNotEmpty())
            <section class="admin-panel">
                <h2>شمارهٔ مشترک؛ نیازمند بررسی</h2>
                <p class="admin-hint">نامزدهای احتمالی تطبیق بر اساس شماره تماس:</p>
                @foreach($duplicates as $duplicate)
                    <div class="crm-related">
                        <strong>{{ $duplicate->name }}</strong>
                        <a class="btn btn-secondary btn-sm" href="{{ route('admin.customers.show', $duplicate) }}">بررسی پروفایل</a>
                    </div>
                @endforeach
            </section>
        @endif

        @if(!$customer->merged_into_id && $customer->is_active)
            @can('customers.link')
                @can('customers.contact')
                    <x-crm-operation :action="route('admin.customers.link', $customer)" :version="$customer->version" title="اتصال یا جداسازی سابقه">
                        <div>
                            <label class="label" for="link-type">نوع سابقه</label>
                            <select id="link-type" name="type" class="input">
                                @can('repairs.customer_data')
                                    @can('repairs.view')
                                        <option value="request">درخواست آنلاین تعمیر</option>
                                        <option value="case">پذیرش فیزیکی</option>
                                    @endcan
                                @endcan
                                @can('consultations.contact')
                                    @can('consultations.view')
                                        <option value="consultation">درخواست مشاوره</option>
                                    @endcan
                                @endcan
                            </select>
                        </div>
                        <x-operation-field name="record" label="شمارهٔ درخواست آنلاین یا شناسهٔ پرونده / مشاوره" :maxlength="64"/>
                        <div>
                            <label for="link-mode" class="label">عملیات</label>
                            <select name="unlink" id="link-mode" class="input">
                                <option value="0">اتصال سابقهٔ بدون پروفایل</option>
                                <option value="1">جداسازی سابقه از همین پروفایل</option>
                            </select>
                        </div>
                        <x-operation-field name="reason" label="دلیل و مرجع بررسی هویت؛ خصوصی" kind="textarea" :maxlength="1000"/>
                        <label class="admin-checkbox"><input type="checkbox" name="identity_reviewed" value="1" required> هویت شخص در سابقه را مستقل از شماره موبایل بررسی کرده‌ام.</label>
                        <button class="btn btn-primary" type="submit">ثبت ارتباط بررسی‌شده</button>
                    </x-crm-operation>
                @endcan
            @endcan

            @if($notes)
                @can('customers.manage')
                    <x-crm-operation :action="route('admin.customers.note', $customer)" :version="$customer->version" title="یادداشت داخلی">
                        <x-operation-field name="body" label="یادداشت خصوصی مشتری" kind="textarea"/>
                        <p class="admin-hint">یادداشت پس از ثبت قابل ویرایش نیست؛ اطلاعات بانکی یا رمز وارد نکنید.</p>
                        <button class="btn btn-secondary" type="submit">ثبت یادداشت</button>
                    </x-crm-operation>
                @endcan
            @endif

            @can('customers.reconcile')
                @can('customers.manage')
                    @can('customers.link')
                        @can('customers.notes')
                            @can('customers.contact')
                                <section class="admin-panel">
                                    <h2>تطبیق پروفایل تکراری</h2>
                                    <p class="admin-hint">سوابق تعمیر و مشاوره به پروفایل مقصد منتقل و این پروفایل بایگانی می‌شود.</p>
                                    <form class="admin-form mt-3" method="post" action="{{ route('admin.customers.reconcile', $customer) }}">
                                        @csrf
                                        <input type="hidden" name="version" value="{{ $customer->version }}">
                                        <x-operation-field name="destination" label="شناسهٔ کامل پروفایل مقصد (UUID)" :maxlength="36"/>
                                        <x-operation-field name="destination_version" label="نسخهٔ جاری پروفایل مقصد" type="number"/>
                                        <x-operation-field name="reason" label="دلیل و مرجع احراز یکسان بودن هویت؛ خصوصی" kind="textarea" :maxlength="1000"/>
                                        <label class="admin-checkbox"><input type="checkbox" name="identity_reviewed" value="1" required> هر دو پروفایل متعلق به یک هویت واحد است و انتقال اتصال‌ها را تأیید می‌کنم.</label>
                                        <button class="btn btn-secondary" type="submit">انتقال ارتباط‌ها و بایگانی این پروفایل</button>
                                    </form>
                                </section>
                            @endcan
                        @endcan
                    @endcan
                @endcan
            @endcan
        @endif
    </aside>
</div>
@endsection
