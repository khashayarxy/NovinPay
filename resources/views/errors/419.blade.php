@php
    $retryTarget = url('/');
    $current = request()?->fullUrl();
    $appOrigin = rtrim((string) config('app.url'), '/');
    if (is_string($current) && $current !== '' && ($appOrigin === '' || str_starts_with($current, $appOrigin) || str_starts_with($current, request()->getSchemeAndHttpHost()))) {
        $retryTarget = $current;
    }
@endphp
<x-error-screen
    code="۴۱۹"
    badge="خطای ۴۱۹ · انقضای نشست امنیتی"
    tone="warning"
    title="نشست شما منقضی شده است"
    description="به دلیل عدم فعالیت یا گذشت زمان، توکن امنیتی فرم منقضی شده است. لطفاً صفحه را دوباره بارگذاری کرده و مجدداً تلاش کنید."
    primary-label="بارگذاری مجدد و تلاش دوباره"
    :primary-url="$retryTarget"
    secondary-label="بازگشت به صفحهٔ اصلی"
    :secondary-url="url('/')"
    tertiary-label="ورود به حساب کاربری"
    :tertiary-url="url('/login')"
>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 12a9 9 0 1 1-2.64-6.36"/>
        <polyline points="21 3 21 9 15 9"/>
        <polyline points="12 7 12 12 15 14"/>
    </svg>
</x-error-screen>
