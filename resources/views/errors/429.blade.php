@php
    $retryTarget = url('/');
    $current = request()?->fullUrl();
    if (is_string($current) && $current !== '' && str_starts_with($current, request()->getSchemeAndHttpHost())) {
        $retryTarget = $current;
    }
@endphp
<x-error-screen
    code="۴۲۹"
    badge="خطای ۴۲۹ · محدودیت موقت درخواست"
    tone="warning"
    title="کمی بعد دوباره تلاش کنید"
    description="تعداد درخواست‌های ارسالی در مدت کوتاه بیش از حد مجاز بوده است. لطفاً چند لحظه صبر کنید و سپس دوباره تلاش نمایید."
    primary-label="تلاش مجدد"
    :primary-url="$retryTarget"
    secondary-label="ثبت یا پیگیری تعمیر"
    :secondary-url="url('/repair')"
    tertiary-label="بازگشت به صفحهٔ اصلی"
    :tertiary-url="url('/')"
    :show-phones="true"
>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="9"/>
        <polyline points="12 7 12 12 15.5 14"/>
    </svg>
</x-error-screen>
