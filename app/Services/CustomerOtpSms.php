<?php

namespace App\Services;

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
        return $this->ipPanel->sendOutcome($phone, $code);
    }
}
