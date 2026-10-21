<div tabindex="0" role="region" aria-label="اسناد مالی؛ جدول در نمایشگر بزرگ و کارت در موبایل" class="admin-table-wrap finance-table-wrap">
    <table class="admin-table commerce-table finance-table" role="table">
        <caption class="sr-only">اسناد و تسویهٔ ثبت‌شده؛ تمام مبالغ به تومان با دو رقم اعشار</caption>
        <thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">سند</th><th scope="col" role="columnheader">مطالبه</th><th scope="col" role="columnheader">دریافت ثبت‌شده</th><th scope="col" role="columnheader">مانده</th><th scope="col" role="columnheader">وضعیت</th></tr></thead>
        <tbody role="rowgroup">
        @forelse($documents as $document)
            <tr role="row">
                <td role="cell"><span class="finance-cell-label" aria-hidden="true">سند</span><div class="finance-cell-value"><a class="admin-text-link" href="{{ route('admin.finance.documents.show',$document) }}">{{ $document->sales_order_id ? 'فروش' : 'تعمیر' }} {{ \App\Support\FaNumber::digits($document->id) }}</a></div></td>
                @foreach(['charged_minor'=>'مطالبه','paid_minor'=>'دریافت ثبت‌شده','outstanding'=>'مانده'] as $field=>$label)
                    <td role="cell"><span class="finance-cell-label" aria-hidden="true">{{ $label }} (تومان)</span><div class="finance-cell-value {{ $field === 'outstanding' ? 'font-bold' : '' }}"><bdi dir="ltr">{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::display($field === 'outstanding' ? $document->charged_minor-$document->paid_minor : $document->$field)) }}</bdi></div></td>
                @endforeach
                <td role="cell"><span class="finance-cell-label" aria-hidden="true">وضعیت</span><div class="finance-cell-value">{{ \App\Support\FinanceInput::STATES[$document->settlement()] }}</div></td>
            </tr>
        @empty
            <tr role="row" class="finance-empty-row"><td role="cell" colspan="5">هنوز سند مطالبه‌ای صادر نشده است.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
{{ $documents->links() }}
