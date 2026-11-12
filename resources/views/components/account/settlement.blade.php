@props(['document', 'plan'])
<section class="np-account-card">
    <h2>پرداخت و تحویل</h2>
    @if($document)
        <p>{{ ['cleared' => 'مبلغی برای پرداخت وجود ندارد.', 'settled' => 'سند تسویه شده است.', 'partial' => 'بخشی از مبلغ پرداخت شده است.', 'unpaid' => 'پرداختی برای این سند ثبت نشده است.'][$document->settlement()] }}</p>
        @if($document->charged_minor > $document->paid_minor)
            <p>مانده <bdi>{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::display($document->charged_minor - $document->paid_minor)) }}</bdi> تومان</p>
        @endif
        @if($plan?->method)
            <p>{{ ['pickup' => 'تحویل حضوری', 'tipax' => 'تیپاکس', 'courier' => 'پیک', 'post' => 'پست'][$plan->method] ?? 'روش تحویل ثبت شده است.' }}</p>
        @endif
        @if($plan?->address_snapshot)
            <p>{{ implode('، ', array_filter(\Illuminate\Support\Arr::only($plan->address_snapshot, ['province', 'city', 'address']))) }}</p>
        @endif
        @if($plan?->tracking)
            <p>کد رهگیری ارسال <bdi dir="ltr">{{ $plan->tracking }}</bdi></p>
        @elseif($plan?->state === 'dispatched')
            <p>کد رهگیری ارسال هنوز ثبت نشده است.</p>
        @endif
        <div class="np-account-shortcuts">
            <a href="{{ route('payments.document', $document->public_id) }}">مشاهده سند و رسیدها</a>
            <a href="{{ route('delivery.show', $document->public_id) }}">جزئیات تحویل</a>
        </div>
    @else
        <p>هنوز سند پرداخت یا جزئیات ارسال برای این مورد ثبت نشده است.</p>
    @endif
</section>
