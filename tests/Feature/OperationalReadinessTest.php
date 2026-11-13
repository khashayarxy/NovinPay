<?php

namespace Tests\Feature;

use App\Services\OperationalReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class OperationalReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_heartbeat_distinguishes_missing_recent_and_stale_without_provider_calls(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $operations = app(OperationalReadiness::class);
        $this->assertFalse($operations->status()['scheduler_recent']);
        $operations->heartbeat();
        $status = $operations->status();
        $this->assertTrue($status['scheduler_recent']);
        $this->assertFalse($status['cross_process_cache_locks']); // Array cache cannot coordinate cron processes.
        $this->assertFalse($status['payment_worker_enabled']);
        $this->assertFalse($status['notification_worker_enabled']);
        $this->travel(181)->seconds();
        $this->assertFalse($operations->status()['scheduler_recent']);
        $this->artisan('operations:status')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_provider_schedules_require_explicit_opt_in_and_have_overlap_locks(): void
    {
        $this->artisan('schedule:list', ['--json' => true])->assertSuccessful();
        $commands = collect(Schedule::events())->filter(fn ($event) => isset($event->command)
            && (str_contains($event->command, 'payments:reconcile') || str_contains($event->command, 'customers:notify')));
        $this->assertCount(2, $commands);
        foreach ($commands as $event) {
            $this->assertTrue($event->withoutOverlapping);
            $this->assertFalse($event->filtersPass($this->app));
        }
    }
}
