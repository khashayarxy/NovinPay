<ol class="admin-step-strip admin-panel" aria-label="مراحل تعمیر">
@foreach(['ثبت درخواست','دریافت دستگاه','عیب‌یابی و برآورد','اتمام تعمیر یا انصراف','تسویه و تحویل'] as $label)
<li @if($loop->iteration < $case->status->step()) data-step-done="1" @endif @if($loop->iteration === $case->status->step()) aria-current="step" @endif>{{ \App\Support\FaNumber::digits($loop->iteration) }}. {{ $label }}</li>
@endforeach
</ol>
@if($customer)
@can('finance.view')
<section class="admin-panel mb-4">
    <div class="admin-panel-heading">
        <h2>خلاصهٔ مالی و هزینه تعمیر</h2>
        <a class="admin-text-link" href="{{ route('admin.finance.sources.show',['type'=>'repair','reference'=>$case->public_id]) }}">سند مالی و ثبت تسویه ←</a>
    </div>
    @if($repairDocument)
        <p>مطالبه: {{ \App\Support\FinanceMoney::persianToman($repairDocument->charged_minor) }} تومان · دریافت ثبت‌شده: {{ \App\Support\FinanceMoney::persianToman($repairDocument->paid_minor) }} تومان · مانده: <strong>{{ \App\Support\FinanceMoney::persianToman($repairDocument->charged_minor - $repairDocument->paid_minor) }} تومان</strong></p>
    @elseif($repairBillable !== null)
        <p>مبلغ نهایی قابل صدور: <strong>{{ \App\Support\FaNumber::digits(number_format($repairBillable)) }} تومان</strong></p>
    @else
        <p class="admin-muted">مبلغ نهایی پس از مشخص شدن نتیجهٔ تعمیر ثبت می‌شود.</p>
    @endif
</section>
@endcan
@endif
