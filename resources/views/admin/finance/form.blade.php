@extends('layouts.admin')
@section('title','ثبت عملیات مالی')
@section('content')
<div class="admin-form-surface">
    @php($title=['issue'=>'صدور فاکتور','receipt'=>'ثبت دریافت دستی','expense'=>'ثبت هزینه','credit'=>'کاهش مبلغ فاکتور','debit'=>'افزایش مبلغ فاکتور','reverse'=>'لغو و اصلاح ثبت'][$action])
    @include('admin.finance.heading',['heading'=>$title])
    <section class="admin-panel">
        <form method="post" action="{{ route('admin.finance.'.$action.'.store') }}" class="finance-form">
            @csrf
            <input type="hidden" name="operation_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
            @if($action==='issue')
                <div class="admin-form-grid">
                    <div>
                        <label class="label" for="finance-source-type">نوع سفارش یا تعمیر</label>
                        <select class="input" name="source_type" id="finance-source-type">
                            <option value="order">سفارش تحویل‌شده</option>
                            <option value="repair">تعمیر تکمیل‌شده</option>
                        </select>
                    </div>
                    <x-finance-field name="source_version" label="نسخهٔ فعلی" type="number"/>
                </div>
                <x-finance-field name="source" label="شناسهٔ سفارش یا پرونده (UUID)" :maxlength="36"/>
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
                        <x-finance-field name="document" label="شناسهٔ سند مالی (برای هزینه عمومی اختیاری)" :required="$action!=='expense'" :maxlength="36"/>
                        <x-finance-field name="version" label="نسخهٔ سند" type="number" :required="$action!=='expense'"/>
                    </div>
                @endif
                @if($action==='reverse')
                    <x-finance-field name="entry" label="شناسهٔ ثبت مالی" :value="$entry?->public_id ?? ''" :maxlength="36"/>
                @else
                    <x-finance-field name="amount" inputmode="decimal" label="مبلغ (تومان)" :maxlength="16"/>
                @endif
                @if(in_array($action,['receipt','expense']))
                    <div class="admin-form-grid">
                        <div>
                            <label class="label" for="finance-method">روش پرداخت</label>
                            <select class="input" name="method" id="finance-method">
                                @foreach(\App\Support\FinanceInput::METHODS as $value=>$label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <x-finance-field name="reference" label="شماره پیگیری یا قبض (بدون درج شماره کارت)" :maxlength="80"/>
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
            <x-finance-field name="reason" label="توضیح و علت ثبت (بدون اطلاعات کارت)" kind="textarea" :maxlength="1500"/>
            <x-finance-field name="password" label="رمز عبور فعلی شما" type="password" :maxlength="255"/>
            <p class="admin-hint">{{ $action === 'reverse' ? 'لغو ثبت یک ردیف اصلاحی در دفتر مالی ایجاد می‌کند و بازگشت آنلاین وجه انجام نمی‌دهد.' : 'این ثبت در دفتر داخلی ذخیره شده و پس از ثبت قابل ویرایش مستقیم نیست.' }}</p>
            <div class="admin-form-footer">
                <button type="submit" class="btn btn-primary">{{ $title }}</button>
            </div>
        </form>
    </section>
</div>
@endsection
