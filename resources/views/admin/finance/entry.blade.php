@extends('layouts.admin')
@section('title','مدرک خصوصی مالی')
@section('content')
@include('admin.finance.heading',['heading'=>'مدرک و تاریخچهٔ مالی'])
<div class="repair-layout">
    <section class="admin-panel">
        <div class="admin-panel-heading">
            <h2>{{ \App\Support\FinanceInput::KINDS[$entry->kind] }} · {{ \App\Support\FinanceInput::CATEGORIES[$entry->category] }}</h2>
            <div class="admin-id-row mt-0">
                <span class="admin-id-chip" title="{{ $entry->public_id }}">#{{ strtoupper(substr((string) $entry->public_id, 0, 8)) }}</span>
                <button type="button" class="admin-copy-mini" data-copy-code="{{ $entry->public_id }}"><span data-copy-code-label>کپی شناسه</span></button>
            </div>
        </div>
        <dl class="finance-definition">
            @foreach(['charge_delta'=>'تغییر فاکتور','receipt_delta'=>'تغییر دریافت','expense_delta'=>'تغییر هزینه'] as $field=>$label)
                <dt>{{ $label }}</dt><dd><bdi>{{ \App\Support\FinanceMoney::persianToman($entry->$field) }}</bdi> تومان</dd>
            @endforeach
            <dt>روش ثبت</dt><dd>{{ \App\Support\FinanceInput::methodLabel($entry->method) }}</dd>
            <dt>شماره پیگیری / مدرک</dt><dd class="crm-code">{{ $entry->reference ?? '—' }}</dd>
            <dt>ثبت‌کننده</dt><dd>{{ $entry->actor_id ? 'همکار '.\App\Support\FaNumber::digits($entry->actor_id) : 'ثبت خودکار درگاه' }}</dd>
            <dt>زمان ثبت</dt><dd><x-finance-time :value="$entry->created_at"/></dd>
        </dl>
        <h3 class="mt-4 mb-1">توضیح ثبت</h3>
        <p class="commerce-private">{{ $entry->note }}</p>
        @if($entry->reversal_of)<p class="admin-hint">اصلاحیهٔ ثبت شمارهٔ #{{ \App\Support\FaNumber::digits($entry->reversal_of) }}</p>@endif
        @if($reversal)<a class="admin-text-link mt-2" href="{{ route('admin.finance.entries.show',$reversal) }}">مشاهدهٔ اصلاحیهٔ این ثبت ←</a>@endif
    </section>
    <aside class="admin-panel">
        <h2>وضعیت ثبت مالی</h2>
        <p class="admin-hint">{{ $entry->method === 'gateway' ? 'تأیید خودکار درگاه پرداخت.' : 'ثبت داخلی همکار.' }} لغو ثبت صرفاً در دفتر مالی اعمال می‌شود و انتقال بانکی انجام نمی‌دهد.</p>
        <div class="grid gap-2 mt-4">
            @if($document)
                <a class="btn btn-secondary" href="{{ route('admin.finance.documents.show',$document) }}">مشاهده فاکتور و تسویه</a>
            @endif
            @if(!$entry->payment_transaction_id && !$reversal && $entry->kind!=='reversal' && $financeAllows('finance.correct'))
                <a class="btn btn-secondary" href="{{ route('admin.finance.reverse.create',['entry'=>$entry->public_id]) }}">لغو و اصلاح ثبت</a>
            @endif
        </div>
    </aside>
</div>
@endsection
