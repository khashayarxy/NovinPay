@extends('layouts.admin')
@section('title','ثبت عملیات مالی')
@section('content')
@php($title=['issue'=>'صدور سند مطالبه','receipt'=>'ثبت دریافت دستی','expense'=>'ثبت هزینه','credit'=>'کاهش مستدل مطالبه','debit'=>'افزایش مستدل مطالبه','reverse'=>'برگشت جبرانی ثبت'][$action])
@include('admin.finance.heading',['heading'=>$title])
<div class="repair-layout"><section class="admin-panel"><form method="post" action="{{ route('admin.finance.'.$action.'.store') }}" class="finance-form">@csrf
<input type="hidden" name="operation_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
@if($action==='issue')
<div><label class="label" for="finance-source-type">نوع منبع تکمیل‌شده</label><select class="input" name="source_type" id="finance-source-type"><option value="order">سفارش تحویل‌شده</option><option value="repair">تعمیر تکمیل‌شده</option></select></div><x-operation-field name="source" label="شناسهٔ UUID منبع" :maxlength="36"/><x-operation-field name="source_version" label="نسخهٔ فعلی منبع" type="number"/>
@else
@if($document)<input type="hidden" name="document" value="{{ $document->public_id }}"><input type="hidden" name="version" value="{{ $document->version }}"><p>سند {{ \App\Support\FaNumber::digits($document->id) }} · مانده {{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::display($document->charged_minor-$document->paid_minor)) }} تومان</p>
@elseif($action!=='reverse')<x-operation-field name="document" label="شناسهٔ UUID سند؛ برای هزینهٔ عمومی اختیاری" :required="$action!=='expense'" :maxlength="36"/><x-operation-field name="version" label="نسخهٔ سند؛ در صورت انتخاب سند لازم است" type="number" :required="$action!=='expense'"/>
@endif
@if($action==='reverse')<x-operation-field name="entry" label="شناسهٔ ثبت اصلی" :value="$entry?->public_id ?? ''" :maxlength="36"/>
@else
<x-operation-field name="amount" label="مبلغ تومان؛ حداکثر دو رقم اعشار، نمونه 1250.50" :maxlength="16"/>
@endif
@if(in_array($action,['receipt','expense']))
<div><label class="label" for="finance-method">روش ثبت</label><select class="input" name="method" id="finance-method">@foreach(\App\Support\FinanceInput::METHODS as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
<x-operation-field name="reference" label="شناسهٔ یکتای مدرک با پیشوند؛ شمارهٔ کارت وارد نکنید" :maxlength="80"/>
@endif
@if($action==='expense')<div><label class="label" for="finance-expense-category">دستهٔ هزینه</label><select class="input" name="category" id="finance-expense-category">@foreach(\Illuminate\Support\Arr::only(\App\Support\FinanceInput::CATEGORIES,['parts','labor','supplies','operating','other']) as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>@endif
@endif
<x-operation-field name="reason" label="شرح مستند و دلیل؛ خصوصی، بدون اطلاعات کارت یا رمز بانکی" kind="textarea" :maxlength="1500"/>
<x-operation-field name="password" label="گذرواژهٔ فعلی حساب شما" type="password" :maxlength="255"/>
<button class="btn btn-primary">{{ $title }} با تأیید صریح</button></form></section><aside class="admin-panel"><h2>پیش از ثبت</h2><p class="admin-hint">صرف تأیید سفارش یا برآورد، دریافت وجه نیست. این ثبت دستی است و بانک آن را تأیید نکرده است.</p><p class="mt-4">پس از ثبت، مبلغ بازنویسی نمی‌شود. اصلاح فقط با برگشت جبرانی یا سند تعدیل مستدل انجام می‌شود. شناسهٔ مدرک پس از برگشت نیز قابل استفادهٔ مجدد نیست.</p><p class="admin-hint mt-4">ورودی خصوصی و گذرواژه در خطاها دوباره نمایش داده نمی‌شوند؛ پیش از ثبت اطلاعات را بررسی کنید.</p></aside></div>
@endsection
