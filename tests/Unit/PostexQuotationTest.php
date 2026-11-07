<?php

namespace Tests\Unit;

use App\Services\PostalQuotation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PostexQuotationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['postex.enabled' => true, 'postex.api_key' => 'synthetic-test-key', 'postex.base_url' => 'https://api.postex.ir', 'postex.cache_store' => 'array', 'postex.independent_postage_verified' => false, 'postex.tariff_evidence' => '']);
        Cache::store('array')->flush();
        Http::preventStrayRequests();
    }

    private function response(): array
    {
        // Contract shape and public tariff observed in the authorized synthetic
        // quote on 2026-11-03; this fixture is never a production rate fallback.
        return ['parcel_count' => 1, 'currency' => 'IRR', 'pickup_price' => 0, 'shipping_price' => 1126400, 'shipping_price_vat' => 0, 'value_added_service_price' => 0, 'total_cost' => 1126400, 'shipping_price_discount' => 0, 'excluded_services' => [], 'shipping_prices' => [['custom_parcel_id' => 'novinpay-quote', 'shipping_price' => 1126400, 'shipping_price_vat' => 0, 'total_shipping_price' => 1126400, 'value_added_service_price' => [], 'service_price' => [['courierCode' => 'IR_POST', 'serviceType' => 'EXPRESS', 'totalPrice' => 1126400, 'initPrice' => 1126400, 'vat' => 87000, 'discountAmount' => 0]]]]];
    }

    private function fake(?array $quote = null, ?array $origin = null): void
    {
        Http::fake([
            'api.postex.ir/api/v1/locality/cities/from/all*' => Http::response($origin ?? [['city_id' => 9, 'city_name' => 'شهریار', 'province_name' => 'تهران']]),
            'api.postex.ir/api/v1/locality/cities/to/all*' => Http::response([['city_id' => 1, 'city_name' => 'تهران', 'province_name' => 'تهران']]),
            'api.postex.ir/api/v1/common/boxes' => Http::response([['id' => 7, 'length' => 20, 'width' => 20, 'height' => 30]]),
            'api.postex.ir/api/v1/shipping-methods' => Http::response(['isSuccess' => true, 'data' => [['courierCode' => 'IR_POST', 'courierServiceCode' => 'EXPRESS', 'isActive' => true]]]),
            'api.postex.ir/api/v1/shipping/quotes' => Http::response($quote ?? $this->response()),
        ]);
    }

    private function estimate(array $properties = [])
    {
        return app(PostalQuotation::class)->estimate(['province' => 'تهران', 'city' => 'شهریار', 'address' => 'PRIVATE ADDRESS NOT SENT'], ['province' => 'تهران', 'city' => 'تهران', 'phone' => 'PRIVATE PHONE NOT SENT'], 1000, $properties + ['length' => 30, 'width' => 20, 'height' => 20, 'declared_value_rial' => 10000000, 'box_type_id' => 7]);
    }

    public function test_quote_request_uses_documented_units_and_only_non_mutating_endpoints(): void
    {
        $this->fake();
        $quote = $this->estimate();
        $this->assertSame(1126400, $quote->amountRial);
        $this->assertSame('IR_POST/EXPRESS', $quote->service);
        $this->assertSame(300, (int) $quote->quotedAt->diffInSeconds($quote->expiresAt));
        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST') {
                return false;
            }
            $payload = $request->data();

            return $request->url() === 'https://api.postex.ir/api/v1/shipping/quotes'
                && $request->hasHeader('x-api-key', 'synthetic-test-key')
                && $payload['collection_type'] === 'courier_drop_off'
                && $payload['from_city_code'] === 9 && $payload['parcels'][0]['to_city_code'] === 1
                && $payload['parcels'][0]['payment_type'] === 'SENDER'
                && $payload['parcels'][0]['parcel_properties']['total_weight'] === 1000
                && $payload['parcels'][0]['parcel_properties']['total_value'] === 10000000
                && $payload['parcels'][0]['parcel_properties']['total_value_currency'] === 'IRR'
                && $payload['value_added_service'] === ['request_label' => false, 'request_packaging' => false, 'request_sms_notification' => false]
                && ! str_contains(json_encode($payload), 'PRIVATE');
        });
        Http::assertSentCount(5);
    }

    public function test_verified_api_price_cannot_be_billed_as_independent_counter_postage(): void
    {
        $this->fake();
        $quote = $this->estimate();
        $this->expectException(HttpException::class);
        $quote->billingMinor($quote->requestDigest);
    }

    public function test_confirmed_applicability_converts_rials_exactly_and_rejects_expired_quote(): void
    {
        $this->fake();
        config(['postex.independent_postage_verified' => true, 'postex.tariff_evidence' => 'Synthetic applicability evidence']);
        $quote = $this->estimate();
        $this->assertSame(11264000, $quote->billingMinor($quote->requestDigest));
        $this->travel(300)->seconds();
        $this->expectException(HttpException::class);
        $quote->billingMinor($quote->requestDigest);
    }

    public function test_quote_cannot_be_used_for_a_different_parcel_or_route(): void
    {
        $this->fake();
        config(['postex.independent_postage_verified' => true, 'postex.tariff_evidence' => 'Synthetic evidence']);
        $quote = $this->estimate();
        $this->expectException(HttpException::class);
        $quote->billingMinor(hash('sha256', 'different parcel or route'));
    }

    public static function configurationFailures(): array
    {
        return [['postex.enabled', false], ['postex.api_key', ''], ['postex.api_key', "key\r\nInjected: secret"], ['postex.base_url', 'https://other.example'], ['postex.api_key', 'replace-me']];
    }

    #[DataProvider('configurationFailures')]
    public function test_unconfigured_or_untrusted_host_never_receives_credentials(string $name, mixed $value): void
    {
        config([$name => $value]);
        try {
            $this->estimate();
            $this->fail('Unconfigured integration accepted');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
            Http::assertNothingSent();
        }
    }

    public static function invalidParcels(): array
    {
        return [[['length' => 0]], [['width' => -1]], [['height' => 2.5]], [['box_type_id' => '7']], [['declared_value_rial' => 0]], [['declared_value_rial' => 1.5]], [['is_fragile' => 'false']]];
    }

    #[DataProvider('invalidParcels')]
    public function test_invalid_parcel_is_rejected_before_network(array $properties): void
    {
        try {
            $this->estimate($properties);
            $this->fail('Invalid parcel accepted');
        } catch (ValidationException) {
            Http::assertNothingSent();
        }
    }

    public static function invalidQuotes(): array
    {
        return [['currency', 'TOMAN'], ['total_cost', 0], ['total_cost', 1126400.5], ['total_cost', '1126400'], ['total_cost', 9999999999999999], ['pickup_price', 1], ['value_added_service_price', 1], ['parcel_count', 2], ['excluded_services', [['message' => 'Unverified service']]]];
    }

    #[DataProvider('invalidQuotes')]
    public function test_unknown_or_ambiguous_quote_fails_closed(string $field, mixed $value): void
    {
        $data = $this->response();
        $data[$field] = $value;
        $this->fake($data);
        $this->expectException(HttpException::class);
        $this->estimate();
    }

    public function test_service_and_parcel_response_must_match_request(): void
    {
        $data = $this->response();
        $data['shipping_prices'][0]['service_price'][0]['courierCode'] = 'UNREQUESTED';
        $this->fake($data);
        $this->expectException(HttpException::class);
        $this->estimate();
    }

    public function test_quote_is_recalculated_while_only_catalogs_are_cached(): void
    {
        $this->fake();
        $this->estimate();
        $this->estimate();
        Http::assertSentCount(6);
        $this->assertCount(2, Http::recorded(fn ($request) => $request->method() === 'POST'));
    }

    public function test_unfamiliar_price_field_is_not_silently_accepted(): void
    {
        $data = $this->response();
        $data['unfamiliar_price'] = 100;
        $this->fake($data);
        $this->expectException(HttpException::class);
        $this->estimate();
    }

    public function test_missing_city_identifier_is_a_safe_unavailable_response(): void
    {
        $this->fake(origin: [['city_name' => 'شهریار', 'province_name' => 'تهران']]);
        $this->expectException(HttpException::class);
        $this->estimate();
    }

    public function test_city_mapping_rejects_ambiguous_name_instead_of_selecting_first(): void
    {
        $this->fake(origin: [['city_id' => 9, 'city_name' => 'شهریار', 'province_name' => 'تهران'], ['city_id' => 999, 'city_name' => 'شهریار', 'province_name' => 'تهران']]);
        $this->expectException(HttpException::class);
        $this->estimate();
    }

    public function test_http_error_never_logs_secret_or_provider_body_and_never_falls_back(): void
    {
        Log::shouldReceive('warning')->once()->with('Postex quote unavailable', ['reason' => 'http_or_json', 'http_status' => 401, 'json_type' => 'array']);
        Http::fake(['api.postex.ir/*' => Http::response(['message' => 'synthetic-test-key PRIVATE CUSTOMER INFORMATION'], 401)]);
        $this->expectException(HttpException::class);
        $this->estimate();
    }

    public function test_network_failure_is_bounded_and_not_retried(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('PRIVATE TRANSPORT DETAILS');
        });
        try {
            $this->estimate();
            $this->fail('Network failure accepted');
        } catch (HttpException $e) {
            $this->assertSame(1, $calls);
            $this->assertSame(503, $e->getStatusCode());
            $this->assertStringNotContainsString('PRIVATE', $e->getMessage());
        }
    }
}
