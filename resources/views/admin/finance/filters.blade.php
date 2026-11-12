<form class="commerce-filters finance-form-filters" method="get">
    <x-finance-field name="from" label="از تاریخ" type="date" :value="$filters['from']" :required="false"/>
    <x-finance-field name="to" label="تا تاریخ" type="date" :value="$filters['to']" :required="false"/>
    <details @if(!empty($filters['category'])) open @endif>
        <summary class="admin-text-link">دستهٔ رویداد</summary>
        <select class="input" aria-label="دستهٔ رویداد" name="category"><option value="">همهٔ دسته‌ها</option>@foreach(\App\Support\FinanceInput::CATEGORIES as $value=>$label)<option value="{{ $value }}" @selected(($filters['category']??'')===$value)>{{ $label }}</option>@endforeach</select>
    </details>
    <div class="admin-actions"><button class="btn btn-primary">اعمال</button><a class="admin-text-link" href="{{ url()->current() }}">پاک کردن فیلترها</a></div>
</form>
<p class="admin-hint">بازه بر اساس زمان ثبت به وقت تهران است. مانده‌ها به بازه محدود نمی‌شوند.</p>
