<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Releases\Verification\VerificationWorkerLocks;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class VerificationWorkerSlotsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/slots-'.bin2hex(random_bytes(6)));
        config(['release_verification.storage_path' => $this->directory]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_configured_slots_are_honoured_beyond_eight_up_to_the_ceiling(): void
    {
        foreach ([[0, 1], [2, 2], [24, 24], [64, 64], [500, VerificationWorkerLocks::MAX_SLOTS]] as [$configured, $expected]) {
            config(['release_verification.candidate_workers' => $configured]);
            $this->assertSame($expected, VerificationWorkerLocks::slots());
        }
    }

    public function test_each_host_holds_only_its_configured_slot_range(): void
    {
        config(['release_verification.candidate_workers' => 2, 'release_verification.slot_offset' => 64]);
        $this->assertSame(65, VerificationWorkerLocks::firstSlot());
        $this->assertSame(66, VerificationWorkerLocks::lastSlot());
        $locks = [new VerificationWorkerLocks, new VerificationWorkerLocks, new VerificationWorkerLocks];
        try {
            $this->assertFalse($locks[0]->start(1));
            $this->assertFalse($locks[0]->start(67));
            $this->assertTrue($locks[0]->start());
            $this->assertTrue($locks[1]->start());
            $this->assertFalse($locks[2]->start());
            $this->assertFileExists($this->directory.'/locks/worker-65.lock');
            $this->assertFileExists($this->directory.'/locks/worker-66.lock');
        } finally {
            array_walk($locks, static fn (VerificationWorkerLocks $lock) => $lock->close());
        }
        config(['release_verification.candidate_workers' => 16, 'release_verification.slot_offset' => 224]);
        $this->assertSame(225, VerificationWorkerLocks::firstSlot());
        $this->assertSame(240, VerificationWorkerLocks::lastSlot());
        config(['release_verification.candidate_workers' => 64, 'release_verification.slot_offset' => 500]);
        $this->assertSame(193, VerificationWorkerLocks::firstSlot());
        $this->assertSame(VerificationWorkerLocks::MAX_SLOT_NUMBER, VerificationWorkerLocks::lastSlot());
        config(['release_verification.candidate_workers' => 16, 'release_verification.slot_offset' => 500]);
        $this->assertSame(241, VerificationWorkerLocks::firstSlot());
        $this->assertSame(VerificationWorkerLocks::MAX_SLOT_NUMBER, VerificationWorkerLocks::lastSlot());
    }

    public function test_a_worker_only_host_schedules_just_its_verification_slots(): void
    {
        config(['release_verification.worker_only' => true, 'release_verification.candidate_workers' => 3,
            'release_verification.slot_offset' => 128]);
        $this->app->instance(Schedule::class, $schedule = new Schedule);
        Facade::clearResolvedInstances();
        require base_path('routes/console.php');

        $commands = array_map(static fn ($event): string => (string) $event->command, $schedule->events());
        $this->assertCount(3, $commands);
        foreach ([129, 130, 131] as $index => $slot) {
            $this->assertStringContainsString('releases:verify-candidates', $commands[$index]);
            $this->assertStringContainsString('--slot='.$slot.' ', $commands[$index]);
        }

        config(['release_verification.worker_only' => false]);
        $this->app->instance(Schedule::class, $primary = new Schedule);
        Facade::clearResolvedInstances();
        require base_path('routes/console.php');
        $primaryCommands = array_map(static fn ($event): string => (string) ($event->command ?? $event->description), $primary->events());
        $this->assertCount(3, array_filter($primaryCommands, static fn (string $command): bool => str_contains($command, 'releases:verify-candidates')));
        $this->assertNotEmpty(array_filter($primaryCommands, static fn (string $command): bool => str_contains($command, 'releases:prepare-candidates')));
    }

    public function test_a_slot_above_eight_can_be_held_and_one_above_the_configured_count_cannot(): void
    {
        config(['release_verification.candidate_workers' => 12]);
        $twelfth = new VerificationWorkerLocks;
        $thirteenth = new VerificationWorkerLocks;
        try {
            $this->assertTrue($twelfth->start(12));
            $this->assertFalse($thirteenth->start(13));
        } finally {
            $twelfth->close();
            $thirteenth->close();
        }
    }
}
