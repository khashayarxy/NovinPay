<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProductionReleaseInventoryTest extends TestCase
{
    public function test_production_history_must_match_before_computing_the_eight_pending_migrations(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/release/pr13-preflight.php';
        $baseline = array_map(fn ($i) => 'baseline_'.$i, range(1, 14));
        $added = array_map(fn ($i) => 'candidate_'.$i, range(1, 8));
        $this->assertSame($added, pr13MigrationDelta(array_reverse($baseline), $baseline, array_merge($baseline, $added)));
        $this->expectException(RuntimeException::class);
        pr13MigrationDelta(array_merge(array_slice($baseline, 1), ['unexpected']), $baseline, array_merge($baseline, $added));
    }

    public function test_candidate_cannot_replace_a_historical_migration(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/release/pr13-preflight.php';
        $baseline = array_map(fn ($i) => 'baseline_'.$i, range(1, 14));
        $candidate = array_merge(array_slice($baseline, 1), array_map(fn ($i) => 'candidate_'.$i, range(1, 9)));
        $this->expectException(RuntimeException::class);
        pr13MigrationDelta($baseline, $baseline, $candidate);
    }
}
