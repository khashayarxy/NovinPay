<?php

namespace App\Support;

use RuntimeException;

class MaintenanceStateFault extends RuntimeException
{
    public function __construct(string $code, public readonly bool $published = false)
    {
        parent::__construct($code);
    }
}
