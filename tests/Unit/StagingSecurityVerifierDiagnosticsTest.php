<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class StagingSecurityVerifierDiagnosticsTest extends TestCase
{
    public function test_controlled_http_labels_with_hyphens_remain_reportable(): void
    {
        $label = 'http_503__support-chat_support-widget_php';
        $this->assertSame(0, preg_match('/\A[a-zA-Z0-9_]+\z/', $label));
        $this->assertSame(1, preg_match('/\A[a-zA-Z0-9_-]+\z/D', $label));
        $this->assertSame(0, preg_match('/\A[a-zA-Z0-9_-]+\z/D', '/home/private/credentials password=value'));
        $source = file_get_contents(__DIR__.'/../../scripts/staging/verify-chat-security-v2.php');
        $this->assertStringContainsString('[a-zA-Z0-9_-]', $source);
        foreach (['security_last_response', 'privateDiagnosticSaved', 'completedChecks', 'exceptionClass', 'private_configuration_disclosure', 'widget_interface', 'basic_protection', 'scoped_web_opcache'] as $required) {
            $this->assertStringContainsString($required, $source);
        }
        $this->assertStringNotContainsString('echo $e->getMessage()', $source);
    }
}
