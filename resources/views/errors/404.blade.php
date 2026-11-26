<x-error-screen
    code="۴۰۴"
    badge="خطای ۴۰۴ · صفحه پیدا نشد"
    tone="info"
    title="صفحه‌ای که دنبال آن بودید پیدا نشد"
    description="ممکن است نشانی واردشده تغییر کرده باشد یا محصول و صفحهٔ موردنظر دیگر در دسترس نباشد. می‌توانید از مسیرهای زیر ادامه دهید."
    primary-label="مشاهده محصولات"
    :primary-url="url('/products')"
    secondary-label="ثبت یا پیگیری تعمیر"
    :secondary-url="url('/repair')"
    tertiary-label="بازگشت به صفحهٔ اصلی"
    :tertiary-url="url('/')"
>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="11" cy="11" r="7"/>
        <path d="m20 20-3.5-3.5"/>
        <path d="M9.5 9.5h3v3"/>
    </svg>
</x-error-screen>
