<?php

namespace Tests\Unit;

use App\Support\FaNumber;
use PHPUnit\Framework\TestCase;

class FaNumberTest extends TestCase
{
    public function test_missing_values_render_without_changing_number_formatting(): void
    {
        $this->assertSame('', FaNumber::digits(null));
        $this->assertSame('۰', FaNumber::digits(0));
        $this->assertSame('۰۹۱۲', FaNumber::digits('0912'));
    }
}
