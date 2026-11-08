<?php

namespace Tests\Feature;

use App\Support\Chat\ChatAssets;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ChatAssetPublicationTest extends TestCase
{
    public function test_preloads_and_inline_imports_resolve_to_the_same_versioned_public_asset(): void
    {
        $public = public_path();
        $temporary = sys_get_temp_dir().'/novinpay-static-test-'.bin2hex(random_bytes(12));
        mkdir($temporary.'/build/support-chat', 0700, true);
        file_put_contents($temporary.'/build/support-chat/manifest.json', json_encode(['version' => str_repeat('a', 24), 'files' => ['support-client/customer-v2.js']]));
        $this->app->usePublicPath($temporary);
        try {
            $html = '<link rel="modulepreload" href="support-client/customer-v2.js?v=old"><script>import(\'./support-client/customer-v2.js?v=old\')</script><script src="api.php"></script>';
            $result = app(ChatAssets::class)->html($html);
            $this->assertSame(2, substr_count($result, '/build/support-chat/'.str_repeat('a', 24).'/support-client/customer-v2.js'));
            $this->assertStringContainsString('src="api.php"', $result);
            $this->assertStringNotContainsString('?v=old', $result);
            $this->assertSame('/support-chat/support/bootstrap.php', app(ChatAssets::class)->url('support/bootstrap.php'));
        } finally {
            $this->app->usePublicPath($public);
            File::deleteDirectory($temporary);
        }
    }
}
