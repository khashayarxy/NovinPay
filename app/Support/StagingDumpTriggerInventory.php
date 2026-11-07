<?php

namespace App\Support;

use RuntimeException;

/** Strict inventory of the executable CREATE TRIGGER blocks emitted by mariadb-dump. */
final class StagingDumpTriggerInventory
{
    public static function verify(string $sql, array $expected): array
    {
        $id = '`?([A-Za-z0-9_]+)`?';
        $pattern = '~^/\*!\d+ CREATE\*/[ \t]+/\*!\d+ DEFINER=`([^`]+)`@`([^`]+)`\*/[ \t]+/\*!\d+ TRIGGER '.$id.' (BEFORE|AFTER) (INSERT|UPDATE|DELETE) ON '.$id.' FOR EACH ROW (.*?) \r?\n\*/;;[ \t]*\r?$~ims';
        preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER);
        preg_match_all('~^(?:/\*!\d+ CREATE\*/[^\r\n]*\bTRIGGER\b|CREATE[^\r\n]*\bTRIGGER\b)~mi', $sql, $headers);
        if (count($matches) !== count($expected) || count($headers[0]) !== count($matches)) {
            throw new RuntimeException('dump_trigger_count_or_format');
        }
        $found = [];
        foreach ($matches as $m) {
            $name = $m[3];
            $actual = ['table' => $m[6], 'event' => strtoupper($m[5]), 'timing' => strtoupper($m[4]),
                'bodySha256' => hash('sha256', $m[7]), 'definer' => $m[1].'@'.$m[2]];
            if (isset($found[$name]) || ! isset($expected[$name])) {
                throw new RuntimeException('dump_trigger_name');
            }
            foreach ($actual as $key => $value) {
                if (($expected[$name][$key] ?? null) !== $value) {
                    throw new RuntimeException('dump_trigger_'.$key.'_'.$name);
                }
            }
            $found[$name] = $actual;
        }
        ksort($found);

        return $found;
    }
}
