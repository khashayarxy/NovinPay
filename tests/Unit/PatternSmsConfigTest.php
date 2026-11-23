<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class PatternSmsConfigTest extends TestCase
{
    public function test_production_style_configuration_selects_ippanel_and_keeps_otp_separate(): void
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';
$notifications = require 'config/notifications.php';
$customer = require 'config/customer.php';
echo json_encode([
    $notifications['pattern_sms']['provider'],
    $notifications['pattern_sms']['enabled'],
    $customer['ippanel']['enabled'],
    $customer['ippanel']['api_key'] === 'fixture-key',
    $customer['ippanel']['sender'] === '+983000505',
    $customer['ippanel']['pattern_code'],
    $notifications['pattern_sms']['patterns']['order_confirmed'],
    $notifications['pattern_sms']['patterns']['order_preparing'],
    $notifications['pattern_sms']['patterns']['order_shipped'],
], JSON_THROW_ON_ERROR);
PHP;
        $process = new Process([PHP_BINARY, '-r', $code], dirname(__DIR__, 2), [
            'SMS_PROVIDER' => 'ippanel',
            'NOTIFICATIONS_PATTERN_SMS_PROVIDER' => '',
            'NOTIFICATIONS_PATTERN_SMS_ENABLED' => '',
            'IPPANEL_ENABLED' => 'true',
            'IPPANEL_API_KEY' => 'fixture-key',
            'IPPANEL_SENDER' => '+983000505',
            'IPPANEL_PATTERN_CODE' => 'fixtureOtpOnly',
            'NOTIFICATIONS_SMS_PATTERN_ORDER_CONFIRMED' => '',
            'NOTIFICATIONS_IPPANEL_PATTERN_ORDER_CONFIRMED' => '',
            'NOTIFICATIONS_PANELCHI_PATTERN_ORDER_CONFIRMED' => '',
            'NOTIFICATIONS_SMS_PATTERN_ORDER_PREPARING' => 'preferredOverride',
            'NOTIFICATIONS_IPPANEL_PATTERN_ORDER_PREPARING' => 'oldOverride',
            'NOTIFICATIONS_SMS_PATTERN_ORDER_SHIPPED' => '',
            'NOTIFICATIONS_IPPANEL_PATTERN_ORDER_SHIPPED' => '',
            'NOTIFICATIONS_PANELCHI_PATTERN_ORDER_SHIPPED' => 'legacyOverride',
            'SMSIR_ENABLED' => 'false',
            'SMSIR_NOTIFICATIONS_ENABLED' => 'false',
        ]);
        $process->mustRun();

        $this->assertSame(
            ['ippanel', null, true, true, true, 'fixtureOtpOnly', 'mfae2yqje8msw9e', 'preferredOverride', 'legacyOverride'],
            json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR),
        );
    }
}
