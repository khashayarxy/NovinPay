<?php

namespace Tests\Unit;

use App\Support\AdminRepairMoney;
use PHPUnit\Framework\TestCase;

class AdminRepairMoneyTest extends TestCase
{
    public function test_thousands_conversion_is_exact_and_never_uses_floating_point(): void
    {
        $this->assertSame(300000, AdminRepairMoney::toman('300'));
        $this->assertSame(300000, AdminRepairMoney::toman('۳۰۰'));
        $this->assertSame(1000000, AdminRepairMoney::toman('۱٬۰۰۰'));
        $this->assertSame(123456, AdminRepairMoney::toman('123.456'));
        $this->assertSame(123456, AdminRepairMoney::toman('۱۲۳٬۴۵۶', 1));
        $this->assertSame('123.456', AdminRepairMoney::input(123456));
    }
}
