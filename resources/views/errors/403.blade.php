<x-error-screen
    code="۴۰۳"
    badge="خطای ۴۰۳ · محدودیت دسترسی"
    tone="warning"
    title="دسترسی به این بخش مجاز نیست"
    description="شما مجوز مشاهدهٔ این صفحه یا انجام این عملیات را ندارید. در صورت نیاز با حساب کاربری مجاز وارد شوید یا به صفحهٔ اصلی بازگردید."
    primary-label="بازگشت به صفحهٔ اصلی"
    :primary-url="url('/')"
    secondary-label="مشاهده فروشگاه"
    :secondary-url="url('/products')"
    tertiary-label="تماس با پشتیبانی"
    :tertiary-url="url('/contact')"
>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        <rect x="9" y="10" width="6" height="5" rx="1"/>
        <path d="M10 10V8a2 2 0 1 1 4 0v2"/>
    </svg>
</x-error-screen>
