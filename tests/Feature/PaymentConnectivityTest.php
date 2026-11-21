<?php

namespace Tests\Feature;

use App\Services\PaymentConnectivity;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PaymentConnectivityTest extends TestCase
{
    public function test_verified_snapshot_classifies_both_ip_families_and_rejects_nonpublic_inputs(): void
    {
        $this->travelToSnapshotMonth();
        foreach ([
            '5.160.0.1' => true, '2a01:5ec0::1' => true, '::ffff:5.160.0.1' => true,
            '8.8.8.8' => false, '2001:4860:4860::8888' => false,
            '127.0.0.1' => null, '10.0.0.23' => null, '2001:db8::1' => null, 'invalid' => null,
        ] as $ip => $result) {
            $this->assertSame($result, $this->check($ip), $ip);
        }
        $path = config('payments.connectivity.ranges_path');
        config(['payments.connectivity.ranges_path' => $path.'.missing']);
        $this->assertNull($this->check('5.160.0.1'));
        config(['payments.connectivity.ranges_path' => $path]);
        $this->travel(3)->months();
        $this->assertNull($this->check('5.160.0.1'));
    }

    public function test_forwarded_headers_are_only_used_from_explicit_proxy_peers(): void
    {
        $this->travelToSnapshotMonth();
        config(['payments.connectivity.trusted_proxies' => ['*', '0.0.0.0/0']]);
        $this->assertFalse($this->check('8.8.8.8', '5.160.0.1'));
        config(['payments.connectivity.trusted_proxies' => ['10.0.0.1', 'fd00::2']]);
        $this->assertTrue($this->check('10.0.0.1', '8.8.8.8, 5.160.0.1, fd00::2'));
        $this->assertFalse($this->check('10.0.0.1', '5.160.0.1, 8.8.8.8'));
        $this->assertNull($this->check('10.0.0.1', '5.160.0.1, unknown'));
        $this->assertNull($this->check('10.0.0.1'));
    }

    private function check(string $peer, string $forwarded = ''): ?bool
    {
        $request = Request::create('/payments/test/connect', server: [
            'REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => $forwarded, 'HTTP_CF_CONNECTING_IP' => '5.160.0.1',
        ]);

        return app(PaymentConnectivity::class)->isIran($request);
    }

    private function travelToSnapshotMonth(): void
    {
        $data = json_decode(file_get_contents(config('payments.connectivity.ranges_path')), true, flags: JSON_THROW_ON_ERROR);
        $this->travelTo(Carbon::parse($data['month'].'-15'));
    }
}
