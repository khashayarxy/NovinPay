<?php

use App\Models\PaymentAttempt;
use App\Services\CustomerLogin;
use App\Services\CustomerNotifications;
use App\Services\IndependentSupportOperators;
use App\Services\SmsIr;
use App\Services\ZibalGateway;
use App\Services\ZibalPayments;
use App\Support\Chat\ChatBridge;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('customers:test-code', function (CustomerLogin $login): int {
    if (! defined('STDIN') || ! defined('STDOUT') || ! stream_isatty(STDIN) || ! stream_isatty(STDOUT)) {
        $this->error('Use a private interactive terminal.');

        return 1;
    }

    $phone = RepairIdentity::phone($this->secret('Allowlisted test mobile', false));
    $code = $login->privateTestCode($phone);
    if ($code === null) {
        $this->error('No active test challenge.');

        return 1;
    }

    $this->line($code);

    return 0;
})->purpose('Read an allowlisted customer test challenge in a private terminal');

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

Artisan::command('support:initialize', function (ChatBridge $bridge): int {
    $bridge->initialize();
    $this->info('Private NovinPay chat initialized. External notifications remain disabled.');

    return 0;
});

Artisan::command('support:cleanup {--apply : Apply eligible attachment cleanup} {--arm-retention : Arm the existing retention warmup} {--retention : Include eligible history retention}', function (ChatBridge $bridge): int {
    abort_unless(app()->environment('staging', 'testing'), 403);
    if (app()->environment('staging')) {
        abort_unless(DB::connection()->getDatabaseName() === 'novinpay_stg38', 403);
    }
    $bridge->begin();
    $prior = getenv('SUPPORT_RETENTION_ENABLED');
    try {
        require_once resource_path('support-chat/support/attachments.php');
        require_once resource_path('support-chat/support/retention.php');
        if ($this->option('arm-retention') || $this->option('retention')) {
            putenv('SUPPORT_RETENTION_ENABLED=1');
        }
        $report = [];
        if ($this->option('arm-retention')) {
            abort_if($this->option('apply'), 422, 'Arm separately; preserve the retention warmup.');
            $report['retention'] = support_retention_initialize();
        } else {
            $report['attachments'] = support_attachment_prune((bool) $this->option('apply'));
            $report['retention'] = support_retention_cleanup((bool) $this->option('apply') && (bool) $this->option('retention'));
        }
        $this->line(json_encode($report, JSON_THROW_ON_ERROR));

        return 0;
    } finally {
        putenv($prior === false ? 'SUPPORT_RETENTION_ENABLED' : 'SUPPORT_RETENTION_ENABLED='.$prior);
        $bridge->finish();
    }
})->purpose('Private chat cleanup; dry-run by default, staging/testing only');

Artisan::command('support:independent-enroll {username?}', function (IndependentSupportOperators $operators): int {
    $password = $this->secret('New independent support password (12-72 bytes; do not reuse the admin password)');
    $confirmation = $this->secret('Confirm independent support password');
    if (! is_string($password) || ! is_string($confirmation) || ! hash_equals($password, $confirmation)) {
        $this->error('Password confirmation failed; nothing changed.');

        return 1;
    }
    try {
        $username = $operators->enroll($this->argument('username'), $password);
    } finally {
        unset($password, $confirmation);
    }
    $this->info('Independent support authentication enrolled; admin credentials unchanged.');
    $this->line('Retained support login username: '.$username);

    return 0;
})->purpose('One-time independent support owner enrollment; passwords are entered privately');
