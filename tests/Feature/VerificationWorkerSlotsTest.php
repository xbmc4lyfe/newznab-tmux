<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Releases\Verification\VerificationWorkerLocks;
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
