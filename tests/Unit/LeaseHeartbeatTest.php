<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\LeaseHeartbeat;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LeaseHeartbeatTest extends TestCase
{
    public function test_progress_calls_coalesce_but_renew_at_the_interval(): void
    {
        $time = 0.0;
        $writes = 0;
        $heartbeat = new LeaseHeartbeat(static function () use (&$writes): void {
            $writes++;
        }, 30,
            static function () use (&$time): float {
                return $time;
            });
        foreach (range(1, 10000) as $unused) {
            $heartbeat();
        }
        $this->assertSame(1, $writes);
        $time = 29.9;
        $heartbeat();
        $this->assertSame(1, $writes);
        $time = 30.0;
        $heartbeat();
        $this->assertSame(2, $writes);
    }

    public function test_renewal_failure_propagates_and_is_not_cached_as_success(): void
    {
        $attempts = 0;
        $heartbeat = new LeaseHeartbeat(static function () use (&$attempts): void {
            if (++$attempts === 1) {
                throw new RuntimeException('Lease lost.');
            }
        }, 30);
        try {
            $heartbeat();
            $this->fail('Renewal failures must stop verification.');
        } catch (RuntimeException $error) {
            $this->assertSame('Lease lost.', $error->getMessage());
        }
        $heartbeat();
        $this->assertSame(2, $attempts);
    }

    public function test_long_operation_renews_immediately_when_it_returns(): void
    {
        $time = 0.0;
        $writes = 0;
        $heartbeat = new LeaseHeartbeat(static function () use (&$writes): void {
            $writes++;
        }, 1,
            static function () use (&$time): float {
                return $time;
            });
        $heartbeat();
        $time = 400.0;
        $heartbeat();
        $this->assertSame(2, $writes);
    }
}
