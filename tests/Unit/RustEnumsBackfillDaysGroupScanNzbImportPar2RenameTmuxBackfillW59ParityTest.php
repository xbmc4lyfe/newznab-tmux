<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BackfillDaysMode;
use App\Enums\GroupScanMode;
use App\Enums\NzbImportStatus;
use App\Enums\Par2RenameOutcome;
use App\Enums\TmuxBackfillMode;
use Closure;
use FFI;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;

final class RustEnumsBackfillDaysGroupScanNzbImportPar2RenameTmuxBackfillW59ParityTest extends TestCase
{
    /** Enum => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        BackfillDaysMode::class => 'backfillDaysPlanBatch',
        GroupScanMode::class => 'groupScanPlanBatch',
        NzbImportStatus::class => 'importStatusRollupBatch',
        Par2RenameOutcome::class => 'renameOutcomeSummaryBatch',
        TmuxBackfillMode::class => 'tmuxBackfillPlanBatch',
    ];

    /** Output words per row, then rollup words after the rows. */
    private const WORDS = [
        BackfillDaysMode::class => [4, 0],
        GroupScanMode::class => [5, 0],
        NzbImportStatus::class => [2, 15],
        Par2RenameOutcome::class => [2, 17],
        TmuxBackfillMode::class => [5, 0],
    ];

    /** Enum-distinct private CDEF constant => export it declares. */
    private const CDEFS = [
        BackfillDaysMode::class => ['W59_BACKFILL_DAYS_FFI_CDEF', 'nntmux_w591_backfill_days_plan_batch'],
        GroupScanMode::class => ['W59_GROUP_SCAN_FFI_CDEF', 'nntmux_w591_group_scan_plan_batch'],
        NzbImportStatus::class => ['W59_NZB_IMPORT_FFI_CDEF', 'nntmux_w591_nzb_import_rollup_batch'],
        Par2RenameOutcome::class => ['W59_PAR2_RENAME_FFI_CDEF', 'nntmux_w591_par2_rename_summary_batch'],
        TmuxBackfillMode::class => ['W59_TMUX_BACKFILL_FFI_CDEF', 'nntmux_w591_tmux_backfill_plan_batch'],
    ];

    /** Case values each enum must keep. */
    private const CASES = [
        BackfillDaysMode::class => [1, 2],
        GroupScanMode::class => [0, 1],
        NzbImportStatus::class => ['inserted', 'queued', 'metadata_ignored', 'metadata_deferred', 'duplicate', 'blacklisted', 'nogroup', 'failed'],
        Par2RenameOutcome::class => ['renamed', 'no_match', 'retryable', 'deferred'],
        TmuxBackfillMode::class => [0, 1, 4],
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns) from
     * test_paired_randomized_common_and_uncommon_benchmarks (NNTMUX_W591_BENCH=1): seed 59,
     * ROUNDS interleaved pairs per workload after WARMUPS, ROWS rows per batch, randomized call
     * order, each side the fastest of REPEATS back-to-back calls, release Rust, PHP 8.5 CLI
     * without opcache, Apple Silicon macOS. Timings include dispatch, implode(), the FFI call and
     * unpack('q*'). Per-metric minimum of four runs. An enum defaults to Rust only when
     * all four reach 1.5x.
     */
    private const QUALIFICATION = [
        BackfillDaysMode::class => [6.036, 2.429, 4.692, 3.159],
        GroupScanMode::class => [5.908, 4.315, 4.66, 3.969],
        NzbImportStatus::class => [7.463, 5.177, 5.036, 3.025],
        Par2RenameOutcome::class => [3.928, 3.702, 2.253, 1.694],
        TmuxBackfillMode::class => [7.228, 6.401, 5.125, 3.146],
    ];

    private const ROUNDS = 60;

    private const WARMUPS = 8;

    private const ROWS = 320;

    /** Back-to-back calls per side of a pair; the fastest is the sample (drops preemption spikes). */
    private const REPEATS = 3;

    private const SEED = 59;

    private const MODE_TOKENS = [
        BackfillDaysMode::class => ['1', '2', 'DaysPerGroup', 'SafeDate', 'days_per_group', 'safe-date', 'days', 'group', 'per group', 'GroupDays', 'safe', 'date', 'safe_backfill', 'backfill-date'],
        GroupScanMode::class => ['0', '1', 'Posts', 'Days', 'post', 'messages', 'message', 'count', 'articles', 'headers', 'day', 'date', 'age', 'time'],
        TmuxBackfillMode::class => ['0', '1', '4', 'Disabled', 'All', 'Safe', 'off', 'none', 'no', 'false', 'disable', 'all_groups', 'full', 'backfill', 'on', 'enabled', 'safe backfill', 'oldest', 'single', 'one-by-one'],
        NzbImportStatus::class => ['Inserted', 'Queued', 'MetadataIgnored', 'Metadata Deferred', 'No Group', 'NO_GROUP', 'insert', 'added', 'imported', 'ok', 'success', 'queue', 'pending', 'enqueued', 'ignored', 'meta-ignored',
            'deferred', 'metadefer', 'metadata_defer', 'dupe', 'dup', 'exists', 'already exists', 'blacklist', 'blocked', 'banned', 'missing_group', 'unknown-group', 'group', 'fail', 'error', 'failure', 'invalid'],
        Par2RenameOutcome::class => ['Renamed', 'NoMatch', 'No Match', 'RETRYABLE', 'Deferred', 'rename', 'ok', 'success', 'done', 'fixed', 'none', 'unmatched', 'skip', 'skipped', 'miss', 'retry', 'transient', 'error', 'failed', 'fail',
            'defer', 'later', 'pending', 'wait', 'postponed'],
    ];

    private const REASONS = ['', 'Read timed out after 30s', 'TIMEOUT', 'CRC mismatch on vol03', 'checksum failed', 'archive corrupt', 'damaged PAR2 packet', 'source file missing', 'File not found', 'No such file or directory',
        'database lock wait', 'resource busy', 'Permission denied', 'access denied', 'read-only filesystem', 'target exists', 'duplicate release name', 'name collision', 'unexpected response', 'no candidates', 'release has no par2',
        'renamed from hash'];

    private const JUNK = ['', ' ', 'x', '-', '+1', '1.5', "\u{e9}", '%20', 'a b', '&=?', "\xff", '-0', '00', '01', ' 1 ', "\x0b1\r", "\n", '9999999999999999999', '-999999999999999999', '999999999999999999', 'NULL', '0x1A', '__', '- -',
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'days_per_group_x', "safe\u{e9}"];

    public function test_wave_members_exist_and_defaults_match_qualification(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            self::assertTrue($reflection->isEnum(), $class);
            self::assertSame(self::CASES[$class], array_map(static fn (\BackedEnum $case): int|string => $case->value, $class::cases()), $class.' keeps every case');
            foreach (['usesW59RustFfi', 'withW59RustFfi', 'w59RustFfiState', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
            }
            foreach (['usesW59RustFfi', 'withW59RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->getMethod($method)->isStatic() && $reflection->getMethod($method)->isPublic(), $class.'::'.$method);
            }
            $state = $reflection->getMethod('w59RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            $constant = $reflection->getReflectionConstant('W59_RUST_FFI_DEFAULT');
            self::assertNotFalse($constant, $class);
            self::assertTrue($constant->isPublic(), $class);
            self::assertIsBool($constant->getValue(), $class);
            self::assertSame($constant->getValue(), $this->uses($class), $class);
            [$cdef, $symbol] = self::CDEFS[$class];
            $cdefConstant = $reflection->getReflectionConstant($cdef);
            self::assertNotFalse($cdefConstant, $class.'::'.$cdef);
            self::assertTrue($cdefConstant->isPrivate(), $class.'::'.$cdef);
            self::assertStringContainsString($symbol, (string) $cdefConstant->getValue());
            self::assertSame([], $reflection->getProperties(\ReflectionProperty::IS_STATIC), $class.' keeps Wave 59 state in function-local statics');

            $speedups = self::QUALIFICATION[$class];
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->uses($class), $class.' default must follow its recorded qualification');
            if (min($speedups) < 1.5) {
                self::assertFalse($this->uses($class), $class.' retains PHP by default');
            }
        }
    }

    public function test_with_w59_rust_ffi_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->uses($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW59RustFfi($enabled, fn (): array => [
                    $this->uses($class),
                    $class::withW59RustFfi(! $enabled, fn (): bool => $this->uses($class)),
                    $this->uses($class),
                ]);
                self::assertSame([$enabled, ! $enabled, $enabled], $seen, $class);
                self::assertSame($default, $this->uses($class), $class);
            }
            try {
                $class::withW59RustFfi(! $default, static function (): never {
                    throw new InvalidArgumentException('boom');
                });
            } catch (InvalidArgumentException) {
            }
            self::assertSame($default, $this->uses($class), $class.' restores routing after an exception');
        }
        // Toggles are independent per enum.
        $others = static fn (): array => array_map(static fn (string $class): bool => $class::usesW59RustFfi(), [GroupScanMode::class, NzbImportStatus::class, Par2RenameOutcome::class, TmuxBackfillMode::class]);
        $before = $others();
        self::assertSame($before, BackfillDaysMode::withW59RustFfi(! BackfillDaysMode::usesW59RustFfi(), $others));
    }

    public function test_toggle_routes_entry_point(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $rows = $this->fixture($class, new Randomizer(new Mt19937(self::SEED + 2)), false, 12);
            $call = $this->entry($class);
            $php = $class::{self::ENTRIES[$class].'Php'}($rows);
            self::assertSame($php, $class::withW59RustFfi(true, static fn (): mixed => $call($rows)), $class);
            self::assertSame($php, $class::withW59RustFfi(false, static fn (): mixed => $call($rows)), $class);
        }
    }

    public function test_earlier_toggles_are_untouched(): void
    {
        self::assertFalse(BackfillDaysMode::usesRustFfi());
        self::assertFalse(GroupScanMode::usesRustFfi());
        self::assertFalse(TmuxBackfillMode::usesRustFfi());
        self::assertFalse(NzbImportStatus::usesRustFfi());
        self::assertFalse(Par2RenameOutcome::usesRustFfi());
    }

    public function test_bindings_resolve_and_guard_capacity(): void
    {
        $this->requireBindings();
    }

    /**
     * @return iterable<string, array{0: class-string, 1: list<string>, 2: list<int>}>
     */
    public static function knownCodes(): iterable
    {
        $s = BackfillDaysMode::W59_SOURCE_SHIFT;
        yield 'days by value' => [BackfillDaysMode::class, ["1\t30\t90\t10\t1700000000"],
            [1 | BackfillDaysMode::W59_SOURCE_VALUE << $s | BackfillDaysMode::W59_DAYS_VALID | BackfillDaysMode::W59_DAYS_GROUP_TARGET, 30, 20, 1_700_000_000 - 30 * 86400]];
        yield 'days safe alias clamped' => [BackfillDaysMode::class, ["safe\t30\t0\t10\t0"],
            [2 | BackfillDaysMode::W59_SOURCE_ALIAS << $s | BackfillDaysMode::W59_DAYS_VALID | BackfillDaysMode::W59_DAYS_SAFE_DATE | BackfillDaysMode::W59_DAYS_REACHED | BackfillDaysMode::W59_DAYS_CLAMPED, 1, 0, -86400]];
        yield 'days unknown defaults' => [BackfillDaysMode::class, ["9\t40000\t1\t-5\t0"], [1 | BackfillDaysMode::W59_DAYS_GROUP_TARGET | BackfillDaysMode::W59_DAYS_CLAMPED, 36500, 36500, -36500 * 86400]];
        yield 'days name' => [BackfillDaysMode::class, [" Days_Per-Group \t7\t1\t7\t100"],
            [1 | BackfillDaysMode::W59_SOURCE_NAME << $s | BackfillDaysMode::W59_DAYS_VALID | BackfillDaysMode::W59_DAYS_GROUP_TARGET | BackfillDaysMode::W59_DAYS_REACHED, 7, 0, 100 - 7 * 86400]];
        yield 'days malformed' => [BackfillDaysMode::class, ["1\t1"], [BackfillDaysMode::W59_DAYS_MALFORMED, 0, 0, 0]];

        $limit = GroupScanMode::W59_SCAN_LIMIT_SHIFT;
        yield 'scan posts' => [GroupScanMode::class, ["0\t3\t50000\t1000\t200000\t20000"], [GroupScanMode::W59_SOURCE_VALUE << $s | GroupScanMode::W59_SCAN_VALID | 20000 << $limit, 50000, 150001, 50000, 3]];
        yield 'scan days name' => [GroupScanMode::class, ["Days\t0\t1\t10\t20\t0"],
            [1 | GroupScanMode::W59_SOURCE_NAME << $s | GroupScanMode::W59_SCAN_VALID | GroupScanMode::W59_SCAN_DAYS_UNIT | GroupScanMode::W59_SCAN_TARGET_CLAMPED | GroupScanMode::W59_SCAN_LIMIT_ADJUSTED | 20000 << $limit, 1, 10, 11, 1]];
        yield 'scan empty range' => [GroupScanMode::class, ["x\t1\t-1\t0\t5\t2000000"], [GroupScanMode::W59_SCAN_TARGET_CLAMPED | GroupScanMode::W59_SCAN_LIMIT_ADJUSTED | GroupScanMode::W59_SCAN_EMPTY_RANGE | 1000000 << $limit, 0, 0, 0, 0]];
        yield 'scan malformed' => [GroupScanMode::class, ["0\t1\t1\t1\t1\t+1"], [GroupScanMode::W59_SCAN_MALFORMED, 0, 0, 0, 0]];

        $order = TmuxBackfillMode::W59_TMUX_ORDER_SHIFT;
        yield 'tmux all' => [TmuxBackfillMode::class, ["1\t0\t10\t3"],
            [1 | TmuxBackfillMode::W59_SOURCE_VALUE << $s | TmuxBackfillMode::W59_TMUX_VALID | TmuxBackfillMode::W59_TMUX_ENABLED | TmuxBackfillMode::W59_TMUX_QTY_DEFAULTED | TmuxBackfillMode::W59_ORDER_ROUND_ROBIN << $order, 20000, 10, 3, 4]];
        yield 'tmux safe alias' => [TmuxBackfillMode::class, ["oldest\t99999999\t10\t0"],
            [4 | TmuxBackfillMode::W59_SOURCE_ALIAS << $s | TmuxBackfillMode::W59_TMUX_VALID | TmuxBackfillMode::W59_TMUX_ENABLED | TmuxBackfillMode::W59_TMUX_QTY_CLAMPED | TmuxBackfillMode::W59_TMUX_PROCESSES_CLAMPED
                | TmuxBackfillMode::W59_ORDER_OLDEST_FIRST << $order, 10000000, 1, 1, 1]];
        yield 'tmux unknown disables' => [TmuxBackfillMode::class, ["2\t5\t5\t5"], [0, 5, 0, 0, 0]];
        yield 'tmux malformed' => [TmuxBackfillMode::class, ["2\t5\t5"], [TmuxBackfillMode::W59_TMUX_MALFORMED, 0, 0, 0, 0]];

        yield 'nzb rollup' => [NzbImportStatus::class, ["failed\t1\t3", "No Group\t5\t3", "queued\t0\t0", "dupe\t0\t0", "x\t0"], [
            8 | NzbImportStatus::W59_SOURCE_VALUE << $s | NzbImportStatus::W59_NZB_RETRYABLE, 2,
            7 | NzbImportStatus::W59_SOURCE_NAME << $s | NzbImportStatus::W59_NZB_TERMINAL | NzbImportStatus::W59_NZB_EXHAUSTED, 0,
            2 | NzbImportStatus::W59_SOURCE_VALUE << $s | NzbImportStatus::W59_NZB_PENDING | NzbImportStatus::W59_NZB_SUCCESS, 0,
            5 | NzbImportStatus::W59_SOURCE_ALIAS << $s | NzbImportStatus::W59_NZB_TERMINAL | NzbImportStatus::W59_NZB_DUPLICATE, 0,
            NzbImportStatus::W59_NZB_MALFORMED, 0,
            1, 0, 1, 0, 0, 1, 0, 1, 1, 1, 2, 1, 1, 1, 1,
        ]];

        $b = Par2RenameOutcome::W59_PAR2_BUCKET_SHIFT;
        $r = Par2RenameOutcome::W59_PAR2_REASON_SHIFT;
        yield 'par2 summary' => [Par2RenameOutcome::class, ['renamed', "retry\t  Read TIMED OUT\tx ", "?\tfile exists"], [
            1 | Par2RenameOutcome::W59_SOURCE_VALUE << $s | Par2RenameOutcome::W59_BUCKET_SUCCESS << $b | Par2RenameOutcome::W59_PAR2_VALID, 0,
            3 | Par2RenameOutcome::W59_SOURCE_ALIAS << $s | Par2RenameOutcome::W59_BUCKET_FAILURE << $b | Par2RenameOutcome::W59_REASON_TIMEOUT << $r | Par2RenameOutcome::W59_PAR2_REQUEUE | Par2RenameOutcome::W59_PAR2_VALID, 16,
            Par2RenameOutcome::W59_BUCKET_FAILURE << $b | Par2RenameOutcome::W59_REASON_COLLISION << $r, 11,
            1, 1, 0, 1, 0, 1, 0, 2, 1, 1, 0, 0, 0, 0, 1, 0, 1,
        ]];
        yield 'par2 empty batch tail' => [Par2RenameOutcome::class, [], array_fill(0, 17, 0)];
        yield 'nzb empty batch tail' => [NzbImportStatus::class, [], array_fill(0, 15, 0)];
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $rows
     * @param  list<int>  $expected
     */
    #[DataProvider('knownCodes')]
    public function test_known_codes(string $class, array $rows, array $expected): void
    {
        self::assertSame($expected, $this->assertParity($class, $rows));
        $this->assertRustAccepts($class, $rows, $expected);
    }

    public function test_batch_words_agree_with_existing_enum_methods(): void
    {
        foreach (range(-1, 5) as $raw) {
            $selection = BackfillDaysMode::normalizeModeSelectionPhp($raw);
            $words = BackfillDaysMode::backfillDaysPlanBatch(["{$raw}\t5\t9\t0\t0"]);
            self::assertSame($selection['mode']->value, $words[0] & 15);
            self::assertSame($selection['is_valid'], ($words[0] & BackfillDaysMode::W59_DAYS_VALID) !== 0);
            self::assertSame($selection['uses_safe_date'], ($words[0] & BackfillDaysMode::W59_DAYS_SAFE_DATE) !== 0);
            self::assertSame($selection['uses_safe_date'] ? 9 : 5, $words[1]);

            foreach ([[0, 0], [-4, 7], [40000, 5_000_000_000], [12, -3]] as [$days, $messages]) {
                $scan = GroupScanMode::normalizeScanSelectionPhp($raw, $days, $messages);
                $words = GroupScanMode::groupScanPlanBatch(["{$raw}\t{$days}\t{$messages}\t1\t10\t1"]);
                self::assertSame([$scan['mode']->value, $scan['is_valid'], $scan['target']], [$words[0] & 15, ($words[0] & GroupScanMode::W59_SCAN_VALID) !== 0, $words[1]]);
            }

            foreach ([0, -7, 3, 5_000_000_000] as $groups) {
                $dispatch = TmuxBackfillMode::normalizeBackfillDispatchPhp($raw, $groups);
                $words = TmuxBackfillMode::tmuxBackfillPlanBatch(["{$raw}\t1\t{$groups}\t4"]);
                self::assertSame([$dispatch['mode']->value, $dispatch['is_valid'], $dispatch['enabled'], $dispatch['group_count']],
                    [$words[0] & 15, ($words[0] & TmuxBackfillMode::W59_TMUX_VALID) !== 0, ($words[0] & TmuxBackfillMode::W59_TMUX_ENABLED) !== 0, $words[2]]);
            }
        }
        foreach ([NzbImportStatus::class, Par2RenameOutcome::class] as $class) {
            $values = self::CASES[$class];
            $words = $class::{self::ENTRIES[$class]}(array_map(static fn (string $value): string => $value."\t0\t0", $values));
            foreach ($values as $index => $value) {
                self::assertSame($value, $class::classifyPhp($value));
                self::assertSame($index + 1, $words[$index * 2] & 15, $class.' '.$value);
            }
        }
    }

    /**
     * @return iterable<string, array{0: class-string, 1: list<string>}>
     */
    public static function edgeBatches(): iterable
    {
        $shared = ['', ' ', "\t", "\t\t", "\t\t\t", "\t\t\t\t", "\t\t\t\t\t", "\t\t\t\t\t\t", "\n", "\r\n", "\x0b", "\xff", "caf\u{e9}", '-', '0', '1', '-0', str_repeat('9', 19), str_repeat("\t", 12)];
        $big = str_repeat('9', 18);
        $classes = [
            BackfillDaysMode::class => ["0\t1\t1\t1\t1", "-0\t1\t1\t1\t1", "01\t1\t1\t1\t1", " 2\t0\t0\t0\t0", "safedate\t-1\t36501\t36501\t-{$big}", "SAFE_DATE\t{$big}\t{$big}\t{$big}\t{$big}", "1\t-{$big}\t1\t-{$big}\t-{$big}",
                "\x0b1\r\t5\t5\t5\t5", "per_group\t5\t5\t4\t5", "da ys\t5\t5\t6\t5", "\u{e9}\t1\t1\t1\t1", "1\t+1\t1\t1\t1", "1\t1\t1\t1\t1\t1", "1\t1\t1\t1", str_repeat('days', 10)."\t1\t1\t1\t1", "dayspergroup\t1\t1\t1\t1.0"],
            GroupScanMode::class => ["0\t0\t0\t0\t0\t0", "1\t0\t0\t1\t1\t1", "-0\t1\t1\t1\t1\t1", "Posts\t1\t4294967296\t1\t{$big}\t1", "posts\t1\t4294967295\t1\t{$big}\t1000001", "days\t36501\t1\t5\t4\t1", "DAYS\t-{$big}\t-{$big}\t{$big}\t{$big}\t{$big}",
                "time\t1\t1\t1\t{$big}\t1", "0\t1\t0\t1\t5\t1", "2\t1\t3\t10\t20\t3", "headers\t1\t3\t-10\t20\t3", "0\t1\t1\t1\t1\t1\t1", "0\t1\t1\t1\t1", "\u{e9}\t1\t1\t1\t1\t1", "1\t1\t1\t1\t1\t 1"],
            NzbImportStatus::class => ["inserted\t0\t0", " inserted\t0\t0", "INSERTED\t0\t0", "metadata_ignored\t1\t1", "Metadata-Deferred\t1\t1", "nogroup\t-5\t3", "no_group\t2\t2", "nogroup\t2\t{$big}", "failed\t{$big}\t1000",
                "failed\t-{$big}\t-{$big}", "group\t0\t1", "\u{e9}\t0\t0", "\t0\t0", "failed\t0\t0\t0", "failed\t0", "failed\t+1\t1", "already-exists\t0\t0", str_repeat('x', 40)."\t0\t0", "1\t0\t0"],
            Par2RenameOutcome::class => ['renamed', 'renamed	', "renamed\t", "no_match\t\t\t", "No Match\t  TIMEOUT  ", "retryable\tcrc\ttimeout", "deferred\tbusy lock", "DEFERRED\tdenied", "\tmissing", "skip\tNOT FOUND", "x\t\u{e9}", "x\t\xff",
                "ok\tread-only fs", "ok\tCollision", "ok\t \x0b ", "retry\tlockfile missing", 'fail', str_repeat('r', 40), "none\t".str_repeat('z', 200)],
            TmuxBackfillMode::class => ["0\t0\t0\t0", "4\t1\t1\t1", "1\t1\t{$big}\t{$big}", "1\t-{$big}\t-{$big}\t-{$big}", "1\t10000000\t4294967296\t32", "1\t10000001\t4294967295\t33", "all\t1\t1\t1", " ALL \t1\t7\t3", "safe\t5\t5\t5",
                "one_by_one\t5\t5\t5", "3\t5\t5\t5", "-1\t5\t5\t5", "on\t1\t1", "on\t1\t1\t1\t1", "\u{e9}\t1\t1\t1", "on\t1\t1\t+1"],
        ];
        foreach ($classes as $class => $rows) {
            yield $class.' mixed edges' => [$class, [...$shared, ...$rows]];
            yield $class.' single empty row' => [$class, ['']];
            yield $class.' empty batch' => [$class, []];
        }
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $rows
     */
    #[DataProvider('edgeBatches')]
    public function test_edge_batch_parity(string $class, array $rows): void
    {
        $result = $this->assertParity($class, $rows);
        self::assertIsArray($result);
        self::assertTrue(array_is_list($result), $class);
        [$perRow, $tail] = self::WORDS[$class];
        self::assertCount(\count($rows) * $perRow + $tail, $result, $class);
        $this->assertRustAccepts($class, $rows, $result);
        foreach ($rows as $row) {
            $this->assertParity($class, [$row]);
        }
    }

    public function test_keyed_rows_and_nul_rows_fall_back_to_php(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            [$perRow, $tail] = self::WORDS[$class];
            $rows = $this->fixture($class, new Randomizer(new Mt19937(self::SEED + 3)), false, 4);
            $keyed = ['k' => $rows[0], 7 => $rows[1], 'z' => $rows[2], 'q' => $rows[3]];
            self::assertSame($this->assertParity($class, $rows), $this->assertParity($class, $keyed), $class);
            $nul = [$rows[0], "a\0b", "\0", $rows[1]];
            self::assertCount(4 * $perRow + $tail, $this->assertParity($class, $nul), $class);
        }
    }

    /** At least 300 randomized rows per enum and workload, checked as batches and one row at a time. */
    public function test_randomized_dataset_parity(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED + 1));
        foreach (array_keys(self::ENTRIES) as $class) {
            [$perRow, $tail] = self::WORDS[$class];
            foreach ([false, true] as $uncommon) {
                $rows = $this->fixture($class, $random, $uncommon, 320);
                $words = $this->assertParity($class, $rows);
                self::assertIsArray($words);
                self::assertCount(320 * $perRow + $tail, $words, $class);
                $this->assertRustAccepts($class, $rows, $words);
                for ($i = 0; $i < 40; $i++) {
                    $this->assertParity($class, [$rows[$i]]);
                }
            }
        }
    }

    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W591_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W591_BENCH=1 to run the Wave 59.1 paired benchmark.');
        }
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(self::SEED));
        $summary = $records = [];
        foreach (array_keys(self::ENTRIES) as $class) {
            $call = $this->entry($class);
            foreach (['common', 'uncommon'] as $workload) {
                $fixtures = [];
                for ($v = 0; $v < 8; $v++) {
                    $fixtures[] = $this->fixture($class, $random, $workload === 'uncommon', self::ROWS);
                }
                $php = $rust = [];
                for ($i = -self::WARMUPS; $i < self::ROUNDS; $i++) {
                    $variant = $random->getInt(0, 7);
                    $rows = $fixtures[$variant];
                    $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                    $results = $elapsed = [];
                    foreach ($order as $native) {
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW59RustFfi($native, static function () use ($call, $rows): array {
                            $result = null;
                            $best = PHP_INT_MAX;
                            for ($r = 0; $r < self::REPEATS; $r++) {
                                $start = hrtime(true);
                                $result = $call($rows);
                                $best = min($best, hrtime(true) - $start);
                            }

                            return [$result, $best];
                        });
                    }
                    self::assertSame($results[0], $results[1], $class.' '.$workload);
                    if ($i >= 0) {
                        $php[] = $elapsed[0];
                        $rust[] = $elapsed[1];
                        $records[] = [$class, $workload, $variant, $order[0], $elapsed[0], $elapsed[1]];
                    }
                }
                sort($php);
                sort($rust);
                $p99 = (int) ceil(\count($php) * 0.99) - 1;
                $summary[$class][$workload] = [
                    'mean_speedup' => round(array_sum($php) / max(1, array_sum($rust)), 3),
                    'p99_speedup' => round($php[$p99] / max(1, $rust[$p99]), 3),
                    'php_mean_ns' => intdiv(array_sum($php), \count($php)), 'rust_mean_ns' => intdiv(array_sum($rust), \count($rust)),
                    'php_p99_ns' => $php[$p99], 'rust_p99_ns' => $rust[$p99],
                ];
            }
        }
        $artifact = sys_get_temp_dir().'/w591-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'warmups' => self::WARMUPS, 'rows' => self::ROWS, 'repeats' => self::REPEATS, 'seed' => self::SEED, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW59.1 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach ($summary as $class => $workloads) {
            $metrics = [$workloads['common']['mean_speedup'], $workloads['common']['p99_speedup'], $workloads['uncommon']['mean_speedup'], $workloads['uncommon']['p99_speedup']];
            self::assertSame(min($metrics) >= 1.5, $this->uses($class), $class.' default must match the measured qualification '.json_encode($metrics));
        }
        self::assertCount(5, $summary);
    }

    private function uses(string $class): bool
    {
        /** @var callable(): bool $uses */
        $uses = [$class, 'usesW59RustFfi'];

        return $uses();
    }

    private function entry(string $class): Closure
    {
        /** @var callable $entry */
        $entry = [$class, self::ENTRIES[$class]];

        return Closure::fromCallable($entry);
    }

    /**
     * @param  array<array-key, string>  $rows
     */
    private function assertParity(string $class, array $rows): mixed
    {
        $call = $this->entry($class);
        $php = $class::withW59RustFfi(false, static fn (): mixed => $call($rows));
        $native = $class::withW59RustFfi(true, static fn (): mixed => $call($rows));
        self::assertSame($php, $native, $class.' Rust batch must match PHP');
        $method = self::ENTRIES[$class];
        self::assertSame($php, $class::{$method.'Php'}($rows), $class);
        self::assertSame($php, $class::{$method.'Native'}($rows), $class);

        return $php;
    }

    /** @return list<string> */
    private function fixture(string $class, Randomizer $random, bool $uncommon, int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = match ($class) {
                BackfillDaysMode::class => $this->daysRow($random, $uncommon),
                GroupScanMode::class => $this->scanRow($random, $uncommon),
                NzbImportStatus::class => $this->nzbRow($random, $uncommon),
                Par2RenameOutcome::class => $this->par2Row($random, $uncommon),
                default => $this->tmuxRow($random, $uncommon),
            };
        }

        return $rows;
    }

    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        foreach (self::CDEFS as $class => [, $symbol]) {
            $buffer = (new ReflectionMethod($class, 'w59RustFfiState'))->invoke(null, 'out', 4);
            self::assertIsArray($buffer, $class.' must load its Wave 59 binding');
            $ffi = $buffer[0];
            self::assertInstanceOf(FFI::class, $ffi, $class);
            [$perRow, $tail] = self::WORDS[$class];
            $out = $ffi->new('int64_t[64]');
            $rows = "a\tb\0c";
            self::assertSame(2, $this->invoke($ffi, $symbol, [$rows, 5, 2, $out, 64]), $symbol);
            self::assertSame(-2, $this->invoke($ffi, $symbol, [$rows, 5, 2, $out, 2 * $perRow + $tail - 1]), $symbol.' short capacity');
            self::assertSame(-1, $this->invoke($ffi, $symbol, [$rows, 5, 3, $out, 64]), $symbol.' row count mismatch');
            self::assertSame(0, $this->invoke($ffi, $symbol, ['', 0, 0, $out, 64]), $symbol.' empty batch');
        }
    }

    /**
     * Calls the export directly so parity cannot pass through a silent PHP fallback.
     *
     * @param  list<string>  $rows
     * @param  list<int>  $expected
     */
    private function assertRustAccepts(string $class, array $rows, array $expected): void
    {
        if ($rows === []) {
            return;
        }
        [$perRow, $tail] = self::WORDS[$class];
        $words = \count($rows) * $perRow + $tail;
        $buffer = (new ReflectionMethod($class, 'w59RustFfiState'))->invoke(null, 'out', $words);
        self::assertIsArray($buffer, $class);
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $rows);
        self::assertSame(\count($rows), $this->invoke($ffi, self::CDEFS[$class][1], [$joined, \strlen($joined), \count($rows), $out, $cap]), $class.' Rust must accept the batch');
        self::assertSame($expected, array_values((array) unpack('q*', FFI::string($out, $words * 8))), $class);
    }

    /**
     * Calls an export declared by an enum's Wave 59 CDEF.
     *
     * @param  list<mixed>  $args
     */
    private function invoke(FFI $ffi, string $symbol, array $args): mixed
    {
        return $ffi->{$symbol}(...$args);
    }

    /** @param list<string> $values */
    private function pick(Randomizer $random, array $values): string
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    /** A mode token: mostly canonical values in common workloads, mutated names, aliases and junk otherwise. */
    private function token(Randomizer $random, string $class, bool $uncommon): string
    {
        $tokens = self::MODE_TOKENS[$class];
        if (! $uncommon) {
            $values = array_map(strval(...), self::CASES[$class]);

            return $random->getInt(0, 9) < 8 ? $this->pick($random, $values) : $this->pick($random, $tokens);
        }

        return match ($random->getInt(0, 6)) {
            0 => $this->pick($random, self::JUNK),
            1 => strtoupper($this->pick($random, $tokens)),
            2 => $this->pick($random, [' ', "\n", "\x0b", "\r", '']).$this->pick($random, $tokens).$this->pick($random, [' ', '', "\r\n"]),
            3 => str_replace(['_', '-'], $this->pick($random, [' ', '__', '-_', '']), $this->pick($random, [...$tokens, ...array_map(strval(...), self::CASES[$class])])),
            4 => (string) $random->getInt(-3, 6),
            5 => $this->pick($random, $tokens).$this->pick($random, ['x', 's', "\u{e9}", '1']),
            default => $this->pick($random, $tokens),
        };
    }

    /**
     * Replaces 1-3 random fields with junk or out-of-range numbers and occasionally changes the field count.
     *
     * @param  list<string>  $fields
     */
    private function mutate(Randomizer $random, array $fields, int $from): string
    {
        for ($n = $random->getInt(1, 3); $n > 0; $n--) {
            $slot = $random->getInt($from, \count($fields) - 1);
            $fields[$slot] = match ($random->getInt(0, 4)) {
                0 => $this->pick($random, self::JUNK),
                1 => (string) $random->getInt(-999_999_999_999_999_999, 999_999_999_999_999_999),
                2 => (string) $random->getInt(-100, 100),
                3 => '-'.$fields[$slot],
                default => (string) $random->getInt(0, 5_000_000_000),
            };
        }
        $roll = $random->getInt(0, 14);
        if ($roll === 0) {
            array_pop($fields);
        } elseif ($roll === 1) {
            $fields[] = '1';
        }

        return implode("\t", $fields);
    }

    private function daysRow(Randomizer $random, bool $uncommon): string
    {
        $fields = [$this->token($random, BackfillDaysMode::class, $uncommon), (string) $random->getInt(1, 3650), (string) $random->getInt(1, 3650), (string) $random->getInt(0, 4000), (string) $random->getInt(1_600_000_000, 1_800_000_000)];

        return $uncommon ? $this->mutate($random, $fields, 1) : implode("\t", $fields);
    }

    private function scanRow(Randomizer $random, bool $uncommon): string
    {
        $first = $random->getInt(1_000_000, 900_000_000);
        $fields = [$this->token($random, GroupScanMode::class, $uncommon), (string) $random->getInt(1, 30), (string) $random->getInt(1000, 500_000), (string) $first, (string) ($first + $random->getInt(0, 5_000_000)),
            $this->pick($random, ['20000', '10000', '50000'])];

        return $uncommon ? $this->mutate($random, $fields, 1) : implode("\t", $fields);
    }

    private function tmuxRow(Randomizer $random, bool $uncommon): string
    {
        $fields = [$this->token($random, TmuxBackfillMode::class, $uncommon), (string) $random->getInt(10_000, 50_000), (string) $random->getInt(1, 20), (string) $random->getInt(1, 16)];

        return $uncommon ? $this->mutate($random, $fields, 1) : implode("\t", $fields);
    }

    private function nzbRow(Randomizer $random, bool $uncommon): string
    {
        $status = $random->getInt(0, 9) < 5 ? 'inserted' : $this->pick($random, self::CASES[NzbImportStatus::class]);
        $fields = [$uncommon ? $this->token($random, NzbImportStatus::class, true) : $status, (string) $random->getInt(0, 5), $this->pick($random, ['3', '5'])];

        return $uncommon ? $this->mutate($random, $fields, 1) : implode("\t", $fields);
    }

    private function par2Row(Randomizer $random, bool $uncommon): string
    {
        $outcome = $random->getInt(0, 9) < 4 ? 'renamed' : $this->pick($random, self::CASES[Par2RenameOutcome::class]);
        if (! $uncommon) {
            return $outcome === 'renamed' && $random->getInt(0, 1) === 0 ? $outcome : $outcome."\t".$this->pick($random, self::REASONS);
        }
        $reason = match ($random->getInt(0, 4)) {
            0 => $this->pick($random, self::JUNK),
            1 => strtoupper($this->pick($random, self::REASONS)),
            2 => $this->pick($random, [' ', "\t", "\x0b"]).$this->pick($random, self::REASONS)."\t".$this->pick($random, self::REASONS),
            3 => str_repeat($this->pick($random, ['x', 'ab ', "\u{e9}"]), $random->getInt(1, 80)).$this->pick($random, ['', ' busy', ' crc', ' exists']),
            default => $this->pick($random, self::REASONS),
        };

        return $this->token($random, Par2RenameOutcome::class, true).($random->getInt(0, 5) === 0 ? '' : "\t".$reason);
    }
}
