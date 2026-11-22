<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ $mailSubject }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f7fb;font-family:Tahoma,Arial,sans-serif;color:#0f172a;direction:rtl;text-align:right;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;">
        <tr>
            <td style="padding:20px 24px;background:#122033;color:#ffffff;font-size:18px;font-weight:bold;">
                {{ config('business.name', 'نوین‌پی') }}
            </td>
        </tr>
        <tr>
            <td style="padding:24px;">
                <h1 style="margin:0 0 12px;font-size:18px;color:#0f172a;">{{ $heading }}</h1>
                <p style="margin:0 0 18px;font-size:14px;line-height:1.9;color:#334155;">{{ $intro }}</p>
                @if($fields !== [])
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                        @foreach($fields as $label => $value)
                            <tr>
                                <td style="padding:10px 14px;font-size:13px;color:#526277;border-bottom:1px solid #e2e8f0;width:38%;">{{ $label }}</td>
                                <td style="padding:10px 14px;font-size:13px;font-weight:bold;color:#0f172a;border-bottom:1px solid #e2e8f0;">{{ $value }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
                @if($actionLabel && $actionUrl)
                    <p style="margin:22px 0 0;">
                        <a href="{{ $actionUrl }}" style="display:inline-block;padding:10px 20px;background:#155bc0;color:#ffffff;text-decoration:none;border-radius:8px;font-size:13px;font-weight:bold;">{{ $actionLabel }}</a>
                    </p>
                @endif
            </td>
        </tr>
        <tr>
            <td style="padding:14px 24px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;">
                این پیام به‌صورت خودکار از سامانه {{ config('business.name', 'نوین‌پی') }} ارسال شده است.
            </td>
        </tr>
    </table>
</body>
</html>
