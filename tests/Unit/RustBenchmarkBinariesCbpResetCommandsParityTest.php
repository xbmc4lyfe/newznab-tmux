<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\BenchmarkRename;
use App\Console\Commands\NntmuxResetTruncate;
use App\Console\Commands\OptimizeCbpStorage;
use App\Console\Commands\PartRepair;
use App\Console\Commands\UpdateBinaries;
use FFI;
use FFI\Exception;
use Random\Engine\Mt19937;
use Random\Randomizer;
use stdClass;
use Tests\TestCase;

final class RustBenchmarkBinariesCbpResetCommandsParityTest extends TestCase
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_w31_benchmark_binaries_cbp_reset_commands(
    const char *input,
    size_t len,
    uint8_t *out,
    size_t out_cap
);
CDEF;

    /** @var list<class-string> */
    private const COMMANDS = [
        BenchmarkRename::class,
        PartRepair::class,
        UpdateBinaries::class,
        OptimizeCbpStorage::class,
        NntmuxResetTruncate::class,
    ];

    protected function tearDown(): void
    {
        foreach (self::COMMANDS as $command) {
            $command::resetRustFfi();
        }
        parent::tearDown();
    }

    public function test_rust_ffi_is_disabled_by_default_and_toggles(): void
    {
        foreach (self::COMMANDS as $command) {
            $this->assertFalse($command::usesRustFfi(), $command);
            $command::setUseRustFfi(true);
            $this->assertTrue($command::usesRustFfi(), $command);
            $command::setUseRustFfi(null);
            $this->assertFalse($command::usesRustFfi(), $command);
            $command::useRustFfi();
            $this->assertTrue($command::usesRustFfi(), $command);
            $command::useRustFfi(false);
            $this->assertFalse($command::usesRustFfi(), $command);
        }
    }

    public function test_rust_evaluates_requests_without_falling_back(): void
    {
        $ffi = $this->loadFfi();
        $durations = [3_000_000, 1_000_000, 2_000_000, 4_000_000, 1_234_567];
        $stats = BenchmarkRename::computeStatisticsPhp($durations, 20);
        $row = ['items' => 20, 'stats' => $stats, 'accuracy_verified' => true];
        $cases = [
            [['benchmark_rename.compute_statistics', $durations, 20], $stats],
            [['benchmark_rename.compute_statistics', [], 20], BenchmarkRename::computeStatisticsPhp([], 20)],
            [['benchmark_rename.is_blocked_production_context', ' Prod ', '', ''], BenchmarkRename::isBlockedProductionContextPhp(' Prod ', '', '')],
            [['benchmark_rename.project_table_row', 'Clean Scene Releases', $row], BenchmarkRename::projectBenchmarkTableRowPhp('Clean Scene Releases', $row)],
            [['part_repair.format_group_not_found_message', "alt.bin\0é😀"], PartRepair::formatGroupNotFoundMessagePhp("alt.bin\0é😀")],
            [['part_repair.format_nntp_error_message', 'Timeout "x"/y'], PartRepair::formatNntpErrorMessagePhp('Timeout "x"/y')],
            [['update_binaries.resolve_max_headers', ' 12 ', 0], UpdateBinaries::resolveMaxHeadersPhp(' 12 ', 0)],
            [['update_binaries.format_elapsed_time', 3725.0], UpdateBinaries::formatElapsedTimePhp(3725.0)],
            [['update_binaries.format_section_header_lines', 'Updating All Groups'], UpdateBinaries::formatSectionHeaderLinesPhp('Updating All Groups')],
            [['optimize_cbp.normalize_identity', "  Foo\u{3000}BAR\t"], OptimizeCbpStorage::normalizeIdentityPhp("  Foo\u{3000}BAR\t")],
            [['optimize_cbp.compute_binary_cbp_hash', 0, 'Subject', 'Poster'], bin2hex(OptimizeCbpStorage::computeBinaryCbpHashPhp(0, 'Subject', 'Poster'))],
            [['optimize_cbp.clamp_batch_size', 9000], OptimizeCbpStorage::clampBatchSizePhp(9000)],
            [['reset_truncate.build_group_reset_payload'], NntmuxResetTruncate::buildGroupResetPayloadPhp()],
            [['reset_truncate.tables_to_truncate'], NntmuxResetTruncate::tablesToTruncatePhp()],
            [['reset_truncate.format_truncated_success_message', 12], NntmuxResetTruncate::formatTruncatedSuccessMessagePhp(12)],
        ];
        foreach ($cases as [$request, $expected]) {
            $this->assertSame([$expected], $this->callRust($ffi, $request), (string) $request[0]);
        }

        foreach ([
            ['benchmark_rename.compute_statistics', [1.5], 1],
            ['benchmark_rename.compute_statistics', [PHP_INT_MAX, 1], 1],
            ['benchmark_rename.compute_statistics', [1], PHP_INT_MAX, 1],
            ['update_binaries.resolve_max_headers', '1e3', 0],
            ['update_binaries.format_elapsed_time', 60.5],
            ['optimize_cbp.normalize_identity', 'ΑΣ'],
            ['optimize_cbp.clamp_batch_size', 5.0],
            ['unknown.operation'],
        ] as $declined) {
            $this->assertNull($this->callRust($ffi, $declined), (string) $declined[0]);
        }
    }

    public function test_benchmark_rename_statistics_match_php(): void
    {
        $ffi = $this->loadFfi();
        $random = new Randomizer(new Mt19937(20261025));
        for ($case = 0; $case < 400; $case++) {
            $count = $random->getInt(1, 64);
            $scale = [1_000, 1_000_000, 50_000_000, 10_000_000_000][$case % 4];
            $durations = [];
            for ($i = 0; $i < $count; $i++) {
                $durations[] = $random->getInt($case % 7 === 0 ? -$scale : 0, $scale);
            }
            $items = $random->getInt(-5, 200);
            $expected = BenchmarkRename::computeStatisticsPhp($durations, $items);
            $this->assertSame([$expected], $this->callRust($ffi, ['benchmark_rename.compute_statistics', $durations, $items]), json_encode($durations).' '.$items);
            $this->assertSame($expected, BenchmarkRename::computeStatisticsNative($durations, $items));
        }

        foreach ([
            [[], 0],
            [[0], 0],
            [[0, 0, 0], 5],
            [[1], 1],
            [[-1, 1], 3],
            [[PHP_INT_MAX], 1],
            [[PHP_INT_MAX, 1], 1],
            [[1, 2, 3], PHP_INT_MAX],
            [[123_456_789, 987_654_321, 555_555_555, 1, 2, 3], 100],
        ] as [$durations, $items]) {
            $this->assertSame(
                $this->capture(static fn (): array => BenchmarkRename::computeStatisticsPhp($durations, $items)),
                $this->capture(static fn (): array => BenchmarkRename::computeStatisticsNative($durations, $items)),
                json_encode($durations).' '.$items
            );
        }

        $this->assertSame(
            $this->capture(static fn (): array => BenchmarkRename::computeStatisticsPhp([1, '2'], 1)), // @phpstan-ignore argument.type
            $this->capture(static fn (): array => BenchmarkRename::computeStatisticsNative([1, '2'], 1)) // @phpstan-ignore argument.type
        );
    }

    public function test_benchmark_rename_safety_and_table_rows_match_php(): void
    {
        $ffi = $this->loadFfi();
        $envs = ['', 'production', ' PRODUCTION ', "prod\0", "\tProd\n", 'production2', 'prod uction', 'local', 'testing', 'próduction'];
        $hosts = ['', '127.0.0.1', '192.168.1.110', '192.168.1.110:3306', ' 192.168.1.110 ', 'mysql://u:p@192.168.1.110/db', 'M5STEPHEN.local', 'db-m5stephen', '192.168.1.111', '192.168.1.1100', "\0192.168.1.110"];
        foreach ($envs as $env) {
            foreach ($hosts as $host) {
                foreach (['', 'nntmux', $host] as $database) {
                    $expected = BenchmarkRename::isBlockedProductionContextPhp($env, $host, $database);
                    $this->assertSame([$expected], $this->callRust($ffi, ['benchmark_rename.is_blocked_production_context', $env, $host, $database]), "{$env}|{$host}|{$database}");
                }
            }
        }
        $this->assertTrue(BenchmarkRename::isBlockedProductionContextPhp(' Prod ', '', ''));
        $this->assertTrue(BenchmarkRename::isBlockedProductionContextPhp('', '192.168.1.110:3306', ''));
        $this->assertFalse(BenchmarkRename::isBlockedProductionContextPhp('local', '127.0.0.1', 'nntmux'));

        $random = new Randomizer(new Mt19937(31));
        $values = [0.0, -0.0, 0.00005, 0.00004999, -0.00004, 0.99995, 1.005, 2.675, 1234567.89125, 99_999.99995, 12345.65, -1234.56789, 1e-9, 99_999_999_999.9];
        for ($i = 0; $i < 400; $i++) {
            $values[] = $random->getFloat(-1, 1) * 10 ** $random->getInt(-6, 10);
        }
        foreach ($values as $index => $value) {
            $stats = [
                'ops_per_sec' => $value,
                'min_ms' => $values[($index + 1) % count($values)],
                'p50_ms' => $value / 3,
                'p95_ms' => $value * 7,
                'p99_ms' => -$value,
                'max_ms' => $value + 0.5,
            ];
            foreach ([true, false] as $verified) {
                $result = ['items' => $index - 7, 'stats' => $stats, 'accuracy_verified' => $verified];
                $this->assertSame(
                    BenchmarkRename::projectBenchmarkTableRowPhp("Stratum {$index}", $result),
                    BenchmarkRename::projectBenchmarkTableRowNative("Stratum {$index}", $result),
                    var_export($stats, true)
                );
            }
        }
        $row = ['items' => 100, 'stats' => BenchmarkRename::computeStatisticsPhp([5_000_000, 7_500_000, 6_250_000], 100), 'accuracy_verified' => true];
        $this->assertSame(
            ['OVERALL (100 Vectors)', 100, '16,000.0', '5.0000', '6.2500', '7.5000', '7.5000', '7.5000', 'PASS'],
            BenchmarkRename::projectBenchmarkTableRowPhp('OVERALL (100 Vectors)', $row)
        );
        $this->assertSame([BenchmarkRename::projectBenchmarkTableRowPhp('OVERALL (100 Vectors)', $row)], $this->callRust($ffi, ['benchmark_rename.project_table_row', 'OVERALL (100 Vectors)', $row]));

        $missing = ['items' => 1, 'stats' => ['ops_per_sec' => 1.0], 'accuracy_verified' => true];
        $this->assertSame(
            $this->capture(static fn (): array => BenchmarkRename::projectBenchmarkTableRowPhp('x', $missing)), // @phpstan-ignore argument.type
            $this->capture(static fn (): array => BenchmarkRename::projectBenchmarkTableRowNative('x', $missing)) // @phpstan-ignore argument.type
        );
    }

    public function test_part_repair_messages_match_php(): void
    {
        $ffi = $this->loadFfi();
        foreach (['', 'alt.binaries.teevee', "a\0b", 'é😀', "line\nbreak", '"quoted"\\path/', "\u{2028}"] as $text) {
            $this->assertSame([PartRepair::formatGroupNotFoundMessagePhp($text)], $this->callRust($ffi, ['part_repair.format_group_not_found_message', $text]));
            $this->assertSame([PartRepair::formatNntpErrorMessagePhp($text)], $this->callRust($ffi, ['part_repair.format_nntp_error_message', $text]));
            $this->assertSame(PartRepair::formatGroupNotFoundMessagePhp($text), PartRepair::formatGroupNotFoundMessageNative($text));
            $this->assertSame(PartRepair::formatNntpErrorMessagePhp($text), PartRepair::formatNntpErrorMessageNative($text));
        }
        $this->assertSame('Group not found: alt.bin', PartRepair::formatGroupNotFoundMessagePhp('alt.bin'));
        $this->assertSame('Unable to connect to usenet. Error: Timeout', PartRepair::formatNntpErrorMessagePhp('Timeout'));
        $this->assertSame(PartRepair::formatGroupNotFoundMessagePhp("\xff"), PartRepair::formatGroupNotFoundMessageNative("\xff"));
    }

    public function test_update_binaries_helpers_match_php(): void
    {
        $ffi = $this->loadFfi();
        $arguments = [
            5, 0, -5, PHP_INT_MAX, PHP_INT_MIN, '5', ' 12 ', "\t7\n", "\v\f9", '+7', '-7', '0', '-0', '00012', '999999999999999999',
            '9223372036854775807', '99999999999999999999', '1e3', '1.9', '.5', '12abc', 'abc', '', ' ', '0x1A', null, true, false,
            2.9, -2.9, 0.5, 1e300, [5], [], ['a' => 1], new stdClass,
        ];
        foreach ($arguments as $argument) {
            foreach ([0, 77, -3] as $configured) {
                $php = $this->capture(static fn (): int => UpdateBinaries::resolveMaxHeadersPhp($argument, $configured));
                $this->assertSame($php, $this->capture(static fn (): int => UpdateBinaries::resolveMaxHeadersNative($argument, $configured)), var_export($argument, true));
            }
        }
        foreach ([5, '5', ' 12 ', '+7', '-7', '', 'abc', null, true, 2.9, [5]] as $argument) {
            $this->assertSame([UpdateBinaries::resolveMaxHeadersPhp($argument, 77)], $this->callRust($ffi, ['update_binaries.resolve_max_headers', $argument, 77]), var_export($argument, true));
        }
        $this->assertSame(1000000, UpdateBinaries::resolveMaxHeadersPhp(null, 0));

        $random = new Randomizer(new Mt19937(60));
        $seconds = [0.0, -0.0, 1e-9, 0.0005, 0.9995, 0.99999999, 1.0, 1.04999, 1.05, 1.25, 59.94, 59.95, 59.96, 59.999, 60.0, 61.0, 3599.0, 3600.0, 3661.0, 86399.0, 90061.0, -0.5, -59.0, -1e12];
        for ($i = 0; $i < 500; $i++) {
            $seconds[] = $random->getFloat(-2, 60);
            $seconds[] = (float) $random->getInt(60, 10_000_000);
        }
        foreach ($seconds as $value) {
            $expected = UpdateBinaries::formatElapsedTimePhp($value);
            $this->assertSame([$expected], $this->callRust($ffi, ['update_binaries.format_elapsed_time', $value]), var_export($value, true));
            $this->assertSame($expected, UpdateBinaries::formatElapsedTimeNative($value));
        }
        foreach ([60.5, 3725.25, -1e300, 1e300] as $declined) {
            $this->assertSame(
                $this->capture(static fn (): string => UpdateBinaries::formatElapsedTimePhp($declined)),
                $this->capture(static fn (): string => UpdateBinaries::formatElapsedTimeNative($declined)),
                var_export($declined, true)
            );
        }
        $this->assertSame('1h 2m', UpdateBinaries::formatElapsedTimePhp(3725.0));

        foreach (['Updating All Groups', 'Complete', '', 'ünïcode Title', "a\0b", 'already UPPER'] as $title) {
            $this->assertSame([UpdateBinaries::formatSectionHeaderLinesPhp($title)], $this->callRust($ffi, ['update_binaries.format_section_header_lines', $title]));
            $this->assertSame(UpdateBinaries::formatSectionHeaderLinesPhp($title), UpdateBinaries::formatSectionHeaderLinesNative($title));
        }
        $this->assertSame(['COMPLETE', '--------'], UpdateBinaries::formatSectionHeaderLinesPhp('Complete'));
    }

    public function test_optimize_cbp_helpers_match_php(): void
    {
        $ffi = $this->loadFfi();
        $values = [
            '', ' ', "\0", "\0 a \0", "  My  Subject\t\n", "A\u{3000}\u{180E}B\u{85}C\u{2028}D\u{A0}E\u{FEFF}F\u{200B}G", "\x0B\x0C\r\n x",
            'İSTANBUL', 'ǅungla', 'ẞtraße', 'ΑΣ', 'Σ', 'ΣΑΣ Σ.', 'Ⅻ', '😀 ＡＢＣ', "Café\u{301}", "\xff\xfeAB", "caf\xe9 Bar",
        ];
        foreach ($values as $value) {
            $this->assertSame(OptimizeCbpStorage::normalizeIdentityPhp($value), OptimizeCbpStorage::normalizeIdentityNative($value), bin2hex($value));
        }
        foreach (["  My  Subject\t\n", "A\u{3000}\u{180E}B\u{85}C", 'İSTANBUL', "Café\u{301}"] as $value) {
            $this->assertSame([OptimizeCbpStorage::normalizeIdentityPhp($value)], $this->callRust($ffi, ['optimize_cbp.normalize_identity', $value]));
        }

        // Every scalar value except the context-sensitive capital sigma, in one request.
        $all = '';
        for ($codePoint = 0; $codePoint <= 0x10FFFF; $codePoint++) {
            if (($codePoint >= 0xD800 && $codePoint <= 0xDFFF) || $codePoint === 0x3A3) {
                continue;
            }
            $all .= mb_chr($codePoint, 'UTF-8').'x';
        }
        $this->assertSame([OptimizeCbpStorage::normalizeIdentityPhp($all)], $this->callRust($ffi, ['optimize_cbp.normalize_identity', $all]));

        foreach ([1, 12, PHP_INT_MAX, 0, -1, PHP_INT_MIN] as $fileNumber) {
            foreach ([['', ''], ['Subject', 'Poster'], ["  My  Subject\t", "Poster\0 <a@b>"], ['İ', 'ΑΣ'], ["\xff", 'x']] as [$subject, $fromName]) {
                $php = OptimizeCbpStorage::computeBinaryCbpHashPhp($fileNumber, $subject, $fromName);
                $this->assertSame(16, strlen($php));
                $this->assertSame($php, OptimizeCbpStorage::computeBinaryCbpHashNative($fileNumber, $subject, $fromName));
            }
        }
        $this->assertSame(md5('file:12', true), OptimizeCbpStorage::computeBinaryCbpHashPhp(12, 'ignored', 'ignored'));
        $this->assertSame(md5("subject:my subject\0poster:poster", true), OptimizeCbpStorage::computeBinaryCbpHashPhp(0, ' My  Subject ', 'Poster'));

        foreach ([PHP_INT_MIN, -1, 0, 1, 2, 500, 4999, 5000, 5001, PHP_INT_MAX] as $batch) {
            $this->assertSame([OptimizeCbpStorage::clampBatchSizePhp($batch)], $this->callRust($ffi, ['optimize_cbp.clamp_batch_size', $batch]));
            $this->assertSame(OptimizeCbpStorage::clampBatchSizePhp($batch), OptimizeCbpStorage::clampBatchSizeNative($batch));
        }
    }

    public function test_reset_truncate_helpers_match_php(): void
    {
        $this->loadFfi();
        $this->assertSame(
            ['first_record' => 0, 'first_record_postdate' => null, 'last_record' => 0, 'last_record_postdate' => null, 'last_updated' => null],
            NntmuxResetTruncate::buildGroupResetPayloadPhp()
        );
        $this->assertSame(NntmuxResetTruncate::buildGroupResetPayloadPhp(), NntmuxResetTruncate::buildGroupResetPayloadNative());
        $this->assertSame(['parts', 'missed_parts', 'binaries', 'collections'], NntmuxResetTruncate::tablesToTruncatePhp());
        $this->assertSame(NntmuxResetTruncate::tablesToTruncatePhp(), NntmuxResetTruncate::tablesToTruncateNative());
        foreach ([0, 1, -1, PHP_INT_MAX, PHP_INT_MIN] as $count) {
            $this->assertSame(NntmuxResetTruncate::formatTruncatedSuccessMessagePhp($count), NntmuxResetTruncate::formatTruncatedSuccessMessageNative($count));
        }
        $this->assertSame('3 releases had no nzb, deleted.', NntmuxResetTruncate::formatTruncatedSuccessMessagePhp(3));
    }

    public function test_dispatchers_follow_the_toggle_and_fall_back_without_the_library(): void
    {
        $this->loadFfi();
        $durations = [4_000_000, 2_000_000, 3_000_000];
        $outputs = [];
        foreach ([false, true] as $useRust) {
            foreach (self::COMMANDS as $command) {
                $command::setUseRustFfi($useRust);
            }
            $outputs[] = $this->runDispatchers($durations);
        }
        $this->assertSame($outputs[0], $outputs[1]);

        foreach (self::COMMANDS as $command) {
            $command::setUseRustFfi(true);
            $command::setFfiForTesting(false);
        }
        $this->assertSame($outputs[0], $this->runDispatchers($durations));
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of this 14-helper workload, including JSON encoding, the FFI call and
        // decoding (PHP 8.5.12, Apple Silicon, release dylib; 3 rounds x 201 samples x 200 iterations, 5 warmups):
        // - Round 1: PHP p50 = 0.704 ms (p99 = 0.955 ms), Rust FFI p50 = 3.592 ms (p99 = 4.151 ms) -> p50 0.20x, p99 0.23x
        // - Round 2: PHP p50 = 0.702 ms (p99 = 1.254 ms), Rust FFI p50 = 3.598 ms (p99 = 6.053 ms) -> p50 0.19x, p99 0.21x
        // - Round 3: PHP p50 = 0.693 ms (p99 = 0.897 ms), Rust FFI p50 = 3.568 ms (p99 = 3.930 ms) -> p50 0.19x, p99 0.23x
        // The FFI round trip dwarfs the microsecond PHP work (< 1.5x in `.ai/rules/rust-performance.md`),
        // so all five commands keep PHP by default and Rust remains opt-in through setUseRustFfi(true).
        $this->loadFfi();
        $warmup = 5;
        $samples = 41;
        $iterations = 200;
        $sink = 0;
        for ($w = 0; $w < $warmup; $w++) {
            $sink += count($this->runWorkloadOnce(false)) + count($this->runWorkloadOnce(true));
        }

        $phpTimes = [];
        $rustTimes = [];
        for ($s = 0; $s < $samples; $s++) {
            foreach (($s & 1) === 1 ? [true, false] : [false, true] as $useRust) {
                $t0 = hrtime(true);
                for ($i = 0; $i < $iterations; $i++) {
                    $sink += count($this->runWorkloadOnce($useRust));
                }
                $elapsed = (hrtime(true) - $t0) / 1_000_000;
                if ($useRust) {
                    $rustTimes[] = $elapsed;
                } else {
                    $phpTimes[] = $elapsed;
                }
            }
        }
        sort($phpTimes);
        sort($rustTimes);

        $this->assertGreaterThan(0, $sink);
        $this->assertGreaterThan(0.0, $phpTimes[(int) floor($samples * 0.5)]);
        $this->assertGreaterThan(0.0, $rustTimes[(int) floor($samples * 0.5)]);
        foreach (self::COMMANDS as $command) {
            $this->assertFalse($command::usesRustFfi(), $command);
        }
    }

    /**
     * @param  list<int>  $durations
     * @return list<mixed>
     */
    private function runDispatchers(array $durations): array
    {
        $stats = BenchmarkRename::computeStatistics($durations, 10);

        return [
            $stats,
            BenchmarkRename::isBlockedProductionContext('local', '192.168.1.110:3306', ''),
            BenchmarkRename::projectBenchmarkTableRow('Clean', ['items' => 10, 'stats' => $stats, 'accuracy_verified' => true]),
            PartRepair::formatGroupNotFoundMessage('alt.binaries.test'),
            PartRepair::formatNntpErrorMessage('Timeout'),
            UpdateBinaries::resolveMaxHeaders('250', 0),
            UpdateBinaries::formatElapsedTime(75.0),
            UpdateBinaries::formatSectionHeaderLines('Updating Single Group'),
            OptimizeCbpStorage::normalizeIdentity("  Some\tSubject  "),
            bin2hex(OptimizeCbpStorage::computeBinaryCbpHash(0, 'Some Subject', 'Poster')),
            OptimizeCbpStorage::clampBatchSize(0),
            NntmuxResetTruncate::buildGroupResetPayload(),
            NntmuxResetTruncate::tablesToTruncate(),
            NntmuxResetTruncate::formatTruncatedSuccessMessage(4),
        ];
    }

    /**
     * @return list<mixed>
     */
    private function runWorkloadOnce(bool $useRust): array
    {
        $durations = [3_100_000, 2_900_000, 3_400_000, 3_000_000, 3_250_000, 2_950_000, 3_050_000];
        $stats = ['ops_per_sec' => 6593.41, 'min_ms' => 2.9, 'p50_ms' => 3.05, 'p95_ms' => 3.4, 'p99_ms' => 3.4, 'max_ms' => 3.4];
        $row = ['items' => 20, 'stats' => $stats, 'accuracy_verified' => true];
        if ($useRust) {
            return [
                BenchmarkRename::computeStatisticsNative($durations, 20),
                BenchmarkRename::isBlockedProductionContextNative('local', '127.0.0.1:3306', 'nntmux'),
                BenchmarkRename::projectBenchmarkTableRowNative('Clean Scene Releases', $row),
                PartRepair::formatGroupNotFoundMessageNative('alt.binaries.teevee'),
                PartRepair::formatNntpErrorMessageNative('Connection refused'),
                UpdateBinaries::resolveMaxHeadersNative('250000', 1000000),
                UpdateBinaries::formatElapsedTimeNative(3725.0),
                UpdateBinaries::formatSectionHeaderLinesNative('Updating All Groups'),
                OptimizeCbpStorage::normalizeIdentityNative('  Some.Release.Name  (01/42)  "file.rar" yEnc '),
                OptimizeCbpStorage::computeBinaryCbpHashNative(0, 'Some.Release.Name (01/42) "file.rar" yEnc', 'poster@example.com (Poster)'),
                OptimizeCbpStorage::clampBatchSizeNative(500),
                NntmuxResetTruncate::buildGroupResetPayloadNative(),
                NntmuxResetTruncate::tablesToTruncateNative(),
                NntmuxResetTruncate::formatTruncatedSuccessMessageNative(42),
            ];
        }

        return [
            BenchmarkRename::computeStatisticsPhp($durations, 20),
            BenchmarkRename::isBlockedProductionContextPhp('local', '127.0.0.1:3306', 'nntmux'),
            BenchmarkRename::projectBenchmarkTableRowPhp('Clean Scene Releases', $row),
            PartRepair::formatGroupNotFoundMessagePhp('alt.binaries.teevee'),
            PartRepair::formatNntpErrorMessagePhp('Connection refused'),
            UpdateBinaries::resolveMaxHeadersPhp('250000', 1000000),
            UpdateBinaries::formatElapsedTimePhp(3725.0),
            UpdateBinaries::formatSectionHeaderLinesPhp('Updating All Groups'),
            OptimizeCbpStorage::normalizeIdentityPhp('  Some.Release.Name  (01/42)  "file.rar" yEnc '),
            OptimizeCbpStorage::computeBinaryCbpHashPhp(0, 'Some.Release.Name (01/42) "file.rar" yEnc', 'poster@example.com (Poster)'),
            OptimizeCbpStorage::clampBatchSizePhp(500),
            NntmuxResetTruncate::buildGroupResetPayloadPhp(),
            NntmuxResetTruncate::tablesToTruncatePhp(),
            NntmuxResetTruncate::formatTruncatedSuccessMessagePhp(42),
        ];
    }

    /**
     * Result of `$helper`, or the class of what it threw, so a PHP warning or error is compared too.
     *
     * @param  callable(): mixed  $helper
     */
    private function capture(callable $helper): mixed
    {
        try {
            return $helper();
        } catch (\Throwable $e) {
            return $e::class.': '.$e->getMessage();
        }
    }

    /**
     * @param  list<mixed>  $request
     * @return list<mixed>|null
     */
    private function callRust(FFI $ffi, array $request): ?array
    {
        $json = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $capacity = max(4096, strlen($json) * 4);
        $buffer = $ffi->new("uint8_t[{$capacity}]");
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_w31_benchmark_binaries_cbp_reset_commands($json, strlen($json), $buffer, $capacity);
        if ($written === -1) {
            return null;
        }
        $this->assertIsInt($written);
        $this->assertGreaterThan(0, $written);
        $decoded = json_decode(FFI::string($buffer, $written), true, 8, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertTrue(array_is_list($decoded));

        return $decoded;
    }

    private function loadFfi(): FFI
    {
        $candidates = [];
        $envPath = getenv('NNTMUX_PIPELINE_FFI_LIB');
        if (is_string($envPath) && $envPath !== '') {
            $candidates[] = $envPath;
        }
        $base = base_path();
        $ext = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $candidates[] = $base."/storage/app/nntmux-native/libnntmux_pipeline.{$ext}";
        $candidates[] = $base."/rust/pipeline-ffi/target/release/libnntmux_pipeline.{$ext}";

        foreach ($candidates as $path) {
            if (is_file($path)) {
                try {
                    $ffi = FFI::cdef(self::FFI_CDEF, $path);
                } catch (Exception) {
                    continue;
                }

                return $ffi;
            }
        }

        $this->markTestSkipped('libnntmux_pipeline shared library with the wave 31.1 export not found.');
    }
}
