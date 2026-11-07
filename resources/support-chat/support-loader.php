<?php
declare(strict_types=1);
function support_core_dir(): string { return resource_path('support-chat/support'); }
function support_core_path(string $file): string {
    if (!preg_match('/\A[a-z0-9-]+\.php\z/D', $file)) throw new RuntimeException('Invalid private chat module.');
    $path = support_core_dir().'/'.$file;
    if (!is_file($path) || is_link($path)) throw new RuntimeException('Private chat module unavailable.');
    return $path;
}
