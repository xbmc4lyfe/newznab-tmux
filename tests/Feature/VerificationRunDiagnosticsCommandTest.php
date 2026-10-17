<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NNTP\NNTPService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\ScratchBudget;
use App\Services\Releases\Verification\VerificationWorkerLocks;
use App\Services\YencService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\Fixtures\VerificationFairnessWriteProbe;

final class VerificationRunDiagnosticsCommandTest extends ReleaseVerificationCandidatesTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['release_verification.candidate_workers' => 2, 'release_verification.free_bytes' => 0]);
    }

    public function test_diagnostics_are_opt_in_and_scheduled_without_global_summary_queries(): void
    {
        $this->requireDiagnosticsOption();
        $reports = [];
        $this->captureReports($reports);
        $this->assertSame(0, Artisan::call('releases:verify-candidates', ['--no-summary' => true]));
        $this->assertSame([], $reports);
        $events = array_values(array_filter(app(Schedule::class)->events(),
            static fn ($event): bool => str_contains($event->command ?? '', 'releases:verify-candidates')));
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertStringContainsString('--diagnostics', $event->command);
            $this->assertStringContainsString('--no-summary', $event->command);
            $this->assertStringContainsString('--limit=1000 --max-seconds=300', $event->command);
        }
    }

    public function test_diagnostics_distinguish_claim_calls_from_admissions_and_count_stop_from_no_claim(): void
    {
        $this->requireDiagnosticsOption();
        $reports = [];
        $this->captureReports($reports);
        $this->assertSame(0, $this->runDiagnostics(['--limit' => 1000]));
        $this->assertCount(1, $reports);
        $this->assertSame('no_claim', $reports[0]['stop_reason']);
        $this->assertSame(1, $reports[0]['claim_calls']);
        $this->assertSame(0, $reports[0]['claims_admitted']);
        $this->assertFalse($reports[0]['limit_reached']);
        $this->assertArrayNotHasKey('queues_exhausted', $reports[0]);

        $this->enqueueEmptyCandidate('private candidate subject');
        $this->assertSame(0, $this->runDiagnostics(['--limit' => 1]));
        $this->assertCount(2, $reports);
        $this->assertSame('limit', $reports[1]['stop_reason']);
        $this->assertSame(1, $reports[1]['claim_calls']);
        $this->assertSame(1, $reports[1]['claims_admitted']);
        $this->assertSame(1, $reports[1]['finish_returned']);
        $this->assertSame(1, array_sum($reports[1]['verifier_verdicts']));
        $this->assertTrue($reports[1]['limit_reached']);
        $this->assertSame('success', $reports[1]['command_result']);
        $this->assertStringNotContainsString('private', json_encode($reports, JSON_THROW_ON_ERROR));
    }

    public function test_diagnostics_log_after_cleanup_and_logger_failure_preserves_success(): void
    {
        $this->requireDiagnosticsOption();
        $this->enqueueEmptyCandidate('cleanup');
        $reports = [];
        $released = false;
        $this->captureReports($reports, static function () use (&$released): never {
            $exclusive = new VerificationWorkerLocks;
            try {
                $released = $exclusive->tryExclusive();
            } finally {
                $exclusive->close();
            }
            throw new RuntimeException('Logger failed');
        });

        $this->assertSame(0, $this->runDiagnostics());
        $this->assertTrue($released);
        $this->assertCount(1, $reports);
        $this->assertSame('unknown', DB::table('release_verification_candidates')->value('status'));
        $this->assertNull(DB::table('release_verification_candidates')->value('lease_token'));
    }

    public function test_diagnostics_record_a_rejected_worker_without_claiming(): void
    {
        $this->requireDiagnosticsOption();
        $this->assertTrue(app(ScratchBudget::class)->initialize());
        $occupied = new VerificationWorkerLocks;
        $reports = [];
        $this->captureReports($reports);
        try {
            $this->assertTrue($occupied->start(1));
            $this->assertSame(0, $this->runDiagnostics(['--slot' => 1]));
        } finally {
            $occupied->close();
        }
        $this->assertCount(1, $reports);
        $this->assertSame('worker_unavailable', $reports[0]['stop_reason']);
        $this->assertFalse($reports[0]['worker_acquired']);
        $this->assertFalse($reports[0]['loop_started']);
        $this->assertSame(0, $reports[0]['claim_calls']);
        $this->assertSame(0, $reports[0]['claims_admitted']);
        $this->assertNull($reports[0]['durations_ms']['claim_loop']);
    }

    public function test_diagnostics_record_unavailable_initialization_without_entering_the_loop(): void
    {
        $this->requireDiagnosticsOption();
        $occupied = new VerificationWorkerLocks;
        $reports = [];
        $this->captureReports($reports);
        try {
            $this->assertTrue($occupied->start(1));
            $this->assertSame(0, $this->runDiagnostics());
        } finally {
            $occupied->close();
        }
        $this->assertCount(1, $reports);
        $this->assertSame('initialization_unavailable', $reports[0]['stop_reason']);
        $this->assertSame(0, $reports[0]['claim_calls']);
        $this->assertFalse($reports[0]['worker_acquired']);
        $this->assertSame('success', $reports[0]['command_result']);
    }

    public function test_diagnostics_preserve_an_initialization_failure_result_when_logging_throws(): void
    {
        $this->requireDiagnosticsOption();
        Schema::drop('release_verification_scratch_budget');
        $reports = [];
        $this->captureReports($reports, static function (): never {
            throw new RuntimeException('Logger failed');
        });

        $this->assertSame(1, $this->runDiagnostics());
        $this->assertCount(1, $reports);
        $this->assertSame('initialization_error', $reports[0]['stop_reason']);
        $this->assertSame('failure', $reports[0]['command_result']);
        $this->assertSame(1, $reports[0]['exit_code']);
        $this->assertFalse($reports[0]['loop_started']);
    }

    public function test_diagnostics_keep_maintenance_outside_the_existing_claim_time_budget(): void
    {
        $this->requireDiagnosticsOption();
        $this->enqueueEmptyCandidate('after-maintenance-one');
        $this->enqueueEmptyCandidate('after-maintenance-two');
        $maintenanceObserved = false;
        DB::listen(static function (QueryExecuted $query) use (&$maintenanceObserved): void {
            if (! $maintenanceObserved && str_starts_with($query->sql, 'select')
                && str_contains($query->sql, 'release_verification_candidates') && str_contains($query->sql, '"last_unique_at" <')) {
                $maintenanceObserved = true;
                usleep(1100000);
            }
        });
        $reports = [];
        $this->captureReports($reports);

        $this->assertSame(0, $this->runDiagnostics(['--limit' => 2, '--max-seconds' => 1]));
        $this->assertTrue($maintenanceObserved);
        $this->assertCount(1, $reports);
        $this->assertSame(2, $reports[0]['claims_admitted']);
        $this->assertSame('limit', $reports[0]['stop_reason']);
        $this->assertSame('ran', $reports[0]['maintenance_state']);
        $this->assertGreaterThanOrEqual(1000, $reports[0]['durations_ms']['maintenance']);
        $this->assertGreaterThanOrEqual($reports[0]['durations_ms']['maintenance'] + $reports[0]['durations_ms']['claim_loop'], $reports[0]['durations_ms']['total']);
    }

    /** @return array<string, array{int, string}> */
    public static function timedStops(): array
    {
        return ['deadline' => [1000, 'deadline'], 'limit_and_deadline' => [1, 'limit']];
    }

    #[DataProvider('timedStops')]
    public function test_diagnostics_keep_active_verification_and_the_actual_stop_branch(int $limit, string $stop): void
    {
        $this->requireDiagnosticsOption();
        config(['release_verification.bytes_per_second' => 0]);
        foreach (['one', 'two'] as $name) {
            app(CandidateRepository::class)->enqueue(['identity' => sha1($name), 'groups_id' => 1, 'expected_files' => 1,
                'files' => [['key' => 'movie', 'subject' => 'private movie.mkv', 'totalparts' => 1,
                    'segments' => [['number' => 1, 'messageid' => $name.'@private', 'bytes' => 5]]]]]);
        }
        $closed = false;
        $this->mock(NNTPService::class, static function ($mock) use (&$closed): void {
            $mock->shouldReceive('doConnect')->once()->with(false)->andReturn(true);
            $mock->shouldReceive('verificationArticle')->once()->andReturnUsing(static function (): string {
                usleep(1100000);

                return (new YencService)->encode('hello', 'movie.mkv');
            });
            $mock->shouldReceive('doQuit')->once()->andReturnUsing(static function () use (&$closed): void {
                $closed = true;
            });
        });
        $reports = [];
        $closedBeforeLog = false;
        $this->captureReports($reports, static function () use (&$closed, &$closedBeforeLog): void {
            $closedBeforeLog = $closed;
        });

        $this->assertSame(0, $this->runDiagnostics(['--limit' => $limit, '--max-seconds' => 1]));
        $this->assertTrue($closedBeforeLog);
        $this->assertCount(1, $reports);
        $this->assertSame($stop, $reports[0]['stop_reason']);
        $this->assertSame(1, $reports[0]['claim_calls']);
        $this->assertSame(1, $reports[0]['claims_admitted']);
        $this->assertSame($limit === 1, $reports[0]['limit_reached']);
        $this->assertTrue($reports[0]['deadline_reached_at_stop']);
        $this->assertSame(['unknown', 'ready'], DB::table('release_verification_candidates')->orderBy('id')->pluck('status')->all());
        $this->assertSame(0, DB::table('release_verification_candidates')->whereNotNull('lease_token')->count());
    }

    public function test_diagnostics_do_not_mask_an_original_claim_error_when_the_logger_also_throws(): void
    {
        $this->requireDiagnosticsOption();
        $this->enqueueEmptyCandidate('claim-error');
        $original = new RuntimeException('Private claim error');
        DB::listen(static function (QueryExecuted $query) use ($original): void {
            if (str_starts_with($query->sql, 'update "release_verification_candidates"') && in_array('verifying', $query->bindings, true)) {
                throw $original;
            }
        });
        $reports = [];
        $released = false;
        $this->captureReports($reports, static function () use (&$released): never {
            $exclusive = new VerificationWorkerLocks;
            try {
                $released = $exclusive->tryExclusive();
            } finally {
                $exclusive->close();
            }
            throw new RuntimeException('Logger failed');
        });
        try {
            $this->runDiagnostics();
            $this->fail('The original claim error must escape.');
        } catch (RuntimeException $error) {
            $this->assertSame($original, $error);
        }
        $this->assertTrue($released);
        $this->assertCount(1, $reports);
        $this->assertSame(1, $reports[0]['claim_calls']);
        $this->assertSame(0, $reports[0]['claims_admitted']);
        $this->assertTrue($reports[0]['fatal_error']);
        $this->assertSame('exception', $reports[0]['command_result']);
        $this->assertNull($reports[0]['exit_code']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_diagnostics_do_not_count_a_pre_call_fairness_failure_as_a_claim(): void
    {
        require_once __DIR__.'/../Fixtures/VerificationFairnessWriteProbe.php';
        $this->requireDiagnosticsOption();
        $this->enqueueEmptyCandidate('fairness-error');
        $this->assertTrue(app(ScratchBudget::class)->initialize());
        $claimQueries = 0;
        DB::listen(static function (QueryExecuted $query) use (&$claimQueries): void {
            if (str_contains($query->sql, 'release_verification_candidates') && str_contains($query->sql, 'order by "next_attempt_at"')) {
                $claimQueries++;
            }
        });
        $reports = [];
        $released = false;
        $this->captureReports($reports, static function () use (&$released): never {
            $exclusive = new VerificationWorkerLocks;
            try {
                $released = $exclusive->tryExclusive();
            } finally {
                $exclusive->close();
            }
            throw new RuntimeException('Logger failed');
        });
        VerificationFairnessWriteProbe::$failPath = (string) config('release_verification.storage_path').'/locks/worker-1.lock';
        try {
            try {
                $this->runDiagnostics(['--slot' => 1]);
                $this->fail('The native fairness write error must escape before calling claim.');
            } catch (RuntimeException $error) {
                $this->assertSame('Cannot persist verification queue selection.', $error->getMessage());
            }
        } finally {
            VerificationFairnessWriteProbe::$failPath = null;
        }
        $this->assertTrue($released);
        $this->assertCount(1, $reports);
        $this->assertSame(0, $claimQueries);
        $this->assertSame(0, $reports[0]['claim_calls']);
        $this->assertSame(0, $reports[0]['claims_admitted']);
        $this->assertSame('exception', $reports[0]['command_result']);
        $this->assertTrue($reports[0]['fatal_error']);
    }

    public function test_diagnostics_count_caught_candidate_errors_without_claiming_a_persisted_finish(): void
    {
        $this->requireDiagnosticsOption();
        $this->enqueueEmptyCandidate('read-error');
        $row = DB::table('release_verification_candidates')->sole();
        file_put_contents(app(ManifestStore::class)->path($row->manifest_path), 'invalid gzip');
        $reports = [];
        $this->captureReports($reports);

        $this->assertSame(0, $this->runDiagnostics());
        $this->assertCount(1, $reports);
        $this->assertSame(1, $reports[0]['claims_admitted']);
        $this->assertSame(1, $reports[0]['candidate_errors']);
        $this->assertSame(1, $reports[0]['finish_returned']);
        $this->assertSame(0, array_sum($reports[0]['verifier_verdicts']));
        $this->assertSame('success', $reports[0]['command_result']);
        $this->assertArrayNotHasKey('persisted_outcomes', $reports[0]);
    }

    public function test_diagnostics_add_no_queries_when_a_claim_attempt_returns_null(): void
    {
        $this->requireDiagnosticsOption();
        $this->freezeTime();
        $this->assertTrue(app(ScratchBudget::class)->initialize());
        $holder = new VerificationWorkerLocks;
        $reports = [];
        $this->captureReports($reports);
        // The holder's own lock checks are not part of either compared invocation.
        $this->assertTrue($holder->start(2));
        $this->assertTrue($holder->tryGc());
        /** @var \ArrayObject<int, array{string, array<mixed>}> $queries */
        $queries = new \ArrayObject;
        DB::listen(static function (QueryExecuted $query) use ($queries): void {
            $queries[] = [$query->sql, $query->connection->prepareBindings($query->bindings)];
        });
        try {
            $this->assertSame(0, Artisan::call('releases:verify-candidates', ['--slot' => 1, '--no-summary' => true]));
            $plain = $queries->getArrayCopy();
            $queries->exchangeArray([]);
            $this->assertSame(0, $this->runDiagnostics(['--slot' => 1]));
            $this->assertSame($plain, $queries->getArrayCopy());
        } finally {
            $holder->close();
        }
        $this->assertCount(1, $reports);
        $this->assertSame('not_acquired', $reports[0]['maintenance_state']);
        $this->assertNotEmpty($queries, 'Both invocations must execute the existing claim selection queries.');
        foreach ($queries as [$sql]) {
            $this->assertStringNotContainsString('count(', strtolower($sql));
        }
    }

    public function test_diagnostics_emit_to_the_ordinary_configured_local_daily_sink(): void
    {
        $this->requireDiagnosticsOption();
        $root = (string) config('release_verification.storage_path');
        config(['logging.channels.daily.path' => $root.'/diagnostics.log', 'logging.channels.daily.level' => 'info']);
        Log::forgetChannel('daily');

        $this->assertSame(0, $this->runDiagnostics(['--limit' => 1000]));

        $paths = glob($root.'/diagnostics-*.log');
        $this->assertCount(1, $paths);
        $contents = file_get_contents($paths[0]);
        $this->assertStringContainsString('Candidate verification run summary.', $contents);
        $this->assertStringContainsString('"schema_version":1', $contents);
        $this->assertStringContainsString('"claims_admitted":0', $contents);
        Log::forgetChannel('daily');
    }

    private function requireDiagnosticsOption(): void
    {
        $this->assertTrue(Artisan::all()['releases:verify-candidates']->getDefinition()->hasOption('diagnostics'),
            'The verifier must expose opt-in run diagnostics.');
    }

    /** @param array<string, mixed> $options */
    private function runDiagnostics(array $options = []): int
    {
        return Artisan::call('releases:verify-candidates', [...$options, '--diagnostics' => true, '--no-summary' => true]);
    }

    private function enqueueEmptyCandidate(string $identity): void
    {
        app(CandidateRepository::class)->enqueue(['identity' => sha1($identity), 'groups_id' => 1, 'files' => []]);
    }

    /** @param list<array<string, mixed>> $reports */
    private function captureReports(array &$reports, ?callable $observer = null): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->andReturnUsing(static function (string $message, array $report) use (&$reports, $observer): void {
            self::assertSame('Candidate verification run summary.', $message);
            $reports[] = $report;
            if ($observer !== null) {
                $observer($report);
            }
        });
        Log::shouldReceive('channel')->with('daily')->andReturn($logger);
    }
}
