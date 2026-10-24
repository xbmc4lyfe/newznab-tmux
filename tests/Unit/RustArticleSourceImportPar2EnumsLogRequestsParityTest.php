<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\NzbImportStatus;
use App\Enums\Par2RenameOutcome;
use App\Http\Requests\Admin\AdminLogFileRequest;
use App\Http\Requests\Admin\AdminLogViewerRequest;
use App\Services\NNTP\NNTPService;
use App\Services\Releases\Verification\NntpArticleSource;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

/**
 * Wave 22 Slice 22.2. No network/database/production runtime is used.
 * Reproduce the optimized build, then run this test with NNTMUX_W22_ARTICLE_BENCH=1.
 * Benchmark: 3 repetitions x 41 randomized paired samples x 200 inner calls,
 * plus 200 warmups per side. Raw ns/call samples, inputs, source/library hashes,
 * PHP/Rust/host identity and failures are saved to a unique system-temp JSON file.
 * p99 is the maximum of 41 batch averages: a coarse local latency screen,
 * not an estimate of individual-call production tail latency.
 * Production defaults stay false unless ALL helpers pass >=1.5x for both
 * common/uncommon mean and p99 in every repetition, including FFI overhead.
 *
 * Apple M5 / PHP 8.5.12 / Rust 1.99.1 release screen (2026-10-24):
 * worst-repetition PHP/Rust speedup (common mean/p99; uncommon mean/p99):
 * batchSizing:       0.1117/0.0774; 0.0966/0.0756
 * prefetchSizing:    0.0855/0.0741; 0.0838/0.0795
 * articleByteCap:    0.0751/0.0690; 0.0595/0.0589
 * bufferByteCap:     0.0642/0.0429; 0.0660/0.0405
 * NZB classify:      0.1058/0.1078; 0.1026/0.1070
 * NZB label:         0.0887/0.0922; 0.0778/0.0497
 * PAR2 classify:     0.1068/0.0825; 0.1096/0.0746
 * PAR2 label:        0.0897/0.0722; 0.0854/0.0462
 * file input:        0.1004/0.1042; 0.0859/0.0566
 * file levels:       0.1002/0.0414; 0.0659/0.0937
 * viewer input:      0.0908/0.0865; 0.0741/0.0636
 * FFI serialization/allocation dominates every path; all five defaults stay PHP.
 * Raw screen: nntmux-w22-article-bench-46mi72pg27keex6LPYW in system temp.
 */
final class RustArticleSourceImportPar2EnumsLogRequestsParityTest extends TestCase
{
    private const array CLASSES = [NntpArticleSource::class, NzbImportStatus::class, Par2RenameOutcome::class, AdminLogFileRequest::class, AdminLogViewerRequest::class];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetRustFfi();
        }
        parent::tearDown();
    }

    public function test_production_defaults_follow_benchmark_gate(): void
    {
        foreach (self::CLASSES as $class) {
            self::assertFalse($class::usesRustFfi(), $class.' initial default');
            $class::resetRustFfi();
            self::assertFalse($class::usesRustFfi(), $class);
            $class::enableRustFfi();
            self::assertTrue($class::usesRustFfi());
            $class::disableRustFfi();
            self::assertFalse($class::usesRustFfi());
            $class::enableRustFfi();
            $class::resetRustFfi();
            self::assertFalse($class::usesRustFfi());
        }
    }

    private function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('wave22-parity-tests', NntpArticleSource::FFI_CDEF);
        self::assertNotNull($ffi, 'Build and copy the release library before running parity tests.');

        return $ffi;
    }

    /** @param list<mixed> $args */
    private function direct(int $operation, array $args): mixed
    {
        $ffi = $this->ffi();
        $wire = serialize($args);
        $capacity = strlen($wire) + 1024;
        $out = $ffi->new('char['.$capacity.']');
        // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
        $written = $ffi->nntmux_w22_article_source_enums_log_requests($operation, $wire, strlen($wire), $out, $capacity);
        self::assertIsInt($written);
        self::assertGreaterThanOrEqual(2, $written, 'The ABI must succeed; PHP fallback must not hide a missing native path.');
        self::assertLessThanOrEqual($capacity, $written);

        return unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
    }

    /** @return list<array{class-string, string, list<mixed>, int, mixed}> */
    private static function cases(): array
    {
        $rows = [];
        foreach ([[67108864, 1048576, 4, 8], [64, 10, 8, 16], [0, 0, 0, 0], [-9, -1, -8, -16], [PHP_INT_MAX, 1, PHP_INT_MAX, PHP_INT_MAX], [1, PHP_INT_MAX, 8, 16]] as $args) {
            [$buffer, $article, $connections, $window] = $args;
            $frames = max(1, intdiv(max(1, $buffer), max(1, $article)));
            $connections = min($frames, max(1, min(8, $connections)));
            $expected = $connections * min(max(1, intdiv($frames, $connections)), max(1, min(16, $window)));
            $rows[] = [NntpArticleSource::class, 'batchSizing', $args, 0, $expected];
        }
        foreach ([[1, 1, 1, 1], [17, 8, 8, 3], [128, 99, 8, 16], [7, -2, 1, 7], [9007199254740991, 8, 8, 1125899906842624]] as [$count, $connections, $expectedConnections, $expectedWindow]) {
            $rows[] = [NntpArticleSource::class, 'prefetchSizing', [$count, $connections], 1, ['connections' => $expectedConnections, 'window' => $expectedWindow]];
        }
        foreach ([[1048576, null, 1048576], [1048576, 8, 8], [0, -8, -8], [PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MIN]] as [$article, $max, $expected]) {
            $rows[] = [NntpArticleSource::class, 'articleByteCap', [$article, $max], 2, $expected];
        }
        foreach ([[10, 20, 20], [-10, -20, -10], [PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MAX]] as [$max, $buffer, $expected]) {
            $rows[] = [NntpArticleSource::class, 'bufferByteCap', [$max, $buffer], 10, $expected];
        }
        foreach ([NzbImportStatus::class => ['inserted' => 'Inserted', 'queued' => 'Queued', 'metadata_ignored' => 'Metadata Ignored', 'metadata_deferred' => 'Metadata Deferred', 'duplicate' => 'Duplicate', 'blacklisted' => 'Blacklisted', 'nogroup' => 'No Group', 'failed' => 'Failed'], Par2RenameOutcome::class => ['renamed' => 'Renamed', 'no_match' => 'No Match', 'retryable' => 'Retryable', 'deferred' => 'Deferred']] as $class => $labels) {
            $op = $class === NzbImportStatus::class ? 3 : 5;
            foreach ($labels + ['' => null, 'UNKNOWN' => null, "renamed\0" => null, ' inserted ' => null] as $value => $label) {
                $rows[] = [$class, 'classify', [$value], $op, $label === null ? null : $value];
                $rows[] = [$class, 'label', [$value], $op + 1, $label];
            }
        }
        foreach ([null, true, false, 0, PHP_INT_MIN, '', ' app.log ', "\0\x0b\t\r\n binary\xff\0\x0c \n", 'é😀', "\t\r\0"] as $file) {
            $expected = ['file' => is_string($file) ? trim($file) : $file];
            $rows[] = [AdminLogFileRequest::class, 'normalizeInput', [$file], 7, $expected];
            foreach ([null, ' search ', "\0\xff\x0b", 0] as $query) {
                $rows[] = [AdminLogViewerRequest::class, 'normalizeInput', [$file, $query], 9, $expected + ['q' => is_string($query) ? trim($query) : $query]];
            }
        }
        foreach ([[], ['error', 'info', 'error'], ['1', '01', '1', '', "\0", ''], ["\xff", 'é', "\xff"]] as $levels) {
            $rows[] = [AdminLogFileRequest::class, 'normalizeLevels', [$levels], 8, array_values(array_unique($levels))];
        }

        return $rows;
    }

    public function test_native_php_and_direct_abi_match_exact_expected_results(): void
    {
        foreach (self::cases() as [$class, $method, $args, $operation, $expected]) {
            self::assertSame($expected, $class::{$method.'Php'}(...$args), $class.' '.$method.' PHP');
            self::assertSame($expected, $this->direct($operation, $args), $class.' '.$method.' ABI');
            self::assertSame($expected, $class::{$method.'Native'}(...$args), $class.' '.$method.' bridge');
            foreach ([false, true] as $enabled) {
                $enabled ? $class::enableRustFfi() : $class::disableRustFfi();
                self::assertSame($expected, self::dispatch($class, $method, $args));
            }
        }
    }

    /**
     * @param  class-string  $class
     * @param  list<mixed>  $args
     */
    private static function dispatch(string $class, string $method, array $args): mixed
    {
        if ($class === NzbImportStatus::class || $class === Par2RenameOutcome::class) {
            return $method === 'classify' ? $class::classify($args[0])?->value : $class::tryFrom($args[0])?->label();
        }
        if ($class === NntpArticleSource::class) {
            return $class::$method(...$args);
        }

        return $class::{$method.($class::usesRustFfi() ? 'Native' : 'Php')}(...$args);
    }

    public function test_noncanonical_inputs_decline_and_preserve_php_values_and_errors(): void
    {
        foreach ([1.25, INF, ['nested'], (object) ['file' => 'x']] as $value) {
            self::assertSame(AdminLogFileRequest::normalizeInputPhp($value), AdminLogFileRequest::normalizeInputNative($value));
            self::assertSame(AdminLogViewerRequest::normalizeInputPhp($value, $value), AdminLogViewerRequest::normalizeInputNative($value, $value));
        }
        foreach ([['x' => 'error', 8 => 'error', 3 => 'info'], [1, '1', true, false, null, 1.25]] as $levels) {
            self::assertSame(AdminLogFileRequest::normalizeLevelsPhp($levels), AdminLogFileRequest::normalizeLevelsNative($levels));
        }
        self::assertNan(AdminLogFileRequest::normalizeInputNative(NAN)['file']);
        self::assertNan(AdminLogViewerRequest::normalizeInputNative(NAN, NAN)['q']);
        $level = 'error';
        $levels = [&$level, &$level];
        self::assertSame(['error'], AdminLogFileRequest::normalizeLevelsNative($levels));
        foreach ([[PHP_INT_MAX, 3], [-1, 8]] as $args) {
            self::assertSame(NntpArticleSource::prefetchSizingPhp(...$args), NntpArticleSource::prefetchSizingNative(...$args));
        }
    }

    public function test_php_level_object_coercion_retains_original_error(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('could not be converted to string');
        self::assertSame([], AdminLogFileRequest::normalizeLevelsPhp([new \stdClass]));
    }

    public function test_native_level_object_coercion_retains_original_error(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('could not be converted to string');
        AdminLogFileRequest::normalizeLevelsNative([new \stdClass]);
    }

    public function test_php_prefetch_zero_count_retains_division_by_zero_error(): void
    {
        $this->expectException(\DivisionByZeroError::class);
        NntpArticleSource::prefetchSizingPhp(0, 1);
    }

    public function test_native_prefetch_zero_count_retains_division_by_zero_error(): void
    {
        $this->expectException(\DivisionByZeroError::class);
        NntpArticleSource::prefetchSizingNative(0, 1);
    }

    public function test_real_request_preparation_validation_levels_and_source_sizing_callers(): void
    {
        $previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['release_verification' => ['article_bytes' => 10, 'download_buffer_bytes' => 64, 'download_connections' => 8, 'pipeline_window' => 16]]));
        Container::setInstance($container);
        try {
            foreach ([false, true] as $enabled) {
                foreach (self::CLASSES as $class) {
                    $enabled ? $class::enableRustFfi() : $class::disableRustFfi();
                }
                $source = new NntpArticleSource($this->createMock(NNTPService::class), maximumBytes: 7);
                self::assertSame(6, $source->batchSize());
                self::assertSame(7, (new ReflectionMethod($source, 'maximumArticleBytes'))->invoke($source));
                $source->prefetch([], static fn () => self::fail('Empty prefetch has no heartbeat or I/O.'));
                $request = AdminLogFileRequest::create('/', 'GET', ['file' => " \tapp.log\0", 'levels' => ['error', 'info', 'error'], 'limit' => 100]);
                (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);
                self::assertSame('app.log', $request->input('file'));
                $validator = (new Factory(new Translator(new ArrayLoader, 'en')))->make($request->all(), $request->rules());
                $request->setValidator($validator);
                self::assertFalse($validator->fails());
                self::assertSame(['error', 'info'], $request->levels());
                foreach ([['search' => ' old ', 'q' => ' new ', 'expected' => 'new'], ['search' => ' old ', 'expected' => 'old'], ['search' => ' old ', 'q' => null, 'expected' => null]] as $input) {
                    $viewer = AdminLogViewerRequest::create('/', 'GET', $input + ['file' => ' app.log ']);
                    (new ReflectionMethod($viewer, 'prepareForValidation'))->invoke($viewer);
                    self::assertSame('app.log', $viewer->input('file'));
                    self::assertSame($input['expected'], $viewer->input('q'));
                }
            }
        } finally {
            Container::setInstance($previous);
        }
    }

    public function test_missing_library_falls_back_for_all_classes(): void
    {
        $property = new ReflectionProperty(RustPipelineLibrary::class, 'domainLibraries');
        $saved = $property->getValue();
        try {
            $property->setValue(null, ['wave22-article-source-sizing' => false, 'wave22-article-source-enums-log-requests' => false]);
            foreach (self::CLASSES as $class) {
                $class::resetRustFfi();
                $class::enableRustFfi();
            }
            foreach (self::cases() as [$class, $method, $args, , $expected]) {
                self::assertSame($expected, $class::{$method.'Native'}(...$args));
                self::assertSame($expected, self::dispatch($class, $method, $args));
            }
        } finally {
            $property->setValue(null, $saved);
            foreach (self::CLASSES as $class) {
                $class::resetRustFfi();
            }
        }
    }

    public function test_abi_rejects_invalid_input_and_preserves_output_on_short_capacity(): void
    {
        $ffi = $this->ffi();
        $out = $ffi->new('char[1024]');
        $sentinel = str_repeat('Z', 1024);
        FFI::memcpy($out, $sentinel, 1024);
        foreach (['', 'a:1:{i:00;N;}', 'a:1:{i:0;R:1;}', 'a:1:{i:0;O:8:"stdClass":0:{}}', 'a:1:{s:1:"0";N;}', 'a:2:{i:0;N;i:0;N;}', 'a:1:{i:0;d:1.0;}', 'a:1:{i:0;N;}junk'] as $wire) {
            // @phpstan-ignore method.notFound
            self::assertSame(-1, $ffi->nntmux_w22_article_source_enums_log_requests(7, $wire, strlen($wire), $out, 1024));
            self::assertSame($sentinel, FFI::string($out, 1024));
        }
        foreach (self::cases() as [, , $args, $op, $expected]) {
            $wire = serialize($args);
            $required = strlen(serialize($expected));
            // @phpstan-ignore method.notFound
            self::assertSame(-$required, $ffi->nntmux_w22_article_source_enums_log_requests($op, $wire, strlen($wire), $out, $required - 1));
            self::assertSame($sentinel, FFI::string($out, 1024));
        }
        $wire = serialize([null]);
        foreach ([[null, 0, $out, 1024], [$wire, strlen($wire), null, 0], [$wire, PHP_INT_MAX, $out, 1024], [$wire, strlen($wire), $out, PHP_INT_MAX], [$out, 6, $out, 1024]] as [$input, $length, $output, $capacity]) {
            // @phpstan-ignore method.notFound
            self::assertSame(-1, $ffi->nntmux_w22_article_source_enums_log_requests(7, $input, $length, $output, $capacity));
            self::assertSame($sentinel, FFI::string($out, 1024));
        }
    }

    /** @return list<array{class-string, string, list<mixed>, list<mixed>}> */
    private static function workloads(): array
    {
        return [
            [NntpArticleSource::class, 'batchSizing', [67108864, 1048576, 4, 8], [PHP_INT_MAX, 1, PHP_INT_MAX, PHP_INT_MAX]],
            [NntpArticleSource::class, 'prefetchSizing', [16, 4], [127, 8]],
            [NntpArticleSource::class, 'articleByteCap', [1048576, null], [PHP_INT_MIN, PHP_INT_MAX]],
            [NntpArticleSource::class, 'bufferByteCap', [1048576, 67108864], [-1, PHP_INT_MIN]],
            [NzbImportStatus::class, 'classify', ['inserted'], ['metadata_deferred']],
            [NzbImportStatus::class, 'label', ['inserted'], ['metadata_ignored']],
            [Par2RenameOutcome::class, 'classify', ['renamed'], ['deferred']],
            [Par2RenameOutcome::class, 'label', ['renamed'], ['retryable']],
            [AdminLogFileRequest::class, 'normalizeInput', [' app.log '], ["\0\x0b".str_repeat('é log ', 40)."\xff \t"]],
            [AdminLogFileRequest::class, 'normalizeLevels', [['error', 'info', 'error']], [array_merge(array_fill(0, 128, 'error'), array_fill(0, 128, 'info'), ['debug', '0', '00', "\xff"])]],
            [AdminLogViewerRequest::class, 'normalizeInput', [' app.log ', ' warning '], ["\0".str_repeat('é', 255), "\x0b".str_repeat('binary\xff ', 100)."\0"]],
        ];
    }

    public function test_paired_randomized_benchmark(): void
    {
        if (getenv('NNTMUX_W22_ARTICLE_BENCH') !== '1') {
            self::markTestSkipped('Opt-in local benchmark.');
        }
        $this->ffi();
        $root = dirname(__DIR__, 2);
        $files = ['rust/pipeline-ffi/src/nfo.rs', 'app/Services/Releases/Verification/NntpArticleSource.php', 'app/Enums/NzbImportStatus.php', 'app/Enums/Par2RenameOutcome.php', 'app/Http/Requests/Admin/AdminLogFileRequest.php', 'app/Http/Requests/Admin/AdminLogViewerRequest.php', 'tests/Unit/RustArticleSourceImportPar2EnumsLogRequestsParityTest.php'];
        $artifact = ['protocol' => '3 reps x 41 randomized pairs x 200 inner calls; 200 warmups/side; ns/call batch averages; p99 nearest rank', 'timestamp_utc' => gmdate('c'), 'php' => PHP_VERSION, 'ffi' => ini_get('ffi.enable'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'host' => php_uname(), 'cpu' => trim((string) shell_exec('sysctl -n machdep.cpu.brand_string')), 'load' => sys_getloadavg(), 'php_binary' => PHP_BINARY, 'vendor_sha256' => hash_file('sha256', $root.'/vendor/composer/installed.php'), 'rust' => shell_exec('rustc --version'), 'profile' => 'cargo release opt-level=3 lto=true codegen-units=1', 'base_head' => trim((string) shell_exec('git rev-parse HEAD')), 'lock_hash' => hash_file('sha256', $root.'/composer.lock'), 'library_sha256' => hash_file('sha256', $root.'/storage/app/nntmux-native/libnntmux_pipeline.dylib'), 'sources' => [], 'runs' => [], 'failures' => []];
        foreach ($files as $file) {
            $artifact['sources'][$file] = hash_file('sha256', $root.'/'.$file);
        }
        $path = tempnam(sys_get_temp_dir(), 'nntmux-w22-article-bench-');
        self::assertNotFalse($path);
        try {
            foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $args) {
                    for ($rep = 0; $rep < 3; $rep++) {
                        $class::disableRustFfi();
                        $expected = self::dispatch($class, $method, $args);
                        $class::enableRustFfi();
                        self::assertSame($expected, self::dispatch($class, $method, $args));
                        foreach ([false, true] as $enabled) {
                            $enabled ? $class::enableRustFfi() : $class::disableRustFfi();
                            for ($i = 0; $i < 200; $i++) {
                                self::dispatch($class, $method, $args);
                            }
                        }
                        $rng = new Randomizer(new Mt19937(2202 + $rep));
                        $samples = ['php' => [], 'rust' => []];
                        $orders = [];
                        for ($pair = 0; $pair < 41; $pair++) {
                            $order = $rng->shuffleArray([false, true]);
                            $orders[] = $order;
                            foreach ($order as $enabled) {
                                $enabled ? $class::enableRustFfi() : $class::disableRustFfi();
                                $start = hrtime(true);
                                for ($i = 0; $i < 200; $i++) {
                                    self::dispatch($class, $method, $args);
                                }
                                $samples[$enabled ? 'rust' : 'php'][] = (hrtime(true) - $start) / 200;
                            }
                        }
                        $stats = [];
                        foreach ($samples as $backend => $values) {
                            $stats[$backend.'_mean_ns'] = array_sum($values) / count($values);
                            sort($values);
                            $stats[$backend.'_p99_ns'] = $values[(int) ceil(count($values) * 0.99) - 1];
                        }
                        $stats['mean_speedup'] = $stats['php_mean_ns'] / $stats['rust_mean_ns'];
                        $stats['p99_speedup'] = $stats['php_p99_ns'] / $stats['rust_p99_ns'];
                        $artifact['runs'][] = ['class' => $class, 'method' => $method, 'kind' => $kind, 'repetition' => $rep, 'serialized_args_base64' => base64_encode(serialize($args)), 'order' => $orders, 'samples_ns' => $samples] + $stats;
                        fwrite(STDERR, json_encode(['method' => $method, 'class' => $class, 'kind' => $kind, 'rep' => $rep] + $stats).PHP_EOL);
                    }
                }
            }
        } catch (Throwable $error) {
            $artifact['failures'][] = $error->getMessage();
            throw $error;
        } finally {
            file_put_contents($path, json_encode($artifact, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            fwrite(STDERR, 'Wave 22 benchmark artifact: '.$path.PHP_EOL);
        }
    }
}
