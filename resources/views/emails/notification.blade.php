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
        'success' => ['bg' => '#f0fdf4', 'text' => '#15803d'],
        'warning' => ['bg' => '#fffbeb', 'text' => '#b45309'],
        'danger' => ['bg' => '#fff1f2', 'text' => '#be123c'],
        default => ['bg' => '#f0f9ff', 'text' => '#0369a1'],
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
            .np-mail-wrap { padding: 20px 16px !important; }
            .np-mail-td-label { width: 40% !important; padding: 10px 0 !important; font-size: 12.5px !important; }
            .np-mail-td-val { width: 60% !important; padding: 10px 0 !important; font-size: 13px !important; }
            .np-mail-cta { display: block !important; width: 100% !important; text-align: center !important; box-sizing: border-box !important; }
        }
        @media (prefers-color-scheme: dark) {
            body, .np-mail-outer, .np-mail-shell { background-color: #0b1120 !important; color: #f8fafc !important; }
            .np-mail-header { border-bottom-color: #1e293b !important; }
            .np-mail-brand, .np-mail-h1, .np-mail-td-val { color: #f8fafc !important; }
            .np-mail-intro, .np-mail-td-label { color: #94a3b8 !important; }
            .np-mail-row-cell { border-bottom-color: #1e293b !important; }
            .np-mail-footer { border-top-color: #1e293b !important; color: #64748b !important; }
            .np-mail-footer-brand { color: #cbd5e1 !important; }
            .np-mail-footer-link { color: #38bdf8 !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#ffffff;font-family:Tahoma,'Segoe UI',-apple-system,BlinkMacSystemFont,Arial,sans-serif;color:#0f172a;direction:rtl;text-align:right;-webkit-font-smoothing:antialiased;">
    @if($previewText !== '')
        <div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#ffffff;opacity:0;">
            {{ $previewText }}&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;
        </div>
    @endif

    <table role="presentation" class="np-mail-outer" width="100%" cellspacing="0" cellpadding="0" border="0" dir="rtl" style="width:100%;background-color:#ffffff;margin:0;padding:0;">
        <tr>
            <td align="center" class="np-mail-wrap" style="padding:36px 24px;background-color:#ffffff;">
                <table role="presentation" class="np-mail-shell" width="100%" cellspacing="0" cellpadding="0" border="0" dir="rtl" style="width:100%;max-width:520px;margin:0 auto;background-color:#ffffff;">
                    {{-- Ultra-Minimal Header: Crisp Inline PNG Logo + Wordmark + Hairline Divider --}}
                    <tr>
                        <td class="np-mail-header" align="right" style="padding:0 0 20px;background-color:#ffffff;border-bottom:1px solid #f1f5f9;text-align:right;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" dir="rtl">
                                <tr>
                                    <td valign="middle" style="padding-left:10px;">
                                        <a href="{{ $siteOrigin }}" style="text-decoration:none;display:inline-block;">
                                            <img src="{{ $logoSrc }}" width="36" height="36" alt="{{ $businessName }}" style="display:block;width:36px;height:36px;border-radius:8px;border:0;outline:none;">
                                        </a>
                                    </td>
                                    <td valign="middle" style="text-align:right;">
                                        <a href="{{ $siteOrigin }}" class="np-mail-brand" style="color:#0f172a;font-size:16px;font-weight:bold;text-decoration:none;line-height:1.4;letter-spacing:-0.01em;">{{ $businessName }}</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Seamless White Content Body --}}
                    <tr>
                        <td class="np-mail-body" style="padding:26px 0 28px;background-color:#ffffff;text-align:right;direction:rtl;">
                            @if(is_string($statusBadge ?? null) && trim($statusBadge) !== '')
                                <div style="margin:0 0 12px;">
                                    <span style="display:inline-block;padding:3px 10px;border-radius:999px;background-color:{{ $badgeStyles['bg'] }};color:{{ $badgeStyles['text'] }};font-size:11.5px;font-weight:bold;line-height:1.5;">
                                        {{ $statusBadge }}
                                    </span>
                                </div>
                            @endif

                            <h1 class="np-mail-h1" style="margin:0 0 10px;font-size:19px;font-weight:bold;line-height:1.6;color:#0f172a;">
                                {{ $heading }}
                            </h1>
                            <p class="np-mail-intro" style="margin:0 0 22px;font-size:14px;line-height:1.9;color:#64748b;">
                                {{ $intro }}
                            </p>

                            @if($fields !== [])
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" dir="rtl" style="width:100%;border-collapse:collapse;background-color:transparent;">
                                    @foreach($fields as $label => $value)
                                        @php
                                            $borderStyle = 'border-bottom:1px solid #f1f5f9;';
                                            $valStr = (string) $value;
                                            $isPureLtr = preg_match('/\A[A-Za-z0-9#._:\/+\-\s]+\z/', $valStr) === 1;
                                        @endphp
                                        <tr>
                                            <td class="np-mail-td-label np-mail-row-cell" valign="top" style="padding:11px 0 11px 12px;font-size:13px;color:#64748b;width:38%;text-align:right;line-height:1.7;{{ $borderStyle }}">
                                                {{ $label }}
                                            </td>
                                            <td class="np-mail-td-val np-mail-row-cell" valign="top" style="padding:11px 0;font-size:13.5px;font-weight:bold;color:#0f172a;width:62%;text-align:right;line-height:1.7;word-break:break-word;{{ $borderStyle }}">
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
                                <div style="margin-top:26px;text-align:right;">
                                    <a href="{{ $actionUrl }}" class="np-mail-cta" style="display:inline-block;padding:10px 20px;background-color:#098CF7;color:#ffffff;text-decoration:none;border-radius:8px;font-size:13.5px;font-weight:bold;line-height:1.5;">
                                        {{ $actionLabel }}
                                    </a>
                                </div>
                            @endif

                            @if(is_string($footerNote ?? null) && trim($footerNote) !== '')
                                <p style="margin:18px 0 0;font-size:12.5px;line-height:1.8;color:#64748b;">
                                    {{ $footerNote }}
                                </p>
                            @endif
                        </td>
                    </tr>

                    {{-- Minimal Plain-Text Style Footer --}}
                    <tr>
                        <td class="np-mail-footer" style="padding:20px 0 0;background-color:#ffffff;border-top:1px solid #f1f5f9;font-size:12px;line-height:1.85;color:#94a3b8;text-align:right;direction:rtl;">
                            <div>
                                <span class="np-mail-footer-brand" style="color:#475569;font-weight:bold;">{{ $businessName }}</span>
                                @if($phonePrimary !== '')
                                    &nbsp;·&nbsp; پشتیبانی <a href="tel:{{ $phonePrimary }}" class="np-mail-footer-link" dir="ltr" style="color:#64748b;text-decoration:none;">{{ $faDigits($phonePrimary) }}</a>
                                @endif
                                &nbsp;·&nbsp; <a href="{{ $siteOrigin }}" class="np-mail-footer-link" dir="ltr" style="color:#64748b;text-decoration:none;">{{ $siteHost }}</a>
                            </div>
                            <div style="margin-top:2px;font-size:11.5px;color:#94a3b8;">
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
