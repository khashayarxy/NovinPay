@extends('layouts.admin')
@section('title', 'جزئیات مشاوره')
@section('content')
<header class="admin-page-heading">
    <h1>{{ \App\Models\Consultation::TOPICS[$consultation->topic] }}</h1>
    <a class="admin-text-link" href="{{ route('admin.consultations.index') }}">صندوق مشاوره</a>
</header>
<x-admin.errors/>
<div class="repair-main-stack">
    <section class="admin-panel">
        <h2>خلاصهٔ درخواست</h2>
        <dl class="admin-definition">
            @if($contact)
                <div><dt>نام مشتری</dt><dd>{{ $consultation->customer_name }}</dd></div>
                <div><dt>موبایل</dt><dd><a href="tel:{{ $consultation->contact_phone }}"><bdi dir="ltr">{{ \App\Support\FaNumber::digits($consultation->contact_phone) }}</bdi></a></dd></div>
                @if($consultation->device_info)<div><dt>دستگاه</dt><dd>{{ $consultation->device_info }}</dd></div>@endif
            @endif
            <div><dt>زمان ثبت</dt><dd><x-finance-time :value="$consultation->created_at" /></dd></div>
            <div><dt>وضعیت</dt><dd>{{ \App\Models\Consultation::STATUSES[$consultation->status] }}</dd></div>
            <div><dt>مسئول پیگیری</dt><dd>{{ $consultation->assignee?->name ?? 'انتخاب نشده' }}</dd></div>
        </dl>
        @if($contact)<h3 class="mt-4">پیام مشتری</h3><p class="whitespace-pre-line">{{ $consultation->question }}</p>@endif
    </section>
    <x-customer-association :record="$consultation"/>
    @if($consultation->status !== 'closed')
        @can('consultations.manage')
            <div class="crm-two-column">
                <x-crm-operation :action="route('admin.consultations.assign', $consultation)" :version="$consultation->version" title="مسئول پیگیری">
                    <label for="assign-consultation" class="label">همکار</label>
                    <select class="input" id="assign-consultation" name="assigned_to" required>
                        <option value="">انتخاب همکار</option>
                        @foreach($staff as $user)
                            <option value="{{ $user->id }}" @selected($user->id === $consultation->assigned_to)>{{ $user->name }}</option>
                        @endforeach
                    </select>
                    <button class="btn btn-secondary" type="submit">ذخیرهٔ مسئول</button>
                </x-crm-operation>
                <x-crm-operation :action="route('admin.consultations.transition', $consultation)" :version="$consultation->version" title="وضعیت درخواست">
                    <label for="consultation-status" class="label">وضعیت جدید</label>
                    <select class="input" id="consultation-status" name="status" required>
                        <option value="" selected disabled>انتخاب وضعیت</option>
                        @foreach($statusOptions as $value)
                            <option value="{{ $value }}">{{ \App\Models\Consultation::STATUSES[$value] }}</option>
                        @endforeach
                    </select>
                    <button class="btn btn-primary">ذخیرهٔ وضعیت</button>
                </x-crm-operation>
            </div>
            @if($history)
                <x-crm-operation :action="route('admin.consultations.entry', $consultation)" :version="$consultation->version" title="ثبت پیگیری">
                    <label class="label" for="entry-outcome">نتیجهٔ پیگیری</label>
                    <select class="input" id="entry-outcome" name="activity">
                        <option value="note">یادداشت داخلی</option>
                        <option value="answered">پاسخ داده شد</option>
                        <option value="no_answer">بدون پاسخ</option>
                        <option value="unreachable">در دسترس نبود</option>
                    </select>
                    <label class="label" for="entry-channel">روش ارتباط (برای تماس)</label>
                    <select class="input" id="entry-channel" name="contact_channel">
                        <option value="phone">تلفن</option><option value="in_person">حضوری</option><option value="external_message">پیام</option>
                    </select>
                    <x-operation-field name="body" label="یادداشت پیگیری" kind="textarea"/>
                    <button class="btn btn-primary">ثبت پیگیری</button>
                </x-crm-operation>
            @endif
        @endcan
    @endif
    @if($history)
        <section class="admin-panel" id="consultation-history">
            <h2>تاریخچهٔ پیگیری</h2>
            @forelse($history as $entry)
                <article class="crm-history">
                    <strong>{{ ['contact' => 'تلاش تماس', 'note' => 'یادداشت داخلی', 'event' => 'رویداد مدیریتی'][$entry->kind] }}</strong>
                    @if($entry->kind === 'contact')
                        <p>{{ ['phone' => 'تلفن', 'in_person' => 'حضوری', 'external_message' => 'پیام خارج از سامانه'][$entry->channel] }} · {{ ['answered' => 'پاسخ داده شد', 'no_answer' => 'بدون پاسخ', 'unreachable' => 'در دسترس نبود'][$entry->outcome] }}</p>
                    @endif
                    <p class="whitespace-pre-line">{{ $entry->body }}</p>
                    <small>{{ $entry->actor->name }} · <x-finance-time :value="$entry->created_at" /></small>
                </article>
            @empty
                <p class="admin-hint">اولین پیگیری اینجا نمایش داده می‌شود.</p>
            @endforelse
            {{ $history->links() }}
        </section>
    @endif
</div>
@endsection
