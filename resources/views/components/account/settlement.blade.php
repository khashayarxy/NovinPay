@props(['document', 'plan', 'paidAtDisplay' => null])
<section class="np-account-card">
    <h2>پرداخت و ارسال</h2>
    @if($document)
        @php
            $settlementState = $document->settlement();
            $settlementBadge = match ($settlementState) {
                'settled' => ['label' => 'پرداخت‌شده', 'class' => 'badge badge-success'],
                'cleared' => ['label' => 'بدون مانده', 'class' => 'badge badge-success'],
                'partial' => ['label' => 'پرداخت ناقص', 'class' => 'badge badge-warning'],
                default => ['label' => 'در انتظار پرداخت', 'class' => 'badge badge-warning'],
            };
        @endphp
        <dl class="np-account-kv">
            <div>
                <dt>وضعیت تسویه</dt>
                <dd><span class="{{ $settlementBadge['class'] }}">{{ $settlementBadge['label'] }}</span></dd>
            </div>
            @if(! empty($paidAtDisplay))
                <div>
                    <dt>تاریخ و ساعت پرداخت</dt>
                    <dd><bdi dir="ltr">{{ $paidAtDisplay }}</bdi></dd>
                </div>
            @endif
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
                    <dd>{{ \App\Support\DeliveryAddress::format($plan->address_snapshot) }}</dd>
                </div>
            @endif
            @if($plan?->tracking)
                <div>
                    <dt>کد رهگیری ارسال</dt>
                    <dd><bdi class="np-latin" dir="ltr">{{ $plan->tracking }}</bdi></dd>
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
