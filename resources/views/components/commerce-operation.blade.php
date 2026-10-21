@props(['action', 'version' => null, 'title', 'confirm' => 'ثبت این عملیات را تأیید می‌کنید؟'])
<form class="admin-form commerce-operation" method="post" action="{{ $action }}" data-confirm="{{ $confirm }}">@csrf
<input type="hidden" name="operation_key" value="{{ \Illuminate\Support\Str::uuid() }}">
@if($version !== null)<input type="hidden" name="version" value="{{ $version }}">@endif
{{ $slot }}
<x-operation-field name="reason" label="دلیل عملیاتی؛ بدون اطلاعات شخصی" kind="textarea" :maxlength="1000"/>
<button class="btn btn-primary" type="submit">{{ $title }}</button>
</form>
