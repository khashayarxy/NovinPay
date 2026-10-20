<?php

namespace App\Models\Concerns;

use LogicException;

trait ImmutableRepairRecord
{
    protected static function bootImmutableRepairRecord(): void
    {
        static::updating(fn () => throw new LogicException('Repair history is append-only.'));
        static::deleting(fn () => throw new LogicException('Repair history is append-only.'));
    }
}
