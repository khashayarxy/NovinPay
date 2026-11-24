@props(['document', 'plan'])
<section class="np-account-card">
    <h2>پرداخت و ارسال</h2>
    @if($document)
        <dl class="np-account-kv">
            <div>
                <dt>وضعیت تسویه</dt>
                <dd>{{ ['cleared' => 'مبلغی برای پرداخت وجود ندارد.', 'settled' => 'سند تسویه شده است.', 'partial' => 'بخشی از مبلغ پرداخت شده است.', 'unpaid' => 'پرداختی برای این سند ثبت نشده است.'][$document->settlement()] }}</dd>
            </div>
            @if($document->charged_minor > $document->paid_minor)
                <div>
                    <dt>مانده قابل پرداخت</dt>
                    <dd><bdi>{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::toman($document->charged_minor - $document->paid_minor)) }}</bdi> تومان</dd>
                </div>
            @endif
            @if($plan?->method)
                <div>
                    <dt>روش ارسال</dt>
                    <dd>{{ ['pickup' => 'تحویل حضوری', 'tipax' => 'تیپاکس', 'courier' => 'پیک', 'post' => 'پست'][$plan->method] ?? 'روش تحویل ثبت شده است.' }}</dd>
                </div>
            @endif
            @if($plan?->address_snapshot)
                <div>
                    <dt>نشانی تحویل</dt>
                    <dd>{{ implode('، ', array_filter(\Illuminate\Support\Arr::only($plan->address_snapshot, ['province', 'city', 'address']))) }}</dd>
                </div>
            @endif
            @if($plan?->tracking)
                <div>
                    <dt>کد رهگیری ارسال</dt>
                    <dd><bdi dir="ltr">{{ $plan->tracking }}</bdi></dd>
                </div>
            @elseif($plan?->state === 'dispatched')
                <div>
                    <dt>کد رهگیری ارسال</dt>
                    <dd>کد رهگیری ارسال هنوز ثبت نشده است.</dd>
                </div>
            @endif
        </dl>
    @else
        <div class="np-account-empty"><p>هنوز سند پرداخت یا جزئیات ارسال برای این مورد ثبت نشده است.</p></div>
    @endif
</section>
