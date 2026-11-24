<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

final class LatinText
{
    public static function html(?string $text): HtmlString
    {
        $raw = (string) $text;
        $pattern = '/(?<![A-Za-z0-9])[A-Za-z][A-Za-z0-9._\/-]*(?:\s+[A-Za-z0-9._\/-]+)*/';

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
