@extends('layouts.admin')
@section('title','ثبت عملیات مالی')
@section('content')
@php($title=['issue'=>'صدور سند مطالبه','receipt'=>'ثبت دریافت دستی','expense'=>'ثبت هزینه','credit'=>'کاهش مستدل مطالبه','debit'=>'افزایش مستدل مطالبه','reverse'=>'برگشت جبرانی ثبت'][$action])
@include('admin.finance.heading',['heading'=>$title])
<div class="repair-layout"><section class="admin-panel"><form method="post" action="{{ route('admin.finance.'.$action.'.store') }}" class="finance-form">@csrf
<input type="hidden" name="operation_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
@if($action==='issue')
<div><label class="label" for="finance-source-type">نوع منبع تکمیل‌شده</label><select class="input" name="source_type" id="finance-source-type"><option value="order">سفارش تحویل‌شده</option><option value="repair">تعمیر تکمیل‌شده</option></select></div><x-finance-field name="source" label="شناسهٔ UUID منبع" :maxlength="36"/><x-finance-field name="source_version" label="نسخهٔ فعلی منبع" type="number"/>
@else
@if($document)<input type="hidden" name="document" value="{{ $document->public_id }}"><input type="hidden" name="version" value="{{ $document->version }}"><p>سند {{ \App\Support\FaNumber::digits($document->id) }} · مانده <bdi dir="ltr">{{ \App\Support\FaNumber::digits(\App\Support\FinanceMoney::display($document->charged_minor-$document->paid_minor)) }}</bdi> تومان</p>
@elseif($action!=='reverse')<x-finance-field name="document" label="شناسهٔ UUID سند؛ برای هزینهٔ عمومی اختیاری" :required="$action!=='expense'" :maxlength="36"/><x-finance-field name="version" label="نسخهٔ سند؛ در صورت انتخاب سند لازم است" type="number" :required="$action!=='expense'"/>
@endif
@if($action==='reverse')<x-finance-field name="entry" label="شناسهٔ ثبت اصلی" :value="$entry?->public_id ?? ''" :maxlength="36"/>
@else
<x-finance-field name="amount" inputmode="decimal" label="مبلغ تومان؛ حداکثر دو رقم اعشار، نمونه 1250.50" :maxlength="16"/>
@endif
@if(in_array($action,['receipt','expense']))
<div><label class="label" for="finance-method">روش ثبت</label><select class="input" name="method" id="finance-method">@foreach(\App\Support\FinanceInput::METHODS as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
<x-finance-field name="reference" label="شناسهٔ یکتای مدرک با پیشوند؛ شمارهٔ کارت وارد نکنید" :maxlength="80"/>
@endif
@if($action==='expense')<div><label class="label" for="finance-expense-category">دستهٔ هزینه</label><select class="input" name="category" id="finance-expense-category">@foreach(\Illuminate\Support\Arr::only(\App\Support\FinanceInput::CATEGORIES,['parts','labor','supplies','operating','other']) as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>@endif
@endif
<x-finance-field name="reason" label="شرح مستند و دلیل؛ خصوصی، بدون اطلاعات کارت یا رمز بانکی" kind="textarea" :maxlength="1500"/>
<x-finance-field name="password" label="گذرواژهٔ فعلی حساب شما" type="password" :maxlength="255"/>
<div class="finance-confirmation"><strong>تأیید نهایی ثبت دستی</strong><p>{{ $action === 'reverse' ? 'برگشت، یک ثبت جبرانی جدید است؛ ثبت اصلی حذف نمی‌شود و هیچ وجهی به‌صورت آنلاین برگردانده نمی‌شود.' : 'مبلغ، سند و مدرک را بررسی کنید. این اقدام فقط در دفتر داخلی ثبت می‌شود و انتقال وجه بانکی انجام نمی‌دهد.' }}</p></div><button type="submit" class="btn btn-primary">{{ $title }} با تأیید صریح</button></form></section><aside class="admin-panel"><h2>پیش از ثبت</h2><p class="admin-hint">صرف تأیید سفارش یا برآورد، دریافت وجه نیست. این ثبت دستی است و بانک آن را تأیید نکرده است.</p><p class="mt-4">پس از ثبت، مبلغ بازنویسی نمی‌شود. اصلاح فقط با برگشت جبرانی یا سند تعدیل مستدل انجام می‌شود. شناسهٔ مدرک پس از برگشت نیز قابل استفادهٔ مجدد نیست.</p><p class="admin-hint mt-4">ورودی خصوصی و گذرواژه در خطاها دوباره نمایش داده نمی‌شوند؛ پیش از ثبت اطلاعات را بررسی کنید.</p></aside></div>
@endsection
