<?php

// Laravel's console exception renderer does not itself exit nonzero. Standalone
// CI harnesses must not let an uncaught assertion become a successful shell step.
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, json_encode(['result' => 'CONCURRENCY_HARNESS_STOP', 'exception' => $error::class], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
});
