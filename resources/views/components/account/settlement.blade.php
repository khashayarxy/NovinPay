@props(['document', 'plan', 'paidAtDisplay' => null, 'repair' => null, 'paymentTransactions' => null, 'zibalAvailable' => null])
@php
    $zibalReady = $zibalAvailable ?? app(\App\Services\ZibalGateway::class)->available();
    $txList = $paymentTransactions ?? collect();
@endphp
<section class="np-account-card">
    <h2>پرداخت و ارسال</h2>
    @if($document)
        @php
            $settlementState = $document->settlement();
            $balanceMinor = max(0, (int) ($document->charged_minor - $document->paid_minor));
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
            @if($balanceMinor > 0)
                <div>
                    <dt>مانده قابل پرداخت</dt>
                    <dd><bdi>{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::toman($balanceMinor)) }}</bdi> تومان</dd>
                </div>
            @elseif($document->paid_minor > 0)
                <div>
                    <dt>مبلغ پرداخت‌شده</dt>
                    <dd><bdi>{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::toman($document->paid_minor)) }}</bdi> تومان</dd>
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

        @if($repair && $balanceMinor > 0)
            <div class="np-repair-pay-cta" data-repair-dashboard-pay>
                <form method="post" action="{{ route('account.repairs.pay', $repair->public_id) }}" data-prevent-double-submit>
                    @csrf
                    <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <button type="submit" class="np-button np-button--primary np-repair-pay-cta__btn" @disabled(! $zibalReady)>
                        پرداخت آنلاین با زیبال ({{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::toman($balanceMinor)) }} تومان)
                    </button>
                </form>
                @if($zibalReady)
                    <p class="np-repair-pay-cta__hint">پرداخت آنی با کلیه کارت‌های عضو شتاب و صدور رسید دیجیتال</p>
                @else
                    <p class="np-repair-pay-cta__hint">درگاه پرداخت آنلاین در حال حاضر غیرفعال است.</p>
                @endif
            </div>
        @endif

        @if($repair && $txList->isNotEmpty())
            <div class="np-repair-receipts" aria-label="رسیدهای پرداخت ثبت‌شده">
                <h3>رسید پرداخت</h3>
                <ul class="np-repair-receipts__list">
                    @foreach($txList as $tx)
                        <li>
                            <span>{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::toman($tx->amount_minor)) }} تومان · {{ \App\Support\FinanceInput::methodLabel($tx->method) }}</span>
                            @if($tx->reference)
                                <bdi class="np-latin" dir="ltr">{{ $tx->reference }}</bdi>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @else
        <div class="np-account-empty"><p>هنوز سند پرداخت یا جزئیات ارسال برای این مورد ثبت نشده است.</p></div>
    @endif
</section>
