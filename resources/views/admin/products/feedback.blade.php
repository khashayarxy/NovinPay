@extends('layouts.admin')
@section('title', 'مدیریت دیدگاه‌ها و پرسش‌ها')
@section('content')
    @php
        $states = ['pending' => 'در انتظار بررسی', 'approved' => 'منتشرشده', 'rejected' => 'ردشده'];
        $stateTones = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'];
    @endphp
    <div class="admin-form-surface space-y-4">
        <header class="admin-page-heading">
            <div>
                <span class="admin-eyebrow">مدیریت دیدگاه و پرسش</span>
                <h1>{{ $product->name }}</h1>
            </div>
            <div class="admin-actions">
                <a href="{{ route('admin.products.edit', $product) }}" class="btn btn-secondary">ویرایش محصول</a>
                <a href="{{ route('admin.products.index') }}" class="btn btn-secondary">فهرست محصولات</a>
            </div>
        </header>

        @if (session('success'))
            <div class="admin-notice admin-notice--success" role="status">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="admin-notice admin-notice--error" role="alert">{{ $errors->first() }}</div>
        @endif

        @forelse ($entries as $entry)
            <form class="admin-panel admin-feedback-card" method="post" action="{{ route('admin.products.feedback.update', [$product, $entry]) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="revision" value="{{ \App\Http\Controllers\Admin\ProductFeedbackController::revision($entry) }}">

                <div class="admin-feedback-card__head">
                    <div class="flex flex-wrap items-center gap-2">
                        <strong>{{ $entry->kind === 'review' ? 'دیدگاه' : 'پرسش' }}</strong>
                        <x-admin.status :tone="$stateTones[$entry->status] ?? 'neutral'">{{ $states[$entry->status] }}</x-admin.status>
                        @if ($entry->verified_buyer)
                            <x-admin.status tone="info">خریدار تأییدشده</x-admin.status>
                        @endif
                        @if ($entry->rating)
                            <span class="admin-status">امتیاز {{ \App\Support\FaNumber::digits($entry->rating) }} از ۵</span>
                        @endif
                    </div>
                    <span class="admin-muted"><x-finance-time :value="$entry->created_at" /></span>
                </div>

                <div class="admin-feedback-card__body whitespace-pre-wrap break-words">{{ $entry->body }}</div>

                @if ($entry->reports->isNotEmpty())
                    <div class="admin-info-card admin-info-card--warning">
                        <h3>گزارش‌های کاربران</h3>
                        <ul class="list-disc ps-5 text-xs space-y-1">
                            @foreach ($entry->reports->countBy('reason') as $reason => $count)
                                <li>{{ \App\Http\Controllers\ProductFeedbackController::REPORT_REASONS[$reason] ?? $reason }} · {{ \App\Support\FaNumber::digits($count) }}</li>
                            @endforeach
                        </ul>
                        <label class="admin-checkbox mt-2"><input type="checkbox" name="resolve_reports" value="1"> گزارش‌ها بررسی شدند</label>
                    </div>
                @endif

                <div class="admin-form-grid">
                    <div>
                        <label class="label" for="status-{{ $entry->id }}">وضعیت انتشار</label>
                        <select class="input" id="status-{{ $entry->id }}" name="status">
                            @foreach ($states as $key => $label)
                                <option value="{{ $key }}" @selected($entry->status === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div>
                    <label class="label" for="reply-{{ $entry->id }}">پاسخ رسمی نوین‌پی</label>
                    <textarea class="input" id="reply-{{ $entry->id }}" name="staff_reply" rows="3" maxlength="1500" placeholder="پاسخ عمومی بدون درج اطلاعات تماس یا سفارش مشتری...">{{ $entry->staff_reply }}</textarea>
                </div>

                <div class="admin-form-footer">
                    <button class="btn btn-primary" type="submit">ذخیره نتیجهٔ بررسی</button>
                </div>
            </form>
        @empty
            <section class="admin-panel">
                <x-admin.empty-state title="هنوز دیدگاه یا پرسشی برای این محصول ثبت نشده است" icon="message-circle" />
            </section>
        @endforelse
        {{ $entries->links() }}
    </div>
@endsection
