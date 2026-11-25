<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ZibalGateway
{
    public const START_URL = 'https://gateway.zibal.ir/start/';

    public const CALLBACK_PATH = '/payments/zibal/callback';

    public static function trackNumber(mixed $track): ?int
    {
        if ((! is_int($track) && ! is_string($track)) || ! preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $track)) {
            return null;
        }
        $number = filter_var($track, FILTER_VALIDATE_INT);

        return $number === false ? null : $number;
    }

    public function isLocalSimulator(): bool
    {
        return app()->isLocal() && ! $this->canVerify();
    }

    public function available(): bool
    {
        if ($this->isLocalSimulator()) {
            return true;
        }

        return config('payments.zibal.enabled') && $this->canVerify()
            && config('payments.zibal.callback_url') === rtrim(config('app.url'), '/').self::CALLBACK_PATH;
    }

    public function canVerify(): bool
    {
        $merchant = (string) config('payments.zibal.merchant');
        $sandboxAllowed = app()->environment(['local', 'testing'])
            && (bool) config('payments.zibal.sandbox', false)
            && strcasecmp($merchant, 'zibal') === 0;

        return config('payments.zibal.live_requests')
            && $merchant !== '' && trim($merchant) === $merchant
            && ($sandboxAllowed || strcasecmp($merchant, 'zibal') !== 0)
            && ! preg_match('/replace|placeholder|example/i', $merchant)
            && config('payments.zibal.api_base_url') === 'https://gateway.zibal.ir'
            && str_starts_with(config('app.url'), 'https://');
    }

    public function call(string $operation, array $payload): array
    {
        abort_unless(in_array($operation, ['request', 'verify', 'inquiry'], true), 422);
        abort_unless($operation === 'request' ? $this->available() : $this->canVerify(), 503, 'پرداخت آنلاین هنوز فعال نشده است.');
        if ($this->isLocalSimulator() && $operation === 'request') {
            return ['result' => 100, 'trackId' => random_int(10000000, 99999999)];
        }
        try {
            $response = Http::withoutRedirecting()->connectTimeout(8)->timeout(20)->acceptJson()->post('https://gateway.zibal.ir/v1/'.$operation, ['merchant' => config('payments.zibal.merchant')] + $payload);

            return $response->successful() && is_array($response->json()) ? $response->json() : [];
        } catch (\Throwable) {
            // Unknown network result is reconciled; never log merchant or provider response.
            return [];
        }
    }
}
