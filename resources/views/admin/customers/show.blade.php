@extends('layouts.admin')
@section('title', 'پروفایل مشتری')
@section('content')
@php
    $canOperate = $contact && $customer->is_active && !$customer->merged_into_id && (auth()->user()->can('customers.link') || ($notes && auth()->user()->can('customers.manage')));
    $hasSideContent = $canOperate || ($contact && $duplicates->isNotEmpty());
    $shortCustId = strtoupper(substr((string) $customer->public_id, 0, 8));
    $canFinanceBalance = auth()->user()->can('finance.view') && auth()->user()->can('finance.balances');
    $canEditCustomer = !$customer->merged_into_id && auth()->user()->can('customers.manage') && auth()->user()->can('customers.contact');

    $availableTabs = [];
    if ($orders) {
        $availableTabs['orders'] = ['label' => 'سفارش‌ها', 'count' => $orders->total()];
    }
    if ($cases) {
        $availableTabs['cases'] = ['label' => 'تعمیرات فیزیکی', 'count' => $cases->total()];
    }
    if ($requests) {
        $availableTabs['requests'] = ['label' => 'درخواست‌های آنلاین', 'count' => $requests->total()];
    }
    if ($consultations) {
        $availableTabs['consultations'] = ['label' => 'مشاوره‌ها', 'count' => $consultations->total()];
    }
    $activeHistoryTab = array_key_first($availableTabs);
    if (request()->has('cases_page') && isset($availableTabs['cases'])) {
        $activeHistoryTab = 'cases';
    } elseif (request()->has('requests_page') && isset($availableTabs['requests'])) {
        $activeHistoryTab = 'requests';
    } elseif (request()->has('consultations_page') && isset($availableTabs['consultations'])) {
        $activeHistoryTab = 'consultations';
    }
@endphp
<a class="admin-back-link" href="{{ route('admin.customers.index') }}">← دفتر مشتریان</a>
<header class="admin-page-heading">
    <div>
        <span class="admin-eyebrow">پروفایل مشتری · نسخهٔ {{ \App\Support\FaNumber::digits($customer->version) }}</span>
        <div class="repair-case-header__title-row">
            <h1>{{ $contact ? $customer->name : 'پروفایل با اطلاعات محدود' }}</h1>
            <x-admin.status :tone="$customer->merged_into_id ? 'info' : ($customer->is_active ? 'success' : 'warning')">
                {{ $customer->merged_into_id ? 'ادغام‌شده' : ($customer->is_active ? 'فعال' : 'بایگانی‌شده') }}
            </x-admin.status>
        </div>
        <div class="admin-id-row">
            <span class="admin-id-chip" title="{{ $customer->public_id }}">#{{ $shortCustId }}</span>
            <button type="button" class="admin-copy-mini" data-copy-code="{{ $customer->public_id }}"><span data-copy-code-label>کپی شناسه کامل</span></button>
        </div>
    </div>
    <div class="admin-actions">
        @if($contact && $customer->is_active && !$customer->merged_into_id)
            @can('orders.create')
                @can('orders.customer_data')
                    <a class="btn btn-primary btn-sm" href="{{ route('admin.orders.create',['customer'=>$customer->public_id]) }}">+ سفارش جدید</a>
                @endcan
            @endcan
        @endif
        @if($canFinanceBalance || $canEditCustomer)
            <details class="admin-more-menu">
                <summary>
                    <span>عملیات بیشتر</span>
                    <svg class="admin-more-menu__chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5.5 7.5 4.5 4.5 4.5-4.5"/></svg>
                </summary>
                <div class="admin-more-menu__panel">
                    @if($canFinanceBalance)
                        <a href="{{ route('admin.finance.customers.show',$customer) }}">مانده و گردش مالی</a>
                    @endif
                    @if($canEditCustomer)
                        <a href="{{ route('admin.customers.edit', $customer) }}">ویرایش / بایگانی پروفایل</a>
                    @endif
                </div>
            </details>
        @endif
    </div>
</header>
<x-admin.errors/>

<div @class(['crm-two-column' => $hasSideContent, 'repair-main-stack' => !$hasSideContent])>
    <div class="repair-main-stack">
        <section class="admin-panel">
            <div class="admin-panel-heading">
                <h2>اطلاعات مشتری</h2>
                @if($accountIdentifier)
                    <span class="admin-id-chip" dir="ltr" title="{{ $accountIdentifier }}">حساب آنلاین متصل: {{ strlen($accountIdentifier) > 12 ? strtoupper(substr($accountIdentifier, 0, 8)).'…' : $accountIdentifier }}</span>
                @endif
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
                    <a class="admin-text-link" href="{{ route('admin.customers.show', $customer->mergedInto) }}">این پروفایل ادغام شده است؛ مشاهدهٔ پروفایل اصلی ←</a>
                </div>
            @endif
            @if($predecessors->isNotEmpty())
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach($predecessors as $previous)
                        <a class="btn btn-secondary btn-sm" href="{{ route('admin.customers.show', $previous) }}" title="{{ $previous->public_id }}">
                            پروفایل ادغام‌شده: <span class="np-latin" dir="ltr">#{{ strtoupper(substr((string) $previous->public_id, 0, 8)) }}</span>
                        </a>
                    @endforeach
                </div>
            @endif

            @if($contact && $customer->is_active && !$customer->merged_into_id)
                @can('customers.link')
                    <details class="admin-accordion mt-3" @if($errors->has('account')) open @endif>
                        <summary>
                            <span>اتصال حساب کاربری آنلاین</span>
                            <svg class="admin-accordion__chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5.5 7.5 4.5 4.5 4.5-4.5"/></svg>
                        </summary>
                        <div class="admin-accordion__body">
                            <form class="admin-form" method="post" action="{{ route('admin.customer-accounts.link') }}">
                                @csrf
                                <input type="hidden" name="customer" value="{{ $customer->public_id }}">
                                <div class="admin-form-grid">
                                    <x-operation-field name="account" label="شناسهٔ حساب کاربری مشتری" :maxlength="36"/>
                                    <x-operation-field name="password" label="رمز عبور فعلی شما" type="password"/>
                                </div>
                                <label class="admin-checkbox"><input type="checkbox" name="identity_confirmed" value="1" required> هویت مشتری بررسی و تأیید شد.</label>
                                <div><button class="btn btn-primary btn-sm" type="submit">ثبت اتصال حساب</button></div>
                            </form>
                        </div>
                    </details>
                @endcan
            @endif
        </section>

        <section class="admin-panel" id="customer-history">
            <div class="admin-panel-heading">
                <h2>سوابق مرتبط</h2>
            </div>
            @if(!empty($availableTabs))
                <div class="admin-tab-bar" role="tablist" aria-label="دسته‌بندی سوابق مشتری" data-admin-tabs>
                    @foreach($availableTabs as $tabKey => $tabMeta)
                        <button type="button" class="admin-tab-btn" role="tab" data-admin-tab="{{ $tabKey }}" aria-selected="{{ $activeHistoryTab === $tabKey ? 'true' : 'false' }}">
                            <span>{{ $tabMeta['label'] }}</span>
                            <span class="admin-tab-btn__count">{{ \App\Support\FaNumber::digits($tabMeta['count']) }}</span>
                        </button>
                    @endforeach
                </div>

                @if($orders)
                    <div data-admin-tab-panel="orders" @if($activeHistoryTab !== 'orders') hidden @endif>
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
                    </div>
                @endif

                @if($cases)
                    <div data-admin-tab-panel="cases" @if($activeHistoryTab !== 'cases') hidden @endif>
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
                    </div>
                @endif

                @if($requests)
                    <div data-admin-tab-panel="requests" @if($activeHistoryTab !== 'requests') hidden @endif>
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
                    </div>
                @endif

                @if($consultations)
                    <div data-admin-tab-panel="consultations" @if($activeHistoryTab !== 'consultations') hidden @endif>
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
                    </div>
                @endif
            @else
                <p class="admin-hint">مشاهدهٔ سوابق به مجوز سفارش، تعمیرات یا مشاوره نیاز دارد.</p>
            @endif
        </section>

        @if($notes)
            <section class="admin-panel">
                <h2>یادداشت‌ها و تاریخچه</h2>
                @forelse($notes as $note)
                    <article class="crm-history">
                        <p class="whitespace-pre-line">{{ $note->body }}</p>
                        <small>{{ $note->actor->name }} · <x-finance-time :value="$note->created_at" /> · {{ ['profile'=>'پروفایل', 'link'=>'اتصال سابقه', 'reconciliation'=>'ادغام پروفایل', 'note'=>'یادداشت'][$note->kind] }}</small>
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
                <p class="admin-hint">پروفایل‌های مشابه با همین شماره تماس:</p>
                @foreach($duplicates as $duplicate)
                    <div class="crm-related">
                        <div>
                            <strong>{{ $duplicate->name }}</strong>
                            <span class="admin-id-chip" title="{{ $duplicate->public_id }}">#{{ strtoupper(substr((string) $duplicate->public_id, 0, 8)) }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            @if(!$customer->merged_into_id && !$duplicate->merged_into_id)
                                <button type="button" class="btn btn-secondary btn-sm" data-prefill-merge data-merge-id="{{ $duplicate->public_id }}" data-merge-version="{{ $duplicate->version }}">انتخاب برای ادغام</button>
                            @endif
                            <a class="admin-text-link" href="{{ route('admin.customers.show', $duplicate) }}">مشاهده</a>
                        </div>
                    </div>
                @endforeach
            </section>
        @endif

        @if(!$customer->merged_into_id && $customer->is_active)
            @if($notes)
                @can('customers.manage')
                    <x-crm-operation :action="route('admin.customers.note', $customer)" :version="$customer->version" title="یادداشت داخلی">
                        <x-operation-field name="body" label="متن یادداشت داخلی" kind="textarea"/>
                        <button class="btn btn-secondary" type="submit">ثبت یادداشت</button>
                    </x-crm-operation>
                @endcan
            @endif

            @can('customers.link')
                @can('customers.contact')
                    <details class="admin-accordion" @if($errors->has('record')) open @endif>
                        <summary>
                            <span>مدیریت سوابق متصل</span>
                            <svg class="admin-accordion__chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5.5 7.5 4.5 4.5 4.5-4.5"/></svg>
                        </summary>
                        <div class="admin-accordion__body">
                            <form class="admin-form" method="post" action="{{ route('admin.customers.link', $customer) }}">
                                @csrf
                                <input type="hidden" name="version" value="{{ $customer->version }}">
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
                                <x-operation-field name="record" label="شمارهٔ درخواست یا شناسهٔ پرونده" :maxlength="64"/>
                                <div>
                                    <label for="link-mode" class="label">عملیات</label>
                                    <select name="unlink" id="link-mode" class="input">
                                        <option value="0">اتصال سابقه به این مشتری</option>
                                        <option value="1">جداسازی سابقه از این مشتری</option>
                                    </select>
                                </div>
                                <x-operation-field name="reason" label="توضیح بررسی هویت" kind="textarea" :maxlength="1000"/>
                                <label class="admin-checkbox"><input type="checkbox" name="identity_reviewed" value="1" required> هویت مشتری بررسی شد.</label>
                                <button class="btn btn-primary btn-sm" type="submit">ثبت تغییر</button>
                            </form>
                        </div>
                    </details>
                @endcan
            @endcan

            @can('customers.reconcile')
                @can('customers.manage')
                    @can('customers.link')
                        @can('customers.notes')
                            @can('customers.contact')
                                <details class="admin-accordion" id="customer-merge-accordion" @if($errors->has('destination')) open @endif>
                                    <summary>
                                        <span>ادغام پروفایل تکراری</span>
                                        <svg class="admin-accordion__chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5.5 7.5 4.5 4.5 4.5-4.5"/></svg>
                                    </summary>
                                    <div class="admin-accordion__body">
                                        <p class="admin-hint mb-3">سوابق تعمیر و مشاوره به پروفایل اصلی منتقل شده و این پروفایل بایگانی می‌شود.</p>
                                        <form class="admin-form" method="post" action="{{ route('admin.customers.reconcile', $customer) }}">
                                            @csrf
                                            <input type="hidden" name="version" value="{{ $customer->version }}">
                                            <x-operation-field name="destination" label="شناسهٔ پروفایل اصلی (مقصد)" :maxlength="36"/>
                                            <x-operation-field name="destination_version" label="نسخهٔ پروفایل مقصد" type="number"/>
                                            <x-operation-field name="reason" label="توضیح ادغام پروفایل‌ها" kind="textarea" :maxlength="1000"/>
                                            <label class="admin-checkbox"><input type="checkbox" name="identity_reviewed" value="1" required> یکسان بودن هویت هر دو پروفایل بررسی و تأیید شد.</label>
                                            <button class="btn btn-secondary btn-sm" type="submit">ادغام و بایگانی این پروفایل</button>
                                        </form>
                                    </div>
                                </details>
                            @endcan
                        @endcan
                    @endcan
                @endcan
            @endcan
        @endif
    </aside>
</div>
@endsection
