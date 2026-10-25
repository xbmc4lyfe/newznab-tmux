<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BackfillDaysMode;
use App\Enums\GroupScanMode;
use App\Enums\TmuxBackfillMode;
use App\Models\TmuxCleanupRule;
use App\Models\TmuxColorExclusion;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use Tests\TestCase;

final class RustIngestionTmuxEnumsCleanupModelsParityTest extends TestCase
{
    /** @var list<class-string> */
    private const TOGGLED = [
        BackfillDaysMode::class,
        GroupScanMode::class,
        TmuxBackfillMode::class,
        TmuxCleanupRule::class,
        TmuxColorExclusion::class,
    ];

    /** @var list<class-string> */
    private const ENUMS = [BackfillDaysMode::class, GroupScanMode::class, TmuxBackfillMode::class];

    /** Rule strings that exercise trimming, case folding, catalogue matching and wire-marker fallbacks. */
    private const RULES = [
        '',
        ' ',
        "\0\x0B\t\r\n",
        'blacklist',
        ' Blacklist ',
        "\tCODEC\n",
        "nzb\0",
        "\x0Cnzb",
        'size ',
        'Password URL',
        'passwordurl',
        'gibberish!',
        'Ünïcödé',
        "\u{00A0}scr\u{00A0}",
        '日本',
        "bad \xff utf8",
        "\xc3",
        "sep\x1Finside",
        "null\x1Emark",
        "\x1E",
        'unknown-rule-name-that-exceeds-the-thirty-two-byte-column',
    ];

    private const INTS = [PHP_INT_MIN, -4294967296, -256, -1, 0, 1, 2, 3, 4, 5, 249, 250, 251, 255, 256, 36500, 36501, 4294967295, 4294967296, PHP_INT_MAX];

    protected function tearDown(): void
    {
        foreach (self::TOGGLED as $class) {
            $class::setUseRustFfi(null);
        }
        parent::tearDown();
    }

    public function test_rust_ffi_is_disabled_by_default_and_toggles_independently(): void
    {
        foreach (self::TOGGLED as $class) {
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi(), $class);
            foreach (array_diff(self::TOGGLED, [$class]) as $other) {
                $this->assertFalse($other::usesRustFfi(), $other.' must not follow '.$class);
            }
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi(), $class);
        }
    }

    public function test_enum_cases_and_model_configuration_are_unchanged(): void
    {
        $this->assertSame([1, 2], array_column(BackfillDaysMode::cases(), 'value'));
        $this->assertSame([0, 1], array_column(GroupScanMode::cases(), 'value'));
        $this->assertSame([0, 1, 4], array_column(TmuxBackfillMode::cases(), 'value'));

        $rule = new TmuxCleanupRule;
        $this->assertSame('tmux_cleanup_rules', $rule->getTable());
        $this->assertFalse($rule->usesTimestamps());
        $this->assertSame([], $rule->getGuarded());

        $color = new TmuxColorExclusion;
        $this->assertSame('tmux_color_exclusions', $color->getTable());
        $this->assertFalse($color->usesTimestamps());
        $this->assertSame('integer', $color->getCasts()['color']);
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame(
            ['mode' => BackfillDaysMode::SafeDate, 'is_valid' => true, 'uses_group_target' => false, 'uses_safe_date' => true],
            BackfillDaysMode::normalizeModeSelectionPhp(2),
        );
        $this->assertSame(
            ['mode' => BackfillDaysMode::DaysPerGroup, 'is_valid' => false, 'uses_group_target' => true, 'uses_safe_date' => false],
            BackfillDaysMode::normalizeModeSelectionPhp(0),
        );

        $this->assertSame(
            ['mode' => GroupScanMode::Days, 'is_valid' => true, 'unit' => 'days', 'target' => 1],
            GroupScanMode::normalizeScanSelectionPhp(1, -5, 10),
        );
        $this->assertSame(36500, GroupScanMode::normalizeScanSelectionPhp(1, PHP_INT_MAX, 0)['target']);
        $this->assertSame(
            ['mode' => GroupScanMode::Posts, 'is_valid' => false, 'unit' => 'posts', 'target' => 4294967295],
            GroupScanMode::normalizeScanSelectionPhp(9, 3, PHP_INT_MAX),
        );

        $this->assertSame(
            ['mode' => TmuxBackfillMode::All, 'is_valid' => true, 'enabled' => true, 'command' => 'multiprocessing:backfill', 'group_count' => 1],
            TmuxBackfillMode::normalizeBackfillDispatchPhp(1, 0),
        );
        $this->assertSame(
            ['mode' => TmuxBackfillMode::Safe, 'is_valid' => true, 'enabled' => true, 'command' => 'multiprocessing:safe backfill', 'group_count' => 1],
            TmuxBackfillMode::normalizeBackfillDispatchPhp(4, 20),
        );
        $this->assertSame(
            ['mode' => TmuxBackfillMode::Disabled, 'is_valid' => false, 'enabled' => false, 'command' => null, 'group_count' => 0],
            TmuxBackfillMode::normalizeBackfillDispatchPhp(2, 20),
        );

        $this->assertSame(['rule' => 'codec', 'is_valid' => true, 'position' => 13], TmuxCleanupRule::normalizeCleanupPatternPhp("\tCODEC\n"));
        $this->assertSame(['rule' => "\x0cnzb", 'is_valid' => false, 'position' => null], TmuxCleanupRule::normalizeCleanupPatternPhp("\x0Cnzb"));
        $this->assertSame(['rule' => '', 'is_valid' => false, 'position' => null], TmuxCleanupRule::normalizeCleanupPatternPhp(' '));

        $this->assertSame(
            ['color' => 5, 'is_valid' => true, 'in_palette' => true, 'range_start' => 1, 'range_end' => 250],
            TmuxColorExclusion::normalizeExcludedColorCodePhp(5),
        );
        $this->assertSame(
            ['color' => 255, 'is_valid' => false, 'in_palette' => false, 'range_start' => 0, 'range_end' => 255],
            TmuxColorExclusion::normalizeExcludedColorCodePhp(PHP_INT_MAX, 300, -1),
        );
        $this->assertTrue(TmuxColorExclusion::normalizeExcludedColorCodePhp(20, 40, 10)['in_palette']);
        $this->assertFalse(TmuxColorExclusion::normalizeExcludedColorCodePhp(0)['in_palette']);
    }

    public function test_enum_native_and_dispatch_match_php(): void
    {
        foreach (self::INTS as $mode) {
            $this->assertBackfillDaysParity($mode);
            foreach (self::INTS as $count) {
                $this->assertGroupScanParity($mode, $count, $count);
                $this->assertGroupScanParity($mode, $count, $this->negate($count));
                $this->assertTmuxBackfillParity($mode, $count);
            }
        }
    }

    public function test_model_native_and_dispatch_match_php(): void
    {
        foreach ([...self::RULES, ...TmuxCleanupRule::KNOWN_RULES, ...array_map(strtoupper(...), TmuxCleanupRule::KNOWN_RULES), str_repeat(' x ', 5000)] as $rule) {
            $this->assertCleanupRuleParity($rule);
        }
        foreach (self::INTS as $color) {
            $this->assertColorParity($color, 1, 250);
            foreach ([PHP_INT_MIN, -1, 0, 1, 128, 250, 255, 256, PHP_INT_MAX] as $bound) {
                $this->assertColorParity($color, $bound, 250);
                $this->assertColorParity($color, 1, $bound);
                $this->assertColorParity($color, $bound, $this->negate($bound));
            }
        }
    }

    public function test_randomized_inputs_match_php(): void
    {
        $random = new Randomizer(new Mt19937(3501));
        $alphabet = ['a', 'B', 'c', 'd', 'e', 'k', 'L', 'n', 'z', 'Z', 's', 'P', ' ', "\t", "\n", "\0", "\x0B", "\x0C", '-', '_', 'é', 'Ü', '日', "\u{1F3AC}", "\u{00A0}", "\x1F", "\x1E", "\xff"];
        $text = static function () use ($random, $alphabet): string {
            if ($random->getInt(0, 2) === 0) {
                $known = TmuxCleanupRule::KNOWN_RULES[$random->getInt(0, count(TmuxCleanupRule::KNOWN_RULES) - 1)];

                return str_repeat(' ', $random->getInt(0, 2)).($random->getInt(0, 1) === 1 ? strtoupper($known) : $known)."\n";
            }
            $out = '';
            $length = $random->getInt(0, 40);
            for ($i = 0; $i < $length; $i++) {
                $out .= $alphabet[$random->getInt(0, count($alphabet) - 1)];
            }

            return $out;
        };
        $int = static fn (): int => match ($random->getInt(0, 3)) {
            0 => $random->getInt(-5, 6),
            1 => $random->getInt(-300, 40_000),
            2 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            default => $random->getInt(4294967290, 4294967300),
        };

        for ($i = 0; $i < 2000; $i++) {
            $this->assertBackfillDaysParity($int());
            $this->assertGroupScanParity($int(), $int(), $int());
            $this->assertTmuxBackfillParity($int(), $int());
            $this->assertCleanupRuleParity($text());
            $this->assertColorParity($int(), $int(), $int());
        }
    }

    public function test_native_exports_validate_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();

        $small = $ffi->new('char[4]');
        FFI::memset($small, ord('Z'), 4);
        $needed = $this->callFfi($ffi, 'nntmux_w35_tmux_backfill_mode_normalize', 1, 3, $small, 4);
        $this->assertSame(-32, $needed);
        $this->assertSame('ZZZZ', FFI::string($small, 4));
        $buffer = $ffi->new('char[32]');
        $this->assertSame(32, $this->callFfi($ffi, 'nntmux_w35_tmux_backfill_mode_normalize', 1, 3, $buffer, 32));
        $this->assertSame("1\x1F1\x1F1\x1Fmultiprocessing:backfill\x1F3", FFI::string($buffer, 32));
        $this->assertLessThan(-1, $this->callFfi($ffi, 'nntmux_w35_tmux_cleanup_rule_normalize', 'blacklist', 9, $small, 4));
        $this->assertSame('ZZZZ', FFI::string($small, 4));

        $out = $ffi->new('char[256]');
        $written = $this->callFfi($ffi, 'nntmux_w35_backfill_days_mode_normalize', 7, $out, 256);
        $this->assertSame("1\x1F0\x1F1\x1F0", FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_w35_group_scan_mode_normalize', 1, 0, 9, $out, 256);
        $this->assertSame("1\x1F1\x1Fdays\x1F1", FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_w35_tmux_backfill_mode_normalize', 3, 9, $out, 256);
        $this->assertSame("0\x1F0\x1F0\x1F\x1E\x1F0", FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_w35_tmux_cleanup_rule_normalize', ' Short ', 7, $out, 256);
        $this->assertSame("short\x1F1\x1F10", FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_w35_tmux_cleanup_rule_normalize', '', 0, $out, 256);
        $this->assertSame("\x1F0\x1F\x1E", FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_w35_tmux_color_exclusion_normalize', 300, 250, 1, $out, 256);
        $this->assertSame("255\x1F0\x1F0\x1F1\x1F250", FFI::string($out, $written));

        // Wire markers inside a rule decline with -1 so PHP keeps its own implementation.
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_tmux_cleanup_rule_normalize', "a\x1Fb", 3, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_tmux_cleanup_rule_normalize', "\x1E", 1, $out, 256));

        // Null pointers decline instead of crashing.
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_tmux_cleanup_rule_normalize', null, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_tmux_cleanup_rule_normalize', 'nzb', 3, null, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_backfill_days_mode_normalize', 1, null, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_group_scan_mode_normalize', 1, 1, 1, null, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_tmux_backfill_mode_normalize', 1, 1, null, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_tmux_color_exclusion_normalize', 1, 1, 1, null, 256));

        foreach (self::TOGGLED as $class) {
            $class::setUseRustFfi(true);
        }
        BackfillDaysMode::normalizeModeSelection(1);
        GroupScanMode::normalizeScanSelection(1, 3, 0);
        TmuxBackfillMode::normalizeBackfillDispatch(1, 2);
        TmuxCleanupRule::normalizeCleanupPattern('nzb');
        TmuxColorExclusion::normalizeExcludedColorCode(5);
        $enumFfi = (new ReflectionProperty(TmuxCleanupRule::class, 'enumFfi'))->getValue();
        foreach (self::ENUMS as $enum) {
            $this->assertInstanceOf(FFI::class, $enumFfi[$enum] ?? null, $enum.' must resolve its native export');
        }
        foreach ([TmuxCleanupRule::class, TmuxColorExclusion::class] as $model) {
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($model, 'ffi'))->getValue(), $model.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        $enumFfi = new ReflectionProperty(TmuxCleanupRule::class, 'enumFfi');
        $savedEnums = $enumFfi->getValue();
        $enumFfi->setValue(null, array_fill_keys(self::ENUMS, false));
        $saved = [];
        foreach ([TmuxCleanupRule::class, TmuxColorExclusion::class] as $model) {
            $saved[$model] = (new ReflectionProperty($model, 'ffi'))->getValue();
            (new ReflectionProperty($model, 'ffi'))->setValue(null, false);
        }
        foreach (self::TOGGLED as $class) {
            $class::setUseRustFfi(true);
        }

        try {
            $this->assertSame(BackfillDaysMode::normalizeModeSelectionPhp(2), BackfillDaysMode::normalizeModeSelection(2));
            $this->assertSame(GroupScanMode::normalizeScanSelectionPhp(1, 7, 0), GroupScanMode::normalizeScanSelection(1, 7, 0));
            $this->assertSame(TmuxBackfillMode::normalizeBackfillDispatchPhp(4, 3), TmuxBackfillMode::normalizeBackfillDispatch(4, 3));
            $this->assertSame(TmuxCleanupRule::normalizeCleanupPatternPhp(' NZB '), TmuxCleanupRule::normalizeCleanupPattern(' NZB '));
            $this->assertSame(TmuxColorExclusion::normalizeExcludedColorCodePhp(9, 10, 1), TmuxColorExclusion::normalizeExcludedColorCode(9, 10, 1));
            $this->assertNull(TmuxCleanupRule::callCleaningNative(null, 'nntmux_w35_backfill_days_mode_normalize', [1]));
        } finally {
            $enumFfi->setValue(null, $savedEnums);
            foreach ($saved as $model => $value) {
                (new ReflectionProperty($model, 'ffi'))->setValue(null, $value);
            }
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of the five helpers through their public dispatchers,
        // including argument passing, the FFI call and field decoding (release dylib, 5 warmups,
        // 301 samples x 40 iterations). Common = stored enum modes, catalogue rules and in-range
        // colours; uncommon = unknown modes, extreme counts, padded/Unicode/long rules and
        // out-of-range colours.
        // Speedup = PHP latency / Rust latency per workload pass (PHP 8.5.12 NTS, Apple M5, 4 rounds):
        // - Round 1: common mean 0.20x / p99 0.21x, uncommon mean 0.20x / p99 0.21x
        // - Round 2: common mean 0.21x / p99 0.21x, uncommon mean 0.21x / p99 0.21x
        // - Round 3: common mean 0.20x / p99 0.21x, uncommon mean 0.21x / p99 0.22x
        // - Round 4: common mean 0.21x / p99 0.21x, uncommon mean 0.20x / p99 0.16x
        // Reproduce: php vendor/bin/phpunit tests/Unit/RustIngestionTmuxEnumsCleanupModelsParityTest.php --filter benchmark
        // Sub-microsecond PHP enum/int/string work is cheaper than the FFI round trip, so every helper
        // stays below the 1.5x bar in .ai/rules/rust-performance.md and keeps RUST_FFI_DEFAULT = false.
        $this->ffiOrSkip();
        $workloads = ['common' => $this->commonWorkload(), 'uncommon' => $this->uncommonWorkload()];
        $random = new Randomizer(new Mt19937(3501));
        $warmup = 5;
        $samples = 301;
        $iterations = 40;
        $sink = 0;

        $speedups = [];
        foreach ($workloads as $name => $workload) {
            for ($w = 0; $w < $warmup; $w++) {
                $sink += $this->runWorkload($workload, false) + $this->runWorkload($workload, true);
            }
            $times = ['php' => [], 'rust' => []];
            for ($s = 0; $s < $samples; $s++) {
                $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                foreach ($order as $useRust) {
                    $start = hrtime(true);
                    for ($i = 0; $i < $iterations; $i++) {
                        $sink += $this->runWorkload($workload, $useRust);
                    }
                    $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                }
            }
            foreach (self::TOGGLED as $class) {
                $class::setUseRustFfi(null);
            }
            $speedups[$name] = [
                'mean' => $this->mean($times['php']) / $this->mean($times['rust']),
                'p99' => $this->p99($times['php']) / $this->p99($times['rust']),
            ];
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustIngestionTmuxEnumsCleanupModels speedup: {$summary}\n");
        if ($speedups['common']['mean'] < 1.5 || $speedups['common']['p99'] < 1.5 || $speedups['uncommon']['p99'] < 1.5) {
            foreach (self::TOGGLED as $class) {
                $this->assertFalse($class::usesRustFfi(), $class.' must keep PHP below 1.5x: '.$summary);
            }
        }
        foreach ($speedups as $name => $speedup) {
            $this->assertGreaterThan(0.0, $speedup['mean'], $name);
            $this->assertGreaterThan(0.0, $speedup['p99'], $name);
        }
    }

    private function assertBackfillDaysParity(int $mode): void
    {
        $expected = BackfillDaysMode::normalizeModeSelectionPhp($mode);
        $context = (string) $mode;
        $this->assertSameResult($expected, BackfillDaysMode::normalizeModeSelectionNative($mode), $context);
        BackfillDaysMode::setUseRustFfi(true);
        $this->assertSameResult($expected, BackfillDaysMode::normalizeModeSelection($mode), $context);
        BackfillDaysMode::setUseRustFfi(false);
        $this->assertSameResult($expected, BackfillDaysMode::normalizeModeSelection($mode), $context);
        BackfillDaysMode::setUseRustFfi(null);
    }

    private function assertGroupScanParity(int $mode, int $days, int $messages): void
    {
        $expected = GroupScanMode::normalizeScanSelectionPhp($mode, $days, $messages);
        $context = json_encode([$mode, $days, $messages]) ?: '';
        $this->assertSameResult($expected, GroupScanMode::normalizeScanSelectionNative($mode, $days, $messages), $context);
        GroupScanMode::setUseRustFfi(true);
        $this->assertSameResult($expected, GroupScanMode::normalizeScanSelection($mode, $days, $messages), $context);
        GroupScanMode::setUseRustFfi(false);
        $this->assertSameResult($expected, GroupScanMode::normalizeScanSelection($mode, $days, $messages), $context);
        GroupScanMode::setUseRustFfi(null);
    }

    private function assertTmuxBackfillParity(int $mode, int $groups): void
    {
        $expected = TmuxBackfillMode::normalizeBackfillDispatchPhp($mode, $groups);
        $context = json_encode([$mode, $groups]) ?: '';
        $this->assertSameResult($expected, TmuxBackfillMode::normalizeBackfillDispatchNative($mode, $groups), $context);
        TmuxBackfillMode::setUseRustFfi(true);
        $this->assertSameResult($expected, TmuxBackfillMode::normalizeBackfillDispatch($mode, $groups), $context);
        TmuxBackfillMode::setUseRustFfi(false);
        $this->assertSameResult($expected, TmuxBackfillMode::normalizeBackfillDispatch($mode, $groups), $context);
        TmuxBackfillMode::setUseRustFfi(null);
    }

    private function assertCleanupRuleParity(string $rule): void
    {
        $expected = TmuxCleanupRule::normalizeCleanupPatternPhp($rule);
        $context = bin2hex(substr($rule, 0, 64));
        $this->assertSameResult($expected, TmuxCleanupRule::normalizeCleanupPatternNative($rule), $context);
        TmuxCleanupRule::setUseRustFfi(true);
        $this->assertSameResult($expected, TmuxCleanupRule::normalizeCleanupPattern($rule), $context);
        TmuxCleanupRule::setUseRustFfi(false);
        $this->assertSameResult($expected, TmuxCleanupRule::normalizeCleanupPattern($rule), $context);
        TmuxCleanupRule::setUseRustFfi(null);
    }

    private function assertColorParity(int $color, int $start, int $end): void
    {
        $expected = TmuxColorExclusion::normalizeExcludedColorCodePhp($color, $start, $end);
        $context = json_encode([$color, $start, $end]) ?: '';
        $this->assertSameResult($expected, TmuxColorExclusion::normalizeExcludedColorCodeNative($color, $start, $end), $context);
        TmuxColorExclusion::setUseRustFfi(true);
        $this->assertSameResult($expected, TmuxColorExclusion::normalizeExcludedColorCode($color, $start, $end), $context);
        TmuxColorExclusion::setUseRustFfi(false);
        $this->assertSameResult($expected, TmuxColorExclusion::normalizeExcludedColorCode($color, $start, $end), $context);
        TmuxColorExclusion::setUseRustFfi(null);
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function assertSameResult(array $expected, array $actual, string $context): void
    {
        $this->assertSame($expected, $actual, $context);
    }

    /**
     * @return array{modes: list<int>, counts: list<int>, rules: list<string>, colors: list<int>}
     */
    private function commonWorkload(): array
    {
        return [
            'modes' => [0, 1, 4, 1, 2, 0],
            'counts' => [7, 20, 100, 30, 500, 1],
            'rules' => ['blacklist', 'codec', 'nzb', 'passworded', 'sample', 'size'],
            'colors' => [1, 16, 33, 128, 200, 250],
        ];
    }

    /**
     * @return array{modes: list<int>, counts: list<int>, rules: list<string>, colors: list<int>}
     */
    private function uncommonWorkload(): array
    {
        return [
            'modes' => [PHP_INT_MIN, -1, 3, 9, 4294967296, PHP_INT_MAX],
            'counts' => [PHP_INT_MIN, -1, 0, 36501, 4294967296, PHP_INT_MAX],
            'rules' => ["  PASSWORDURL \n", 'Ünïcödé Rule', "\0\x0B Gibberish \t", str_repeat('x', 200), "sep\x1Fmark", ''],
            'colors' => [PHP_INT_MIN, -1, 0, 251, 256, PHP_INT_MAX],
        ];
    }

    /**
     * @param  array{modes: list<int>, counts: list<int>, rules: list<string>, colors: list<int>}  $workload
     */
    private function runWorkload(array $workload, bool $useRust): int
    {
        foreach (self::TOGGLED as $class) {
            $class::setUseRustFfi($useRust);
        }
        $sink = 0;
        foreach ($workload['modes'] as $i => $mode) {
            $count = $workload['counts'][$i];
            $sink += BackfillDaysMode::normalizeModeSelection($mode)['mode']->value;
            $sink += GroupScanMode::normalizeScanSelection($mode, $count, $count)['target'] & 0xFF;
            $sink += TmuxBackfillMode::normalizeBackfillDispatch($mode, $count)['group_count'] & 0xFF;
            $sink += \strlen(TmuxCleanupRule::normalizeCleanupPattern($workload['rules'][$i])['rule']);
            $sink += TmuxColorExclusion::normalizeExcludedColorCode($workload['colors'][$i], 1, 250)['color'];
        }

        return $sink;
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(count($values) - 1, (int) ceil(count($values) * 0.99) - 1)];
    }

    /** Integer negation that maps PHP_INT_MIN to PHP_INT_MAX instead of promoting it to float. */
    private function negate(int $value): int
    {
        return $value === PHP_INT_MIN ? PHP_INT_MAX : -$value;
    }

    private function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $cdef = implode("\n", [
            BackfillDaysMode::FFI_CDEF,
            GroupScanMode::FFI_CDEF,
            TmuxBackfillMode::FFI_CDEF,
            TmuxCleanupRule::FFI_CDEF,
            TmuxColorExclusion::FFI_CDEF,
        ]);
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', $cdef);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the ingestion/tmux enum and cleanup model exports is not available.');
        }

        return $ffi;
    }
}
