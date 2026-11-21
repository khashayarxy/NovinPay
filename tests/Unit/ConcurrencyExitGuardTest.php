<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ConcurrencyExitGuardTest extends TestCase
{
    public function test_uncaught_standalone_assertion_cannot_be_rendered_as_a_successful_ci_step(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'np-exit-guard-');
        try {
            file_put_contents($file, '<?php set_exception_handler(function ($e) {}); require '
                .var_export(dirname(__DIR__).'/Concurrency/exit-guard.php', true)
                .'; throw new RuntimeException("synthetic-private-payload");');
            $pipes = [];
            $process = proc_open([PHP_BINARY, $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(1, proc_close($process));
            $this->assertSame('', $stdout);
            $this->assertSame('CONCURRENCY_HARNESS_STOP', json_decode($stderr, true, 8, JSON_THROW_ON_ERROR)['result']);
            $this->assertStringNotContainsString('synthetic-private-payload', $stderr);
        } finally {
            unlink($file);
        }
    }
}
