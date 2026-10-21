<form class="commerce-filters" method="get">
    <x-operation-field name="from" label="از تاریخ میلادی (UTC)" type="date" :value="$filters['from']"/>
    <x-operation-field name="to" label="تا تاریخ میلادی (UTC)" type="date" :value="$filters['to']"/>
    <div><label class="label" for="finance-category">دستهٔ رویداد</label><select class="input" id="finance-category" name="category"><option value="">همهٔ دسته‌ها</option>@foreach(\App\Support\FinanceInput::CATEGORIES as $value=>$label)<option value="{{ $value }}" @selected(($filters['category']??'')===$value)>{{ $label }}</option>@endforeach</select></div>
    <button class="btn btn-primary">اعمال فیلتر</button>
</form>
<p class="admin-hint">دوره بر اساس زمان ثبت دفتر (UTC)، حداکثر ۳۶۶ روز است. وضعیت مانده‌ها مربوط به امروز و مستقل از این فیلتر است.</p>
