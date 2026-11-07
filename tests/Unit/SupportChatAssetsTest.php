<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SupportChatAssetsTest extends TestCase
{
    public function test_every_local_javascript_import_and_stylesheet_resource_is_present(): void
    {
        $root = dirname(__DIR__, 2).'/resources/support-chat';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $checked = 0;
        foreach ($files as $file) {
            $extension = $file->getExtension();
            if (! in_array($extension, ['js', 'css'], true)) {
                continue;
            }
            $pattern = $extension === 'js' ? '/(?:from\s*|import\s*)[\'"]([.][.\/]?\/[^\'"]+)[\'"]/' : '/url\([\'"]?([^\'"\s)]+)[\'"]?\)/';
            preg_match_all($pattern, file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $reference) {
                if (str_starts_with($reference, 'data:') || str_starts_with($reference, '#') || str_starts_with($reference, 'http')) {
                    continue;
                }
                $path = explode('?', $reference)[0];
                $this->assertFileExists($file->getPath().'/'.$path, $file->getFilename().' requires '.$reference);
                $checked++;
            }
        }
        $this->assertGreaterThan(70, $checked);
        foreach (['support-notifications.php', 'support-recovery-email.php'] as $child) {
            $this->assertFileExists($root.'/tools/'.$child);
        }
    }
}
