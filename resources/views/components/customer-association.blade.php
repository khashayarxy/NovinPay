@props(['record'])
@can('customers.view')
<div class="admin-notice mt-4">
@if($record->customer_id)
<p>این سابقه به یک پروفایل مشتری متصل شده است؛ اطلاعات تاریخی آن مستقل باقی می‌ماند.</p><a class="admin-text-link" href="{{ route('admin.customers.show', $record->customer) }}">مشاهدهٔ پروفایل مرتبط ←</a>
@else
<p>هنوز به پروفایل مشتری متصل نشده است. اتصال فقط پس از بررسی مستقل هویت انجام می‌شود.</p>@can('customers.link')<a class="admin-text-link" href="{{ route('admin.customers.index') }}">یافتن پروفایل و اتصال تأییدشده ←</a>@endcan
@endif
</div>
@endcan
