<section class="admin-panel">
    <h2>خلاصهٔ مالی <small class="admin-muted">تومان</small></h2>
    <dl class="admin-finance-summary">
        @foreach(['charges'=>'خالص مطالبات دوره','receipts'=>'خالص دریافت دستی','expenses'=>'خالص هزینه‌ها','gross_sales_documents'=>'ناخالص اسناد فروش','outstanding'=>'ماندهٔ فعلی'] as $key=>$label)
            <div><dt>{{ $label }}</dt><dd><bdi>{{ \App\Support\FinanceMoney::persianToman($summary[$key]) }}</bdi></dd></div>
        @endforeach
    </dl>
    @if($summary['discrepancies'])
        <p class="finance-alert">مغایرت دفتر و مانده: {{ \App\Support\FaNumber::digits($summary['discrepancies']) }} سند؛ توقف و بررسی مدیر کل لازم است.</p>
    @endif
    <aside class="admin-info-card mt-3" aria-label="تطبیق و جزئیات مانده">
        <h3>تطبیق و جزئیات مانده</h3>
        <p>سفارش‌های دارای سند: {{ \App\Support\FaNumber::digits($summary['settled_orders']) }} تسویه‌شده، {{ \App\Support\FaNumber::digits($summary['unsettled_orders']) }} دارای مانده</p>
        <p>همهٔ اسناد: {{ \App\Support\FaNumber::digits($summary['settled']) }} تسویه‌شده، {{ \App\Support\FaNumber::digits($summary['unsettled']) }} دارای مانده</p>
        @unless($summary['discrepancies'])<p>جمع دفتر و ماندهٔ اسناد تطبیق دارد.</p>@endunless
        <p class="admin-hint">ناخالص اسناد فروش مستقل از دستهٔ انتخابی است. این اعداد سود خالص نیستند.</p>
    </aside>
</section>
