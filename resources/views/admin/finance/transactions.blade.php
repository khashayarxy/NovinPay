<div tabindex="0" role="region" aria-label="تاریخچهٔ مالی؛ جدول در نمایشگر بزرگ و کارت در موبایل" class="admin-table-wrap finance-table-wrap">
    <table class="admin-table commerce-table finance-table" role="table">
        <caption class="sr-only">رویدادهای مالی؛ تمام مبالغ به تومان با دو رقم اعشار؛ زمان نمایش شمسی تهران، فیلتر میلادی UTC</caption>
        <thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">رویداد و دسته</th><th scope="col" role="columnheader">تغییر مطالبه</th><th scope="col" role="columnheader">دریافت</th><th scope="col" role="columnheader">هزینه</th><th scope="col" role="columnheader">ثبت‌کننده و زمان</th><th scope="col" role="columnheader">مدرک</th></tr></thead>
        <tbody role="rowgroup">
        @forelse($entries as $entry)
            <tr role="row">
                <td role="cell"><span class="finance-cell-label" aria-hidden="true">رویداد و دسته</span><div class="finance-cell-value"><strong>{{ \App\Support\FinanceInput::KINDS[$entry->kind] }}</strong><small>{{ \App\Support\FinanceInput::CATEGORIES[$entry->category] }}</small></div></td>
                @foreach(['charge_delta'=>'تغییر مطالبه','receipt_delta'=>'دریافت','expense_delta'=>'هزینه'] as $field=>$label)
                    <td role="cell"><span class="finance-cell-label" aria-hidden="true">{{ $label }} (تومان)</span><div class="finance-cell-value"><bdi dir="ltr">{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::display($entry->$field)) }}</bdi></div></td>
                @endforeach
                <td role="cell"><span class="finance-cell-label" aria-hidden="true">ثبت‌کننده و زمان</span><div class="finance-cell-value">همکار {{ \App\Support\FaNumber::digits($entry->actor_id) }}<small><x-finance-time :value="$entry->created_at"/></small></div></td>
                <td role="cell"><span class="finance-cell-label" aria-hidden="true">مدرک</span><div class="finance-cell-value">@if($financeAllows('finance.details'))<a class="admin-text-link" href="{{ route('admin.finance.entries.show',$entry) }}">مشاهدهٔ خصوصی</a>@else<span class="admin-hint">مجوز جداگانه</span>@endif</div></td>
            </tr>
        @empty
            <tr role="row" class="finance-empty-row"><td role="cell" colspan="6"><div class="admin-empty"><h3>رویدادی در این بازه ثبت نشده است</h3><p>بدون ثبت واقعی، عدد یا سابقه‌ای ایجاد نمی‌شود.</p></div></td></tr>
        @endforelse
        </tbody>
    </table>
</div>
{{ $entries->links() }}
