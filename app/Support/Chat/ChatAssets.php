<?php

namespace App\Support\Chat;

final class ChatAssets
{
    private ?array $manifest = null;

    public function url(string $path): string
    {
        if ($this->manifest === null) {
            $file = public_path('build/support-chat/manifest.json');
            $this->manifest = is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
        }
        if (preg_match('/\A[a-f0-9]{24}\z/D', $this->manifest['version'] ?? '')
            && in_array($path, $this->manifest['files'] ?? [], true)) {
            return '/build/support-chat/'.$this->manifest['version'].'/'.$path;
        }

        return '/support-chat/'.$path;
    }

    public function html(string $html): string
    {
        return preg_replace_callback('~([\x22\x27])((?:/support-chat/|\./)?(?:support-widget\.js|(?:operator/|support-client/|assets/fonts/|assets/icons/)[a-zA-Z0-9_./-]+\.(?:js|css|woff2|png|svg)))(?:\?[^\x22\x27]*)?\1~', function (array $match): string {
            $path = preg_replace('~^(?:/support-chat/|\./)~', '', $match[2]);

            return $match[1].$this->url($path).$match[1];
        }, $html);
    }
}
