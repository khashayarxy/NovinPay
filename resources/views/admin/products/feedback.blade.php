@extends('layouts.admin')
@section('title', 'مدیریت دیدگاه‌ها و پرسش‌ها')
@section('content')
    @php($states = ['pending' => 'در انتظار بررسی', 'approved' => 'منتشرشده', 'rejected' => 'ردشده'])
    <div class="admin-form-surface space-y-4">
        <header class="admin-page-heading">
            <div><h1>دیدگاه‌ها و پرسش‌های محصول</h1><p>{{ $product->name }}</p></div>
            <a href="{{ route('admin.products.edit', $product) }}" class="btn btn-secondary">ویرایش محصول</a>
        </header>
        <p class="text-sm text-slate-600">اگر نوشته حاوی اطلاعات شخصی است، آن را منتشر نکنید. در پاسخ رسمی هم اطلاعات تماس یا سفارش را درج نکنید.</p>
        @if (session('success')) <p role="status">{{ session('success') }}</p> @endif
        @if ($errors->any()) <p role="alert">{{ $errors->first() }}</p> @endif
        @forelse ($entries as $entry)
            <form class="card space-y-3 p-4" method="post" action="{{ route('admin.products.feedback.update', [$product, $entry]) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="revision" value="{{ \App\Http\Controllers\Admin\ProductFeedbackController::revision($entry) }}">
                <p class="font-semibold">{{ $entry->kind === 'review' ? 'دیدگاه' : 'پرسش' }} · {{ $states[$entry->status] }}</p>
                @if ($entry->verified_buyer) <p>خریدار تأییدشده @if ($entry->rating) · امتیاز {{ \App\Support\FaNumber::digits($entry->rating) }} از ۵ @endif</p> @endif
                <p class="whitespace-pre-wrap break-words">{{ $entry->body }}</p>
                @if ($entry->reports->isNotEmpty())
                    <ul class="list-disc ps-5">
                        @foreach ($entry->reports->countBy('reason') as $reason => $count)
                            <li>{{ \App\Http\Controllers\ProductFeedbackController::REPORT_REASONS[$reason] ?? $reason }} · {{ \App\Support\FaNumber::digits($count) }}</li>
                        @endforeach
                    </ul>
                    <label><input type="checkbox" name="resolve_reports" value="1"> گزارش‌ها بررسی شدند</label>
                @endif
                <label class="label" for="status-{{ $entry->id }}">وضعیت انتشار</label>
                <select class="input" id="status-{{ $entry->id }}" name="status">
                    @foreach ($states as $key => $label)
                        <option value="{{ $key }}" @selected($entry->status === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <label class="label" for="reply-{{ $entry->id }}">پاسخ رسمی نوین‌پی</label>
                <textarea class="input" id="reply-{{ $entry->id }}" name="staff_reply" rows="3" maxlength="1500">{{ $entry->staff_reply }}</textarea>
                <button class="btn btn-primary" type="submit">ذخیره نتیجهٔ بررسی</button>
            </form>
        @empty
            <p>هنوز دیدگاه یا پرسشی برای این محصول ثبت نشده است.</p>
        @endforelse
        {{ $entries->links() }}
    </div>
@endsection
