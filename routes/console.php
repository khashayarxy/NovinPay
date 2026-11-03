<?php

use App\Models\PaymentAttempt;
use App\Services\CustomerNotifications;
use App\Services\SmsIr;
use App\Services\ZibalGateway;
use App\Services\ZibalPayments;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('payments:reconcile', function (ZibalPayments $payments, ZibalGateway $gateway): int {
    if (! $gateway->available()) {
        $this->info('Payments disabled; no provider request made.');

        return 0;
    }
    $started = microtime(true);
    foreach (PaymentAttempt::query()->whereIn('state', ['pending', 'uncertain', 'processing'])->whereNotNull('track_id')->where(fn ($q) => $q->whereNull('retry_at')->orWhere('retry_at', '<=', now()))->orderBy('id')->limit(40)->get() as $attempt) {
        if (microtime(true) - $started > 180) {
            break;
        }
        if ($attempt->attempts >= 1440) {
            continue; // Retain evidence; manual review, never discard unresolved funds.
        }
        $payments->verify($attempt);
    }
    $this->info('Reconciliation completed; no transaction details printed.');

    return 0;
})->purpose('Reconcile known payment attempts without trusting browser callbacks');

Schedule::command('payments:reconcile')->everyMinute()->withoutOverlapping(10);

Artisan::command('customers:notify', function (CustomerNotifications $notifications): int {
    $this->info('Provider-accepted notifications: '.$notifications->sendPending());

    return 0;
})->purpose('Send configured customer notifications without exposing customer data');
Schedule::command('customers:notify')->everyMinute()->withoutOverlapping(10);

Artisan::command('customers:sms-status {--provider : Inspect configured OTP template via read-only GET; never send}', function (SmsIr $sms): int {
    $status = [
        'otp_enabled' => (bool) config('customer.sms_enabled'),
        'otp_approved_configuration' => $sms->ready(config('customer.otp_template'), config('customer.otp_parameter')),
        'notifications_enabled' => (bool) config('customer.notifications_enabled'),
        'ready_notification_templates' => count(array_filter(config('customer.notification_templates', []), fn ($id) => $sms->ready($id, config('customer.notification_parameter')))),
        'sending_executed' => false,
    ];
    if ($this->option('provider')) {
        $status['otp_provider_metadata'] = $sms->inspect(config('customer.otp_template'), config('customer.otp_parameter'));
    }
    $this->line(json_encode($status, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

    return isset($status['otp_provider_metadata']) && (! $status['otp_provider_metadata']['accessible'] || ! ($status['otp_provider_metadata']['exact_parameter_match'] ?? false)) ? 1 : 0;
})->purpose('Report non-secret SMS configuration and optional read-only template metadata');
