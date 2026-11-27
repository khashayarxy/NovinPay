@extends('layouts.admin')
@section('title', 'سلامت سیستم و لاگ‌ها')
@section('content')
<div class="admin-form-surface" style="max-width: 1120px;">
    <header class="admin-page-heading">
        <div>
            <h1>سلامت سیستم و لاگ‌ها</h1>
        </div>
        <a class="btn btn-secondary" href="{{ route('admin.system-health.index', ['channel' => $logs['channel'], 'level' => $logs['level']]) }}">به‌روزرسانی وضعیت</a>
    </header>

    <section class="admin-panel" aria-labelledby="health-checks-title">
        <div class="admin-panel-heading">
            <h2 id="health-checks-title">وضعیت اجزای سیستم</h2>
            <span class="admin-hint">PHP {{ $checks['php_version'] }} · محیط: {{ $checks['app_env'] }}</span>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-top: 12px;">
            <article class="admin-info-card">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                    <strong>پایگاه داده ({{ $checks['database']['driver'] }})</strong>
                    <span @class(['admin-state-dot', 'admin-state-dot--danger' => !$checks['database']['ok']]) aria-hidden="true"></span>
                </div>
                <p class="admin-hint" style="margin-top:6px;">
                    @if($checks['database']['ok'])
                        متصل · تأخیر {{ $checks['database']['latency_ms'] }} میلی‌ثانیه
                    @else
                        قطع یا خطا ({{ $checks['database']['error'] }})
                    @endif
                </p>
            </article>

            <article class="admin-info-card">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                    <strong>مایگریشن‌ها</strong>
                    <span @class(['admin-state-dot', 'admin-state-dot--attention' => !$checks['migrations']['ok']]) aria-hidden="true"></span>
                </div>
                <p class="admin-hint" style="margin-top:6px;">
                    @if($checks['migrations']['pending'] === null)
                        قابل بررسی نیست
                    @elseif($checks['migrations']['pending'] === 0)
                        به‌روز ({{ $checks['migrations']['total_files'] }} فایل)
                    @else
                        {{ $checks['migrations']['pending'] }} مایگریشن در انتظار اجرا
                    @endif
                </p>
            </article>

            <article class="admin-info-card">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                    <strong>مسیرهای نوشتنی و کش</strong>
                    <span @class(['admin-state-dot', 'admin-state-dot--danger' => !($checks['writable']['ok'] && $checks['cache']['ok'])]) aria-hidden="true"></span>
                </div>
                <p class="admin-hint" style="margin-top:6px;">
                    مسیرها: {{ $checks['writable']['ok'] ? 'سالم' : 'نیاز به بررسی' }} · کش ({{ $checks['cache']['store'] }}): {{ $checks['cache']['ok'] ? 'سالم' : 'خطا' }}
                </p>
            </article>

            <article class="admin-info-card">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                    <strong>فضای دیسک</strong>
                    <span @class(['admin-state-dot', 'admin-state-dot--attention' => !$checks['disk']['ok']]) aria-hidden="true"></span>
                </div>
                <p class="admin-hint" style="margin-top:6px;">
                    @if($checks['disk']['free_gb'] !== null)
                        {{ $checks['disk']['free_gb'] }} GB آزاد از {{ $checks['disk']['total_gb'] }} GB ({{ $checks['disk']['free_percent'] }}%)
                    @else
                        اطلاعات فضای دیسک در دسترس نیست
                    @endif
                </p>
            </article>

            <article class="admin-info-card">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                    <strong>زمان‌بند (Scheduler)</strong>
                    <span @class(['admin-state-dot', 'admin-state-dot--attention' => !($checks['scheduler']['scheduler_recent'] ?? false)]) aria-hidden="true"></span>
                </div>
                <p class="admin-hint" style="margin-top:6px;">
                    @if($checks['scheduler']['scheduler_recent'] ?? false)
                        فعال و به‌روز
                    @else
                        تپش اخیر ثبت نشده است
                    @endif
                </p>
            </article>

            <article class="admin-info-card">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                    <strong>آخرین بکاپ</strong>
                    @php($backupStatus = $checks['backup']['status'] ?? null)
                    <span @class([
                        'admin-state-dot',
                        'admin-state-dot--danger' => in_array($backupStatus, ['failed', 'missing'], true),
                        'admin-state-dot--attention' => $backupStatus === 'stale' || $backupStatus === null,
                    ]) aria-hidden="true"></span>
                </div>
                <p class="admin-hint" style="margin-top:6px;">
                    @if(is_array($checks['backup']))
                        وضعیت: {{ $checks['backup']['status'] }}
                        @if(!empty($checks['backup']['file'])) · {{ $checks['backup']['file'] }} @endif
                        @if(isset($checks['backup']['size_mb'])) ({{ $checks['backup']['size_mb'] }} MB) @endif
                    @else
                        هنوز گزارشی ثبت نشده است
                    @endif
                </p>
            </article>
        </div>
    </section>

    <section class="admin-panel mt-3" aria-labelledby="daily-metrics-title">
        <div class="admin-panel-heading">
            <h2 id="daily-metrics-title">آمار پایش امروز</h2>
            <span class="admin-hint">
                تاپیک‌های تلگرام: {{ $checks['telegram_topics_enabled'] ? 'فعال' : 'غیرفعال' }} ·
                تله‌متری JS: {{ $checks['client_telemetry_enabled'] ? 'فعال' : 'غیرفعال' }}
            </span>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(165px, 1fr)); gap: 10px; margin-top: 12px;">
            <div class="admin-info-card"><strong>خطای سرور (500)</strong><p class="admin-hint" style="margin-top:4px;">{{ $checks['metrics']['http_500'] }}</p></div>
            <div class="admin-info-card"><strong>خطای مرورگر (JS)</strong><p class="admin-hint" style="margin-top:4px;">{{ $checks['metrics']['client_js'] }}</p></div>
            <div class="admin-info-card"><strong>درخواست کند HTTP</strong><p class="admin-hint" style="margin-top:4px;">{{ $checks['metrics']['slow_requests'] }}</p></div>
            <div class="admin-info-card"><strong>کوئری کند دیتابیس</strong><p class="admin-hint" style="margin-top:4px;">{{ $checks['metrics']['slow_queries'] }}</p></div>
            <div class="admin-info-card">
                <strong>پیامک‌های امروز</strong>
                <p class="admin-hint" style="margin-top:4px;">کل: {{ $checks['sms']['total'] }} (ناموفق: {{ $checks['sms']['failed'] }})</p>
                <p class="admin-hint" style="margin-top:2px; font-size:0.8rem;">OTP: {{ $checks['sms']['otp'] }} · سفارش: {{ $checks['sms']['order'] }} · تعمیر: {{ $checks['sms']['repair'] }} · هشدار: {{ $checks['sms']['alert'] }}</p>
            </div>
        </div>
    </section>

    <section class="admin-panel mt-3" aria-labelledby="logs-viewer-title">
        <div class="admin-panel-heading">
            <div>
                <h2 id="logs-viewer-title">مشاهدهٔ لاگ‌های اخیر (فقط‌خواندنی و پاک‌سازی‌شده)</h2>
                <p class="admin-hint">
                    @if($logs['file'])
                        فایل فعال: <span dir="ltr" style="font-family: monospace;">{{ $logs['file'] }}</span>
                    @else
                        هنوز فایلی برای این کانال ثبت نشده است.
                    @endif
                </p>
            </div>
        </div>

        <form method="get" action="{{ route('admin.system-health.index') }}" style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin-top:12px;">
            <div style="min-width: 220px; flex: 1;">
                <label class="label" for="log-channel">کانال لاگ</label>
                <select class="input" id="log-channel" name="channel">
                    @foreach($logs['channels'] as $key => $meta)
                        <option value="{{ $key }}" @selected($logs['channel'] === $key)>{{ $meta['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div style="min-width: 150px;">
                <label class="label" for="log-level">سطح خطا</label>
                <select class="input" id="log-level" name="level">
                    @foreach($levels as $lvl)
                        <option value="{{ $lvl }}" @selected($logs['level'] === $lvl)>{{ $lvl === 'ALL' ? 'همه سطوح' : $lvl }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <button class="btn btn-primary" type="submit">اعمال فیلتر</button>
            </div>
        </form>

        <div style="margin-top: 14px; overflow-x: auto;">
            @if(count($logs['entries']) === 0)
                <p class="admin-hint">موردی برای نمایش با فیلتر انتخابی یافت نشد.</p>
            @else
                <table style="width:100%; border-collapse:collapse; font-size:0.86rem;">
                    <thead>
                        <tr style="border-bottom:1px solid #cbd5e1; text-align:right;">
                            <th style="padding:8px 10px; white-space:nowrap;">زمان</th>
                            <th style="padding:8px 10px; white-space:nowrap;">سطح</th>
                            <th style="padding:8px 10px;">پیام (پاک‌سازی‌شده)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($logs['entries'] as $entry)
                            <tr style="border-bottom:1px solid #e2e8f0; vertical-align:top;">
                                <td dir="ltr" style="padding:8px 10px; white-space:nowrap; font-family:monospace; color:#475569;">{{ $entry['timestamp'] }}</td>
                                <td dir="ltr" style="padding:8px 10px; white-space:nowrap; font-family:monospace; font-weight:600;">{{ $entry['level'] }}</td>
                                <td dir="ltr" style="padding:8px 10px; font-family:monospace; word-break:break-word; text-align:left;">{{ $entry['message'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </section>
</div>
@endsection
