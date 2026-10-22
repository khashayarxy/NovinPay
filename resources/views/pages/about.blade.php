@extends('layouts.app')
@section('title', 'درباره ما')
@section('meta_description', 'آشنایی با نوین‌پی؛ فروش و تعمیر کارتخوان در شهریار')
@section('content')
<div class="np-container public-section design-about">
<section class="design-about-hero"><div><p class="design-eyebrow">درباره ما</p><h1>از یک نیاز ساده<br>تا همراه تخصصی کسب‌وکار شما</h1><p>نوین‌پی در شهریار، در زمینهٔ فروش و تعمیر دستگاه‌های کارتخوان فعالیت می‌کند. هدف ما، ساده‌تر شدن انتخاب، راه‌اندازی و پشتیبانی کارتخوان کسب‌وکار شماست.</p><p>از بررسی مشخصات و وضعیت دستگاه تا ثبت درخواست تعمیر، اطلاعات روشن و راهنمایی متناسب با نیاز شما در کنار هم قرار می‌گیرند.</p><a class="public-inline-link" href="{{ route('services') }}">آشنایی با خدمات <x-home.icon name="arrow-left"/></a></div><figure class="design-team-art"><img src="{{ asset('images/mascots/everyone-aboutus2.webp') }}" width="1536" height="1024" alt="ماسکات‌های نوین‌پی؛ همراه خرید و تعمیر کارتخوان" decoding="async" fetchpriority="high"></figure></section>
<section class="design-values"><p class="design-eyebrow">ارزش‌های ما</p><h2>چیزی که به آن باور داریم</h2><div class="design-values-grid">@foreach([['shield-check','شفافیت در انتخاب','وضعیت، مشخصات و قیمت ثبت‌شدهٔ دستگاه را پیش از تصمیم بررسی کنید.'],['tool','تعمیرات تخصصی','درخواست بررسی مشکل دستگاه را ثبت و مراحل بعدی را با ما هماهنگ کنید.'],['headphones','همراهی و راهنمایی','برای پرسش دربارهٔ خرید، راه‌اندازی یا تعمیر با همکاران ما در ارتباط باشید.']] as [$icon,$title,$text])<article><x-home.icon :name="$icon"/><h3>{{ $title }}</h3><p>{{ $text }}</p></article>@endforeach</div></section>
<section class="design-brand-section"><h2>برندهای تحت پوشش</h2><p>فروش و تعمیر انواع دستگاه‌های کارتخوان از برندهای معتبر</p><x-site.brands/></section>
</div><x-site.location/>
@endsection
