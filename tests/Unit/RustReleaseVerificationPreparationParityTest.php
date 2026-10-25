<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\ForwardVerifiedPublications;
use App\Console\Commands\VerifyReleaseCandidates;
use App\Services\Releases\Verification\CandidatePreparationService;
use App\Services\Releases\Verification\PreparedCandidateUpdate;
use App\Services\Releases\Verification\PublicationPrelock;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

final class RustReleaseVerificationPreparationParityTest extends TestCase
{
    private const array CLASSES = [ForwardVerifiedPublications::class, VerifyReleaseCandidates::class, CandidatePreparationService::class,
        PreparedCandidateUpdate::class, PublicationPrelock::class];

    private const int SEED = 33022026;

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
        }
        parent::tearDown();
    }

    public function test_defaults_nullable_toggles_are_independent(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertFalse($class::usesRustFfi(), $class);
            $this->assertSame(CandidatePreparationService::FFI_CDEF, $class::FFI_CDEF);
        }
        foreach (self::CLASSES as $selected) {
            $selected::setUseRustFfi(true);
            foreach (self::CLASSES as $class) {
                $this->assertSame($selected === $class, $class::usesRustFfi());
            }
            $selected::setUseRustFfi(false);
            $this->assertFalse($selected::usesRustFfi());
            $selected::setUseRustFfi(null);
            $this->assertFalse($selected::usesRustFfi());
        }
    }

    public function test_php_helpers_preserve_the_previous_inline_expressions(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        for ($i = 0; $i < 2000; $i++) {
            [$wall, $oldest, $wake, $linger, $maxAge] = self::lingerArgs($random);
            // The forwarder's linger() before extraction.
            $margin = min(60.0, $maxAge / 2);
            $age = max(0.0, $wall - $oldest);
            $budget = (min($linger / 1000.0, $maxAge - $margin) - $age) * 1000.0;
            if ($wake !== null) {
                $budget = min($budget, ($wake - $wall) * 1000.0);
            }
            $this->assertSame(serialize($budget), serialize(ForwardVerifiedPublications::computeLingerBudgetMsPhp($wall, $oldest, $wake, $linger, $maxAge)));

            [$failures, $delay] = self::backoffArgs($random);
            $this->assertSame(serialize($delay ?? min(30.0, 2.0 ** ($failures + 1))),
                serialize(ForwardVerifiedPublications::computeRetryBackoffSecondsPhp($failures, $delay)));

            $reason = self::reason($random);
            $this->assertSame(mb_strcut($reason, 0, 2000, 'UTF-8'), CandidatePreparationService::truncateAssessmentReasonPhp($reason));

            [$cursor, $max, $exhausted, $count, $last] = self::cursorArgs($random);
            $this->assertSame(self::previousCheckpoint($cursor, $max, $exhausted, $count, $last),
                VerifyReleaseCandidates::normalizeScratchCursorPhp($cursor, $max, $exhausted, $count, $last), serialize($cursor));
        }
        $this->assertSame('', CandidatePreparationService::truncateAssessmentReasonPhp(null));
        $this->assertSame('abc', CandidatePreparationService::truncateAssessmentReasonPhp('abcdef', -3));
        $this->assertSame(1500.0, ForwardVerifiedPublications::computeLingerBudgetMsPhp(100.0, 99.5, null, 2000, 240.0));
        $this->assertSame(250.0, ForwardVerifiedPublications::computeLingerBudgetMsPhp(100.0, 99.5, 100.25, 2000, 240.0));
        $this->assertSame(15000.0, ForwardVerifiedPublications::computeLingerBudgetMsPhp(100.0, 100.0, null, 60000, 30.0));
        $this->assertSame([2.0, 16.0, 30.0, 1.0, 0.25], [ForwardVerifiedPublications::computeRetryBackoffSecondsPhp(0),
            ForwardVerifiedPublications::computeRetryBackoffSecondsPhp(3), ForwardVerifiedPublications::computeRetryBackoffSecondsPhp(9),
            ForwardVerifiedPublications::computeRetryBackoffSecondsPhp(3, 1.0), ForwardVerifiedPublications::computeRetryBackoffSecondsPhp(0, null, 0.25)]);

        // The preparation update's previous branches: reason unless ready, retry time only when unknown.
        foreach (['ready' => [], 'waiting_upload' => ['reason'], 'unknown' => ['reason', 'next_attempt_at'], 'missing_headers' => ['reason']] as $status => $keys) {
            $shape = PreparedCandidateUpdate::classifyUpdateShapePhp($status !== 'ready', $status === 'unknown');
            $actual = array_keys(array_filter(['reason' => ($shape & PreparedCandidateUpdate::SHAPE_REASON) !== 0,
                'next_attempt_at' => ($shape & PreparedCandidateUpdate::SHAPE_RETRY) !== 0]));
            $this->assertSame($keys, $actual, $status);
        }
        $this->assertSame([0, 1, 2, 3], [PreparedCandidateUpdate::classifyUpdateShapePhp(false, false), PreparedCandidateUpdate::classifyUpdateShapePhp(true, false),
            PreparedCandidateUpdate::classifyUpdateShapePhp(false, true), PreparedCandidateUpdate::classifyUpdateShapePhp(true, true)]);

        $this->assertSame(['size_known' => true, 'archive_known' => true, 'policy' => 'accepted', 'coverage' => 'required', 'coverage_count' => 3, 'locked_steps' => 0],
            (new PublicationPrelock(10, '/m/a.json', [], true, null, 'gz', ['a' => true, 'b' => true, 'c' => true], true))->summary());
        $this->assertSame(['size_known' => false, 'archive_known' => false, 'policy' => 'unknown', 'coverage' => 'unknown', 'coverage_count' => null, 'locked_steps' => 4],
            (new PublicationPrelock)->summary());
        $this->assertSame(['size_known' => true, 'archive_known' => true, 'policy' => 'rejected', 'coverage' => 'skipped', 'coverage_count' => null, 'locked_steps' => 0],
            PublicationPrelock::summarizePrelockStatePhp(5, '/m', true, 'too small', false, null));
        $this->assertSame(['size_known' => true, 'archive_known' => false, 'policy' => 'accepted', 'coverage' => 'unusable', 'coverage_count' => null, 'locked_steps' => 1],
            PublicationPrelock::summarizePrelockStatePhp(5, null, true, null, true, null));
        $this->assertSame(['size_known' => true, 'archive_known' => true, 'policy' => 'accepted', 'coverage' => 'none', 'coverage_count' => 0, 'locked_steps' => 0],
            PublicationPrelock::summarizePrelockStatePhp(5, '', true, null, true, -4));
    }

    public function test_native_helpers_and_public_dispatch_have_exact_parity(): void
    {
        $this->nativeFfi();
        foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
            $php = $method.'Php';
            $native = $method.'Native';
            foreach ([...$common, ...$uncommon] as $args) {
                $expected = serialize($class::$php(...$args));
                $this->assertSame($expected, serialize($class::$native(...$args)), $class.'::'.$native.' '.bin2hex(serialize($args)));
                $class::setUseRustFfi(true);
                $forced = serialize($class::$method(...$args));
                $class::setUseRustFfi(false);
                $default = serialize($class::$method(...$args));
                $this->assertSame([$expected, $expected], [$forced, $default], $class.'::'.$method);
            }
        }
    }

    public function test_seeded_random_inputs_match_php_and_rust_answers_directly(): void
    {
        $this->nativeFfi();
        $random = new Randomizer(new Mt19937(self::SEED + 1));
        for ($i = 0; $i < 3000; $i++) {
            $linger = self::lingerArgs($random);
            $this->assertSame(serialize(ForwardVerifiedPublications::computeLingerBudgetMsPhp(...$linger)),
                serialize(self::raw(0, self::encodeLinger(...$linger), 'd')), 'linger '.serialize($linger));
            $backoff = self::backoffArgs($random);
            $this->assertSame(serialize(ForwardVerifiedPublications::computeRetryBackoffSecondsPhp(...$backoff)),
                serialize(self::raw(1, self::encodeBackoff(...$backoff), 'd')), 'backoff '.serialize($backoff));
            $cursor = self::cursorArgs($random);
            $this->assertSame(array_values(VerifyReleaseCandidates::normalizeScratchCursorPhp(...$cursor)),
                self::raw(2, self::encodeCursor(...$cursor), 'q2'), 'cursor '.serialize($cursor));
            $reason = self::reason($random);
            $max = $random->getInt(0, 3) === 0 ? $random->getInt(0, 24) : 2000;
            $this->assertSame(CandidatePreparationService::truncateAssessmentReasonPhp($reason, $max),
                CandidatePreparationService::evaluateNative(3, pack('q', $max).$reason), bin2hex($reason).' '.$max);
            $hasReason = $random->getInt(0, 1) === 1;
            $hasNext = $random->getInt(0, 1) === 1;
            $this->assertSame(PreparedCandidateUpdate::classifyUpdateShapePhp($hasReason, $hasNext), self::raw(4, pack('q2', (int) $hasReason, (int) $hasNext), 'q')[0]);
            $prelock = self::prelockArgs($random);
            $this->assertSame(PublicationPrelock::summarizePrelockStatePhp(...$prelock), PublicationPrelock::summarizePrelockStateNative(...$prelock), serialize($prelock));
            $this->assertSame(serialize(PublicationPrelock::summarizePrelockStatePhp(...$prelock)['locked_steps']),
                serialize(self::raw(5, self::encodePrelock(...$prelock), 'q7')[6]));
        }
    }

    public function test_declined_inputs_fall_back_to_identical_php_results(): void
    {
        $this->nativeFfi();
        foreach ([-1, -3, PHP_INT_MIN] as $max) {
            $this->assertNull(CandidatePreparationService::evaluateNative(3, pack('q', $max).'abcdef'));
            $this->assertSame(CandidatePreparationService::truncateAssessmentReasonPhp('abcdéf', $max), CandidatePreparationService::truncateAssessmentReasonNative('abcdéf', $max));
            CandidatePreparationService::setUseRustFfi(true);
            $this->assertSame(mb_strcut('abcdéf', 0, $max, 'UTF-8'), CandidatePreparationService::truncateAssessmentReason('abcdéf', $max));
            CandidatePreparationService::setUseRustFfi(null);
        }
        foreach ([[6, pack('q2', 1, 1)], [-1, pack('q2', 1, 1)], [0, pack('q6', 0, 0, 0, 0, 0, 0)], [0, pack('q7', 0, 0, 2, 0, 0, 0, 0)],
            [1, pack('q4', 0, 2, 0, 0)], [2, pack('q6', 0, 0, 0, 3, 0, 0)], [2, pack('q7', 0, 0, 0, 0, 0, 0, 0)], [3, 'short'], [3, ''],
            [4, pack('q2', 1, -1)], [4, pack('q3', 1, 1, 1)], [5, pack('q8', 2, 0, 0, 0, 0, 0, 0, 0)], [5, pack('q8', 0, 0, 0, 0, 0, 0, 9, 0)]] as [$op, $input]) {
            $this->assertNull(CandidatePreparationService::evaluateNative($op, $input), $op.' '.bin2hex($input));
        }
        $this->assertNull(CandidatePreparationService::evaluateNative(4, str_repeat('x', 16777217)));
    }

    public function test_abi_capacity_malformed_inputs_and_bad_ranges_preserve_sentinels(): void
    {
        $ffi = $this->nativeFfi();
        $vectors = [[0, self::encodeLinger(100.0, 99.5, 100.25, 2000, 240.0)], [1, self::encodeBackoff(3, null)], [2, self::encodeCursor(null, 500, false, 100, 120)],
            [3, pack('q', 7).'abcdé€😀'], [3, pack('q', 0).'abc'], [4, pack('q2', 1, 1)], [5, self::encodePrelock(1, '/m', true, null, true, 4)]];
        foreach ($vectors as [$op, $input]) {
            $buffer = $ffi->new('char[1024]');
            FFI::memset($buffer, 0x5A, 1024);
            // @phpstan-ignore method.notFound
            $length = $ffi->nntmux_w33_release_verification_preparation($op, $input, strlen($input), $buffer, 1024);
            $this->assertGreaterThanOrEqual(0, $length);
            $wire = FFI::string($buffer, $length);
            $this->assertSame(CandidatePreparationService::evaluateNative($op, $input), $wire);
            $small = $ffi->new('char['.($length + 2).']');
            foreach (array_unique([0, 1, max(0, $length - 1), $length, $length + 1]) as $capacity) {
                FFI::memset($small, 0x5A, $length + 2);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w33_release_verification_preparation($op, $input, strlen($input), $small, $capacity);
                $this->assertSame($capacity < $length ? -$length : $length, $written, $op.' capacity '.$capacity);
                $this->assertSame($capacity < $length ? str_repeat('Z', $length + 2) : $wire.'ZZ', FFI::string($small, $length + 2));
            }
        }
        $buffer = $ffi->new('char[1024]');
        $input = pack('q2', 1, 1);
        foreach ([[null, 0, $buffer, 1024], [null, 16, $buffer, 1024], [$input, 16, null, 0], [$input, 16, null, 8], [$input, 16777217, $buffer, 1024],
            [$input, 16, $buffer, 16777217], [$buffer, 16, $buffer, 1024], [FFI::addr($buffer[8]), 16, $buffer, 1024], [$buffer, 16, FFI::addr($buffer[8]), 1016]] as [$in, $length, $out, $capacity]) {
            FFI::memset($buffer, 0, 16);
            FFI::memset(FFI::addr($buffer[16]), 0x5A, 1008);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w33_release_verification_preparation(4, $in, $length, $out, $capacity));
            $this->assertSame(str_repeat("\0", 16).str_repeat('Z', 1008), FFI::string($buffer, 1024));
        }
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w33_release_verification_preparation(99, $input, 16, $buffer, 1024));
    }

    public function test_missing_library_falls_back_in_fresh_process(): void
    {
        $this->nativeFfi();
        $code = <<<'CHILD'
            require $argv[1].'/vendor/autoload.php';
            $vectors = unserialize(base64_decode($argv[2]), ['allowed_classes' => false]);
            if (App\Services\Releases\Verification\CandidatePreparationService::evaluateNative(4, pack('q2', 1, 1)) !== null) {
                throw new RuntimeException('Missing explicit library unexpectedly loaded.');
            }
            foreach ($vectors as [$class, $method, $common, $uncommon]) {
                $php = $method.'Php';
                $native = $method.'Native';
                $class::setUseRustFfi(true);
                foreach ([...$common, ...$uncommon] as $args) {
                    $expected = serialize($class::$php(...$args));
                    if ($expected !== serialize($class::$native(...$args)) || $expected !== serialize($class::$method(...$args))) {
                        throw new RuntimeException($class.'::'.$method.' fallback mismatch');
                    }
                }
            }
            echo 'fallback-ok';
            CHILD;
        $missing = '/tmp/w33-2-missing-'.getmypid().'.dylib';
        $this->assertFileDoesNotExist($missing);
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=true', '-r', $code, base_path(), base64_encode(serialize(self::workloads()))], base_path(), ['NNTMUX_PIPELINE_FFI_LIB' => $missing]);
        $process->mustRun();
        $this->assertSame('fallback-ok', $process->getOutput());
    }

    /** The wired callers' own suites (preparation, forwarder linger/retry, terminal scratch cursor) pass with every toggle forced on. */
    public function test_wired_callers_pass_their_suites_with_rust_forced(): void
    {
        $this->nativeFfi();
        $prepend = tempnam(sys_get_temp_dir(), 'w33-2-prepend-');
        $this->assertIsString($prepend);
        try {
            $classes = var_export(self::CLASSES, true);
            file_put_contents($prepend, <<<PREPEND
                <?php
                require '{$this->basePath()}/vendor/autoload.php';
                if (App\\Services\\Releases\\Verification\\CandidatePreparationService::evaluateNative(4, pack('q2', 1, 1)) !== pack('q', 3)) {
                    fwrite(STDERR, 'Rust library did not load in the forced child.');
                    exit(97);
                }
                foreach ({$classes} as \$class) {
                    \$class::setUseRustFfi(true);
                }
                PREPEND);
            $process = new Process([PHP_BINARY, '-d', 'ffi.enable=true', '-d', 'auto_prepend_file='.$prepend, 'vendor/bin/phpunit', '--no-progress',
                'tests/Feature/PublicationOutboxTest.php', 'tests/Feature/VerificationReadyQueueTest.php', 'tests/Feature/PublishedVerificationScratchCleanupTest.php'],
                base_path(), ['NNTMUX_PIPELINE_FFI_LIB' => getenv('NNTMUX_PIPELINE_FFI_LIB') ?: false], null, 900);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            // The project's PHPUnit printer reports one JSON summary line.
            $summary = json_decode(trim($process->getOutput()), true);
            $this->assertIsArray($summary, $process->getOutput());
            $this->assertSame('passed', $summary['result'] ?? null);
            $this->assertGreaterThan(100, $summary['tests'] ?? 0);
            $this->assertSame($summary['tests'], $summary['passed'] ?? null);
        } finally {
            @unlink($prepend);
        }
    }

    public function test_paired_randomized_benchmarks_keep_php_default_below_threshold(): void
    {
        $this->nativeFfi();
        $path = '/tmp/wave33-2-release-verification-preparation-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $rounds = 3;
        $pairs = 21;
        $calls = 100;
        $report = [
            'protocol' => ['seed' => self::SEED, 'rounds' => $rounds, 'pairs_per_round' => $pairs, 'calls_per_side_per_pair' => $calls,
                'warmup_per_side_per_round' => 64, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'Random side order, identical randomized input indices per pair; toggle and parity outside timing.',
                'gate' => 'Each common/uncommon mean AND p99 speedup >=1.5 in every round; no averaging across helpers.',
                'scope' => 'Public helper dispatch including packing, FFI, allocation and unpacking. Excludes app boot and I/O.',
                'reproduce' => 'CARGO_TARGET_DIR=/tmp/cargo-w33-2 cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml && NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w33-2/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustReleaseVerificationPreparationParityTest.php'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'head' => self::command(['git', 'rev-parse', 'HEAD']), 'status' => self::command(['git', 'status', '--short']),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.model', 'hw.ncpu', 'hw.memsize'] : ['lscpu']),
            'php' => ['version' => PHP_VERSION, 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'profile' => 'opt-level=3, lto=true, codegen-units=1', 'source_sha256' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['rust/pipeline-ffi/src/nfo.rs', 'app/Support/RustPipelineLibrary.php', 'app/Console/Commands/ForwardVerifiedPublications.php',
            'app/Console/Commands/VerifyReleaseCandidates.php', 'app/Services/Releases/Verification/CandidatePreparationService.php',
            'app/Services/Releases/Verification/PreparedCandidateUpdate.php', 'app/Services/Releases/Verification/PublicationPrelock.php',
            'tests/Unit/RustReleaseVerificationPreparationParityTest.php'] as $file) {
            $report['source_sha256'][$file] = hash_file('sha256', base_path($file));
        }
        $library = getenv('NNTMUX_PIPELINE_FFI_LIB') ?: base_path('rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so'));
        $report['library'] = ['path' => realpath($library), 'sha256' => hash_file('sha256', $library)];
        $random = new Randomizer(new Mt19937(self::SEED));
        try {
            foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
                $name = $class.'::'.$method;
                $php = $method.'Php';
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $inputs) {
                    $expected = [];
                    foreach ($inputs as $args) {
                        $expected[] = serialize($class::$php(...$args));
                        $class::setUseRustFfi(true);
                        $this->assertSame(end($expected), serialize($class::$method(...$args)), $name.' preflight');
                    }
                    $cell = ['input_sha256' => hash('sha256', serialize($inputs)), 'rounds' => []];
                    for ($round = 0; $round < $rounds; $round++) {
                        foreach ([false, true] as $enabled) {
                            $class::setUseRustFfi($enabled);
                            for ($i = 0; $i < 64; $i++) {
                                $class::$method(...$inputs[$i % count($inputs)]);
                            }
                        }
                        $raw = ['php' => [], 'native' => []];
                        for ($pair = 0; $pair < $pairs; $pair++) {
                            $indices = [];
                            for ($i = 0; $i < $calls; $i++) {
                                $indices[] = $random->getInt(0, count($inputs) - 1);
                            }
                            $order = $random->getInt(0, 1) === 0 ? ['php', 'native'] : ['native', 'php'];
                            foreach ($order as $side) {
                                $class::setUseRustFfi($side === 'native');
                                foreach ($indices as $index) {
                                    $args = $inputs[$index];
                                    $start = hrtime(true);
                                    $result = $class::$method(...$args);
                                    $elapsed = hrtime(true) - $start;
                                    if (serialize($result) !== $expected[$index]) {
                                        throw new RuntimeException($name.'/'.$kind.' mismatch during timing');
                                    }
                                    $raw[$side][] = $elapsed;
                                }
                            }
                        }
                        $phpStats = self::statistics($raw['php']);
                        $nativeStats = self::statistics($raw['native']);
                        $cell['rounds'][] = ['raw_ns' => $raw, 'php' => $phpStats, 'native' => $nativeStats,
                            'mean_speedup' => $phpStats['mean_ns'] / max(1.0, $nativeStats['mean_ns']), 'p99_speedup' => $phpStats['p99_ns'] / max(1, $nativeStats['p99_ns'])];
                    }
                    $cell['qualifies'] = true;
                    foreach (['mean_speedup', 'p99_speedup'] as $metric) {
                        $values = array_column($cell['rounds'], $metric);
                        $cell['round_range'][$metric] = ['min' => min($values), 'max' => max($values)];
                        $cell['qualifies'] = $cell['qualifies'] && min($values) >= 1.5;
                    }
                    $report['results'][$name][$kind] = $cell;
                }
                $qualifies = $report['results'][$name]['common']['qualifies'] && $report['results'][$name]['uncommon']['qualifies'];
                $report['results'][$name]['qualifies'] = $qualifies;
                $class::setUseRustFfi(null);
                if (! $qualifies) {
                    $this->assertFalse($class::usesRustFfi(), $name.' must keep PHP as the default below 1.5x');
                }
            }
        } catch (Throwable $error) {
            $report['failures'][] = ['type' => $error::class, 'message' => $error->getMessage()];
            throw $error;
        } finally {
            $report['finished_at_utc'] = gmdate(DATE_ATOM);
            if (file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) === false) {
                throw new RuntimeException('Cannot preserve benchmark evidence at '.$path);
            }
            $summary = [];
            foreach ($report['results'] as $helper => $cells) {
                $summary[] = sprintf('%s common mean %.2fx p99 %.2fx; uncommon mean %.2fx p99 %.2fx', $helper,
                    $cells['common']['round_range']['mean_speedup']['min'], $cells['common']['round_range']['p99_speedup']['min'],
                    $cells['uncommon']['round_range']['mean_speedup']['min'], $cells['uncommon']['round_range']['p99_speedup']['min']);
            }
            fwrite(STDERR, "\nWave 33.2 raw benchmark evidence: {$path}\n".implode("\n", $summary)."\n");
        }
        $this->assertFileExists($path);
    }

    private function nativeFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('nfo', CandidatePreparationService::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The optimized library must load; native tests must not silently skip.');

        return $ffi;
    }

    private function basePath(): string
    {
        return addslashes(base_path());
    }

    /** @return list<array{class-string, string, list<list<mixed>>, list<list<mixed>>}> */
    private static function workloads(): array
    {
        $reason = 'Missing headers for 37 of 412 articles in segment group alt.binaries.example; waiting for propagation.';
        $long = str_repeat('Article <part'.str_repeat('é', 3).'@example.invalid> is not yet visible; ', 40);

        return [
            [ForwardVerifiedPublications::class, 'computeLingerBudgetMs', [[1761400000.25, 1761399999.9, null, 2000, 240.0], [1761400000.5, 1761400000.1, 1761400001.0, 1500, 840.0]],
                [[100.0, 120.0, null, 500, 240.0], [100.0, 50.0, null, 500, 240.0], [100.0, 100.0, null, 60000, 30.0], [100.0, 99.0, 99.5, 1000, 240.0],
                    [0.0, 0.0, null, PHP_INT_MAX, 240.0], [1.0, 1.0, null, PHP_INT_MIN, 1.0], [NAN, 1.0, null, 500, 240.0], [1.0, 1.0, INF, 500, INF],
                    [-0.0, 0.0, -0.0, 0, 0.0], [1.0, 0.5, null, 500, 240.0, 0.0], [1.0, 0.5, null, 500, 240.0, -5.0]]],
            [ForwardVerifiedPublications::class, 'computeRetryBackoffSeconds', [[0], [1], [2], [3, 1.0]],
                [[4], [40], [-1], [-3000], [PHP_INT_MAX], [PHP_INT_MIN], [2, -5.5], [2, NAN], [2, null, 0.25], [8, null, INF], [3, null, NAN], [1, -0.0, 30.0]]],
            [VerifyReleaseCandidates::class, 'normalizeScratchCursor', [[null, 500, false, 100, 100], [['after' => 100, 'ceiling' => 500], 500, false, 100, 200],
                [['after' => 400, 'ceiling' => 500], 500, false, 40, 480]],
                [[null, 0, false, 0, 0], [null, 500, true, 30, 30], [['after' => 500, 'ceiling' => 500], 500, false, 100, 500], [['after' => '12abc', 'ceiling' => 1.9e2], 0, true, 0, 0],
                    [['after' => -5, 'ceiling' => -9], -1, true, 0, -7], [['after' => true, 'ceiling' => [1]], 9, true, 0, 0], [['after' => null], PHP_INT_MAX, false, 100, 0],
                    [['ceiling' => PHP_INT_MAX], PHP_INT_MIN, false, PHP_INT_MAX, PHP_INT_MAX - 1], [['after' => 3], 9, false, PHP_INT_MIN, 0], [[0 => 'after'], 7, true, 1, 1]]],
            [CandidatePreparationService::class, 'truncateAssessmentReason', [[$reason], ['Header inventory incomplete.'], [null]],
                [[$long], [$long, 7], [str_repeat('€', 700)], [str_repeat('😀', 501)], ["\x80\x80\x80\xff", 2], ['', 0], ['abc', 0], ["a\xC3", 1], ["\xF0\x9F\x98", 4],
                    ["a\0b\0c", 3], ['abcdef', -2], [str_repeat('x', 1999).'é', 2000]]],
            [PreparedCandidateUpdate::class, 'classifyUpdateShape', [[true, false], [true, true], [false, false]], [[false, true]]],
            [PublicationPrelock::class, 'summarizePrelockState', [[18432, '/data/manifests/123-1.json', true, null, true, 412], [18432, '/data/manifests/123-1.json', true, null, true, 0]],
                [[null, null, false, null, false, null], [5, '/m', true, 'Below the minimum size.', false, null], [5, null, true, null, true, null], [5, '', true, null, true, -4],
                    [PHP_INT_MIN, '/m', false, 'stale', true, PHP_INT_MAX], [0, '/m', false, null, false, 3], [7, "/m\0é", true, '', false, PHP_INT_MIN]]],
        ];
    }

    /** @return array{float, float, ?float, int, float} */
    private static function lingerArgs(Randomizer $random): array
    {
        $special = [NAN, INF, -INF, -0.0, 0.0, 1e-300, 1.7976931348623157e308];
        $float = static fn (float $base, float $spread): float => $random->getInt(0, 30) === 0 ? $special[$random->getInt(0, 6)]
            : $base + ($random->getFloat(0.0, 1.0) - 0.5) * $spread;
        $wall = $float(1761400000.0, 20.0);

        return [$wall, $float($wall - 1.0, 4.0), $random->getInt(0, 2) === 0 ? null : $float($wall + 0.5, 2.0),
            $random->getInt(0, 20) === 0 ? $random->getInt(PHP_INT_MIN, PHP_INT_MAX) : $random->getInt(0, 5000), $random->getInt(0, 10) === 0 ? $float(10.0, 20.0) : (float) $random->getInt(30, 900)];
    }

    /** @return array{int, ?float} */
    private static function backoffArgs(Randomizer $random): array
    {
        $failures = match ($random->getInt(0, 9)) {
            0 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            1 => $random->getInt(-2000, 2000),
            2 => PHP_INT_MAX,
            default => $random->getInt(0, 12),
        };

        return [$failures, $random->getInt(0, 3) === 0 ? [1.0, -0.0, NAN, INF, $random->getFloat(-5.0, 60.0)][$random->getInt(0, 4)] : null];
    }

    /** @return array{?array<array-key, mixed>, int, bool, int, int} */
    private static function cursorArgs(Randomizer $random): array
    {
        $values = [null, 0, 1, -1, 99, 100, 101, 500, '42', '12abc', 'x', '', 3.7, -2.5, true, false, [], [1], PHP_INT_MAX, PHP_INT_MIN];
        $cursor = null;
        if ($random->getInt(0, 4) !== 0) {
            $cursor = [];
            foreach (['after', 'ceiling'] as $key) {
                if ($random->getInt(0, 5) !== 0) {
                    $cursor[$key] = $random->getInt(0, 2) === 0 ? $values[$random->getInt(0, count($values) - 1)] : $random->getInt(0, 600);
                }
            }
        }
        $pick = static fn (): int => [0, 1, 99, 100, 101, 500, -1, PHP_INT_MAX, PHP_INT_MIN][$random->getInt(0, 8)];

        return [$cursor, $random->getInt(0, 3) === 0 ? $pick() : $random->getInt(0, 600), $random->getInt(0, 1) === 1,
            $random->getInt(0, 3) === 0 ? $pick() : $random->getInt(0, 100), $random->getInt(0, 3) === 0 ? $pick() : $random->getInt(0, 600)];
    }

    private static function reason(Randomizer $random): string
    {
        $alphabet = ['a', ' ', "\0", "\x80", "\xBF", "\xC3", "\xA9", "\xE2", "\x82", "\xAC", "\xF0", "\x9F", "\x98", "\xFF", "\xC0", "\xF8", 'é', '€', '😀'];
        $length = $random->getInt(0, 4) === 0 ? $random->getInt(1990, 2010) : $random->getInt(0, 40);
        $reason = '';
        while (strlen($reason) < $length) {
            $reason .= $random->getInt(0, 2) === 0 ? $alphabet[$random->getInt(0, count($alphabet) - 1)] : 'x';
        }

        return $reason;
    }

    /** @return array{?int, ?string, bool, ?string, bool, ?int} */
    private static function prelockArgs(Randomizer $random): array
    {
        $int = static fn (): ?int => [null, 0, 1, -1, 412, PHP_INT_MAX, PHP_INT_MIN][$random->getInt(0, 6)];
        $string = static fn (): ?string => [null, '', '/m/1.json', "é\0", 'Below the minimum size.'][$random->getInt(0, 4)];

        return [$int(), $string(), $random->getInt(0, 1) === 1, $string(), $random->getInt(0, 1) === 1, $int()];
    }

    /**
     * The checkpoint cleanupTerminalScratch() wrote before extraction, for a pass that ended at $last.
     *
     * @param  array<array-key, mixed>|null  $cursor
     * @return array{after: int, ceiling: int}
     */
    private static function previousCheckpoint(?array $cursor, int $max, bool $exhausted, int $count, int $last): array
    {
        $after = max(0, (int) ($cursor['after'] ?? 0));
        $ceiling = max(0, (int) ($cursor['ceiling'] ?? 0));
        if ($ceiling === 0) {
            $ceiling = $max;
        }
        // Processing only moves the cursor forward.
        $after = max($after, $last);
        if ((! $exhausted && $count < 100) || $after >= $ceiling) {
            $after = 0;
            $ceiling = 0;
        }

        return ['after' => $after, 'ceiling' => $ceiling];
    }

    private static function encodeLinger(float $wall, float $oldest, ?float $wake, int $linger, float $maxAge, float $margin = 60.0): string
    {
        return pack('d2', $wall, $oldest).pack('q', (int) ($wake !== null)).pack('d', $wake ?? 0.0).pack('q', $linger).pack('d2', $maxAge, $margin);
    }

    private static function encodeBackoff(int $failures, ?float $delay, float $max = 30.0): string
    {
        return pack('q2', $failures, (int) ($delay !== null)).pack('d2', $delay ?? 0.0, $max);
    }

    /** @param array<array-key, mixed>|null $cursor */
    private static function encodeCursor(?array $cursor, int $max, bool $exhausted, int $count, int $last): string
    {
        return pack('q6', (int) ($cursor['after'] ?? 0), (int) ($cursor['ceiling'] ?? 0), $max, (int) $exhausted, $count, $last);
    }

    private static function encodePrelock(?int $size, ?string $path, bool $policyKnown, ?string $rejection, bool $coverageKnown, ?int $count): string
    {
        return pack('q8', (int) ($size !== null), $size ?? 0, (int) ($path !== null), (int) $policyKnown, (int) ($rejection !== null), (int) $coverageKnown,
            (int) ($count !== null), $count ?? 0);
    }

    /** @return list<int|float>|float */
    private static function raw(int $operation, string $input, string $format): array|float
    {
        $output = CandidatePreparationService::evaluateNative($operation, $input);
        if ($output === null) {
            throw new RuntimeException('Rust declined operation '.$operation.': '.bin2hex($input));
        }
        $values = array_values(unpack($format, $output) ?: []);

        return $format === 'd' ? $values[0] : $values;
    }

    /**
     * @param  list<int>  $values
     * @return array{count: int, mean_ns: float, p99_ns: int, min_ns: int, max_ns: int}
     */
    private static function statistics(array $values): array
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);

        return ['count' => $count, 'mean_ns' => array_sum($values) / $count, 'p99_ns' => $values[(int) ceil($count * 0.99) - 1],
            'min_ns' => $values[0], 'max_ns' => $values[$count - 1]];
    }

    /** @param list<string> $args */
    private static function command(array $args): string
    {
        $process = new Process($args, base_path());
        $process->mustRun();

        return trim($process->getOutput());
    }
}
