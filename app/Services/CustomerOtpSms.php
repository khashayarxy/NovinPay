<?php

namespace App\Services;

final class CustomerOtpSms
{
    public function __construct(private SmsIr $smsIr, private IpPanel $ipPanel) {}

    public function provider(): string
    {
        return match (config('customer.sms_provider')) {
            'smsir' => 'smsir',
            'ippanel' => 'ippanel',
            default => 'unsupported',
        };
    }

    public function enabled(): bool
    {
        return match ($this->provider()) {
            'smsir' => (bool) config('customer.sms_enabled'),
            'ippanel' => (bool) config('customer.ippanel.enabled'),
            default => false,
        };
    }

    public function configured(): bool
    {
        return match ($this->provider()) {
            'smsir' => $this->smsIr->ready(config('customer.otp_template'), config('customer.otp_parameter')),
            'ippanel' => $this->ipPanel->configured(),
            default => false,
        };
    }

    public function send(string $phone, string $code): bool
    {
        return match ($this->provider()) {
            'smsir' => $this->smsIr->send($phone, config('customer.otp_template'), config('customer.otp_parameter'), $code, 'otp'),
            'ippanel' => $this->ipPanel->send($phone, $code),
            default => false,
        };
    }
}
