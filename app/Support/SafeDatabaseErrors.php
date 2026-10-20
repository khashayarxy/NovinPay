<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Throwable;

final class SafeDatabaseErrors
{
    public static function query(Throwable $exception): ?QueryException
    {
        do {
            if ($exception instanceof QueryException) {
                return $exception;
            }
            $exception = $exception->getPrevious();
        } while ($exception);

        return null;
    }
}
