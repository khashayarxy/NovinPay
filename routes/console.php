<?php

use App\Models\PaymentAttempt;
use App\Services\CustomerNotifications;
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
    $this->info('Sent notifications: '.$notifications->sendPending());

    return 0;
})->purpose('Send configured customer notifications without exposing customer data');
Schedule::command('customers:notify')->everyMinute()->withoutOverlapping(10);
