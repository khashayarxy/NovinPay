<?php

namespace App\Services;

use App\Services\Observability\ObservabilityEvents;

final class CustomerOtpSms
{
    public function __construct(private IpPanel $ipPanel) {}

    public function provider(): string
    {
        return 'ippanel';
    }

    public function enabled(): bool
    {
        return (bool) config('customer.ippanel.enabled');
    }

    public function configured(): bool
    {
        return $this->ipPanel->configured();
    }

    public function sendOutcome(string $phone, string $code): string
    {
        $outcome = $this->ipPanel->sendOutcome($phone, $code);
        $provider = $this->provider();
        dispatch(static function () use ($outcome, $provider): void {
            app(ObservabilityEvents::class)->sms('otp', $outcome, $provider, 'customer_otp');
        })->afterResponse();

        return $outcome;
    }
}
