<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

final class SmsIr
{
    /** Approval is an operator-attested prerequisite, not inferred from a send. */
    public function ready(mixed $template, mixed $parameter): bool
    {
        return $this->configured($template, $parameter)
            && in_array((int) $template, config('customer.approved_template_ids', []), true);
    }

    private function configured(mixed $template, mixed $parameter): bool
    {
        $key = config('customer.sms_api_key');

        return is_string($key) && $key !== '' && ! preg_match('/[\x00-\x20\x7f]/', $key)
            && (is_int($template) || is_string($template)) && ctype_digit((string) $template)
            && (int) $template > 0 && (int) $template <= 2147483647
            && is_string($parameter) && preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,39}\z/D', $parameter) === 1;
    }

    /** Read-only template metadata: never sends a message or changes approval. */
    public function inspect(mixed $template, mixed $parameter): array
    {
        if (! $this->configured($template, $parameter)) {
            return ['accessible' => false, 'reason' => 'configuration_incomplete'];
        }
        try {
            $response = Http::withoutRedirecting()->connectTimeout(4)->timeout(10)->acceptJson()
                ->withHeaders(['x-api-key' => config('customer.sms_api_key')])
                ->get('https://api.sms.ir/v1/templates/'.(int) $template);
            $data = $response->json('data');
            if (! $response->successful() || $response->json('status') !== 1 || ! is_array($data) || ($data['id'] ?? null) !== (int) $template) {
                return ['accessible' => false, 'reason' => 'provider_access_or_identity', 'http_status' => $response->status(), 'provider_status' => is_int($response->json('status')) ? $response->json('status') : null];
            }
            $parameters = $data['parameters'] ?? null;

            return [
                'accessible' => true,
                'exact_parameter_match' => is_array($parameters) && array_is_list($parameters) && count($parameters) === 1 && is_array($parameters[0]) && ($parameters[0]['name'] ?? null) === $parameter,
                'approval_status' => is_int($data['status'] ?? null) ? $data['status'] : null,
                // Numeric status is reported without inventing an enum meaning.
                // Approval must still be confirmed in the provider panel.
                'approval_attested' => in_array((int) $template, config('customer.approved_template_ids', []), true),
                'sending_executed' => false,
            ];
        } catch (\Throwable) {
            return ['accessible' => false, 'reason' => 'transport_or_response'];
        }
    }

    public function send(string $phone, mixed $template, mixed $parameter, string $value, string $purpose): bool
    {
        $enabled = match ($purpose) {
            'otp' => config('customer.sms_enabled'),
            'notification' => config('customer.notifications_enabled'),
            default => false,
        };
        if (! $enabled || ! $this->ready($template, $parameter) || preg_match('/\A09[0-9]{9}\z/D', $phone) !== 1) {
            return false;
        }
        try {
            // Parameter names are preserved byte-for-byte. The configured name
            // must match the provider's approved template, including its case.
            $response = Http::withoutRedirecting()->connectTimeout(4)->timeout(10)->acceptJson()
                ->withHeaders(['x-api-key' => config('customer.sms_api_key')])
                ->post('https://api.sms.ir/v1/send/verify', ['mobile' => $phone, 'templateId' => (int) $template, 'parameters' => [['name' => $parameter, 'value' => $value]]]);

            // An accepted request is not proof of delivery to the handset.
            return $response->successful() && $response->json('status') === 1
                && is_int($response->json('data.messageId')) && $response->json('data.messageId') > 0;
        } catch (\Throwable) {
            // Never log API keys, mobile numbers, OTPs, provider bodies or errors.
            // In particular, a transport timeout must not trigger an automatic retry.
            return false;
        }
    }
}
