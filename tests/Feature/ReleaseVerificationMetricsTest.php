<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Monitoring\BoundedMetricQuery;
use App\Services\Monitoring\Collectors\ReleaseVerificationCollector;
use App\Services\Monitoring\Prometheus\TextFormatter;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ScratchBudget;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ReleaseVerificationMetricsTest extends ReleaseVerificationCandidatesTest
{
    public function test_a_database_age_timeout_does_not_block_other_metrics_or_publish_zero_age(): void
    {
        $pdoException = new \PDOException('Query execution was interrupted');
        $pdoException->errorInfo = ['70100', 1969, 'max_statement_time exceeded'];
        $failure = new QueryException('testing', 'select min(last_unique_at)', [], $pdoException);
        $this->mock(BoundedMetricQuery::class)->shouldReceive('get')->andReturnUsing(static function (Builder $query) use ($failure): array {
            if (str_contains($query->toSql(), 'MIN(last_unique_at)')) {
                throw $failure;
            }

            return $query->get()->all();
        });
        config(['monitoring.enabled' => true, 'monitoring.pushgateway.url' => 'http://pushgateway.test:9091', 'queue.default' => 'sync']);
        Http::fake(['*' => Http::response('', 200)]);

        $this->artisan('monitoring:export-metrics')->assertSuccessful();

        Http::assertSent(static fn (Request $request): bool => str_contains($request->body(), "nntmux_up 1\n")
            && str_contains($request->body(), 'nntmux_release_verification_backlog_age_measurement_available 0')
            && ! str_contains($request->body(), 'nntmux_release_verification_backlog_age_seconds '));
    }

    public function test_a_successful_age_measurement_remains_exact(): void
    {
        $this->freezeTime();
        app(CandidateRepository::class)->enqueue(['identity' => sha1('oldest'), 'groups_id' => 1, 'files' => []]);
        DB::table('release_verification_candidates')->update(['status' => 'pending', 'last_unique_at' => now()->subHours(2)]);

        $text = $this->metrics();

        $this->assertStringContainsString('nntmux_release_verification_backlog_age_measurement_available 1', $text);
        $this->assertStringContainsString('nntmux_release_verification_backlog_age_seconds 7200', $text);
    }

    public function test_timing_out_both_large_aggregates_preserves_the_cheap_metrics(): void
    {
        $cause = new \PDOException('Query execution was interrupted');
        $cause->errorInfo = ['HY000', 3024, 'max execution time exceeded'];
        $this->mock(BoundedMetricQuery::class)->shouldReceive('get')
            ->andThrow(new QueryException('testing', 'select', [], $cause));

        $text = $this->metrics();

        $this->assertStringContainsString('nntmux_release_verification_candidates_measurement_available 0', $text);
        $this->assertStringContainsString('nntmux_release_verification_backlog_age_measurement_available 0', $text);
        $this->assertStringContainsString('nntmux_release_verification_pending_bytes ', $text);
        $this->assertStringNotContainsString('nntmux_release_verification_candidates{', $text);
    }

    public function test_per_claim_metrics_do_not_walk_retained_audit_scratch_before_the_next_claim(): void
    {
        DB::table('release_verification_scratch_budget')->where('id', 1)->update(['initialized' => true]);
        $nativeRoot = (string) config('release_verification.storage_path');
        File::ensureDirectoryExists($nativeRoot.'/scratch', 0700);
        $this->assertTrue(app(ScratchBudget::class)->initialize());
        VerificationMetricTreeProbe::$nativeRoot = $nativeRoot;
        VerificationMetricTreeProbe::$reads = 0;
        stream_wrapper_register('verification-metric-probe', VerificationMetricTreeProbe::class);
        try {
            foreach ([1, 2] as $number) {
                app(CandidateRepository::class)->enqueue(['identity' => sha1('metric-'.$number), 'groups_id' => 1,
                    'conflict' => true, 'files' => []]);
            }
            config(['release_verification.storage_path' => 'verification-metric-probe://root']);
            $claims = 0;
            $readsBeforeSecondClaim = null;
            DB::listen(static function ($query) use (&$claims, &$readsBeforeSecondClaim): void {
                if (str_starts_with($query->sql, 'update "release_verification_candidates"')
                    && ($query->bindings[0] ?? null) === 'verifying' && ++$claims === 2) {
                    $readsBeforeSecondClaim = VerificationMetricTreeProbe::$reads;
                }
            });

            $this->artisan('releases:verify-candidates', ['--limit' => 2])->assertSuccessful();

            $this->assertSame(2, $claims);
            $this->assertSame(0, $readsBeforeSecondClaim);
            $this->assertSame(0, VerificationMetricTreeProbe::$reads);
            $this->assertSame(2, DB::table('release_verification_candidates')->where('status', 'header_rejected')->count());
            config(['release_verification.storage_path' => $nativeRoot]);
            app(CandidateRepository::class)->enqueue(['identity' => sha1('native-metric-snapshot'), 'groups_id' => 1,
                'conflict' => true, 'files' => []]);
            $this->artisan('releases:verify-candidates')->assertSuccessful();
            $snapshot = json_decode(file_get_contents($nativeRoot.'/status.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNull($snapshot['scratch_bytes']);
            $this->assertSame('unknown', $snapshot['scratch_bytes_status']);
            $this->assertSame(0, $snapshot['verified_bytes_per_second']);
        } finally {
            config(['release_verification.storage_path' => $nativeRoot]);
            stream_wrapper_unregister('verification-metric-probe');
        }
    }

    public function test_unknown_or_missing_scratch_measurement_never_exports_fake_zero_bytes(): void
    {
        $root = (string) config('release_verification.storage_path');
        File::ensureDirectoryExists($root);
        foreach ([null, ['scratch_bytes' => null, 'scratch_bytes_status' => 'unknown'],
            ['scratch_bytes' => 999, 'scratch_bytes_status' => 'unknown']] as $snapshot) {
            if ($snapshot !== null) {
                file_put_contents($root.'/status.json', json_encode($snapshot, JSON_THROW_ON_ERROR));
            }
            $text = $this->metrics();
            $this->assertStringContainsString('nntmux_release_verification_scratch_bytes_measurement_available 0', $text);
            $this->assertStringNotContainsString('nntmux_release_verification_scratch_bytes ', $text);
            $this->assertStringNotContainsString('nntmux_release_verification_scratch_bytes_measurement_age_seconds ', $text);
        }
    }

    public function test_legacy_numeric_snapshot_exports_cached_bytes_with_its_actual_measurement_age(): void
    {
        $this->freezeTime();
        $root = (string) config('release_verification.storage_path');
        File::ensureDirectoryExists($root);
        file_put_contents($root.'/status.json', '{"scratch_bytes":1234,"verified_bytes_per_second":5678}');
        touch($root.'/status.json', now()->timestamp - 600);
        clearstatcache(true, $root.'/status.json');

        $text = $this->metrics();

        $this->assertStringContainsString('nntmux_release_verification_scratch_bytes 1234', $text);
        $this->assertStringContainsString('nntmux_release_verification_scratch_bytes_measurement_available 1', $text);
        $this->assertStringContainsString('nntmux_release_verification_scratch_bytes_measurement_age_seconds 600', $text);
        $this->assertStringContainsString('cached', $text);
        $this->assertStringContainsString('nntmux_release_verification_verified_bytes_per_second 5678', $text);
    }

    private function metrics(): string
    {
        return (new TextFormatter)->format([...app(ReleaseVerificationCollector::class)->collect()]);
    }
}

/** A virtual retained audit tree detects redundant traversal without a large physical fixture. */
final class VerificationMetricTreeProbe
{
    public mixed $context;

    public static string $nativeRoot;

    public static int $reads = 0;

    private mixed $handle = null;

    private string $directory = '';

    private int $entry = 0;

    private function path(string $path): string
    {
        return self::$nativeRoot.substr($path, strlen('verification-metric-probe://root'));
    }

    /** @return array<int|string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        if (str_contains($path, '/scratch/unregistered-audit')) {
            $stat = stat(self::$nativeRoot.'/scratch');
            $stat['mode'] = $stat[2] = str_ends_with($path, '/unregistered-audit') ? 0040755 : 0100644;
            $stat['size'] = $stat[7] = 1;

            return $stat;
        }

        return ($flags & STREAM_URL_STAT_LINK) !== 0 ? @lstat($this->path($path)) : @stat($this->path($path));
    }

    public function dir_opendir(string $path, int $options): bool
    {
        $this->directory = $path;
        $this->entry = 0;
        if (str_ends_with($path, '/scratch') || str_ends_with($path, '/unregistered-audit')) {
            return true;
        }
        $this->handle = opendir($this->path($path));

        return $this->handle !== false;
    }

    public function dir_readdir(): string|false
    {
        if (str_ends_with($this->directory, '/unregistered-audit')) {
            if (++self::$reads > 128) {
                throw new RuntimeException('Redundant metrics walked retained audit scratch before the next claim.');
            }

            return 'retained-'.self::$reads;
        }
        if (str_ends_with($this->directory, '/scratch')) {
            return $this->entry++ === 0 ? 'unregistered-audit' : false;
        }

        return readdir($this->handle);
    }

    public function dir_rewinddir(): bool
    {
        $this->entry = 0;
        if (is_resource($this->handle)) {
            rewinddir($this->handle);
        }

        return true;
    }

    public function dir_closedir(): bool
    {
        if (is_resource($this->handle)) {
            closedir($this->handle);
        }

        return true;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->handle = fopen($this->path($path), $mode);

        return $this->handle !== false;
    }

    public function stream_read(int $count): string|false
    {
        return fread($this->handle, $count);
    }

    public function stream_write(string $data): int|false
    {
        return fwrite($this->handle, $data);
    }

    public function stream_eof(): bool
    {
        return feof($this->handle);
    }

    public function stream_tell(): int|false
    {
        return ftell($this->handle);
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_flush(): bool
    {
        return fflush($this->handle);
    }

    public function stream_truncate(int $size): bool
    {
        return ftruncate($this->handle, $size);
    }

    public function stream_lock(int $operation): bool
    {
        return $operation === 0 || flock($this->handle, $operation);
    }

    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        return fstat($this->handle);
    }

    public function stream_close(): void
    {
        fclose($this->handle);
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        return mkdir($this->path($path), $mode, ($options & STREAM_MKDIR_RECURSIVE) !== 0);
    }

    public function rename(string $from, string $to): bool
    {
        return rename($this->path($from), $this->path($to));
    }

    public function unlink(string $path): bool
    {
        return unlink($this->path($path));
    }
}
