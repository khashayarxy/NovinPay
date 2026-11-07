<ol class="admin-form-grid admin-panel mb-6" aria-label="مراحل تعمیر">
@foreach(['ثبت درخواست','دریافت دستگاه','عیب‌یابی و برآورد','اتمام تعمیر یا انصراف','تسویه و تحویل'] as $label)
<li @if($loop->iteration === $case->status->step()) aria-current="step" @endif>{{ \App\Support\FaNumber::digits($loop->iteration) }}. {{ $label }} @if($loop->iteration === $case->status->step())<strong> · مرحلهٔ فعلی</strong>@endif</li>
@endforeach
</ol>
@if($customer)
@can('finance.view')<section class="admin-panel mb-6"><h2>خلاصهٔ مالی</h2>
@if($repairDocument)<p>مطالبه: {{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::display($repairDocument->charged_minor)) }} تومان · دریافت ثبت‌شده: {{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::display($repairDocument->paid_minor)) }} تومان</p><p>مانده: <strong>{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::display($repairDocument->charged_minor - $repairDocument->paid_minor)) }} تومان</strong></p>
@elseif($repairBillable !== null)<p>مبلغ نهایی قابل صدور: {{ \App\Support\FaNumber::digits(number_format($repairBillable)) }} تومان</p>@else<p>مبلغ نهایی پس از مشخص شدن نتیجهٔ تعمیر ثبت می‌شود.</p>@endif
<a class="admin-text-link" href="{{ route('admin.finance.sources.show',['type'=>'repair','reference'=>$case->public_id]) }}">سند مالی و ثبت تسویه ←</a></section>@endcan
@endif
