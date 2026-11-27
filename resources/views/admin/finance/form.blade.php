@extends('layouts.admin')
@section('title','ثبت عملیات مالی')
@section('content')
<div class="admin-form-surface">
    @php($title=['issue'=>'صدور سند مطالبه','receipt'=>'ثبت دریافت دستی','expense'=>'ثبت هزینه','credit'=>'کاهش مستدل مطالبه','debit'=>'افزایش مستدل مطالبه','reverse'=>'برگشت جبرانی ثبت'][$action])
    @include('admin.finance.heading',['heading'=>$title])
    <section class="admin-panel">
        <form method="post" action="{{ route('admin.finance.'.$action.'.store') }}" class="finance-form">
            @csrf
            <input type="hidden" name="operation_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
            @if($action==='issue')
                <div class="admin-form-grid">
                    <div>
                        <label class="label" for="finance-source-type">نوع منبع تکمیل‌شده</label>
                        <select class="input" name="source_type" id="finance-source-type">
                            <option value="order">سفارش تحویل‌شده</option>
                            <option value="repair">تعمیر تکمیل‌شده</option>
                        </select>
                    </div>
                    <x-finance-field name="source_version" label="نسخهٔ فعلی منبع" type="number"/>
                </div>
                <x-finance-field name="source" label="شناسهٔ UUID منبع" :maxlength="36"/>
            @else
                @if($document)
                    <input type="hidden" name="document" value="{{ $document->public_id }}">
                    <input type="hidden" name="version" value="{{ $document->version }}">
                    <div class="admin-filter-context mb-0">
                        <span>سند #{{ \App\Support\FaNumber::digits($document->id) }}</span>
                        <strong>مانده: <bdi dir="ltr">{{ \App\Support\FinanceMoney::persianToman($document->charged_minor-$document->paid_minor) }}</bdi> تومان</strong>
                    </div>
                @elseif($action!=='reverse')
                    <div class="admin-form-grid">
                        <x-finance-field name="document" label="شناسهٔ UUID سند؛ برای هزینهٔ عمومی اختیاری" :required="$action!=='expense'" :maxlength="36"/>
                        <x-finance-field name="version" label="نسخهٔ سند؛ در صورت انتخاب سند لازم است" type="number" :required="$action!=='expense'"/>
                    </div>
                @endif
                @if($action==='reverse')
                    <x-finance-field name="entry" label="شناسهٔ ثبت اصلی" :value="$entry?->public_id ?? ''" :maxlength="36"/>
                @else
                    <x-finance-field name="amount" inputmode="decimal" label="مبلغ (تومان)" :maxlength="16"/>
                @endif
                @if(in_array($action,['receipt','expense']))
                    <div class="admin-form-grid">
                        <div>
                            <label class="label" for="finance-method">روش ثبت</label>
                            <select class="input" name="method" id="finance-method">
                                @foreach(\App\Support\FinanceInput::METHODS as $value=>$label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <x-finance-field name="reference" label="شناسهٔ یکتای مدرک با پیشوند؛ شمارهٔ کارت وارد نکنید" :maxlength="80"/>
                    </div>
                @endif
                @if($action==='expense')
                    <div>
                        <label class="label" for="finance-expense-category">دستهٔ هزینه</label>
                        <select class="input" name="category" id="finance-expense-category">
                            @foreach(\Illuminate\Support\Arr::only(\App\Support\FinanceInput::CATEGORIES,['parts','labor','supplies','operating','other']) as $value=>$label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            @endif
            <x-finance-field name="reason" label="شرح مستند و دلیل؛ خصوصی، بدون اطلاعات کارت یا رمز بانکی" kind="textarea" :maxlength="1500"/>
            <x-finance-field name="password" label="رمز عبور فعلی حساب شما" type="password" :maxlength="255"/>
            <div class="finance-confirmation">
                <strong>تأیید ثبت دفتری</strong>
                <p>{{ $action === 'reverse' ? 'برگشت یک ثبت جبرانی جدید است؛ ثبت اصلی حذف نمی‌شود و وجهی به‌صورت آنلاین برگردانده نمی‌شود.' : 'این عملیات صرفاً در دفتر داخلی ثبت می‌شود و پس از ثبت غیرقابل ویرایش است.' }}</p>
            </div>
            <div class="admin-form-footer">
                <button type="submit" class="btn btn-primary">{{ $title }}</button>
            </div>
        </form>
    </section>
</div>
@endsection
