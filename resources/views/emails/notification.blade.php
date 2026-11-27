@php
    $businessName = (string) config('business.name', 'نوین‌پی');
    $siteOrigin = rtrim((string) (config('business.public_url') ?: config('app.url', 'https://novinpaypos.com')), '/');
    $siteHost = parse_url($siteOrigin, PHP_URL_HOST) ?: 'novinpaypos.com';
    $pngLogoFile = public_path('brand/novinpay-email-logo.png');
    $logoSrc = (isset($message) && is_object($message) && method_exists($message, 'embed') && is_file($pngLogoFile))
        ? $message->embed($pngLogoFile)
        : $siteOrigin.'/brand/novinpay-email-logo.png';
    $phonePrimary = (string) config('business.phone', '');
    $faDigits = static fn (string $v): string => \App\Support\FaNumber::digits($v);
    $previewText = trim((string) ($preheader ?? $intro));

    $badgeStyles = match ($statusTone ?? 'info') {
        'success' => ['bg' => '#ecfdf5', 'border' => '#a7f3d0', 'text' => '#047857'],
        'warning' => ['bg' => '#fffbeb', 'border' => '#fde68a', 'text' => '#b45309'],
        'danger' => ['bg' => '#fff1f2', 'border' => '#fecdd3', 'text' => '#be123c'],
        default => ['bg' => '#eff8ff', 'border' => '#b9dfff', 'text' => '#0674cf'],
    };
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $mailSubject }}</title>
    <style>
        @media only screen and (max-width: 600px) {
            .np-mail-wrap { padding: 10px 6px !important; }
            .np-mail-card { border-radius: 14px !important; }
            .np-mail-header { padding: 14px 16px !important; }
            .np-mail-body { padding: 18px 16px !important; }
            .np-mail-footer { padding: 14px 16px !important; }
            .np-mail-td-label { width: 38% !important; padding: 9px 10px !important; font-size: 12px !important; }
            .np-mail-td-val { width: 62% !important; padding: 9px 10px !important; font-size: 12.5px !important; }
            .np-mail-cta { display: block !important; width: 100% !important; text-align: center !important; box-sizing: border-box !important; }
        }
        @media (prefers-color-scheme: dark) {
            body, .np-mail-outer { background-color: #0f172a !important; }
            .np-mail-card { background-color: #162238 !important; border-color: #263a59 !important; }
            .np-mail-header { background-color: #162238 !important; border-bottom-color: #243856 !important; }
            .np-mail-brand { color: #f8fafc !important; }
            .np-mail-h1 { color: #f8fafc !important; }
            .np-mail-intro { color: #cbd5e1 !important; }
            .np-mail-table { background-color: #1b2a44 !important; border-color: #2b4266 !important; }
            .np-mail-td-label { color: #94a3b8 !important; border-bottom-color: #263b5c !important; }
            .np-mail-td-val { color: #f1f5f9 !important; border-bottom-color: #263b5c !important; }
            .np-mail-footer { background-color: #131e32 !important; border-top-color: #243856 !important; color: #94a3b8 !important; }
            .np-mail-footer-link { color: #38bdf8 !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#f4f8fc;font-family:Tahoma,'Segoe UI',Arial,sans-serif;color:#0f172a;direction:rtl;text-align:right;-webkit-font-smoothing:antialiased;">
    @if($previewText !== '')
        <div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#f4f8fc;opacity:0;">
            {{ $previewText }}&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;
        </div>
    @endif

    <table role="presentation" class="np-mail-outer" width="100%" cellspacing="0" cellpadding="0" border="0" dir="rtl" style="width:100%;background-color:#f4f8fc;margin:0;padding:0;">
        <tr>
            <td align="center" class="np-mail-wrap" style="padding:22px 12px;">
                <table role="presentation" class="np-mail-card" width="100%" cellspacing="0" cellpadding="0" border="0" dir="rtl" style="width:100%;max-width:560px;margin:0 auto;background-color:#ffffff;border:1px solid #dde8f3;border-top:4px solid #098CF7;border-radius:16px;overflow:hidden;">
                    {{-- Minimal Brand Header: PNG Logo + Brand Name Only --}}
                    <tr>
                        <td class="np-mail-header" align="right" style="padding:16px 22px;background-color:#ffffff;border-bottom:1px solid #edf2f8;text-align:right;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" dir="rtl">
                                <tr>
                                    <td valign="middle" style="padding-left:10px;">
                                        <a href="{{ $siteOrigin }}" style="text-decoration:none;display:inline-block;">
                                            <img src="{{ $logoSrc }}" width="36" height="36" alt="{{ $businessName }}" style="display:block;width:36px;height:36px;border-radius:8px;border:0;outline:none;">
                                        </a>
                                    </td>
                                    <td valign="middle" style="text-align:right;">
                                        <a href="{{ $siteOrigin }}" class="np-mail-brand" style="color:#0f2440;font-size:17px;font-weight:bold;text-decoration:none;line-height:1.4;">{{ $businessName }}</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Body: Status Badge Above Title + Clean Table + Primary CTA --}}
                    <tr>
                        <td class="np-mail-body" style="padding:22px 22px 20px;text-align:right;direction:rtl;">
                            @if(is_string($statusBadge ?? null) && trim($statusBadge) !== '')
                                <div style="margin:0 0 10px;">
                                    <span style="display:inline-block;padding:4px 11px;border-radius:999px;background-color:{{ $badgeStyles['bg'] }};border:1px solid {{ $badgeStyles['border'] }};color:{{ $badgeStyles['text'] }};font-size:11.5px;font-weight:bold;line-height:1.5;">
                                        {{ $statusBadge }}
                                    </span>
                                </div>
                            @endif

                            <h1 class="np-mail-h1" style="margin:0 0 8px;font-size:18px;font-weight:bold;line-height:1.6;color:#0f2440;">
                                {{ $heading }}
                            </h1>
                            <p class="np-mail-intro" style="margin:0 0 16px;font-size:13.5px;line-height:1.85;color:#475569;">
                                {{ $intro }}
                            </p>

                            @if($fields !== [])
                                <table role="presentation" class="np-mail-table" width="100%" cellspacing="0" cellpadding="0" border="0" dir="rtl" style="width:100%;border-collapse:separate;border-spacing:0;background-color:#f8fbff;border:1px solid #e2ecf6;border-radius:12px;overflow:hidden;">
                                    @foreach($fields as $label => $value)
                                        @php
                                            $borderStyle = $loop->last ? 'border-bottom:0;' : 'border-bottom:1px solid #e8f0f8;';
                                            $valStr = (string) $value;
                                            $isPureLtr = preg_match('/\A[A-Za-z0-9#._:\/+\-\s]+\z/', $valStr) === 1;
                                        @endphp
                                        <tr>
                                            <td class="np-mail-td-label" valign="top" style="padding:10px 13px;font-size:12.5px;color:#5b708b;width:36%;text-align:right;line-height:1.7;{{ $borderStyle }}">
                                                {{ $label }}
                                            </td>
                                            <td class="np-mail-td-val" valign="top" style="padding:10px 13px;font-size:13px;font-weight:bold;color:#0f2440;width:64%;text-align:right;line-height:1.7;word-break:break-word;{{ $borderStyle }}">
                                                @if($isPureLtr)
                                                    <bdi dir="ltr" style="font-family:Consolas,'Segoe UI',Tahoma,monospace;">{{ $valStr }}</bdi>
                                                @else
                                                    {{ $valStr }}
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            @if($actionLabel && $actionUrl)
                                <div style="margin-top:20px;text-align:right;">
                                    <a href="{{ $actionUrl }}" class="np-mail-cta" style="display:inline-block;padding:11px 22px;background-color:#098CF7;color:#ffffff;text-decoration:none;border-radius:10px;font-size:13.5px;font-weight:bold;line-height:1.5;">
                                        {{ $actionLabel }}
                                    </a>
                                </div>
                            @endif

                            @if(is_string($footerNote ?? null) && trim($footerNote) !== '')
                                <p style="margin:14px 0 0;font-size:12px;line-height:1.75;color:#64748b;">
                                    {{ $footerNote }}
                                </p>
                            @endif
                        </td>
                    </tr>

                    {{-- Concise Footer --}}
                    <tr>
                        <td class="np-mail-footer" style="padding:14px 22px;background-color:#f8fbff;border-top:1px solid #edf2f8;font-size:12px;line-height:1.8;color:#64748b;text-align:right;direction:rtl;">
                            <div>
                                <strong style="color:#1e3a5f;">{{ $businessName }}</strong>
                                @if($phonePrimary !== '')
                                    &nbsp;·&nbsp; پشتیبانی: <a href="tel:{{ $phonePrimary }}" class="np-mail-footer-link" dir="ltr" style="color:#0674cf;text-decoration:none;font-weight:bold;">{{ $faDigits($phonePrimary) }}</a>
                                @endif
                                &nbsp;·&nbsp; <a href="{{ $siteOrigin }}" class="np-mail-footer-link" dir="ltr" style="color:#0674cf;text-decoration:none;">{{ $siteHost }}</a>
                            </div>
                            <div style="margin-top:2px;font-size:11px;color:#8496ad;">
                                این پیام به‌صورت خودکار ارسال شده است.
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
