<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/../../scripts/staging/repair-chat-symfony-security-v3.php';

final class StagingSecurityManifestTest extends TestCase
{
    public function test_old_literal_reproduces_the_reported_pin_failure(): void
    {
        $old = '{"synthetic":"v1"}';
        $current = '{"synthetic":"v2"}';
        $this->assertFalse(hash_equals(hash('sha256', $current), hash('sha256', $old)));
    }

    public function test_executing_helper_selects_its_own_manifest_not_retained_versions(): void
    {
        foreach ([2, 3, 4] as $version) {
            $helper = '/synthetic/repair-chat-symfony-security-v'.$version.'.php';
            $raw = json_encode(['synthetic' => $version], JSON_THROW_ON_ERROR);
            $reads = [];
            $actual = sp_manifest($helper, hash('sha256', $raw), function ($path) use ($helper, $raw, &$reads) {
                $reads[] = $path;

                return $path === substr($helper, 0, -4).'.json' ? $raw : '{"synthetic":"old"}';
            });
            $this->assertSame(['synthetic' => $version], $actual);
            $this->assertSame([substr($helper, 0, -4).'.json'], $reads);
        }
    }

    public function test_mismatched_bytes_are_still_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('manifest_pin');
        sp_manifest('/synthetic/reviewed.php', hash('sha256', '{}'), fn () => '{"altered":true}');
    }

    public function test_invalid_pin_is_rejected_before_file_access(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('manifest_pin_format');
        sp_manifest('/synthetic/reviewed.php', 'not-a-sha256', function () {
            $this->fail('Invalid pin must not access files');
        });
    }

    public function test_manifest_is_checked_before_journal_fence_or_process_execution(): void
    {
        $source = file_get_contents(__DIR__.'/../../scripts/staging/repair-chat-symfony-security-v3.php');
        $pin = strpos($source, '$m = sp_manifest(');
        $this->assertNotFalse($pin);
        foreach (["\$phase = 'code_backup'", "\$phase = 'fence'", "\$phase = 'dependencies'"] as $later) {
            $this->assertLessThan(strpos($source, $later), $pin);
        }
        $this->assertStringNotContainsString("sp_read(KIT.'/repair-chat-symfony-security-v1.json')", $source);
        $this->assertStringContainsString("'helper' => basename(__FILE__)", $source);
    }
}
