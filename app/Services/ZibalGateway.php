<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ZibalGateway
{
    public function available(): bool
    {
        $merchant = (string) config('payments.zibal.merchant');

        return config('payments.zibal.enabled') && config('payments.zibal.live_requests')
            && $merchant !== '' && ! preg_match('/replace|placeholder|example/i', $merchant)
            && config('payments.zibal.api_base_url') === 'https://gateway.zibal.ir'
            && config('payments.zibal.callback_url') === rtrim(config('app.url'), '/').'/bot/payment_verify.php'
            && str_starts_with(config('app.url'), 'https://');
    }

    public function call(string $operation, array $payload): array
    {
        abort_unless($this->available(), 503, 'پرداخت آنلاین هنوز فعال نشده است.');
        abort_unless(in_array($operation, ['request', 'verify', 'inquiry'], true), 422);
        try {
            $response = Http::withoutRedirecting()->connectTimeout(8)->timeout(20)->acceptJson()->post('https://gateway.zibal.ir/v1/'.$operation, ['merchant' => config('payments.zibal.merchant')] + $payload);

            return $response->successful() && is_array($response->json()) ? $response->json() : [];
        } catch (\Throwable) {
            // Unknown network result is reconciled; never log merchant or provider response.
            return [];
        }
    }
}
