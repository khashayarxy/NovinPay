<?php

namespace App\Services;

use App\Models\PaymentAttempt;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class OperationalReadiness
{
    private const HEARTBEAT = 'operations.scheduler.heartbeat';

    public function heartbeat(): void
    {
        Cache::put(self::HEARTBEAT, now()->getTimestamp(), now()->addDay());
    }

    /** Private CLI counters only; never return identifiers or provider responses. */
    public function status(): array
    {
        $heartbeat = Cache::get(self::HEARTBEAT);
        $age = is_int($heartbeat) ? now()->getTimestamp() - $heartbeat : null;
        $locks = Cache::getStore() instanceof LockProvider && ! in_array(config('cache.default'), ['array', 'null'], true);
        $due = PaymentAttempt::query()->whereIn('state', ['requesting', 'pending', 'uncertain', 'processing', 'verified'])
            ->where(fn ($q) => $q->whereNull('retry_at')->orWhere('retry_at', '<=', now()))
            ->where('created_at', '<=', now()->subMinutes((int) config('operations.queue_stale_minutes')))->count();
        $notifications = DB::table('customer_notifications')->whereIn('state', ['pending', 'sending'])
            ->where('created_at', '<=', now()->subMinutes((int) config('operations.queue_stale_minutes')))->count();

        return [
            'scheduler_recent' => $age !== null && $age >= 0 && $age <= (int) config('operations.heartbeat_max_age_seconds'),
            'heartbeat_age_seconds' => $age,
            'cross_process_cache_locks' => $locks,
            'stale_payment_attempts' => $due,
            'payment_review_count' => PaymentAttempt::query()->where('state', 'review')->count(),
            'stale_notifications' => $notifications,
            'notification_review_count' => DB::table('customer_notifications')->where('state', 'review')->count(),
            'payment_worker_enabled' => (bool) config('operations.scheduled_payments'),
            'notification_worker_enabled' => (bool) config('operations.scheduled_notifications'),
            'provider_requests_executed' => false,
        ];
    }
}
