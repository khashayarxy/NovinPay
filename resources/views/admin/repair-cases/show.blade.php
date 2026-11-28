@extends('layouts.admin')
@section('title', 'پروندهٔ تعمیر')
@section('content')
@php
    $stage = $case->status->value;
    $shortCaseId = strtoupper(substr((string) $case->public_id, 0, 8));
    $canPrintDocs = auth()->user()->can('operate', [$case, 'print']);
    $canViewFinance = auth()->user()->can('finance.view');
@endphp
<a class="admin-back-link" href="{{ route('admin.repair-cases.index') }}">← پرونده‌های تعمیر</a>
<header class="admin-page-heading">
    <div>
        <div class="repair-case-header__title-row">
            <h1>پروندهٔ تعمیر {{ ($case->device_brand?->value ?? '—') }}</h1>
            <x-admin.status :tone="in_array($stage, ['ready', 'delivered'], true) ? 'success' : ($stage === 'repairing' ? 'info' : 'neutral')">{{ $case->status->label() }}</x-admin.status>
        </div>
        <p class="repair-case-header__sub">
            <span>دریافت: <x-finance-time :value="$case->received_at" /> · {{ $case->receiver->name }}</span>
            <span>· تکنسین: <strong>{{ $case->technician?->name ?? 'تخصیص نیافته' }}</strong></span>
            <span>· {{ $case->repair_request_id ? 'مرتبط با درخواست آنلاین' : 'مستقیم در فروشگاه' }}</span>
        </p>
        <div class="admin-id-row">
            <span class="admin-id-chip" title="{{ $case->public_id }}">#{{ $shortCaseId }}</span>
            <button type="button" class="admin-copy-mini" data-copy-code="{{ $case->public_id }}"><span data-copy-code-label>کپی شناسه</span></button>
        </div>
    </div>
    @if($canPrintDocs || $canViewFinance)
        <div class="admin-actions">
            <div class="admin-more-menu" data-admin-menu>
                <button type="button" data-admin-menu-toggle aria-expanded="false" aria-controls="repair-print-menu">
                    <span>چاپ و اسناد</span>
                    <svg class="admin-more-menu__chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5.5 7.5 4.5 4.5 4.5-4.5"/></svg>
                </button>
                <div class="admin-more-menu__panel" id="repair-print-menu" hidden>
                    @if($canPrintDocs)
                        <a href="{{ route('admin.repair-cases.document', [$case, 'intake']) }}">رسید پذیرش</a>
                        <a href="{{ route('admin.repair-cases.document', [$case, 'summary']) }}">خلاصهٔ تعمیر</a>
                        @if($delivery)<a href="{{ route('admin.repair-cases.document', [$case, 'delivery']) }}">سند تحویل</a>@endif
                    @endif
                    @if($canViewFinance)
                        <a href="{{ route('admin.finance.sources.show',['type'=>'repair','reference'=>$case->public_id]) }}">سند مالی و هزینه تعمیر</a>
                    @endif
                </div>
            </div>
        </div>
    @endif
</header>
<x-admin.errors />
<x-customer-association :record="$case" :subtle="true"/>

@include('admin.repair-cases.workflow')

<div class="repair-layout mt-4">
    <div class="repair-main-stack">
        @if($customer)
            <section class="admin-panel">
                <div class="admin-panel-heading">
                    <h2>اطلاعات پذیرش</h2>
                    @if($case->repair_request_id)
                        <a class="admin-text-link" href="{{ route('admin.repairs.show', $case->repair_request_id) }}">درخواست آنلاین اولیه ←</a>
                    @endif
                </div>
                <dl class="admin-definition">
                    <div><dt>مشتری</dt><dd>{{ $case->customer_name }} · <bdi>{{ $case->contact_phone }}</bdi></dd></div>
                    <div><dt>دستگاه</dt><dd>{{ $case->device_model }} · سریال: <bdi>{{ $case->device_serial ?? 'ثبت نشده' }}</bdi></dd></div>
                    <div><dt>مشکل اعلام‌شده</dt><dd class="whitespace-pre-line">{{ $case->issue }}</dd></div>
                    <div><dt>وضعیت ظاهری</dt><dd class="whitespace-pre-line">{{ $case->physical_condition }}</dd></div>
                    <div><dt>لوازم همراه</dt><dd class="whitespace-pre-line">{{ $case->accessories }}</dd></div>
                    <div><dt>کد پیگیری مشتری</dt><dd class="repair-code-chip" dir="ltr">{{ $case->tracking_reference }}</dd></div>
                </dl>
            </section>
            <section class="admin-panel">
                <h2>سوابق عیب‌یابی، برآورد و تحویل</h2>
                @include('admin.repair-cases.history')
                @foreach($case->contactAttempts()->latest('id')->get() as $contact)
                    <div class="repair-history-card">
                        <p><strong>تماس بی‌پاسخ</strong> · <x-finance-time :value="$contact->created_at" /></p>
                        <p class="whitespace-pre-line mt-1">{{ $contact->note }}</p>
                    </div>
                @endforeach
            </section>
        @else
            <section class="admin-panel">
                <h2>اطلاعات شخصی محدود است</h2>
                <p class="admin-hint">اطلاعات مشتری، شرح کار، برآورد و یادداشت‌ها به مجوز جداگانه نیاز دارند.</p>
            </section>
        @endif

        <section class="admin-panel" id="repair-timeline">
            <h2>خط زمانی پرونده</h2>
            <ol class="repair-timeline">
                @foreach($events as $event)
                    <li>
                        <strong>{{ \App\Enums\AdminAction::from($event->action)->label() }}</strong>
                        <p>{{ $event->from_status ? \App\Enums\RepairStage::from($event->from_status)->label().' ← ' : '' }}{{ \App\Enums\RepairStage::from($event->to_status)->label() }}</p>
                        <p class="admin-hint">{{ $event->actor->name }} · <x-finance-time :value="$event->created_at" /> @if($event->subject_type === 'user') · تکنسین: کاربر {{ \App\Support\FaNumber::digits($event->subject_id) }} @endif</p>
                    </li>
                @endforeach
            </ol>
            {{ $events->links() }}
        </section>
    </div>

    <aside class="repair-action-stack" aria-label="عملیات مجاز پرونده" id="repair-actions">
        @include('admin.repair-cases._payment-link-card')

        @if(!$case->status->closed())
            @include('admin.repair-cases.diagnose')

            @php($nextStage = ['approved'=>'repairing','repaired'=>'ready'][$stage] ?? null)
            @if($nextStage)
                @can('operate', [$case, $nextStage === 'inspecting' ? 'inspect' : ($nextStage === 'repairing' ? 'start' : 'manage')])
                    <x-repair-operation :case="$case" action="transition" title="مرحلهٔ بعد">
                        <input type="hidden" name="status" value="{{ $nextStage }}">
                        <p class="admin-hint">{{ \App\Enums\RepairStage::from($nextStage)->label() }}</p>
                        <button class="btn btn-primary" type="submit">{{ ['waiting_inspection'=>'ورود به صف بررسی','inspecting'=>'شروع بررسی','repairing'=>'شروع تعمیر با تأیید مشتری','ready'=>'آمادهٔ مراجعه اعلام شود'][$nextStage] }}</button>
                    </x-repair-operation>
                @endcan
            @endif

            @if($stage === 'waiting_approval' && $estimates->first())
                @can('operate', [$case, 'decision'])
                    <x-repair-operation :case="$case" action="decision" title="ثبت تصمیم مشتری">
                        <input type="hidden" name="estimate_id" value="{{ $estimates->first()->id }}">
                        <p class="admin-muted">برآورد نسخهٔ {{ \App\Support\FaNumber::digits($estimates->first()->revision) }} · جمع {{ \App\Support\FaNumber::digits(number_format($estimates->first()->total())) }} تومان</p>
                        <label class="label" for="estimate-decision">تصمیم اعلام‌شده</label>
                        <select class="input" id="estimate-decision" name="decision" required>
                            <option value="">انتخاب تصمیم</option>
                            <option value="approved">مشتری تأیید کرده است</option>
                            <option value="rejected">مشتری رد کرده است</option>
                        </select>
                        <label class="label" for="decision-channel">کانال دریافت تصمیم</label>
                        <select class="input" id="decision-channel" name="channel" required>
                            <option value="">انتخاب کانال</option>
                            @foreach(['phone'=>'تلفنی','in_person'=>'حضوری','message'=>'پیام مشتری','document'=>'سند مشتری'] as $key=>$label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <x-operation-field name="evidence" label="توضیح یا مرجع تأیید / رد" kind="textarea" :maxlength="1000"/>
                        <label class="admin-checkbox"><input type="checkbox" name="staff_recorded_confirmation" value="1" required> تصمیم واقعی مشتری را ثبت می‌کنم.</label>
                        <button class="btn btn-primary" type="submit">ثبت تصمیم مشتری</button>
                    </x-repair-operation>
                @endcan
            @endif

            @if($stage === 'repairing')
                @can('operate', [$case, 'work'])
                    <x-repair-operation :case="$case" action="work" title="ثبت کار تعمیر">
                        <x-operation-field name="work_performed" label="کار انجام‌شده (قابل درج در خلاصهٔ مشتری)" kind="textarea"/>
                        <x-operation-field name="parts_replaced" label="قطعات تعویضی (اگر ندارد بنویسید ندارد)" kind="textarea"/>
                        <x-operation-field name="internal_notes" label="یادداشت داخلی کار" kind="textarea" :required="false"/>
                        <label class="label" for="work-complete">نتیجهٔ این ثبت</label>
                        <select class="input" id="work-complete" name="complete">
                            <option value="0">کار ادامه دارد</option>
                            <option value="1">تعمیر و کنترل پایان تکمیل شده است</option>
                        </select>
                        <x-operation-field name="completion_checks" label="کنترل‌های پایان (برای تکمیل الزامی)" kind="textarea" :required="false"/>
                        <x-operation-field name="final_result" label="نتیجهٔ نهایی (برای تکمیل الزامی)" kind="textarea" :required="false"/>
                        <button class="btn btn-primary" type="submit">ثبت کار و نتیجه</button>
                    </x-repair-operation>
                @endcan
            @endif

            @if(in_array($stage,['ready','return_pending']))
                @can('operate', [$case, 'delivery'])
                    <x-repair-operation :case="$case" action="delivery" title="ثبت تحویل دستگاه">
                        <p class="admin-muted">{{ $stage === 'ready' ? 'تحویل پس از تعمیر و کنترل‌های پایان' : 'بازگرداندن بدون تعمیر موفق' }}</p>
                        <x-operation-field name="recipient_name" label="نام شخص تحویل‌گیرنده" :maxlength="100"/>
                        <x-operation-field name="delivered_at" label="تاریخ و ساعت تحویل (تهران)" type="datetime-local" :value="\App\Support\RepairTime::input()"/>
                        <x-operation-field name="outcome_notes" label="نتیجه و توضیحات تحویل / علت بازگرداندن" kind="textarea"/>
                        <label class="label" for="delivery-method">روش تأیید ثبت‌شده (در صورت وجود)</label>
                        <select id="delivery-method" name="confirmation_method" class="input">
                            <option value="">مدرکی ثبت نشده است</option>
                            <option value="staff_observation">ثبت مشاهدات کارمند</option>
                            <option value="message">پیام تحویل‌گیرنده</option>
                            <option value="document">سند قابل ارجاع</option>
                        </select>
                        <x-operation-field name="confirmation_reference" label="مرجع مدرک تحویل (در صورت انتخاب روش)" kind="textarea" :required="false" :maxlength="1000"/>
                        @if($stage === 'ready' && $estimates->first())
                            <label class="admin-checkbox"><input type="checkbox" name="amounts_confirmed" value="1" required> مبلغ نهایی برابر آخرین برآورد تأییدشده ({{ \App\Support\FaNumber::digits(number_format($estimates->first()->total())) }} تومان) بررسی شد.</label>
                        @endif
                        @if($case->customer_account_id)
                            <x-operation-field name="current_password" label="رمز عبور فعلی" type="password" :maxlength="255" :required="$hasRepairDocument"/>
                        @endif
                        <label class="admin-checkbox"><input type="checkbox" name="handover_confirmed" value="1" required> تحویل واقعی دستگاه و لوازم را به شخص نام‌برده تأیید می‌کنم.</label>
                        <button class="btn btn-primary" type="submit">ثبت تحویل و بستن پرونده</button>
                    </x-repair-operation>
                @endcan
            @endif

            @if(in_array($stage,['inspecting','inspected','waiting_approval','approved','repairing']))
                @can('operate', [$case, 'inspect'])
                    <x-repair-operation :case="$case" action="inspect" title="ثبت بررسی فنی">
                        <x-operation-field name="findings" label="مشاهدات فنی" kind="textarea"/>
                        <x-operation-field name="diagnosis" label="تشخیص عیب" kind="textarea"/>
                        <x-operation-field name="proposed_actions" label="اقدامات پیشنهادی" kind="textarea"/>
                        <x-operation-field name="internal_notes" label="یادداشت داخلی بررسی" kind="textarea" :required="false"/>
                        <label class="label" for="inspection-complete">وضعیت بررسی</label>
                        <select id="inspection-complete" name="complete" class="input">
                            <option value="0">ادامهٔ بررسی</option>
                            <option value="1">بررسی تکمیل شده</option>
                        </select>
                        <button class="btn btn-primary" type="submit">ثبت بررسی</button>
                    </x-repair-operation>
                @endcan
            @endif

            @if(in_array($stage,['inspected','waiting_approval','approved']))
                @can('operate', [$case, 'estimate'])
                    <x-repair-operation :case="$case" action="estimate" title="ثبت برآورد هزینه">
                        <x-operation-field name="customer_summary" label="شرح پیشنهادی قابل ارائه به مشتری" kind="textarea"/>
                        <div class="admin-form-grid">
                            <x-operation-field name="service_cost" label="خدمات (تومان)" type="number"/>
                            <x-operation-field name="parts_cost" label="قطعات (تومان)" type="number"/>
                        </div>
                        <button class="btn btn-primary" type="submit">صدور برآورد و انتظار تأیید</button>
                    </x-repair-operation>
                @endcan
            @endif

            @can('operate', [$case, 'assign'])
                <details class="admin-accordion">
                    <summary>
                        <span>تخصیص یا تغییر تکنسین</span>
                        <svg class="admin-accordion__chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5.5 7.5 4.5 4.5 4.5-4.5"/></svg>
                    </summary>
                    <div class="admin-accordion__body">
                        <form method="post" action="{{ route('admin.repair-cases.assign', $case) }}" class="admin-form repair-operation">
                            @csrf<input type="hidden" name="version" value="{{ $case->version }}">
                            <label class="label" for="assign-tech">تکنسین فعال و مجاز</label>
                            <select class="input" name="technician_id" id="assign-tech" required>
                                <option value="">انتخاب تکنسین</option>
                                @foreach($technicians as $tech)<option value="{{ $tech->id }}" @selected($case->technician_id === $tech->id)>{{ $tech->name }}</option>@endforeach
                            </select>
                            <button class="btn btn-secondary" type="submit">ثبت تخصیص</button>
                        </form>
                    </div>
                </details>
            @endcan

            @if(in_array($stage,['received','waiting_inspection','inspecting','inspected','waiting_approval','approved','repairing']))
                @can('operate', [$case, 'manage'])
                    <details class="admin-accordion">
                        <summary>
                            <span>لغو ادامهٔ تعمیر و بازگرداندن دستگاه</span>
                            <svg class="admin-accordion__chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5.5 7.5 4.5 4.5 4.5-4.5"/></svg>
                        </summary>
                        <div class="admin-accordion__body">
                            <form method="post" action="{{ route('admin.repair-cases.transition', $case) }}" class="admin-form repair-operation">
                                @csrf<input type="hidden" name="version" value="{{ $case->version }}">
                                <input type="hidden" name="status" value="return_pending">
                                <p class="admin-hint">خروج دستگاه فقط با ثبت تحویلِ بازگرداندن انجام می‌شود.</p>
                                <button class="btn btn-secondary" type="submit">انتظار بازگرداندن بدون تعمیر</button>
                            </form>
                        </div>
                    </details>
                @endcan
            @endif
        @endif
    </aside>
</div>
@endsection
