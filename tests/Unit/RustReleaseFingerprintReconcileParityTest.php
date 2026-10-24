<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Fingerprint\ReleaseFingerprintCollector;
use App\Support\RustPipelineLibrary;
use Closure;
use ReflectionClass;
use Tests\TestCase;

/**
 * Parity and paired caller benchmarks for the ReleaseFingerprintCollector reconciliation exports.
 *
 * Benchmark summary (macOS arm64 Apple M5, PHP 8.5.12 CLI without opcache, rustc 1.99.1 release
 * LTO dylib from base 6c2c99036; 1000 randomized-order pairs per workload after 20 warmups, each
 * sample averaging the listed call count; PHP closures are pre-bound so both sides pay one closure
 * call; timings include serialize()/pack() marshaling, the FFI boundary and unserialize()/array
 * rebuilding; machine load averaged 6.9 during the run, so p99 values carry noticeable jitter):
 *
 * - `timestampSummary` (adopted from 40 timestamps; the caller dispatches with Rust on versus off):
 *   40: 1224 -> 632 ns mean (1.94x), 2225 -> 1309 ns p99 (1.70x); 48: 2.14x mean, 1.77x p99;
 *   64: 2.45x mean, 1.92x p99; 128 (the per-server sample cap): 3793 -> 1046 ns (3.63x), p99 3.16x;
 *   uncommon 2000: 82.9 -> 8.0 us mean (10.41x), p99 8.56x. Below 40 PHP runs (1/16/39: 0.96x-1.01x,
 *   dispatch only); earlier runs measured Rust at 1: 1.18x, 16: 1.44x, 24: 1.76x mean / 1.36x p99,
 *   32: 1.74x mean / 1.51x p99, so 40 keeps margin under load. Timestamps beyond +/-2^40 stay in PHP.
 * - `parseOverview` (retained native PHP; `parseOverviewNative` exposed): Rust derives keys, PHP
 *   array_combine()s them with the row. Common 8-field LIST OVERVIEW.FMT: 1.42x mean / 1.45x p99
 *   (below 1.5x); 24 fields 1.95x / 2.00x; 3 fields 0.87x / 0.90x. A serialize() output variant
 *   measured 0.99x. The PHP explode()/array_combine() rebuild alone costs ~390 ns.
 * - `decodedFileRange` (retained; `decodedFileRangeNative` exposed): serializing the yEnc record
 *   costs more than the PHP checks: 0.47x / 0.45x mean (multipart / single-part), 0.06x unverified.
 * - `matchPar2SourcesToNzbFiles` (retained; `matchPar2SourcesToNzbFilesNative` exposed): PHP
 *   forwards only eligible first-segment fields, yet marshaling and unserialize() of the matches
 *   lose: 0.62x mean (20 files x 2 servers), 0.70x mean / 0.91x p99 (200 x 2), 0.60x (3 x 1).
 * - `mapIfscPreviewToSource` (retained; `mapIfscPreviewToSourceNative` exposed): unserialize() of
 *   the slice arrays costs more than PHP building them: 0.48x (16 slices), 0.61x, 0.41x (1 slice).
 *
 * Reproduce: NNTMUX_RELEASE_FP_RECONCILE_BENCH=1 [NNTMUX_RELEASE_FP_RECONCILE_SAMPLES=1000]
 * [NNTMUX_RELEASE_FP_RECONCILE_ONLY=<workload substring>] php artisan test --compact
 * --filter=test_benchmark_paired_callers tests/Unit/RustReleaseFingerprintReconcileParityTest.php
 */
class RustReleaseFingerprintReconcileParityTest extends TestCase
{
    private const string FFI_CDEF = <<<'C'
        int32_t nntmux_release_fp_decoded_file_range_packed(const char *data, size_t length, int64_t decoded_size, int32_t identity_verified, int64_t *out);
        C;

    private ReleaseFingerprintCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();

        if (RustPipelineLibrary::resolveDomain('release_fingerprint_reconcile_test', self::FFI_CDEF) === null) {
            $this->markTestSkipped('Rust pipeline FFI library is not available.');
        }
        $this->collector = (new ReflectionClass(ReleaseFingerprintCollector::class))->newInstanceWithoutConstructor();
        ReleaseFingerprintCollector::setUseRustFfi(true);
    }

    protected function tearDown(): void
    {
        ReleaseFingerprintCollector::setUseRustFfi(true);
        parent::tearDown();
    }

    public function test_decoded_file_range_matches_php(): void
    {
        $cases = [
            'multipart_valid' => $this->multipartMetadata(),
            'single_valid' => $this->singlePartMetadata(),
            'unverified_identity' => array_replace($this->multipartMetadata(), ['article_identity' => null]),
            'no_yenc' => array_replace($this->multipartMetadata(), ['yenc' => null]),
            'string_yenc' => array_replace($this->multipartMetadata(), ['yenc' => 'yenc']),
        ];
        $escape = $this->multipartMetadata();
        $escape['yenc']['payload_escape_state_valid'] = false;
        $cases['unmatched_escape'] = $escape;
        $range = $this->multipartMetadata();
        $range['yenc']['end'] = 10;
        $cases['range_mismatch'] = $range;
        $crc = $this->singlePartMetadata();
        $crc['yenc']['crc32'] = 'ABCDEF';
        $cases['single_crc_case_and_padding'] = $crc;
        $crc['yenc']['crc32'] = '1abcdef';
        $cases['single_crc_mismatch'] = $crc;
        foreach ($cases as $name => $metadata) {
            foreach ([str_repeat('x', 3000), str_repeat('y', 100), null] as $bytes) {
                $this->assertSame($this->php('decodedFileRange', $metadata, $bytes), ReleaseFingerprintCollector::decodedFileRangeNative($metadata, $bytes), $name);
            }
        }

        mt_srand(9301);
        for ($i = 0; $i < 4000; $i++) {
            $metadata = $this->randomRangeMetadata();
            $bytes = $this->pick([[6, null], [10, fn () => str_repeat('d', mt_rand(0, 40))]]);
            $this->assertSame($this->php('decodedFileRange', $metadata, $bytes), ReleaseFingerprintCollector::decodedFileRangeNative($metadata, $bytes), "random {$i}");
        }
    }

    public function test_match_par2_sources_matches_php(): void
    {
        [$sources, $observations] = $this->par2Workload(6, 2, 2);
        $this->assertSame($this->php('matchPar2SourcesToNzbFiles', $sources, $observations), ReleaseFingerprintCollector::matchPar2SourcesToNzbFilesNative($sources, $observations));
        $this->assertSame($this->php('matchPar2SourcesToNzbFiles', [], []), ReleaseFingerprintCollector::matchPar2SourcesToNzbFilesNative([], []));

        mt_srand(9302);
        $handled = 0;
        for ($i = 0; $i < 1500; $i++) {
            [$sources, $observations] = $this->randomPar2Input();
            $expected = $this->attempt(fn () => $this->php('matchPar2SourcesToNzbFiles', $sources, $observations));
            $native = ReleaseFingerprintCollector::matchPar2SourcesToNzbFilesNative($sources, $observations);
            if ($native !== null) {
                $handled++;
                $this->assertSame($expected, $native, "random {$i}");
            }
        }
        $this->assertGreaterThan(900, $handled);

        // Shapes PHP warns about or casts with float formatting stay in PHP.
        [$sources, $observations] = $this->par2Workload(2, 1, 1);
        $observations[1]['message_id'] = 1.5;
        $this->assertNull(ReleaseFingerprintCollector::matchPar2SourcesToNzbFilesNative($sources, $observations));
        [$sources, $observations] = $this->par2Workload(2, 1, 1);
        $observations[1]['server'] = "bad\xff";
        $this->assertNull(ReleaseFingerprintCollector::matchPar2SourcesToNzbFilesNative($sources, $observations));
        [$sources, $observations] = $this->par2Workload(2, 1, 1);
        $observations[1]['metadata'] = 'metadata';
        $this->assertNull(ReleaseFingerprintCollector::matchPar2SourcesToNzbFilesNative($sources, $observations));
    }

    public function test_map_ifsc_preview_matches_php(): void
    {
        $record = '00112233445566778899AABBCCDDEEFF78563412';
        $cases = [
            [str_repeat($record, 3), 250, 100, 16],
            [str_repeat($record, 3), 300, 100, 16],
            [str_repeat($record, 3), 150, 100, 2],
            [str_repeat($record, 3), null, 100, 16],
            [str_repeat($record, 3), 250, null, 16],
            [str_repeat($record, 3), 250, 0, 16],
            [str_repeat($record, 3), -250, 100, 16],
            [str_repeat($record, 3), PHP_INT_MAX, PHP_INT_MAX, 16],
            [str_repeat($record, 3), PHP_INT_MAX, intdiv(PHP_INT_MAX, 2) + 1, 16],
            [str_repeat($record, 3), 250, 100, 0],
            [str_repeat($record, 205), 250, 100, 300],
            [str_repeat($record, 204), 250, 100, 300],
            [substr($record, 1), 250, 100, 16],
            ['', 250, 100, 16],
            [null, 250, 100, 16],
            [str_replace('A', 'g', $record), 250, 100, 16],
        ];
        foreach ($cases as $i => [$hex, $length, $block, $max]) {
            $this->assertSame($this->php('mapIfscPreviewToSource', $hex, $length, $block, $max), ReleaseFingerprintCollector::mapIfscPreviewToSourceNative($hex, $length, $block, $max), "case {$i}");
        }

        mt_srand(9303);
        for ($i = 0; $i < 3000; $i++) {
            $hex = '';
            for ($r = mt_rand(0, 20); $r > 0; $r--) {
                $hex .= md5((string) mt_rand()).sprintf('%08x', mt_rand());
            }
            $hex = $this->pick([[20, $hex], [1, strtoupper($hex)], [1, $hex.'0'], [1, $hex."\0"]]);
            $length = $this->pick([[6, fn () => mt_rand(0, 5000)], [1, null], [1, -3], [1, PHP_INT_MAX]]);
            $block = $this->pick([[6, fn () => mt_rand(1, 700)], [1, null], [1, 0], [1, -4], [1, PHP_INT_MAX]]);
            $max = $this->pick([[6, 16], [2, fn () => mt_rand(-1, 30)]]);
            $this->assertSame($this->php('mapIfscPreviewToSource', $hex, $length, $block, $max), ReleaseFingerprintCollector::mapIfscPreviewToSourceNative($hex, $length, $block, $max), "random {$i}");
        }
    }

    public function test_timestamp_summary_matches_php(): void
    {
        $cases = [
            [['a' => 1_700_000_000], 1, 1],
            [['a' => 1_700_000_100, 'b' => 1_700_000_000, 'c' => 1_700_000_050], 3, 4],
            [['a' => 0, 'b' => -1, 'c' => 951_782_400], 5, 2],
            [['a' => -62_198_755_200, 'b' => 253_402_300_800], 2, 2],
            [['a' => 1 << 40, 'b' => -(1 << 40)], 2, 2],
        ];
        foreach ($cases as $i => [$timestamps, $observations, $expected]) {
            $this->assertSame($this->php('timestampSummary', $timestamps, $observations, $expected), ReleaseFingerprintCollector::timestampSummaryNative($timestamps, $observations, $expected), "case {$i}");
        }
        $this->assertNull(ReleaseFingerprintCollector::timestampSummaryNative([], 0, 0));
        $this->assertNull(ReleaseFingerprintCollector::timestampSummaryNative(['a' => PHP_INT_MAX], 1, 1));

        mt_srand(9304);
        for ($i = 0; $i < 3000; $i++) {
            $timestamps = [];
            $base = $this->pick([[8, 1_700_000_000], [1, fn () => mt_rand(-(1 << 40), 1 << 40)], [1, fn () => mt_rand(-80_000_000_000, 300_000_000_000)]]);
            for ($n = mt_rand(1, 90); $n > 0; $n--) {
                $timestamps["<id{$n}@x>"] = $base + mt_rand(-100_000, 100_000);
            }
            $timestamps = array_map(static fn (int $t): int => max(-(1 << 40), min(1 << 40, $t)), $timestamps);
            $observations = mt_rand(0, 70);
            $expected = mt_rand(0, 70);
            $expectedSummary = $this->php('timestampSummary', $timestamps, $observations, $expected);
            $this->assertSame($expectedSummary, ReleaseFingerprintCollector::timestampSummaryNative($timestamps, $observations, $expected), "random {$i}");
            $this->assertSame($expectedSummary, $this->caller('timestampSummary', $timestamps, $observations, $expected), "caller {$i}");
        }

        // The caller hands 40 or more timestamps to Rust and keeps PHP for out-of-range values.
        $many = [];
        for ($n = 0; $n < 45; $n++) {
            $many["<m{$n}@x>"] = 1_700_000_000 - $n * 977;
        }
        $this->assertSame($this->php('timestampSummary', $many, 45, 50), $this->caller('timestampSummary', $many, 45, 50));
        $many['<huge@x>'] = PHP_INT_MAX;
        $this->assertSame($this->php('timestampSummary', $many, 46, 46), $this->caller('timestampSummary', $many, 46, 46));
    }

    public function test_parse_overview_matches_php(): void
    {
        $format = ['Subject:', 'From:', 'Date:', 'Message-ID:', 'References:', ':bytes', ':lines', 'Xref:full'];
        $row = "12345\tsubject\tposter@example\tSat, 24 Oct 2026 10:00:00 +0000\t<id@x>\t\t700000\t5400\tXref: news.example a.b.c:12345";
        $cases = [
            [$row, $format],
            [$row, ['Subject:', 'From:', 'Date:', 'Message-ID:', 'References:', 'Bytes:', 'Lines:', 'Xref:full']],
            [$row, [' Subject: ', 'From', '5:', '007:', '-1:', 'X Y:full', ':BYTES', 'Bytes::', 'lines:full', "\x0B:Tab\t"]],
            ['', $format],
            ['12', $format],
            ["12\t\t\t", ['a', 'b']],
            [null, $format],
            [$row, []],
        ];
        foreach ($cases as $i => [$caseRow, $caseFormat]) {
            $this->assertSame($this->php('parseOverview', $caseRow, $caseFormat), ReleaseFingerprintCollector::parseOverviewNative($caseRow, $caseFormat), "case {$i}");
        }
        foreach ([["a\0b"], [1 => 'Subject:'], ['Subject:', 'SUBJECT'], ['Subject:', ''], ['Subject:', '::'], ['Article_Number:'], ["a\tb:"]] as $i => $caseFormat) {
            $this->assertNull(ReleaseFingerprintCollector::parseOverviewNative($row, $caseFormat), "fallback {$i}");
        }
        $this->assertSame(['article_number' => '12', 'subject' => 's'], ReleaseFingerprintCollector::parseOverviewNative("12\ts", ['Subject:', 'Subject:full', '']));

        mt_srand(9305);
        $handled = 0;
        $fields = ['Subject:', 'From:', 'Date:', 'Message-ID:', 'References:', ':bytes', ':lines', 'Xref:full', 'Bytes:', 'LINES:', ':Bytes',
            ' Xref:FULL ', 'x-trace:full', 'X Y:full', '', ':', '::', '12:', '0:', '-3:', '05:', 'Name', 'a:b', ":tab\t", "\x0Bv:", 'ÄÖ:', 'article_number'];
        for ($i = 0; $i < 4000; $i++) {
            $caseFormat = [];
            for ($n = mt_rand(1, 12); $n > 0; $n--) {
                $caseFormat[] = $fields[array_rand($fields)];
            }
            $values = [];
            for ($n = mt_rand(0, 14); $n > 0; $n--) {
                $values[] = $this->pick([[5, fn () => (string) mt_rand()], [2, ''], [2, 'value with : colon'], [1, "bin\xff\x00"]]);
            }
            $caseRow = implode("\t", $values);
            $native = ReleaseFingerprintCollector::parseOverviewNative($caseRow, $caseFormat);
            if ($native !== null) {
                $handled++;
                $this->assertSame($this->php('parseOverview', $caseRow, $caseFormat), $native, "random {$i}");
            }
        }
        $this->assertGreaterThan(1000, $handled);
    }

    /**
     * Paired randomized-order caller benchmark; run with NNTMUX_RELEASE_FP_RECONCILE_BENCH=1.
     */
    public function test_benchmark_paired_callers(): void
    {
        if (getenv('NNTMUX_RELEASE_FP_RECONCILE_BENCH') !== '1') {
            $this->expectNotToPerformAssertions();

            return;
        }
        $samples = (int) (getenv('NNTMUX_RELEASE_FP_RECONCILE_SAMPLES') ?: 1000);
        $record = static fn (int $i): string => md5("slice{$i}").sprintf('%08x', crc32("slice{$i}"));
        $hex16 = implode('', array_map($record, range(0, 15)));
        $hex204 = implode('', array_map($record, range(0, 203)));
        $timestamps = static function (int $count): array {
            $values = [];
            for ($i = 0; $i < $count; $i++) {
                $values["<part{$i}.".md5((string) $i).'@news.example>'] = 1_700_000_000 + ($i * 7919 % 86_400);
            }

            return $values;
        };
        $format = ['Subject:', 'From:', 'Date:', 'Message-ID:', 'References:', ':bytes', ':lines', 'Xref:full'];
        $row = "12345678\t[01/40] - \"release.part01.rar\" yEnc (1/120)\tposter <poster@example.com>\tSat, 24 Oct 2026 10:00:00 +0000\t<part1of120.abcdef0123@news.example>\t\t716800\t5512\tXref: news.example alt.binaries.test:12345678";
        $wideFormat = array_merge($format, array_map(static fn (int $i): string => "X-Header-{$i}:full", range(1, 16)));
        $wideRow = $row.str_repeat("\tX-Header: some extended value", 16);
        $shortFormat = ['Subject:', 'From:', ':bytes'];
        $shortRow = "12345678\tsubject\tposter@example\t716800";
        $hex1 = substr($hex16, 0, 40);
        $multipart = $this->multipartMetadata();
        $single = $this->singlePartMetadata();
        $unverified = array_replace($multipart, ['article_identity' => null]);
        $bytes = str_repeat('x', 3000);
        $par2Common = $this->par2Workload(20, 2, 3);
        $par2Large = $this->par2Workload(200, 2, 3);
        $par2Small = $this->par2Workload(3, 1, 3);

        // PHP closures are bound to the collector once (not per call), so both sides pay one closure call.
        $collector = $this->collector;
        $workloads = [
            'decodedFileRange common multipart' => [Closure::bind(fn () => $this->decodedFileRange($multipart, $bytes), $collector, ReleaseFingerprintCollector::class), fn () => ReleaseFingerprintCollector::decodedFileRangeNative($multipart, $bytes), 200],
            'decodedFileRange uncommon single-part' => [Closure::bind(fn () => $this->decodedFileRange($single, $bytes), $collector, ReleaseFingerprintCollector::class), fn () => ReleaseFingerprintCollector::decodedFileRangeNative($single, $bytes), 200],
            'decodedFileRange uncommon unverified' => [Closure::bind(fn () => $this->decodedFileRange($unverified, $bytes), $collector, ReleaseFingerprintCollector::class), fn () => ReleaseFingerprintCollector::decodedFileRangeNative($unverified, $bytes), 200],
            'matchPar2 common 20 files x2 servers' => [Closure::bind(fn () => $this->matchPar2SourcesToNzbFiles(...$par2Common), $collector, ReleaseFingerprintCollector::class), fn () => ReleaseFingerprintCollector::matchPar2SourcesToNzbFilesNative(...$par2Common), 10],
            'matchPar2 uncommon 200 files x2 servers' => [Closure::bind(fn () => $this->matchPar2SourcesToNzbFiles(...$par2Large), $collector, ReleaseFingerprintCollector::class), fn () => ReleaseFingerprintCollector::matchPar2SourcesToNzbFilesNative(...$par2Large), 2],
            'matchPar2 uncommon 3 files x1 server' => [Closure::bind(fn () => $this->matchPar2SourcesToNzbFiles(...$par2Small), $collector, ReleaseFingerprintCollector::class), fn () => ReleaseFingerprintCollector::matchPar2SourcesToNzbFilesNative(...$par2Small), 50],
            'mapIfscPreview common 16 slices' => [Closure::bind(fn () => $this->mapIfscPreviewToSource($hex16, 15_000_000, 1_000_000, 16), $collector, ReleaseFingerprintCollector::class), fn () => ReleaseFingerprintCollector::mapIfscPreviewToSourceNative($hex16, 15_000_000, 1_000_000, 16), 50],
            'mapIfscPreview uncommon 204 records, 16 slices' => [Closure::bind(fn () => $this->mapIfscPreviewToSource($hex204, 300_000_000, 1_000_000, 16), $collector, ReleaseFingerprintCollector::class), fn () => ReleaseFingerprintCollector::mapIfscPreviewToSourceNative($hex204, 300_000_000, 1_000_000, 16), 50],
            'mapIfscPreview uncommon 1 slice' => [Closure::bind(fn () => $this->mapIfscPreviewToSource($hex1, 500, 1_000_000, 1), $collector, ReleaseFingerprintCollector::class), fn () => ReleaseFingerprintCollector::mapIfscPreviewToSourceNative($hex1, 500, 1_000_000, 1), 200],
        ];
        // Adopted caller: timestampSummary() itself with Rust disabled versus enabled.
        foreach ([1, 16, 39, 40, 48, 64, 128, 2000] as $count) {
            $values = $timestamps($count);
            $workloads["timestampSummary caller {$count}"] = [
                Closure::bind(function () use ($values, $count) {
                    ReleaseFingerprintCollector::setUseRustFfi(false);

                    return self::timestampSummary($values, $count, $count);
                }, null, ReleaseFingerprintCollector::class),
                Closure::bind(function () use ($values, $count) {
                    ReleaseFingerprintCollector::setUseRustFfi(true);

                    return self::timestampSummary($values, $count, $count);
                }, null, ReleaseFingerprintCollector::class),
                $count > 200 ? 10 : 200,
            ];
        }
        $workloads['parseOverview common 8 fields'] = [Closure::bind(fn () => $this->parseOverview($row, $format), $collector, ReleaseFingerprintCollector::class), fn () => ReleaseFingerprintCollector::parseOverviewNative($row, $format), 200];
        $workloads['parseOverview uncommon 24 fields'] = [Closure::bind(fn () => $this->parseOverview($wideRow, $wideFormat), $collector, ReleaseFingerprintCollector::class), fn () => ReleaseFingerprintCollector::parseOverviewNative($wideRow, $wideFormat), 100];
        $workloads['parseOverview uncommon 3 fields'] = [Closure::bind(fn () => $this->parseOverview($shortRow, $shortFormat), $collector, ReleaseFingerprintCollector::class), fn () => ReleaseFingerprintCollector::parseOverviewNative($shortRow, $shortFormat), 200];
        $only = getenv('NNTMUX_RELEASE_FP_RECONCILE_ONLY') ?: '';
        foreach ($workloads as $name => [$phpCall, $rustCall, $repeat]) {
            if ($only !== '' && ! str_contains($name, $only)) {
                continue;
            }
            $this->assertSame($phpCall(), $rustCall(), $name);
            $measure = static function (callable $call) use ($repeat): float {
                $started = hrtime(true);
                for ($i = 0; $i < $repeat; $i++) {
                    $call();
                }

                return (hrtime(true) - $started) / $repeat;
            };
            for ($i = 0; $i < 20; $i++) {
                $measure($phpCall);
                $measure($rustCall);
            }
            $phpTimes = [];
            $rustTimes = [];
            for ($i = 0; $i < $samples; $i++) {
                if (mt_rand(0, 1) === 0) {
                    $phpTimes[] = $measure($phpCall);
                    $rustTimes[] = $measure($rustCall);
                } else {
                    $rustTimes[] = $measure($rustCall);
                    $phpTimes[] = $measure($phpCall);
                }
            }
            $stats = static function (array $times): array {
                sort($times);

                return [array_sum($times) / count($times), $times[(int) floor((count($times) - 1) * 0.99)]];
            };
            [$phpMean, $phpP99] = $stats($phpTimes);
            [$rustMean, $rustP99] = $stats($rustTimes);
            fwrite(STDERR, sprintf("%-48s php %9.1f ns (p99 %9.1f) rust %9.1f ns (p99 %9.1f) mean %.2fx p99 %.2fx n=%d x%d\n",
                $name, $phpMean, $phpP99, $rustMean, $rustP99, $phpMean / $rustMean, $phpP99 / $rustP99, $samples, $repeat));
        }
    }

    /** The collector's PHP implementation, with every Rust dispatch disabled. */
    private function php(string $method, mixed ...$arguments): mixed
    {
        ReleaseFingerprintCollector::setUseRustFfi(false);
        try {
            return Closure::bind(fn () => $this->{$method}(...$arguments), $this->collector, ReleaseFingerprintCollector::class)();
        } finally {
            ReleaseFingerprintCollector::setUseRustFfi(true);
        }
    }

    /** The collector's production dispatch, with Rust enabled. */
    private function caller(string $method, mixed ...$arguments): mixed
    {
        return Closure::bind(fn () => $this->{$method}(...$arguments), $this->collector, ReleaseFingerprintCollector::class)();
    }

    /** @return mixed the result, or the class of the Throwable PHP raised */
    private function attempt(callable $call): mixed
    {
        try {
            return $call();
        } catch (\Throwable $error) {
            return $error::class;
        }
    }

    /** @param list<array{0: int, 1: mixed}> $weighted */
    private function pick(array $weighted): mixed
    {
        $roll = mt_rand(1, array_sum(array_column($weighted, 0)));
        foreach ($weighted as [$weight, $value]) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $value instanceof Closure ? $value() : $value;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function identity(): array
    {
        return [
            'parser_engine' => 'nntmux-rust-article-identity',
            'schema_version' => 3,
            'request' => ['syntax_status' => 'valid', 'token' => '<part1of120.abcdef0123@news.example>'],
            'head_response' => ['availability' => 'available', 'parse_status' => 'valid', 'response_code' => 221],
            'head_message_id' => ['availability' => 'available', 'parse_status' => 'valid', 'occurrence_count' => 1, 'matches_request' => true],
            'relations' => ['head_response_matches_request' => true, 'head_response_matches_head_field' => true, 'body_response_matches_request' => true],
        ];
    }

    /** @return array<string, mixed> */
    private function multipartMetadata(): array
    {
        return [
            'article_identity' => $this->identity(),
            'yenc' => [
                'name' => 'release.part01.rar', 'line_length' => 128, 'part' => 2, 'total_parts' => 120, 'multipart' => true,
                'file_size' => 358_400_000, 'begin' => 3001, 'end' => 6000, 'ypart_range_valid' => true,
                'payload_escape_state_valid' => true, 'header_numeric_fields_valid' => true, 'trailer_numeric_fields_valid' => true,
                'trailer_size_is_decimal' => true, 'decoded_size_matches_yenc' => true, 'decoded_size_matches_yend_size' => true,
                'decoded_size_matches_header_size' => true, 'decoded_size_matches_ypart_range' => true,
                'all_declared_crc32_fields_are_hex' => true, 'declared_crc32_is_hex' => true, 'declared_crc32_matches' => true,
                'pcrc32' => '0a1b2c3d', 'crc32' => null, 'observed_decoded_crc32' => '0a1b2c3d', 'segment_crc32_basis' => 'pcrc32',
                'trailer_fields' => ['size' => '3000', 'part' => '2', 'pcrc32' => '0a1b2c3d'],
                'trailer_part_matches_header' => true, 'trailer_total_matches_header' => true,
                'par2_first_16k_md5_candidate' => null,
            ],
            'yenc_nzb_part_number_matches' => true, 'yenc_nzb_total_parts_matches' => true,
            'yenc_nzb_trailer_part_number_matches' => true, 'yenc_nzb_trailer_total_parts_matches' => true,
            'decoded_format_metadata' => ['signatures' => [], 'headers' => []],
        ];
    }

    /** @return array<string, mixed> */
    private function singlePartMetadata(): array
    {
        $metadata = $this->multipartMetadata();
        $metadata['yenc'] = array_replace($metadata['yenc'], [
            'part' => null, 'total_parts' => null, 'multipart' => false, 'file_size' => 3000, 'begin' => null, 'end' => null,
            'ypart_range_valid' => null, 'pcrc32' => null, 'crc32' => 'abcdef', 'observed_decoded_crc32' => '00abcdef',
            'segment_crc32_basis' => 'crc32',
        ]);
        $metadata['yenc_nzb_part_number_matches'] = null;

        return $metadata;
    }

    /** @return array<string, mixed> */
    private function randomRangeMetadata(): array
    {
        $tri = fn (int $true = 8) => $this->pick([[$true, true], [2, false], [2, null], [1, 'yes'], [1, 1]]);
        $multipart = mt_rand(0, 1) === 1;
        $begin = $this->pick([[8, 1], [4, fn () => mt_rand(-2, 30)], [1, null], [1, '1']]);
        $end = is_int($begin) ? $this->pick([[8, fn () => $begin + mt_rand(-2, 40)], [1, null], [1, (string) $begin]]) : mt_rand(0, 40);
        $crc = fn () => $this->pick([[6, fn () => sprintf('%08x', mt_rand())], [2, fn () => dechex(mt_rand(0, 0xFFFF))], [1, fn () => strtoupper(sprintf('%08x', mt_rand()))],
            [1, ''], [1, 'xyz'], [1, '123456789'], [1, null], [1, 5], [1, '00000000']]);
        $observed = $this->pick([[6, fn () => sprintf('%08x', mt_rand())], [3, '0000abcd'], [1, null], [1, 7], [1, 'ABCD'], [1, '0000ABCD']]);
        $pcrc = $crc();
        if (mt_rand(0, 3) === 0 && is_string($observed)) {
            $pcrc = ltrim($observed, '0') ?: '0';
        }
        $yenc = [
            'multipart' => $this->pick([[8, $multipart], [1, null], [1, 'true']]),
            'part' => $this->pick([[5, $multipart ? mt_rand(1, 5) : null], [1, null], [1, 0], [1, '2']]),
            'file_size' => $this->pick([[8, fn () => mt_rand(0, 40)], [1, -1], [1, null], [1, '10'], [1, 10.0]]),
            'begin' => $begin,
            'end' => $end,
            'ypart_range_valid' => $tri(),
            'payload_escape_state_valid' => $tri(12),
            'header_numeric_fields_valid' => $tri(12),
            'trailer_numeric_fields_valid' => $tri(12),
            'trailer_size_is_decimal' => $tri(12),
            'decoded_size_matches_yenc' => $tri(12),
            'decoded_size_matches_yend_size' => $tri(12),
            'all_declared_crc32_fields_are_hex' => $tri(12),
            'declared_crc32_is_hex' => $tri(12),
            'declared_crc32_matches' => $tri(12),
            'pcrc32' => $pcrc,
            'crc32' => $crc(),
            'observed_decoded_crc32' => $observed,
            'segment_crc32_basis' => $this->pick([[3, 'pcrc32'], [3, 'crc32'], [2, 'missing-crc32'], [2, 'missing-pcrc32'], [1, 'unavailable-multipart-structure'],
                [1, 'unavailable-conflicting-yenc-field'], [2, null], [1, 4]]),
        ];
        foreach (array_keys($yenc) as $key) {
            if (mt_rand(1, 30) === 1) {
                unset($yenc[$key]);
            }
        }
        if (is_int($yenc['begin'] ?? null) && is_int($yenc['end'] ?? null) && mt_rand(0, 1) === 1) {
            $yenc['file_size'] = $yenc['end'];
        }
        $identity = $this->pick([[10, $this->identity()], [1, null], [1, 'identity'],
            [1, array_replace($this->identity(), ['schema_version' => 2])], [1, array_replace($this->identity(), ['relations' => ['body_response_matches_request' => false]])]]);

        return [
            'article_identity' => $identity,
            'yenc' => $this->pick([[30, $yenc], [1, null], [1, 'yenc'], [1, []]]),
            'yenc_nzb_part_number_matches' => $tri(),
            'yenc_nzb_total_parts_matches' => $tri(12),
            'yenc_nzb_trailer_part_number_matches' => $tri(12),
            'yenc_nzb_trailer_total_parts_matches' => $tri(12),
        ];
    }

    /**
     * Release-shaped PAR2 matching input: `$files` NZB files each sampled first/middle/last on
     * `$servers` providers, with one PAR2 source description per file.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function par2Workload(int $files, int $servers, int $roles): array
    {
        $sources = [];
        $observations = [];
        for ($f = 0; $f < $files; $f++) {
            $prefix = md5("prefix{$f}");
            $sources[] = ['recovery_set_id' => md5('set'), 'file_id' => strtoupper(md5("file{$f}")), 'filename' => sprintf('release.part%03d.rar', $f + 1),
                'file_length' => 358_400_000 + $f, 'md5_first_16k' => $f % 7 === 6 ? strtoupper($prefix) : $prefix, 'md5' => md5("full{$f}")];
            for ($s = 0; $s < $servers; $s++) {
                foreach (array_slice(['first', 'middle', 'last'], 0, $roles) as $role) {
                    $metadata = $this->multipartMetadata();
                    $metadata['yenc']['file_size'] = $f % 11 === 10 ? 1 : 358_400_000 + $f;
                    $metadata['yenc']['par2_first_16k_md5_candidate'] = $role === 'first' ? $prefix : null;
                    $metadata['decoded_format_metadata']['signatures'] = [['format' => 'rar5', 'offset' => 0, 'basis' => 'magic'], ['format' => 'zip', 'offset' => 7]];
                    $metadata['headers'] = array_fill(0, 12, str_repeat('h', 80));
                    $observations[] = [
                        'file_index' => $f, 'nzb_subject' => sprintf('[%d/%d] "release.part%03d.rar" yEnc (1/120)', $f + 1, $files, $f + 1), 'kind' => $f === 0 ? 'par2' : 'rar',
                        'message_id' => "part{$role}of{$f}.".md5("{$f}{$role}").'@news.example', 'segment_number' => $role === 'first' ? 1 : 60,
                        'sample_role' => $role, 'server' => "news{$s}.example", 'provider_label' => $s === 0 ? 'Primary' : null,
                        'server_role' => $s === 0 ? 'primary' : 'alternate', 'metadata' => $metadata,
                    ];
                }
            }
        }

        return [$sources, $observations];
    }

    /** @return array{0: list<mixed>, 1: list<mixed>} */
    private function randomPar2Input(): array
    {
        $prefixes = array_map(static fn (int $i): string => md5("p{$i}"), range(0, 5));
        $sources = [];
        for ($i = mt_rand(0, 8); $i > 0; $i--) {
            $prefix = $prefixes[array_rand($prefixes)];
            $source = [
                'recovery_set_id' => $this->pick([[8, md5('set')], [1, null], [1, 7], [1, true]]),
                'file_id' => $this->pick([[6, fn () => md5((string) mt_rand(0, 4))], [2, fn () => strtoupper(md5((string) mt_rand(0, 4)))], [1, null], [1, 3]]),
                'filename' => $this->pick([[6, 'a.rar'], [1, null], [1, 2.5]]),
                'file_length' => $this->pick([[6, fn () => mt_rand(1, 3)], [2, null], [1, '2'], [1, 2.0]]),
                'md5_first_16k' => $this->pick([[8, $prefix], [2, strtoupper($prefix)], [1, 'short'], [1, null], [1, 5], [1, 1.5e40], [1, false]]),
            ];
            if (mt_rand(1, 10) === 1) {
                unset($source[array_rand($source)]);
            }
            $sources[] = $source;
        }
        $observations = [];
        for ($i = mt_rand(0, 12); $i > 0; $i--) {
            $metadata = $this->randomRangeMetadata();
            if (is_array($metadata['yenc'])) {
                $metadata['yenc']['par2_first_16k_md5_candidate'] = $this->pick([[8, $prefixes[array_rand($prefixes)]], [2, strtoupper($prefixes[0])], [1, null], [1, 'nope'], [1, 3]]);
                $metadata['yenc']['file_size'] = $this->pick([[6, fn () => mt_rand(1, 3)], [1, null], [1, '1']]);
                foreach (['decoded_size_matches_header_size', 'decoded_size_matches_ypart_range', 'trailer_part_matches_header', 'trailer_total_matches_header'] as $field) {
                    $metadata['yenc'][$field] = $this->pick([[10, true], [1, false], [1, null]]);
                }
            }
            $metadata['decoded_format_metadata'] = $this->pick([[8, ['signatures' => [
                ['format' => 'rar', 'offset' => 0, 'basis' => 'magic'], ['format' => 'zip', 'offset' => $this->pick([[1, 0], [1, '0'], [1, 3]])],
                ['format' => 5, 'offset' => 0], 'junk', ['format' => 'mkv', 'offset' => 0, 'basis' => 4],
            ]]], [1, ['signatures' => 'none']], [1, 'meta'], [1, null]]);
            $observation = [
                'file_index' => $this->pick([[10, fn () => mt_rand(0, 3)], [1, '1'], [1, null]]),
                'nzb_subject' => $this->pick([[5, 'subject'], [1, null], [1, 4.25], [1, ['nested' => true]]]),
                'kind' => $this->pick([[6, 'rar'], [2, 'par2'], [1, null], [1, 3]]),
                'message_id' => $this->pick([[8, fn () => 'id'.mt_rand(0, 3).'@x'], [1, ''], [1, null], [1, 42], [1, true]]),
                'segment_number' => $this->pick([[8, 1], [1, 2], [1, '1']]),
                'sample_role' => $this->pick([[8, 'first'], [1, 'last'], [1, null]]),
                'server' => $this->pick([[6, fn () => 'news'.mt_rand(0, 2)], [1, null], [1, 5], [1, false]]),
                'provider_label' => $this->pick([[5, 'Label'], [3, null], [1, 'Other']]),
                'metadata' => $metadata,
            ];
            if (mt_rand(1, 10) === 1) {
                unset($observation[array_rand($observation)]);
            }
            $observations[] = $observation;
            if (mt_rand(1, 4) === 1) {
                $observations[] = $observation;
            }
        }

        return [$sources, $observations];
    }
}
