<?php

namespace Tests\Unit;

use App\Support\FinanceMoney;
use App\Support\FinanceTime;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FinancePresentationTest extends TestCase
{
    public static function amounts(): array
    {
        return [
            [0, '0.00'], [1, '0.01'], [-1, '−0.01'],
            [123456789, '1,234,567.89'], [-123456789, '−1,234,567.89'],
            ['9223372036854775807', '92,233,720,368,547,758.07'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_display_groups_exact_hundredths_without_float_conversion(int|string $minor, string $expected): void
    {
        $this->assertSame($expected, FinanceMoney::display($minor));
    }

    public function test_jalali_new_year_uses_tehran_time_without_mutating_utc_storage(): void
    {
        $before = CarbonImmutable::parse('2026-03-20T20:29:00Z');
        $after = $before->addMinute();
        $this->assertSame('۱۴۰۴/۱۲/۲۹ ۲۳:۵۹ (شمسی، تهران)', FinanceTime::display($before));
        $this->assertSame('۱۴۰۵/۰۱/۰۱ ۰۰:۰۰ (شمسی، تهران)', FinanceTime::display($after));
        $this->assertSame('2026-03-20 20:29:00 +00:00', $before->format('Y-m-d H:i:s P'));
        $this->assertSame('—', FinanceTime::display(null));
    }

    public function test_timestamp_display_is_independent_of_source_timezone(): void
    {
        $utc = CarbonImmutable::parse('2026-10-21T21:00:00Z');
        $this->assertSame(FinanceTime::display($utc), FinanceTime::display($utc->timezone('America/New_York')));
        $this->assertStringContainsString('۰۰:۳۰', FinanceTime::display($utc));
    }
}
