<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReleaseCartSchemaContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_release_observer_accepts_the_reviewed_mariadb_cart_schema(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            || ! str_starts_with((string) DB::selectOne('SELECT VERSION() AS v')->v, '10.6.28-MariaDB')) {
            $this->markTestSkipped('Requires the disposable MariaDB 10.6.28 release-schema job.');
        }
        require_once base_path('scripts/release/recovery-contract.php');
        require_once base_path('scripts/release/reconcile-source.php');

        \NovinPay\Release\Postflight\verifyCartSchema(DB::connection()->getPdo());
        $this->assertTrue(DB::connection()->getPdo()->inTransaction());
    }
}
