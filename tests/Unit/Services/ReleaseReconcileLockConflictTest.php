<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ReleaseProcessingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use PDOException;
use RuntimeException;
use Tests\TestCase;

final class ReleaseReconcileLockConflictTest extends TestCase
{
    public function test_a_page_that_keeps_deadlocking_is_skipped_after_retries_instead_of_failing_the_run(): void
    {
        Log::spy();
        $calls = 0;

        $completed = $this->runReconcileStatement(static function () use (&$calls): int {
            $calls++;

            throw new QueryException('mariadb', 'UPDATE binaries …', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
        });

        $this->assertFalse($completed);
        $this->assertSame(5, $calls, 'Retried before giving up.');
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_other_errors_still_fail_the_run(): void
    {
        $this->expectException(RuntimeException::class);

        $this->runReconcileStatement(static function (): int {
            throw new RuntimeException('Table binaries does not exist');
        });
    }

    public function test_a_statement_that_succeeds_reports_success(): void
    {
        $this->assertTrue($this->runReconcileStatement(static fn (): int => 3));
    }

    /**
     * @param  callable(): int  $statement
     */
    private function runReconcileStatement(callable $statement): bool
    {
        $reflection = new \ReflectionClass(ReleaseProcessingService::class);

        return $reflection->getMethod('runReconcileStatement')->invoke($reflection->newInstanceWithoutConstructor(), $statement);
    }
}
