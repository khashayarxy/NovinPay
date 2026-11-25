<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

final class LatinText
{
    public static function html(?string $text): HtmlString
    {
        $raw = (string) $text;
        if (preg_match('/<\/[A-Za-z]+>|<script\b/i', $raw) === 1) {
            return new HtmlString(e($raw));
        }

        $tokenAtom = '(?:[A-Za-z][A-Za-z0-9._\/+-]*|[0-9]+(?:\.[0-9]+)?[A-Za-z][A-Za-z0-9._\/+-]*|\([A-Za-z0-9][A-Za-z0-9._\/+\s-]{0,20}\))';
        $pattern = '/(?<![A-Za-z0-9])'.$tokenAtom.'(?:\s*(?:\/|\s)\s*(?:'.$tokenAtom.'|[0-9]+(?:[._\/-][A-Za-z0-9]+)*))*/';

        if (! preg_match_all($pattern, $raw, $matches, PREG_OFFSET_CAPTURE)) {
            return new HtmlString(e($raw));
        }

        $out = '';
        $cursor = 0;
        foreach ($matches[0] as [$token, $offset]) {
            if ($offset > $cursor) {
                $out .= e(substr($raw, $cursor, $offset - $cursor));
            }
            $out .= '<bdi class="np-latin" dir="ltr">'.e($token).'</bdi>';
            $cursor = $offset + strlen($token);
        }

        if ($cursor < strlen($raw)) {
            $out .= e(substr($raw, $cursor));
        }

        return new HtmlString($out);
    }
}
