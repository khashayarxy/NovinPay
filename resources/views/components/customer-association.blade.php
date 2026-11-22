@props(['record', 'subtle' => false])
@can('customers.view')
<div @class(['admin-notice mt-4' => !$subtle, 'admin-inline-association' => $subtle])>
@if($record->customer_id)
    <span>این سابقه به یک پروفایل مشتری متصل شده است؛ اطلاعات تاریخی آن مستقل باقی می‌ماند.</span>
    <a class="admin-text-link" href="{{ route('admin.customers.show', $record->customer) }}">مشاهدهٔ پروفایل مرتبط ←</a>
@else
    <span>هنوز به پروفایل مشتری متصل نشده است.</span>
    @can('customers.link')<a class="admin-text-link" href="{{ route('admin.customers.index') }}">یافتن پروفایل و اتصال تأییدشده ←</a>@endcan
@endif
</div>
@endcan
