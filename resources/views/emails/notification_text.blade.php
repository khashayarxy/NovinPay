@php
    $businessName = (string) config('business.name', 'نوین‌پی');
    $siteOrigin = rtrim((string) (config('business.public_url') ?: config('app.url', 'https://novinpaypos.com')), '/');
    $phonePrimary = (string) config('business.phone', '');
    $phoneSecondary = (string) config('business.secondary_phone', '');
    $address = (string) config('business.address', '');
@endphp
{{ $businessName }}@if(is_string($statusBadge ?? null) && trim($statusBadge) !== '') [{{ $statusBadge }}]@endif

==================================================
{{ $heading }}

{{ $intro }}
@if($fields !== [])

--------------------------------------------------
@foreach($fields as $label => $value)
• {{ $label }}: {{ $value }}
@endforeach
--------------------------------------------------
@endif
@if($actionLabel && $actionUrl)

{{ $actionLabel }}:
{{ $actionUrl }}
@endif
@if(is_string($footerNote ?? null) && trim($footerNote) !== '')

{{ $footerNote }}
@endif

==================================================
{{ $businessName }} — فروش و تعمیرات تخصصی کارتخوان
@if($phonePrimary !== '' || $phoneSecondary !== '')
تلفن پشتیبانی: {{ implode(' | ', array_filter([$phoneSecondary, $phonePrimary])) }}
@endif
@if($address !== '')
نشانی: {{ $address }}
@endif
وب‌سایت: {{ $siteOrigin }}
