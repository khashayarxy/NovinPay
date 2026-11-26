@php
    $businessName = (string) config('business.name', 'نوین‌پی');
    $siteOrigin = rtrim((string) (config('business.public_url') ?: config('app.url', 'https://novinpaypos.com')), '/');
    $logoUrl = $siteOrigin.'/favicon-96x96.png';
    $phonePrimary = (string) config('business.phone', '');
    $phoneSecondary = (string) config('business.secondary_phone', '');
    $address = (string) config('business.address', '');
    $faDigits = static fn (string $v): string => \App\Support\FaNumber::digits($v);
    $previewText = trim((string) ($preheader ?? $intro));

    $badgeStyles = match ($statusTone ?? 'info') {
        'success' => ['bg' => '#dcfce7', 'border' => '#86efac', 'text' => '#166534'],
        'warning' => ['bg' => '#fef3c7', 'border' => '#fcd34d', 'text' => '#92400e'],
        'danger' => ['bg' => '#ffe4e6', 'border' => '#fda4af', 'text' => '#9f1239'],
        default => ['bg' => '#dbeafe', 'border' => '#93c5fd', 'text' => '#1e40af'],
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
            .np-mail-wrap { padding: 12px 8px !important; }
            .np-mail-card { border-radius: 14px !important; }
            .np-mail-header { padding: 16px 18px !important; }
            .np-mail-body { padding: 20px 18px !important; }
            .np-mail-footer { padding: 16px 18px !important; }
            .np-mail-cta { display: block !important; width: 100% !important; text-align: center !important; box-sizing: border-box !important; }
        }
        @media (prefers-color-scheme: dark) {
            body, .np-mail-outer { background-color: #0b1526 !important; }
            .np-mail-card { background-color: #111f36 !important; border-color: #1f3556 !important; }
            .np-mail-h1 { color: #f1f6fd !important; }
            .np-mail-intro { color: #b6cae2 !important; }
            .np-mail-table { background-color: #152642 !important; border-color: #253f66 !important; }
            .np-mail-td-label { color: #93adc8 !important; border-bottom-color: #223a5e !important; }
            .np-mail-td-val { color: #f1f6fd !important; border-bottom-color: #223a5e !important; }
            .np-mail-footer { background-color: #0e1b30 !important; border-top-color: #1f3556 !important; color: #8da6c2 !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#eef4fb;font-family:Tahoma,'Segoe UI',Arial,sans-serif;color:#0f172a;direction:rtl;text-align:right;-webkit-font-smoothing:antialiased;">
    @if($previewText !== '')
        <div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#eef4fb;opacity:0;">
            {{ $previewText }}&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;
        </div>
    @endif

    <table role="presentation" class="np-mail-outer" width="100%" cellspacing="0" cellpadding="0" border="0" dir="rtl" style="width:100%;background-color:#eef4fb;margin:0;padding:0;">
        <tr>
            <td align="center" class="np-mail-wrap" style="padding:28px 14px;">
                <table role="presentation" class="np-mail-card" width="100%" cellspacing="0" cellpadding="0" border="0" dir="rtl" style="width:100%;max-width:600px;margin:0 auto;background-color:#ffffff;border:1px solid #d7e4f2;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,35,66,0.06);">
                    {{-- Brand Header --}}
                    <tr>
                        <td class="np-mail-header" style="padding:20px 26px;background-color:#0d223f;background-image:linear-gradient(135deg,#0d223f 0%,#12345e 100%);border-bottom:3px solid #098cf7;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" dir="rtl">
                                <tr>
                                    <td align="right" valign="middle" style="text-align:right;">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" dir="rtl">
                                            <tr>
                                                <td valign="middle" style="padding-left:12px;">
                                                    <a href="{{ $siteOrigin }}" style="text-decoration:none;display:inline-block;">
                                                        <img src="{{ $logoUrl }}" width="38" height="38" alt="{{ $businessName }}" style="display:block;width:38px;height:38px;border-radius:9px;background-color:#ffffff;padding:3px;border:0;">
                                                    </a>
                                                </td>
                                                <td valign="middle" style="text-align:right;">
                                                    <a href="{{ $siteOrigin }}" style="color:#ffffff;font-size:18px;font-weight:bold;text-decoration:none;display:block;line-height:1.4;">{{ $businessName }}</a>
                                                    <span style="color:#9ec5eb;font-size:11.5px;display:block;line-height:1.5;">فروش و تعمیرات تخصصی کارتخوان</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                    @if(is_string($statusBadge) && trim($statusBadge) !== '')
                                        <td align="left" valign="middle" style="text-align:left;white-space:nowrap;">
                                            <span style="display:inline-block;padding:5px 12px;border-radius:999px;background-color:{{ $badgeStyles['bg'] }};border:1px solid {{ $badgeStyles['border'] }};color:{{ $badgeStyles['text'] }};font-size:11.5px;font-weight:bold;line-height:1.4;">
                                                {{ $statusBadge }}
                                            </span>
                                        </td>
                                    @endif
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Main Body --}}
                    <tr>
                        <td class="np-mail-body" style="padding:26px 26px 24px;text-align:right;direction:rtl;">
                            <h1 class="np-mail-h1" style="margin:0 0 12px;font-size:19px;font-weight:bold;line-height:1.6;color:#0b1f3a;">
                                {{ $heading }}
                            </h1>
                            <p class="np-mail-intro" style="margin:0 0 20px;font-size:14px;line-height:1.95;color:#334155;">
                                {{ $intro }}
                            </p>

                            @if($fields !== [])
                                <table role="presentation" class="np-mail-table" width="100%" cellspacing="0" cellpadding="0" border="0" dir="rtl" style="width:100%;border-collapse:separate;border-spacing:0;background-color:#f8fbff;border:1px solid #dce8f5;border-radius:12px;overflow:hidden;">
                                    @foreach($fields as $label => $value)
                                        @php
                                            $isLast = $loop->last;
                                            $borderStyle = $isLast ? 'border-bottom:0;' : 'border-bottom:1px solid #e4eef8;';
                                            $rowBg = $loop->even ? 'background-color:#f2f7fd;' : '';
                                            $valStr = (string) $value;
                                            $isPureLtr = preg_match('/\A[A-Za-z0-9#._:\/+\-\s]+\z/', $valStr) === 1;
                                        @endphp
                                        <tr style="{{ $rowBg }}">
                                            <td class="np-mail-td-label" valign="top" style="padding:11px 14px;font-size:13px;color:#526780;width:38%;text-align:right;line-height:1.75;{{ $borderStyle }}">
                                                {{ $label }}
                                            </td>
                                            <td class="np-mail-td-val" valign="top" style="padding:11px 14px;font-size:13.5px;font-weight:bold;color:#0f2440;width:62%;text-align:right;line-height:1.75;word-break:break-word;{{ $borderStyle }}">
                                                @if($isPureLtr)
                                                    <bdi dir="ltr" style="font-family:Consolas,Monaco,'Segoe UI',Tahoma,monospace;">{{ $valStr }}</bdi>
                                                @else
                                                    {{ $valStr }}
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            @if($actionLabel && $actionUrl)
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" dir="rtl" style="margin-top:24px;">
                                    <tr>
                                        <td align="right" style="text-align:right;">
                                            <a href="{{ $actionUrl }}" class="np-mail-cta" style="display:inline-block;padding:12px 26px;background-color:#075da6;color:#ffffff;text-decoration:none;border-radius:11px;font-size:13.5px;font-weight:bold;line-height:1.5;">
                                                {{ $actionLabel }}
                                            </a>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding-top:12px;font-size:11.5px;line-height:1.7;color:#64748b;text-align:right;">
                                            در صورت باز نشدن دکمه، لینک زیر را در مرورگر خود باز کنید:<br>
                                            <a href="{{ $actionUrl }}" dir="ltr" style="color:#075da6;text-decoration:underline;word-break:break-all;font-family:Consolas,Tahoma,sans-serif;">{{ $actionUrl }}</a>
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            @if(is_string($footerNote ?? null) && trim($footerNote) !== '')
                                <p style="margin:18px 0 0;padding:10px 14px;background-color:#f1f5f9;border-radius:9px;font-size:12px;line-height:1.8;color:#475569;">
                                    {{ $footerNote }}
                                </p>
                            @endif
                        </td>
                    </tr>

                    {{-- Official NovinPay Footer --}}
                    <tr>
                        <td class="np-mail-footer" style="padding:18px 26px;background-color:#f5f9fe;border-top:1px solid #dce8f5;font-size:12px;line-height:1.9;color:#5b708b;text-align:right;direction:rtl;">
                            <div style="font-weight:bold;color:#1e3a5f;margin-bottom:4px;">{{ $businessName }} — فروش و تعمیرات تخصصی دستگاه کارتخوان</div>
                            @if($phonePrimary !== '' || $phoneSecondary !== '')
                                <div>
                                    پشتیبانی تلفنی:
                                    @foreach(array_filter([$phoneSecondary, $phonePrimary]) as $idx => $p)
                                        @if($idx > 0) &nbsp;|&nbsp; @endif
                                        <a href="tel:{{ $p }}" dir="ltr" style="color:#075da6;text-decoration:none;font-weight:bold;">{{ $faDigits($p) }}</a>
                                    @endforeach
                                </div>
                            @endif
                            @if($address !== '')
                                <div>نشانی: {{ $address }}</div>
                            @endif
                            <div style="margin-top:6px;font-size:11px;color:#788da8;">
                                این ایمیل به‌صورت خودکار از سامانه رسمی <a href="{{ $siteOrigin }}" style="color:#075da6;text-decoration:none;">{{ parse_url($siteOrigin, PHP_URL_HOST) ?: $businessName }}</a> ارسال شده است.
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
