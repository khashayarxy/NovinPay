@if($repairDocument && $repairDocument->charged_minor > $repairDocument->paid_minor)
<section class="admin-panel mt-3" aria-labelledby="repair-zibal-link">
    <div class="admin-panel-heading">
        <h2 id="repair-zibal-link">لینک پرداخت آنلاین تعمیر (زیبال)</h2>
    </div>
    <p class="admin-hint mt-2">مانده قابل پرداخت: <strong style="font-variant-numeric: tabular-nums;">{{ \App\Support\FinanceMoney::persianToman($repairDocument->charged_minor - $repairDocument->paid_minor) }} تومان</strong></p>
    <form method="post" action="{{ route('admin.repair-cases.payment-link', $case) }}" class="mt-3">
        @csrf
        <button type="submit" class="btn btn-primary">تولید لینک پرداخت آنلاین</button>
    </form>
    @if(session('repair_payment_link'))
        <div class="mt-3 space-y-2">
            <label class="label" for="generated-repair-payment-link">لینک امضاشده پرداخت مشتری (اعتبار ۱۴ روز)</label>
            <input id="generated-repair-payment-link" type="text" class="input" dir="ltr" readonly value="{{ session('repair_payment_link') }}">
            <div class="flex items-center gap-3">
                <button type="button" class="btn btn-secondary" data-copy-card="{{ session('repair_payment_link') }}">کپی لینک پرداخت</button>
                <span role="status" class="admin-hint" data-copy-result></span>
            </div>
        </div>
    @endif
</section>
@endif
