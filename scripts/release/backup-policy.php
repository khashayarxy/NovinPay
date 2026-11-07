<?php

declare(strict_types=1);

// Owner decision, 2026-11-07. No environment or per-release age override.
// Cadence is policy data, not an instruction to start a backup or provision a DB.
return [
    'intervalSeconds' => 72 * 3600,
    'maxSnapshotAgeSeconds' => 72 * 3600,
];
