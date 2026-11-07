<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/../../scripts/staging/attempt-markers.php';

final class StagingAttemptPreservationTest extends TestCase
{
    public function test_known_failed_snapshot_does_not_override_later_started_marker(): void
    {
        $kit = sys_get_temp_dir().'/np-marker-test-'.bin2hex(random_bytes(8));
        mkdir($kit, 0700);
        mkdir($kit.'/known-v4-recovery', 0700);
        file_put_contents($kit.'/known-v4-recovery/database.sql', 'synthetic evidence');
        try {
            np_staging_require_fresh_attempt($kit);
            $this->assertFileExists($kit.'/known-v4-recovery/database.sql');
            foreach (['started', 'pass'] as $kind) {
                $marker = $kit.'/repair-chat-'.$kind.'-v5.json';
                file_put_contents($marker, '{"synthetic":true}');
                try {
                    np_staging_require_fresh_attempt($kit);
                    $this->fail('Replay accepted');
                } catch (RuntimeException $e) {
                    $this->assertSame('previous_attempt_preserve_'.basename($marker), $e->getMessage());
                    $this->assertFileExists($marker);
                }
                unlink($marker);
            }
        } finally {
            unlink($kit.'/known-v4-recovery/database.sql');
            rmdir($kit.'/known-v4-recovery');
            rmdir($kit);
        }
    }
}
