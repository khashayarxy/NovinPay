@php
    $incidentId = request()?->attributes->get('incident_id');
@endphp
<x-error-screen
    code="۵۰۰"
    badge="خطای ۵۰۰ · اختلال موقت سرور"
    tone="danger"
    title="خطایی در سرور رخ داد"
    description="متأسفانه پردازش درخواست شما با خطای موقت مواجه شد. تیم فنی به‌صورت خودکار مطلع شده است؛ لطفاً چند لحظهٔ دیگر دوباره تلاش کنید."
    :incident-id="is_string($incidentId) ? $incidentId : null"
    primary-label="بازگشت به صفحهٔ اصلی"
    :primary-url="url('/')"
    secondary-label="مشاهده محصولات"
    :secondary-url="url('/products')"
    tertiary-label="تماس با پشتیبانی"
    :tertiary-url="url('/contact')"
    :show-phones="true"
>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round">
        <rect x="2" y="3" width="20" height="7" rx="2"/>
        <rect x="2" y="14" width="20" height="7" rx="2"/>
        <path d="M6 6.5h.01M6 17.5h.01"/>
        <path d="M13 17.5h5"/>
    </svg>
</x-error-screen>
