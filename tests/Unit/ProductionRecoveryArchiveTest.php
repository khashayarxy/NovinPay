<?php

namespace Tests\Unit;

use NovinPay\Release\GateFailure;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class ProductionRecoveryArchiveTest extends TestCase
{
    public function test_private_artifact_extraction_is_complete_and_cannot_overwrite_a_previous_attempt(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/release/pr13-recovery.php';
        $root = sys_get_temp_dir().'/np-pr13-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $zip = new ZipArchive;
        $zip->open($root.'/fixture.zip', ZipArchive::CREATE | ZipArchive::EXCL);
        $zip->addFromString('nested/file.txt', 'synthetic artifact');
        $zip->close();
        try {
            pr13ExtractPrivate($root.'/fixture.zip', $root.'/out');
            $this->assertSame('synthetic artifact', file_get_contents($root.'/out/nested/file.txt'));
            $this->expectException(GateFailure::class);
            pr13ExtractPrivate($root.'/fixture.zip', $root.'/out');
        } finally {
            unlink($root.'/out/nested/file.txt');
            rmdir($root.'/out/nested');
            rmdir($root.'/out');
            unlink($root.'/fixture.zip');
            rmdir($root);
        }
    }

    public function test_traversal_and_drive_paths_are_rejected_without_creating_the_member(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/release/pr13-recovery.php';
        foreach (['../escape.txt', '/absolute.txt', 'C:/drive.txt', 'nested/../escape.txt'] as $name) {
            $root = sys_get_temp_dir().'/np-pr13-'.bin2hex(random_bytes(8));
            mkdir($root, 0700);
            $zip = new ZipArchive;
            $zip->open($root.'/fixture.zip', ZipArchive::CREATE | ZipArchive::EXCL);
            $zip->addFromString($name, 'synthetic');
            $zip->close();
            try {
                try {
                    pr13ExtractPrivate($root.'/fixture.zip', $root.'/out');
                    $this->fail('Unsafe archive path accepted');
                } catch (GateFailure $error) {
                    $this->assertSame('zip_member_path', $error->check);
                }
                $this->assertSame(['.', '..'], scandir($root.'/out'));
            } finally {
                rmdir($root.'/out');
                unlink($root.'/fixture.zip');
                rmdir($root);
            }
        }
    }
}
