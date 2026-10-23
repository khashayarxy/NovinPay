<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DatabaseTriggerAccess
{
    public static function verify(array $tables): void
    {
        $policy = DB::selectOne('SELECT @@global.log_bin AS logging, @@global.log_bin_trust_function_creators AS trusted');
        $grants = DB::select('SHOW GRANTS FOR CURRENT_USER');
        $schema = '`'.str_replace('`', '``', DB::connection()->getDatabaseName()).'`';
        $covered = [];
        $super = false;
        foreach ($grants as $row) {
            $grant = array_values((array) $row)[0];
            if (! preg_match('/\AGRANT (.+?) ON (.+?) TO /', $grant, $match)) {
                continue;
            }
            $privileges = array_map('trim', explode(',', $match[1]));
            $scope = str_replace(['\\_', '\\%'], ['_', '%'], $match[2]);
            $global = $scope === '*.*';
            $all = in_array('ALL PRIVILEGES', $privileges, true);
            $super = $super || ($global && ($all || in_array('SUPER', $privileges, true)));
            foreach ($tables as $table) {
                if (($global || in_array($scope, [$schema.'.*', $schema.'.`'.$table.'`'], true)) && ($all || in_array('TRIGGER', $privileges, true))) {
                    $covered[$table] = true;
                }
            }
        }
        if (count($covered) !== count($tables)) {
            throw new RuntimeException('Immutable history requires verifiable direct TRIGGER grants on every target table; request provider preflight.');
        }
        if ($policy->logging && ! $policy->trusted && ! $super) {
            throw new RuntimeException('History triggers are restricted by server logging policy; request provider preflight.');
        }
    }
}
