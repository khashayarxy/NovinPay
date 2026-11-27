<section class="admin-panel">
    <div class="admin-panel-heading">
        <h2>خلاصهٔ مالی <small class="admin-muted">(تومان)</small></h2>
        <span class="admin-muted">سفارش‌ها: {{ \App\Support\FaNumber::digits($summary['settled_orders']) }} تسویه‌شده · {{ \App\Support\FaNumber::digits($summary['unsettled_orders']) }} دارای مانده</span>
    </div>
    <dl class="admin-finance-summary">
        @foreach(['charges'=>'خالص مطالبات دوره','receipts'=>'خالص دریافت دستی','expenses'=>'خالص هزینه‌ها','gross_sales_documents'=>'ناخالص اسناد فروش','outstanding'=>'ماندهٔ فعلی'] as $key=>$label)
            <div><dt>{{ $label }}</dt><dd><bdi>{{ \App\Support\FinanceMoney::persianToman($summary[$key]) }}</bdi></dd></div>
        @endforeach
    </dl>
    @if($summary['discrepancies'])
        <p class="finance-alert">مغایرت دفتر و مانده: {{ \App\Support\FaNumber::digits($summary['discrepancies']) }} سند؛ بررسی مدیر کل لازم است.</p>
    @else
        <p class="admin-hint">همهٔ اسناد ({{ \App\Support\FaNumber::digits($summary['settled']) }} تسویه‌شده، {{ \App\Support\FaNumber::digits($summary['unsettled']) }} دارای مانده) با جمع دفتر تطبیق دارد.</p>
    @endif
</section>
