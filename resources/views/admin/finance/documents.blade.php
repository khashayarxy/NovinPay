<div tabindex="0" role="region" aria-label="اسناد مالی؛ جدول در نمایشگر بزرگ و کارت در موبایل" class="admin-table-wrap finance-table-wrap">
    <table class="admin-table commerce-table finance-table" role="table">
        <caption class="sr-only">اسناد و تسویهٔ ثبت‌شده؛ تمام مبالغ به تومان با دو رقم اعشار</caption>
        <thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">سند</th><th scope="col" role="columnheader">مبلغ فاکتور</th><th scope="col" role="columnheader">دریافت‌شده</th><th scope="col" role="columnheader">مانده</th><th scope="col" role="columnheader">وضعیت</th></tr></thead>
        <tbody role="rowgroup">
        @forelse($documents as $document)
            @php
                $settlementKey = $document->settlement();
                $docTone = match($settlementKey) {
                    'settled' => 'success',
                    'overpaid', 'partial' => 'warning',
                    default => 'neutral',
                };
            @endphp
            <tr role="row">
                <td role="cell"><span class="finance-cell-label" aria-hidden="true">سند</span><div class="finance-cell-value"><a class="admin-text-link" href="{{ route('admin.finance.documents.show',$document) }}">{{ $document->sales_order_id ? 'فروش' : 'تعمیر' }} #{{ \App\Support\FaNumber::digits($document->id) }}</a></div></td>
                @foreach(['charged_minor'=>'مبلغ فاکتور','paid_minor'=>'دریافت‌شده','outstanding'=>'مانده'] as $field=>$label)
                    <td role="cell"><span class="finance-cell-label" aria-hidden="true">{{ $label }} (تومان)</span><div class="finance-cell-value {{ $field === 'outstanding' ? 'font-bold' : '' }}"><bdi dir="ltr">{{ \App\Support\FinanceMoney::persianToman($field === 'outstanding' ? $document->charged_minor-$document->paid_minor : $document->$field) }}</bdi></div></td>
                @endforeach
                <td role="cell"><span class="finance-cell-label" aria-hidden="true">وضعیت</span><div class="finance-cell-value"><x-admin.status :tone="$docTone">{{ \App\Support\FinanceInput::STATES[$settlementKey] }}</x-admin.status></div></td>
            </tr>
        @empty
            <tr role="row" class="finance-empty-row"><td role="cell" colspan="5" class="admin-muted">هنوز فاکتوری صادر نشده است.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@if($documents->hasPages())<div class="admin-collection-footer">{{ $documents->links() }}</div>@endif
