<?php

namespace App\Services\Observability;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ObservabilityMetrics
{
    public const SMS_CATEGORIES = ['otp', 'order', 'repair', 'alert', 'other'];

    public function dayKey(?Carbon $date = null): string
    {
        return ($date ?? now('Asia/Tehran'))->format('Y-m-d');
    }

    public function increment(string $metric, int $by = 1, ?string $day = null): void
    {
        $key = $this->cacheKey($day ?? $this->dayKey(), $metric);
        try {
            $current = (int) Cache::get($key, 0);
            Cache::put($key, $current + $by, now()->addDays(7));
        } catch (Throwable) {
            // Metrics must never interrupt application requests.
        }
    }

    public function get(string $metric, ?string $day = null): int
    {
        try {
            return (int) Cache::get($this->cacheKey($day ?? $this->dayKey(), $metric), 0);
        } catch (Throwable) {
            return 0;
        }
    }

    public function recordSms(string $category, string $outcome, string $provider, string $event = ''): void
    {
        $normalized = in_array($category, self::SMS_CATEGORIES, true) ? $category : 'other';
        $isSuccess = $outcome === 'accepted';
        $statusKey = $isSuccess ? 'sent' : 'failed';

        $this->increment("sms.{$normalized}.{$statusKey}");
        $this->increment("sms.total.{$statusKey}");

        try {
            Log::channel('sms')->log(
                $isSuccess ? 'info' : 'warning',
                'SMS dispatch recorded',
                [
                    'category' => $normalized,
                    'event' => $event !== '' ? $event : $normalized,
                    'provider' => $provider,
                    'outcome' => $outcome,
                ]
            );
        } catch (Throwable) {
        }
    }

    /**
     * @return array{otp: int, order: int, repair: int, alert: int, other: int, sent: int, failed: int, total: int}
     */
    public function smsBreakdown(?string $day = null): array
    {
        $targetDay = $day ?? $this->dayKey();
        $result = [];
        $sentTotal = 0;
        $failedTotal = 0;

        foreach (self::SMS_CATEGORIES as $category) {
            $sent = $this->get("sms.{$category}.sent", $targetDay);
            $failed = $this->get("sms.{$category}.failed", $targetDay);
            $result[$category] = $sent + $failed;
            $sentTotal += $sent;
            $failedTotal += $failed;
        }

        $result['sent'] = $sentTotal;
        $result['failed'] = $failedTotal;
        $result['total'] = $sentTotal + $failedTotal;

        return $result;
    }

    /**
     * @return array{http_500: int, client_js: int, slow_requests: int, slow_queries: int, user_logins: int, user_registrations: int}
     */
    public function summary(?string $day = null): array
    {
        $targetDay = $day ?? $this->dayKey();

        return [
            'http_500' => $this->get('errors.http_500', $targetDay),
            'client_js' => $this->get('errors.client_js', $targetDay),
            'slow_requests' => $this->get('performance.slow_requests', $targetDay),
            'slow_queries' => $this->get('performance.slow_queries', $targetDay),
            'user_logins' => $this->get('users.login', $targetDay),
            'user_registrations' => $this->get('users.registered', $targetDay),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function storeLatestBackup(array $snapshot): void
    {
        try {
            Cache::put('obs:backup:latest', $snapshot, now()->addDays(14));
        } catch (Throwable) {
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestBackup(): ?array
    {
        try {
            $val = Cache::get('obs:backup:latest');

            return is_array($val) ? $val : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function cacheKey(string $day, string $metric): string
    {
        return "obs:metrics:{$day}:{$metric}";
    }
}
