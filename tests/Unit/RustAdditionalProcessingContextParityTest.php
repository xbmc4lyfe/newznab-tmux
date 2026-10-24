<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Release;
use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\AdditionalProcessing\AdditionalProcessingDiagnostics;
use App\Services\AdditionalProcessing\DTO\AdditionalBatchResult;
use App\Services\AdditionalProcessing\DTO\AdditionalWorkPlan;
use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use App\Services\AdditionalProcessing\DTO\DownloadMetrics;
use App\Services\AdditionalProcessing\DTO\PersistenceMetrics;
use App\Services\AdditionalProcessing\DTO\ReleaseProcessingResult;
use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;

/**
 * Reproduce paired caller benchmarks with NNTMUX_ADDITIONAL_CONTEXT_BENCH=1
 * php artisan test --compact tests/Unit/RustAdditionalProcessingContextParityTest.php.
 * Production stays PHP unless common/uncommon mean AND p99 each exceed 1.5x.
 * Native candidates preserve this checkout's DTOs, volume-set proofs, index and SQL aliases.
 * Screening on Apple M5 / PHP 8.5.12 / rustc 1.99.1 release found every candidate
 * slower than PHP in all four metrics, so every production toggle remains false.
 * In particular this checkout uses 256 witnesses/4096 bytes, a seven-column claim
 * index, unrounded stage totals and successful-release artifact yield. The requested
 * alternate data model is not substituted for these existing processing contracts.
 */
final class RustAdditionalProcessingContextParityTest extends TestCase
{
    private const string CDEF = <<<'C'
        int32_t nntmux_additional_validate_naming_witness(const char *input, size_t len, size_t count, size_t total);
        ssize_t nntmux_additional_compressed_files_are_media_json(const char *input, size_t len, char *out, size_t cap);
        ssize_t nntmux_additional_id_order_expression_json(const char *input, size_t len, char *out, size_t cap);
        ssize_t nntmux_additional_batch_result_metrics_json(const char *input, size_t len, char *out, size_t cap);
        ssize_t nntmux_additional_claim_index_matches_json(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array COLUMNS = ['passwordstatus', 'haspreview', 'nzbstatus', 'leftguid', 'postdate', 'id', 'additional_pp_claimed_at'];

    private const array METRICS = ['outcomeCounts', 'unsupportedReasonCounts', 'stageDurationTotals', 'downloadMetrics', 'persistenceMetrics', 'artifactYieldPercent'];

    private static function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('additional', self::CDEF);
        self::assertNotNull($ffi, 'Build the release library with all five Slice 2 exports; native parity must not silently skip.');

        return $ffi;
    }

    protected function tearDown(): void
    {
        ReleaseProcessingContext::setUseRustFfi(false);
        AdditionalCandidateQuery::setUseRustFfi(false);
        AdditionalProcessingDiagnostics::setUseRustFfi(false);
        parent::tearDown();
    }

    public function test_abi_rejects_invalid_buffers_and_malformed_json(): void
    {
        $ffi = self::ffi();
        // @phpstan-ignore method.notFound (domain C ABI)
        self::assertSame(-1, $ffi->nntmux_additional_validate_naming_witness(null, 1, 0, 0));
        // @phpstan-ignore method.notFound (domain C ABI)
        self::assertSame(0, $ffi->nntmux_additional_validate_naming_witness(null, 0, 0, 0));
        foreach (['compressed_files_are_media', 'id_order_expression', 'batch_result_metrics', 'claim_index_matches'] as $helper) {
            $symbol = 'nntmux_additional_'.$helper.'_json';
            $out = $ffi->new('char[8]');
            foreach (['', '{', 'null trailing', '[NaN]', '[[[[', '"\ud800"'] as $input) {
                // @phpstan-ignore method.notFound, method.notFound, method.notFound, method.notFound (domain C ABI)
                self::assertSame(-1, $ffi->{$symbol}($input, strlen($input), $out, 8), $symbol.' '.$input);
            }
            // @phpstan-ignore method.notFound, method.notFound, method.notFound, method.notFound (domain C ABI)
            self::assertSame(-1, $ffi->{$symbol}(null, 1, $out, 8));
            // @phpstan-ignore method.notFound, method.notFound, method.notFound, method.notFound (domain C ABI)
            self::assertSame(-1, $ffi->{$symbol}('[]', 2, null, 8));
            // @phpstan-ignore method.notFound, method.notFound, method.notFound, method.notFound (domain C ABI)
            self::assertSame(-1, $ffi->{$symbol}(null, PHP_INT_MAX, $out, 8));
        }
        // A small output buffer reports the required size without overwriting it.
        $input = json_encode(['ids' => range(1, 100), 'driver' => 'sqlite'], JSON_THROW_ON_ERROR);
        $out = $ffi->new('char[2]');
        $out[0] = 'x';
        // @phpstan-ignore method.notFound (domain C ABI)
        $needed = $ffi->nntmux_additional_id_order_expression_json($input, strlen($input), $out, 2);
        self::assertLessThan(-2, $needed);
        self::assertSame('x', $out[0]);
    }

    public function test_naming_witness_admission_boundaries_duplicates_and_latched_omission(): void
    {
        self::ffi();
        foreach (['', '0', 'episode.mkv', '番組.é.mkv', "nul\0file", "\xff", "\xc0\xaf", "\xed\xa0\x80", str_repeat('a', 4096), str_repeat('a', 4097)] as $name) {
            $php = new ReleaseProcessingContext(new Release);
            $native = clone $php;
            self::assertSame($php->validateNamingWitnessPhp($name), $native->validateNamingWitnessNative($name));
            self::assertSame($php->rememberNamingWitnessPhp($name), $native->rememberNamingWitnessNative($name));
            self::assertEquals($php, $native);
        }
        foreach ([array_map(static fn (int $i): string => 'name-'.$i, range(0, 257)), array_map(static fn (int $i): string => $i.str_repeat('a', 4092), range(0, 10))] as $names) {
            $php = new ReleaseProcessingContext(new Release);
            $native = clone $php;
            foreach ($names as $name) {
                self::assertSame($php->rememberNamingWitnessPhp($name), $native->rememberNamingWitnessNative($name));
                self::assertSame($php->rememberNamingWitnessPhp($name), $native->rememberNamingWitnessNative($name));
                self::assertEquals($php, $native);
            }
            self::assertTrue($php->namingWitnessesOmitted);
            self::assertTrue($php->archiveNameEvidenceIncomplete);
            self::assertFalse($native->rememberNamingWitnessNative($names[0]), 'Omission stays latched, even for a previously admitted name.');
        }
        // Exactly the total-byte cap is admitted before the next name latches omission.
        $context = new ReleaseProcessingContext(new Release);
        for ($i = 0; $i < 8; $i++) {
            self::assertTrue($context->rememberNamingWitnessNative($i.str_repeat('a', 4095)));
        }
        self::assertFalse($context->rememberNamingWitnessNative('x'));
    }

    public function test_randomized_witness_validation_matches_utf8_and_caps(): void
    {
        self::ffi();
        $random = new Randomizer(new Mt19937(1405));
        for ($i = 0; $i < 1000; $i++) {
            $php = new ReleaseProcessingContext(new Release);
            // Include byte/count exhaustion without making every iteration build a full inventory.
            if ($i % 3 === 0) {
                $php->archiveNamingWitnesses = array_fill_keys(array_map(static fn (int $n): string => 'known-'.$n, range(0, $random->getInt(250, 257))), true);
            }
            $native = clone $php;
            $name = match ($i % 5) {
                0 => str_repeat('a', $random->getInt(4090, 4100)),
                1 => str_repeat('番組', $random->getInt(0, 700)),
                2 => chr($random->getInt(0, 255)).chr($random->getInt(0, 255)).'.mkv',
                3 => 'known-'.$random->getInt(0, 300),
                default => 'episode-'.$random->getInt(0, 10000).'.mkv',
            };
            self::assertSame($php->validateNamingWitnessPhp($name), $native->validateNamingWitnessNative($name));
            self::assertSame($php->rememberNamingWitnessPhp($name), $native->rememberNamingWitnessNative($name));
            self::assertEquals($php, $native);
        }
    }

    private static function mediaContext(int $count, bool $missing = false): ReleaseProcessingContext
    {
        $context = new ReleaseProcessingContext(new Release);
        $candidates = [];
        for ($i = 0; $i < $count; $i++) {
            $key = 'rar:episode-'.$i;
            $candidates[] = new ArchiveCandidate('episode-'.$i.'.rar', [], true, $i, setKey: $key);
            if (! $missing || $i < $count - 1) {
                $context->mediaVolumeSets[$key] = true;
            }
        }
        $context->workPlan = new AdditionalWorkPlan(archiveCandidates: $candidates);

        return $context;
    }

    public function test_media_proofs_preserve_unknown_archives_and_volume_set_membership(): void
    {
        $ffi = self::ffi();
        foreach ([0, 1, 2, 256] as $count) {
            foreach ([false, true] as $missing) {
                foreach ([false, true] as $unplanned) {
                    $context = self::mediaContext($count, $missing);
                    $context->unplannedArchiveSeen = $unplanned;
                    self::assertSame($context->compressedFilesAreMediaPhp(), $context->compressedFilesAreMediaNative());
                    ReleaseProcessingContext::setUseRustFfi(true);
                    self::assertSame($context->compressedFilesAreMediaPhp(), $context->compressedFilesAreMedia());
                }
            }
        }
        // Exercise the exported JSON helper's PHP truthiness independently of normalized proof rows.
        foreach ([false, null, 0, 0.0, '', '0', [], true, 1, -1, 'false', ['x'], (object) []] as $value) {
            $input = json_encode([['is_media' => $value]], JSON_THROW_ON_ERROR);
            $out = $ffi->new('char[16]');
            // @phpstan-ignore method.notFound (domain C ABI)
            $written = $ffi->nntmux_additional_compressed_files_are_media_json($input, strlen($input), $out, 16);
            self::assertGreaterThan(0, $written);
            self::assertSame((bool) $value, json_decode(FFI::string($out, $written)));
        }
    }

    public function test_sql_order_and_diagnostics_match_randomized_inputs(): void
    {
        self::ffi();
        $random = new Randomizer(new Mt19937(1402));
        self::assertSame('FIELD(id, 9,4,9)', AdditionalCandidateQuery::idOrderExpressionNative([9, 4, 9], 'mysql'));
        self::assertSame('CASE id WHEN 9 THEN 0 WHEN 4 THEN 1 ELSE 2 END', AdditionalCandidateQuery::idOrderExpressionNative([9, 4], 'sqlite'));
        for ($i = 0; $i < 250; $i++) {
            $ids = array_map(static fn (): int => $random->getInt(-1000000000000, 1000000000000), range(0, $random->getInt(0, 64)));
            if ($i === 0) {
                $ids = [];
            } elseif ($i === 1) {
                $ids = [PHP_INT_MIN, PHP_INT_MAX, 0];
            }
            foreach (['sqlite', 'mysql', 'mariadb', 'pgsql'] as $driver) {
                $expected = AdditionalCandidateQuery::idOrderExpressionPhp($ids, $driver);
                self::assertSame($expected, AdditionalCandidateQuery::idOrderExpressionNative($ids, $driver));
                AdditionalCandidateQuery::setUseRustFfi(true);
                self::assertSame($expected, AdditionalCandidateQuery::idOrderExpression($ids, $driver));
            }
            $columns = match ($i % 4) {
                0 => self::COLUMNS, 1 => [...self::COLUMNS, 'extra'], 2 => array_reverse(self::COLUMNS), default => []
            };
            $args = [$columns, $random->getInt(-10, 3600), $random->getInt(-10, 600), $random->getInt(-10, 500), $random->getInt(-10, 64)];
            self::assertSame(AdditionalProcessingDiagnostics::claimIndexMatchesPhp(...$args), AdditionalProcessingDiagnostics::claimIndexMatchesNative(...$args));
            AdditionalProcessingDiagnostics::setUseRustFfi(true);
            self::assertSame(AdditionalProcessingDiagnostics::claimIndexMatchesPhp(...$args), AdditionalProcessingDiagnostics::claimIndexMatches(...$args));
        }
    }

    private static function batch(Randomizer $random, int $count): AdditionalBatchResult
    {
        $results = [];
        $outcomes = ProcessingOutcome::cases();
        for ($i = 0; $i < $count; $i++) {
            $results[] = new ReleaseProcessingResult(
                $i, 'guid-'.$i, $outcomes[$random->getInt(0, count($outcomes) - 1)],
                artifactsCreated: $random->getInt(0, 1) === 1,
                stageDurations: ['parse' => fdiv($random->getInt(-1000, 10000), 997), 'é\\"' => fdiv($random->getInt(0, 10000), 7), 'zero' => 0.005],
                downloadMetrics: $i % 3 === 0 ? null : new DownloadMetrics($random->getInt(0, 10), 1, 3, $random->getInt(0, 1000000000), 8192, 16384),
                persistenceMetrics: $i % 4 === 0 ? null : new PersistenceMetrics(5, fdiv($random->getInt(-1000, 10000), 997), 2, 1),
                unsupportedReasons: $i % 2 === 0 ? ['0', 'unsupported', 'unsupported', '番組'] : [],
            );
        }

        return new AdditionalBatchResult(results: $results);
    }

    public function test_typed_metrics_match_php_including_order_and_float_bits(): void
    {
        self::ffi();
        $random = new Randomizer(new Mt19937(1403));
        foreach ([0, 1, 2, 3, 10, 256, ...array_fill(0, 50, 16)] as $count) {
            $batch = self::batch($random, $count);
            $enabled = new AdditionalBatchResult(results: $batch->results, useRustFfi: true);
            foreach (self::METRICS as $method) {
                $expected = $batch->{$method.'Php'}();
                $native = $batch->{$method.'Native'}();
                self::assertNotNull($native, $method.' must execute native code');
                if (is_object($expected)) {
                    self::assertSame(get_object_vars($expected), get_object_vars($native));
                    self::assertEquals($expected, $enabled->{$method}());
                } else {
                    self::assertSame($expected, $native, $method);
                    self::assertSame($expected, $enabled->{$method}());
                }
            }
        }
        $batch = new AdditionalBatchResult(results: [
            new ReleaseProcessingResult(1, 'a', ProcessingOutcome::Completed, artifactsCreated: true),
            new ReleaseProcessingResult(2, 'b', ProcessingOutcome::Passworded),
            new ReleaseProcessingResult(3, 'c', ProcessingOutcome::Failed, artifactsCreated: true),
        ]);
        self::assertSame(50.0, $batch->artifactYieldPercentNative(), 'Yield uses successful releases, not all attempts.');
        self::assertSame([], $batch->stageDurationTotalsNative());
    }

    public function test_native_declines_non_finite_metrics_and_production_falls_back(): void
    {
        self::ffi();
        $batch = new AdditionalBatchResult(results: [new ReleaseProcessingResult(
            1, 'a', ProcessingOutcome::Completed,
            stageDurations: ['parse' => INF],
            persistenceMetrics: new PersistenceMetrics(databaseMilliseconds: INF),
        )], useRustFfi: true);
        self::assertNull($batch->stageDurationTotalsNative());
        self::assertSame(['parse' => INF], $batch->stageDurationTotals());
        self::assertSame(INF, $batch->persistenceMetrics()->databaseMilliseconds);
        self::assertSame(['completed' => 1], $batch->outcomeCounts());
    }

    /**
     * Optional benchmark: 3 repetitions, 2000 randomized pairs each, 100 warmups.
     * Inputs and closures are prepared outside the measured interval; actual caller
     * encoding, FFI resolution, scratch allocation/reuse, decoding and DTO allocation
     * remain timed. Context mutation uses a fresh clone for both implementations.
     * Raw single-call nanoseconds and source/runtime identities survive in /tmp.
     */
    public function test_optional_paired_caller_benchmarks(): void
    {
        if (getenv('NNTMUX_ADDITIONAL_CONTEXT_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_ADDITIONAL_CONTEXT_BENCH=1 for paired benchmarks.');
        }
        self::ffi();
        $random = new Randomizer(new Mt19937(1404));
        $raw = [];
        $summary = [];
        $inputHashes = [];
        $failures = [];
        $error = null;
        try {
            foreach (['common', 'uncommon'] as $workload) {
                $context = self::mediaContext($workload === 'common' ? 2 : 200, $workload !== 'common');
                $name = $workload === 'common' ? 'Episode.Name.1080p.mkv' : str_repeat('番組', 600).'.mkv';
                $ids = range(1, $workload === 'common' ? 16 : 1024);
                $batch = self::batch($random, $workload === 'common' ? 8 : 256);
                $diagnosticArgs = $workload === 'common' ? [self::COLUMNS, 1800, 60, 20, 8] : [[...self::COLUMNS, ...array_fill(0, 128, 'unrelated_index_column')], -1, 1860, 500, 64];
                $inputHashes[$workload] = hash('sha256', serialize([$name, $ids, $diagnosticArgs, $batch->results, $context->mediaVolumeSets, $context->workPlan]));
                $pairs = [
                    'rememberNamingWitness' => [static fn () => (clone $context)->rememberNamingWitnessPhp($name), static fn () => (clone $context)->rememberNamingWitnessNative($name)],
                    'compressedFilesAreMedia' => [$context->compressedFilesAreMediaPhp(...), $context->compressedFilesAreMediaNative(...)],
                    'claimIndexMatches' => [static fn () => AdditionalProcessingDiagnostics::claimIndexMatchesPhp(...$diagnosticArgs), static fn () => AdditionalProcessingDiagnostics::claimIndexMatchesNative(...$diagnosticArgs)],
                ];
                foreach (['mysql', 'sqlite'] as $driver) {
                    $pairs['idOrderExpression.'.$driver] = [static fn () => AdditionalCandidateQuery::idOrderExpressionPhp($ids, $driver), static fn () => AdditionalCandidateQuery::idOrderExpressionNative($ids, $driver)];
                }
                foreach (self::METRICS as $method) {
                    $pairs[$method] = [$batch->{$method.'Php'}(...), $batch->{$method.'Native'}(...)];
                }
                foreach ($random->shuffleArray(array_keys($pairs)) as $method) {
                    [$php, $native] = $pairs[$method];
                    self::assertNotNull($native(), $method);
                    self::assertEquals($php(), $native(), $method.' correctness gate');
                    for ($warmup = 0; $warmup < 100; $warmup++) {
                        $php();
                        $native();
                    }
                    for ($repetition = 0; $repetition < 3; $repetition++) {
                        $samples = ['php' => [], 'native' => []];
                        for ($sample = 0; $sample < 2000; $sample++) {
                            foreach ($random->shuffleArray(['php', 'native']) as $implementation) {
                                $call = $implementation === 'php' ? $php : $native;
                                $start = hrtime(true);
                                $value = $call();
                                $samples[$implementation][] = hrtime(true) - $start;
                                self::assertNotNull($value, $method.' benchmark must not fall back');
                            }
                        }
                        $raw[$method][$workload][] = $samples;
                        $stats = [];
                        foreach ($samples as $implementation => $latencies) {
                            sort($latencies);
                            $stats[$implementation] = ['mean' => array_sum($latencies) / count($latencies), 'p99' => $latencies[(int) ceil(count($latencies) * 0.99) - 1]];
                        }
                        $summary[$method][$workload][] = [
                            'mean' => $stats['php']['mean'] / $stats['native']['mean'],
                            'p99' => $stats['php']['p99'] / $stats['native']['p99'],
                        ];
                    }
                }
            }
        } catch (\Throwable $failure) {
            $failures[] = $failure->getMessage();
            $error = $failure;
        }
        $hashes = [];
        foreach ([ReleaseProcessingContext::class, AdditionalCandidateQuery::class, AdditionalProcessingDiagnostics::class, AdditionalBatchResult::class, self::class] as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            self::assertIsString($file);
            $hashes[$class] = hash_file('sha256', $file);
        }
        foreach (['rust/pipeline-ffi/src/additional.rs', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so')] as $file) {
            $hashes[$file] = hash_file('sha256', $file);
        }
        $path = sys_get_temp_dir().'/nntmux-wave14-slice2-'.getmypid().'.json';
        file_put_contents($path, json_encode([
            'seed' => 1404, 'warmup' => 100, 'repetitions' => 3, 'samples_per_repetition' => 2000,
            'php' => PHP_VERSION, 'jit' => ini_get('opcache.jit'), 'os' => php_uname(),
            'cpu' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n machdep.cpu.brand_string' : 'uname -m')),
            'rustc' => trim((string) shell_exec('rustc --version')), 'cargo' => trim((string) shell_exec('cargo --version')),
            'memory_limit' => ini_get('memory_limit'), 'resource_limits' => 'One host executor; no task-specific CPU caps',
            'input_sha256' => $inputHashes, 'failures' => $failures, 'generated_at_utc' => gmdate('c'),
            'head' => trim((string) shell_exec('git rev-parse HEAD')), 'profile' => 'cargo release',
            'source_sha256' => $hashes, 'summary' => $summary, 'raw_ns' => $raw,
        ], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nWave14 Slice2 raw benchmark: ".$path."\n".json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
        if ($error !== null) {
            throw $error;
        }
    }
}
