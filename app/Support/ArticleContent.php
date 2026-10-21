<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/** A bounded Markdown subset parsed to an AST, never raw HTML. */
final class ArticleContent
{
    private static function invalid(): never
    {
        throw ValidationException::withMessages(['content' => 'ساختار متن معتبر نیست؛ فقط بند، تیتر ## یا ###، فهرست و پیوند http/https مجاز است.']);
    }

    public static function parse(string $source): array
    {
        if (mb_strlen($source) > 40000 || str_contains($source, '<') || str_contains($source, '>') || str_contains($source, '![')) {
            self::invalid();
        }
        $chunks = preg_split('/\n\s*\n/u', trim(str_replace(["\r\n", "\r"], "\n", $source)));
        if (! $chunks || count($chunks) > 100 || trim($source) === '') {
            self::invalid();
        }
        $blocks = [];
        foreach ($chunks as $chunk) {
            if (preg_match('/\A(#{2,3}) (.+)\z/u', $chunk, $heading)) {
                $blocks[] = ['type' => 'heading', 'level' => strlen($heading[1]), 'inlines' => self::inlines($heading[2])];
            } elseif (preg_match('/\A(?:- |[0-9]+\. )/', $chunk)) {
                $items = [];
                $ordered = ! str_starts_with($chunk, '- ');
                foreach (explode("\n", $chunk) as $line) {
                    if (! preg_match($ordered ? '/\A[0-9]+\. (.+)\z/u' : '/\A- (.+)\z/u', $line, $item) || count($items) >= 100) {
                        self::invalid();
                    }
                    $items[] = self::inlines($item[1]);
                }
                $blocks[] = ['type' => 'list', 'ordered' => $ordered, 'items' => $items];
            } else {
                $blocks[] = ['type' => 'paragraph', 'inlines' => self::inlines(str_replace("\n", ' ', $chunk))];
            }
        }

        return $blocks;
    }

    private static function inlines(string $text): array
    {
        preg_match_all('/\[[^\]\n]*\]\(([^)\n]*)\)/u', $text, $links);
        foreach ($links[1] as $url) {
            $parts = parse_url($url);
            if (! filter_var($url, FILTER_VALIDATE_URL) || ! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/', $url)) {
                self::invalid();
            }
        }
        preg_match_all('/\*\*[^*\n]+\*\*|\*[^*\n]+\*|\[[^\]\n]{1,200}\]\([^\s)]{1,2048}\)/u', $text, $matches, PREG_OFFSET_CAPTURE);
        $nodes = [];
        $cursor = 0;
        foreach ($matches[0] as [$token,$offset]) {
            if ($offset > $cursor) {
                $nodes[] = ['type' => 'text', 'text' => substr($text, $cursor, $offset - $cursor)];
            }
            if (str_starts_with($token, '**')) {
                $nodes[] = ['type' => 'strong', 'text' => substr($token, 2, -2)];
            } elseif (str_starts_with($token, '*')) {
                $nodes[] = ['type' => 'em', 'text' => substr($token, 1, -1)];
            } else {
                preg_match('/\A\[([^\]]+)\]\((.+)\)\z/u', $token, $link);
                $nodes[] = ['type' => 'link', 'text' => $link[1], 'url' => $link[2]];
            }
            $cursor = $offset + strlen($token);
        }
        if ($cursor < strlen($text)) {
            $nodes[] = ['type' => 'text', 'text' => substr($text, $cursor)];
        }

        return $nodes;
    }
}
