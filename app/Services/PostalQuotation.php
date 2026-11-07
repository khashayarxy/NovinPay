<?php

namespace App\Services;

use App\Support\PostexQuote;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PostalQuotation
{
    private const CATALOGS = [
        'origin' => '/api/v1/locality/cities/from/all',
        'destination' => '/api/v1/locality/cities/to/all',
        'boxes' => '/api/v1/common/boxes',
        'services' => '/api/v1/shipping-methods',
    ];

    public function quote(array $origin, array $destination, int $weightGrams, array $dimensions): int
    {
        $quote = $this->estimate($origin, $destination, $weightGrams, $dimensions);

        return $quote->billingMinor($quote->requestDigest);
    }

    /** Quote only. Never register a parcel, request collection, buy a label or pay. */
    public function estimate(array $origin, array $destination, int $weightGrams, array $properties): PostexQuote
    {
        $this->configured();
        $properties = $this->parcel($weightGrams, $properties);
        $from = $this->city('origin', $origin);
        $to = $this->city('destination', $destination);
        $boxes = $this->catalog('boxes');
        if (! collect($boxes)->contains(fn ($box) => is_array($box) && ($box['id'] ?? null) === $properties['box_type_id'])) {
            $this->unavailable('unknown_box');
        }
        $services = $this->catalog('services');
        if (($services['isSuccess'] ?? null) !== true || ! is_array($services['data'] ?? null) || ! collect($services['data'])->contains(fn ($service) => is_array($service) && ($service['courierCode'] ?? null) === 'IR_POST' && ($service['courierServiceCode'] ?? null) === 'EXPRESS' && ($service['isActive'] ?? null) === true)) {
            $this->unavailable('unsupported_service');
        }
        $request = [
            'collection_type' => 'courier_drop_off',
            'from_city_code' => $from,
            'courier' => ['courier_code' => 'IR_POST', 'service_type' => 'EXPRESS'],
            'parcels' => [[
                'custom_parcel_id' => 'novinpay-quote',
                'to_city_code' => $to,
                'payment_type' => 'SENDER',
                'parcel_properties' => $properties,
            ]],
            'value_added_service' => ['request_label' => false, 'request_packaging' => false, 'request_sms_notification' => false],
        ];
        $startedAt = CarbonImmutable::now('UTC');
        $response = $this->send('/api/v1/shipping/quotes', $request);
        $amount = $this->price($response);
        $ttl = (int) config('postex.quote_ttl_seconds');
        if ($ttl < 1 || $ttl > 300) {
            $this->unavailable('invalid_expiry');
        }

        return new PostexQuote($amount, hash('sha256', json_encode($request, JSON_THROW_ON_ERROR)), $startedAt, $startedAt->addSeconds($ttl), 'IR_POST/EXPRESS', config('postex.independent_postage_verified') === true, trim((string) config('postex.tariff_evidence')));
    }

    private function configured(): void
    {
        $key = config('postex.api_key');
        if (config('postex.enabled') !== true || ! is_string($key) || $key === '' || preg_match('/[\x00-\x20\x7f]/', $key) || preg_match('/placeholder|replace|example/i', $key) || rtrim((string) config('postex.base_url'), '/') !== 'https://api.postex.ir') {
            $this->unavailable('disabled_or_unconfigured');
        }
    }

    private function parcel(int $weight, array $input): array
    {
        $values = ['length' => $input['length'] ?? null, 'width' => $input['width'] ?? null, 'height' => $input['height'] ?? null, 'total_weight' => $weight, 'total_value' => $input['declared_value_rial'] ?? null, 'box_type_id' => $input['box_type_id'] ?? null];
        foreach ($values as $name => $value) {
            $max = in_array($name, ['length', 'width', 'height', 'box_type_id'], true) ? 2147483647 : 999999999999;
            if (! is_int($value) || $value < 1 || $value > $max) {
                throw ValidationException::withMessages(['parcel' => 'ابعاد، وزن، ارزش ریالی و نوع جعبه باید معتبر و دقیق باشند.']);
            }
        }
        foreach (['is_fragile', 'is_liquid'] as $flag) {
            if (isset($input[$flag]) && ! is_bool($input[$flag])) {
                throw ValidationException::withMessages(['parcel' => 'ویژگی‌های بسته معتبر نیست.']);
            }
            $values[$flag] = $input[$flag] ?? false;
        }
        $values['total_value_currency'] = 'IRR';

        return $values;
    }

    private function city(string $direction, array $address): int
    {
        $city = $address['city'] ?? null;
        $province = $address['province'] ?? null;
        if (! is_string($city) || ! is_string($province) || trim($city) === '' || trim($province) === '' || mb_strlen($city) > 100 || mb_strlen($province) > 100 || preg_match('/[\x00-\x1f\x7f]/', $city.$province)) {
            throw ValidationException::withMessages(['address' => 'شهر و استان معتبر را انتخاب کنید.']);
        }
        $normalize = fn ($name) => trim(str_replace(['ي', 'ك'], ['ی', 'ک'], $name));
        $matches = array_values(array_filter($this->catalog($direction, $city), fn ($row) => is_array($row) && is_string($row['city_name'] ?? null) && is_string($row['province_name'] ?? null) && $normalize($row['city_name']) === $normalize($city) && $normalize($row['province_name']) === $normalize($province)));
        if (count($matches) !== 1 || ! is_int($matches[0]['city_id'] ?? null) || $matches[0]['city_id'] < 1 || $matches[0]['city_id'] > 2147483647) {
            $this->unavailable('unresolved_city');
        }

        return $matches[0]['city_id'];
    }

    private function catalog(string $name, ?string $keyword = null): array
    {
        $path = self::CATALOGS[$name];
        // Public catalog only; never cache credentials, customer addresses or quotes.
        $cacheKey = 'postex:catalog:v1:'.hash('sha256', $path.':'.$keyword.':'.hash('sha256', config('postex.api_key')));

        return Cache::store(config('postex.cache_store'))->remember($cacheKey, 3600, function () use ($path, $keyword, $name): array {
            $data = $this->send($path, null, $keyword);
            if ($name === 'services' ? (($data['isSuccess'] ?? null) !== true || ! is_array($data['data'] ?? null) || ! array_is_list($data['data'])) : ! array_is_list($data)) {
                $this->unavailable('catalog_shape');
            }

            return $data;
        });
    }

    private function send(string $path, ?array $payload = null, ?string $keyword = null): array
    {
        try {
            $http = Http::withoutRedirecting()->connectTimeout(5)->timeout(15)->acceptJson()->withHeaders(['x-api-key' => config('postex.api_key')]);
            $response = $payload === null ? $http->get('https://api.postex.ir'.$path, $keyword === null ? [] : ['keyword' => $keyword]) : $http->post('https://api.postex.ir'.$path, $payload);
        } catch (ConnectionException) {
            $this->unavailable('transport');
        }
        $body = $response->body();
        $data = strlen($body) <= 2097152 ? json_decode($body, true, 32, JSON_BIGINT_AS_STRING) : null;
        if (! $response->successful() || ! is_array($data)) {
            // Do not retain provider messages, request/response bodies or headers.
            $this->unavailable('http_or_json', ['http_status' => $response->status(), 'json_type' => get_debug_type($data)]);
        }

        return $data;
    }

    private function price(array $data): int
    {
        $known = ['parcel_count', 'currency', 'pickup_price', 'shipping_price', 'shipping_price_vat', 'value_added_service_price', 'total_cost', 'shipping_prices', 'excluded_services', 'shipping_price_discount'];
        if (array_diff(array_keys($data), $known) !== []) {
            $this->unavailable('unfamiliar_quote', ['field_count' => count($data)]);
        }
        foreach (['shipping_price', 'shipping_price_vat', 'shipping_price_discount'] as $field) {
            if (! is_int($data[$field] ?? null) || $data[$field] < 0 || $data[$field] > 999999999999) {
                $this->unavailable('quote_money_type');
            }
        }
        $parcels = $data['shipping_prices'] ?? null;
        if (($data['currency'] ?? null) !== 'IRR' || ($data['parcel_count'] ?? null) !== 1 || ! is_array($parcels) || ! array_is_list($parcels) || count($parcels) !== 1 || ! is_array($parcels[0]) || ($parcels[0]['custom_parcel_id'] ?? null) !== 'novinpay-quote' || ($data['pickup_price'] ?? null) !== 0 || ($data['value_added_service_price'] ?? null) !== 0 || ($data['excluded_services'] ?? null) !== [] || ($parcels[0]['value_added_service_price'] ?? null) !== []) {
            $this->unavailable('quote_shape');
        }
        $services = $parcels[0]['service_price'] ?? null;
        if (! is_array($services) || ! array_is_list($services) || count($services) !== 1 || ! is_array($services[0]) || ($services[0]['courierCode'] ?? null) !== 'IR_POST' || ($services[0]['serviceType'] ?? null) !== 'EXPRESS') {
            $this->unavailable('quote_service');
        }
        $amount = $data['total_cost'] ?? null;
        // Do not guess which of several price/tax/discount fields is payable.
        if (! is_int($amount) || $amount < 1 || $amount > 999999999999 || ($parcels[0]['total_shipping_price'] ?? null) !== $amount || ($services[0]['totalPrice'] ?? null) !== $amount) {
            $this->unavailable('quote_amount');
        }

        return $amount;
    }

    private function unavailable(string $reason, array $diagnostic = []): never
    {
        Log::warning('Postex quote unavailable', ['reason' => $reason] + $diagnostic);
        throw new HttpException(503, 'استعلام معتبر هزینهٔ پست در دسترس نیست؛ مبلغی به سند اضافه نشد.');
    }
}
