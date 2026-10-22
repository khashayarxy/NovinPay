@extends('layouts.app')
@section('title', 'درخواست تعمیر')
@section('meta_description', 'ثبت آنلاین درخواست تعمیر کارتخوان و دریافت کد پیگیری نوین‌پی')
@section('content')
<div class="np-container public-section design-repair">
    <section class="design-service-hero design-service-hero--repair"><div><p class="design-eyebrow">تعمیرات نوین‌پی</p><h1>تعمیر تخصصی کارتخوان<br><span>یک قدم تا بررسی دستگاه شما</span></h1><p>برای بررسی مشکلات سخت‌افزاری و نرم‌افزاری کارتخوان‌های PAX، NewPOS و Newland، درخواست آنلاین خود را ثبت کنید.</p><a href="{{ route('repair.track') }}" class="public-inline-link">قبلاً درخواست داده‌اید؟ پیگیری درخواست <x-home.icon name="arrow-left" /></a><div class="design-hero-facts"><span><x-home.icon name="tool"/>بررسی تخصصی</span><span><x-home.icon name="shield-check"/>هماهنگی پیش از تعمیر</span><span><x-home.icon name="headphones"/>پیگیری انسانی</span></div></div><figure><img src="{{ asset('images/mascots/orange-repair2.webp') }}" width="1254" height="1254" alt="ماسکات تعمیرات نوین‌پی" fetchpriority="high" decoding="async"></figure></section>
    <div class="design-issue-grid">@foreach([['settings','مشکلات نرم‌افزاری','خطاهای دستگاه و راه‌اندازی'],['antenna-bars-5','آنتن و ارتباط','بررسی ارتباط با شبکه'],['battery-charging','باتری و شارژ','روشن نشدن یا شارژ نشدن'],['printer','مشکل پرینتر','بررسی چاپ رسید']] as [$icon,$title,$text])<div><x-home.icon :name="$icon"/><h2>{{ $title }}</h2><p>{{ $text }}</p></div>@endforeach</div>
    <section class="design-process"><h2>فرایند درخواست تعمیر کارتخوان</h2><p>ثبت این فرم فقط یک درخواست آنلاین است و به معنی دریافت دستگاه نیست.</p><ol>@foreach(['ثبت درخواست آنلاین','بررسی و هماهنگی','پذیرش و بررسی دستگاه','هماهنگی تعمیر و تحویل'] as $step)<li><span>{{ \App\Support\FaNumber::digits($loop->iteration) }}</span><h3>{{ $step }}</h3></li>@endforeach</ol></section>
    <div class="public-split public-split--form">
        <section class="public-panel" aria-labelledby="repair-form-title">
            <div class="public-panel__heading"><span class="public-icon"><x-home.icon name="tool" /></span><div><h2 id="repair-form-title">ثبت درخواست آنلاین</h2><p>همهٔ موارد ستاره‌دار ضروری هستند.</p></div></div>
            <x-form-errors />
            <form method="post" action="{{ route('repair.store') }}" class="public-form">
                @csrf
                <input type="hidden" name="submission_token" value="{{ $submissionToken }}">
                <div class="public-form__grid">
                    <x-repair-field name="customer_name" label="نام و نام خانوادگی"><input id="customer_name" name="customer_name" class="input" autocomplete="name" required minlength="2" maxlength="100" aria-invalid="{{ $errors->has('customer_name') ? 'true' : 'false' }}" aria-describedby="customer_name-feedback"></x-repair-field>
                    <x-repair-field name="contact_phone" label="شمارهٔ همراه" hint="شمارهٔ ۱۱ رقمی با ۰۹؛ برای پیگیری هم لازم است."><input type="tel" id="contact_phone" name="contact_phone" class="input" dir="ltr" inputmode="tel" autocomplete="tel" required maxlength="40" placeholder="0912 000 0000" aria-invalid="{{ $errors->has('contact_phone') ? 'true' : 'false' }}" aria-describedby="contact_phone-feedback"></x-repair-field>
                    <x-repair-field name="device_brand" label="برند دستگاه"><select id="device_brand" name="device_brand" class="input" required aria-invalid="{{ $errors->has('device_brand') ? 'true' : 'false' }}" aria-describedby="device_brand-feedback"><option value="">انتخاب برند</option>@foreach ($brands as $brand)<option value="{{ $brand->value }}">{{ $brand->value }}</option>@endforeach</select></x-repair-field>
                    <x-repair-field name="device_model" label="مدل دستگاه" hint="مدل را از روی برچسب دستگاه بنویسید."><input id="device_model" name="device_model" class="input" required maxlength="100" aria-invalid="{{ $errors->has('device_model') ? 'true' : 'false' }}" aria-describedby="device_model-feedback"></x-repair-field>
                </div>
                <x-repair-field name="issue" label="شرح مشکل" hint="۱۰ تا ۲۰۰۰ نویسه؛ اطلاعات کارت بانکی، رمز یا اطلاعات حساب را وارد نکنید."><textarea id="issue" name="issue" class="input" rows="5" required minlength="10" maxlength="2000" aria-invalid="{{ $errors->has('issue') ? 'true' : 'false' }}" aria-describedby="issue-feedback"></textarea></x-repair-field>
                <p class="public-hint">نام، شماره تماس، مدل و شرح مشکل برای ثبت این درخواست ذخیره می‌شوند. <a class="public-inline-link" href="{{ route('privacy') }}">اطلاعات حریم خصوصی</a></p>
                <button class="np-button np-button--primary" type="submit">ثبت درخواست و دریافت کد <x-home.icon name="arrow-left" /></button>
            </form>
        </section>
        <aside class="public-sidebar">
            <div class="public-notice"><x-home.icon name="shield-check" /><h2>درخواست آنلاین، پیش از پذیرش دستگاه</h2><p>در این مرحله فقط اطلاعات درخواست ثبت می‌شود. دریافت فیزیکی، هزینه و زمان تعمیر با ارسال فرم تأیید نمی‌شوند.</p></div>
            <div class="public-panel"><h2>پس از ثبت چه می‌بینید؟</h2><ol class="public-steps"><li><span>۱</span><div><h3>کد پیگیری اختصاصی</h3><p>کد نمایش‌داده‌شده را در جای امن نگه دارید.</p></div></li><li><span>۲</span><div><h3>پیگیری با شمارهٔ همراه</h3><p>فقط با کد کامل و شمارهٔ ثبت‌شده، وضعیت اولیه را ببینید.</p></div></li><li><span>۳</span><div><h3>هماهنگی مراجعه</h3><p>برای هماهنگی مراجعه یا پرسش دربارهٔ دستگاه با ما تماس بگیرید.</p></div></li></ol><a class="public-inline-link" href="{{ config('business.phone_uri') }}" dir="ltr">{{ config('business.phone') }} <x-home.icon name="phone" /></a></div>
    <section class="design-faq"><h2>سؤالات متداول دربارهٔ تعمیرات</h2>@foreach([['ثبت فرم به معنی دریافت دستگاه است؟','خیر. این فرم یک درخواست آنلاین است؛ پذیرش فیزیکی دستگاه باید جداگانه با نوین‌پی هماهنگ شود.'],['هزینه و زمان تعمیر چگونه مشخص می‌شود؟','پس از بررسی دستگاه و هماهنگی با شما؛ ثبت فرم هزینه یا زمان مشخصی را تضمین نمی‌کند.'],['برای پیگیری به چه اطلاعاتی نیاز دارم؟','کد کامل پیگیری و همان شمارهٔ همراهی که هنگام ثبت درخواست وارد کرده‌اید.']] as [$question,$answer])<details><summary>{{ $question }}</summary><p>{{ $answer }}</p></details>@endforeach</section>
        </aside>
    </div>

</div><x-site.location/>
@endsection
