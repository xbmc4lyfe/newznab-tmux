<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\TmuxPaneRole;
use App\Enums\TmuxPostProcessMode;
use App\Enums\TmuxSequentialMode;
use App\Services\NameFixing\PredbIdentityKey;
use App\Services\NameFixing\SeasonPackDetector;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class RustEnumsTmuxPanePostProcessSequentialPredbKeySeasonPackW59ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        TmuxPaneRole::class => 'planPaneRoleBatch',
        TmuxPostProcessMode::class => 'planPostProcessModeBatch',
        TmuxSequentialMode::class => 'planSequentialModeBatch',
        PredbIdentityKey::class => 'summarizeIdentityKeyBatch',
        SeasonPackDetector::class => 'planSeasonPackBatch',
    ];

    /** Class => Wave 59.2 export. */
    private const SYMBOLS = [
        TmuxPaneRole::class => 'nntmux_w592_tmux_pane_role_plan_batch',
        TmuxPostProcessMode::class => 'nntmux_w592_tmux_post_process_mode_plan_batch',
        TmuxSequentialMode::class => 'nntmux_w592_tmux_sequential_mode_plan_batch',
        PredbIdentityKey::class => 'nntmux_w592_predb_identity_key_summary_batch',
        SeasonPackDetector::class => 'nntmux_w592_season_pack_detection_plan_batch',
    ];

    /** Class => private C declaration constant. */
    private const CDEFS = [
        TmuxPaneRole::class => 'W59_PANE_ROLE_FFI_CDEF',
        TmuxPostProcessMode::class => 'W59_POST_PROCESS_MODE_FFI_CDEF',
        TmuxSequentialMode::class => 'W59_SEQUENTIAL_MODE_FFI_CDEF',
        PredbIdentityKey::class => 'W59_IDENTITY_KEY_FFI_CDEF',
        SeasonPackDetector::class => 'W59_SEASON_PACK_FFI_CDEF',
    ];

    /** Class => summary words after the per-item words (the digest included). */
    private const TAIL = [
        TmuxPaneRole::class => 8,
        TmuxPostProcessMode::class => 11,
        TmuxSequentialMode::class => 8,
        PredbIdentityKey::class => 8,
        SeasonPackDetector::class => 8,
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns), each the minimum of
     * three runs of test_paired_randomized_common_and_uncommon_benchmarks (seed 59, 60
     * interleaved rounds of 10 pairs per class and workload after 2 warmups, 600 pooled samples
     * per metric, each side the fastest of 3 back-to-back calls, batches of 320 Common or
     * 320-640 Uncommon items, release Rust, PHP 8.5.12 CLI, Apple Silicon macOS on a shared host
     * at load average 6-13). Timings include dispatch, implode, the FFI call and unpack('P*'). A
     * class defaults to Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        TmuxPaneRole::class => [5.956, 6.341, 3.391, 3.297],
        TmuxPostProcessMode::class => [10.175, 9.939, 8.476, 8.249],
        TmuxSequentialMode::class => [10.688, 11.954, 9.839, 9.113],
        PredbIdentityKey::class => [3.005, 2.966, 3.036, 2.955],
        SeasonPackDetector::class => [5.913, 5.829, 6.622, 5.894],
    ];

    /** Interleaved benchmark rounds; each times PAIRS pairs per class and workload. */
    private const ROUNDS = 60;

    private const PAIRS = 10;

    private const WARMUPS = 2;

    /** Back-to-back calls per side of a pair; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    /** Batches per class and workload in the randomized parity sweep. */
    private const PARITY_BATCHES = 6;

    private const RELEASES = [
        'Some.Show.S01E02.720p.HDTV.x264-GRP', 'The.Great.Movie.2019.1080p.BluRay.x264-SPARKS', 'Docu.Series.S03E10.1080p.WEB.h264-GOSSIP',
        'Show.Name.S02.1080p.BluRay.x264-ROVERS', 'Show.Name.S01-S03.Complete.720p.WEB-DL-GRP', 'Cartoon.1x05.Episode.Name.PDTV-LOL',
        'Show.Name.Season.2.DVDRip.XviD-SAiNTS', 'Show.Name.S01D2.NTSC.DVDR-GRP', 'Show.Name.S01E01E02.720p.HDTV.x264-FLEET',
        'Show.Name.Complete.Series.1080p.BluRay-GRP', 'Night_Show_2023_10_05_720p_WEB_h264-JEBAITED', 'Show Name - E05 - [1080p]',
    ];

    private const WORDS = ['the', 'great', 'movie', 'show', 'night', 'big', 'game', 'tool', 'pro', 'film', 'series', 'docu', 'saga', 'old', 'new'];

    private const GROUPS = ['GRP', 'SPARKS', 'FLUX', 'GOSSIP', 'DUPLEX', 'CODEX', 'XYZ', 'TNT', 'FiCO', 'LOL', 'NTb', 'rarbg'];

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW59RustFfi', 'withW59RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
                self::assertTrue($reflection->getMethod($method)->isPublic() && $reflection->getMethod($method)->isStatic(), $class.'::'.$method);
                self::assertSame($class, $reflection->getMethod($method)->getDeclaringClass()->getName(), $class.'::'.$method);
            }
            $state = $reflection->getMethod('w59RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            self::assertTrue($reflection->hasConstant('W59_RUST_FFI_DEFAULT'), $class);
            self::assertIsBool($reflection->getConstant('W59_RUST_FFI_DEFAULT'), $class);
            self::assertTrue($reflection->getReflectionConstant('W59_RUST_FFI_DEFAULT')?->isPublic(), $class);
            self::assertTrue($reflection->getReflectionConstant(self::CDEFS[$class])?->isPrivate(), $class);
            self::assertStringContainsString(self::SYMBOLS[$class], (string) $reflection->getConstant(self::CDEFS[$class]), $class);
            foreach (array_keys($reflection->getStaticProperties()) as $property) {
                self::assertStringNotContainsString('w59', strtolower((string) $property), 'Wave 59 state lives in w59RustFfiState().');
            }
        }
        foreach ([TmuxPaneRole::class, TmuxPostProcessMode::class, TmuxSequentialMode::class] as $enum) {
            self::assertTrue((new ReflectionClass($enum))->isEnum(), $enum);
            self::assertSame([], (new ReflectionClass($enum))->getStaticProperties(), $enum);
        }
    }

    public function test_existing_public_api_is_preserved(): void
    {
        self::assertSame(['monitor', 'binaries', 'backfill', 'releases', 'sequential', 'fix_names', 'remove_crap', 'post_additional', 'post_tv',
            'post_metadata', 'post_movies', 'irc_scraper', 'htop', 'nmon', 'vnstat', 'tcptrack', 'bandwidth_monitor', 'mytop', 'redis', 'console'],
            array_map(static fn (TmuxPaneRole $role): string => $role->value, TmuxPaneRole::cases()));
        self::assertSame([0, 1, 2, 3], array_map(static fn (TmuxPostProcessMode $mode): int => $mode->value, TmuxPostProcessMode::cases()));
        self::assertSame([0, 1, 2], array_map(static fn (TmuxSequentialMode $mode): int => $mode->value, TmuxSequentialMode::cases()));
        $expected = [
            TmuxPaneRole::class => ['setUseRustFfi', 'usesRustFfi', 'normalizePaneRoleValue', 'normalizePaneRoleValuePhp', 'normalizePaneRoleValueNative'],
            TmuxPostProcessMode::class => ['setUseRustFfi', 'usesRustFfi', 'normalizePostProcessModeValue', 'normalizePostProcessModeValuePhp',
                'normalizePostProcessModeValueNative'],
            TmuxSequentialMode::class => ['setUseRustFfi', 'usesRustFfi', 'normalizeSequentialModeValue', 'normalizeSequentialModeValuePhp',
                'normalizeSequentialModeValueNative'],
            PredbIdentityKey::class => ['normalize', 'normalizeNative', 'normalizePhp'],
            SeasonPackDetector::class => ['isSeasonPack', 'isSeasonPackPhp', 'isEpisodeName', 'isEpisodeNamePhp', 'isUnclassifiedEpisodeName',
                'isUnclassifiedEpisodeNamePhp', 'isMultiEpisodeSet', 'hasDistinctEpisodeIdentities', 'hasDistinctEpisodeIdentitiesPhp', 'episodeIdentity',
                'episodeIdentityPhp', 'hasNonSingleEpisodeEvidence', 'hasNonSingleEpisodeEvidencePhp', 'isAncillaryName', 'isAncillaryNamePhp',
                'seasonNameFromEpisodes', 'seasonNameFromEpisodesPhp', 'seasonNameFromEpisode', 'seasonNameFromEpisodePhp', 'seasonFolder',
                'seasonFolderPhp', 'isUnclassifiedEpisodeNameNative', 'hasDistinctEpisodeIdentitiesNative', 'isAncillaryNameNative', 'seasonFolderNative'],
        ];
        foreach ($expected as $class => $methods) {
            $reflection = new ReflectionClass($class);
            foreach ($methods as $method) {
                self::assertTrue($reflection->hasMethod($method) && $reflection->getMethod($method)->isPublic(), $class.'::'.$method);
            }
        }
        self::assertSame(['value' => 'fix_names', 'is_valid' => true], TmuxPaneRole::normalizePaneRoleValuePhp(TmuxPaneRole::FixNames));
        self::assertSame(['value' => 3, 'is_valid' => true], TmuxPostProcessMode::normalizePostProcessModeValuePhp('3'));
        self::assertSame(['value' => 0, 'is_valid' => false], TmuxSequentialMode::normalizeSequentialModeValuePhp(7));
        self::assertSame('some.movie.2019', PredbIdentityKey::normalizePhp(' Some__Movie 2019 '));
        self::assertTrue(SeasonPackDetector::isSeasonPackPhp('Show.Name.S01.1080p.BluRay-GRP'));
        self::assertSame('Show.S01.1080p.WEB-DL-GRP', SeasonPackDetector::seasonNameFromEpisodesPhp(['Show.S01E01.Pilot.1080p.WEB-DL-GRP.mkv',
            'Show.S01E02.The.Title.1080p.WEB-DL-GRP.mkv']));
    }

    /** The plan tables follow the enum cases they describe. */
    public function test_plan_tables_follow_enum_cases(): void
    {
        $roles = $this->constant(TmuxPaneRole::class, 'W59_ROLES');
        self::assertSame(array_map(static fn (TmuxPaneRole $role): string => $role->value, TmuxPaneRole::cases()), array_keys($roles));
        foreach (TmuxPaneRole::cases() as $index => $role) {
            self::assertSame($index, $roles[$role->value][0], $role->value);
            self::assertSame($role->value, $this->constant(TmuxPaneRole::class, 'W59_COMPACT')[strtolower($role->name)], $role->name);
        }
        foreach ($this->constant(TmuxPaneRole::class, 'W59_LEGACY') as $target => $value) {
            $first = null;
            foreach ($roles as $candidate => [, $window, $pane]) {
                if ($first === null && $target === $window.'.'.$pane) {
                    $first = $candidate;
                }
            }
            self::assertSame($first, $value, $target);
        }
        self::assertCount(\count(array_unique(array_map(static fn (array $row): string => $row[1].'.'.$row[2], $roles))),
            $this->constant(TmuxPaneRole::class, 'W59_LEGACY'));
        foreach ([TmuxPostProcessMode::class, TmuxSequentialMode::class] as $enum) {
            self::assertSame(array_combine(array_map(static fn ($case): string => strtolower($case->name), $enum::cases()),
                array_map(static fn ($case): int => $case->value, $enum::cases())), $this->constant($enum, 'W59_NAMES'), $enum);
        }
    }

    public function test_defaults_match_recorded_qualification(): void
    {
        foreach (self::QUALIFICATION as $class => $speedups) {
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->default($class), $class);
            self::assertSame($this->default($class), $class::usesW59RustFfi(), $class);
            if (min($speedups) < 1.5) {
                self::assertFalse($class::usesW59RustFfi(), $class.' retains PHP by default');
            }
        }
    }

    public function test_with_toggle_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->default($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW59RustFfi($enabled, static function () use ($class, $enabled): array {
                    $inner = $class::withW59RustFfi(! $enabled, static fn (): bool => $class::usesW59RustFfi());

                    return [$class::usesW59RustFfi(), $inner];
                });
                self::assertSame([$enabled, ! $enabled], $seen, $class);
                self::assertSame($default, $class::usesW59RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW59RustFfi(! $default, static fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown, 'The callback exception must propagate.');
            self::assertSame($default, $class::usesW59RustFfi(), $class);
        }
    }

    public function test_toggles_stay_independent_of_each_other_and_earlier_switches(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $seen = $class::withW59RustFfi(! $this->default($class), static fn (): array => array_map(
                static fn (string $other): bool => $other::usesW59RustFfi(),
                array_keys(self::ENTRIES),
            ));
            foreach (array_keys(self::ENTRIES) as $i => $other) {
                self::assertSame($other === $class ? ! $this->default($other) : $this->default($other), $seen[$i], $class.' vs '.$other);
            }
        }
        // The Wave 59 toggles leave the earlier enum switches untouched.
        foreach ([TmuxPaneRole::class, TmuxPostProcessMode::class, TmuxSequentialMode::class] as $enum) {
            $before = $enum::usesRustFfi();
            $enum::withW59RustFfi(! $this->default($enum), static function () use ($enum, $before): void {
                self::assertSame($before, $enum::usesRustFfi());
            });
            self::assertSame($before, $enum::usesRustFfi(), $enum);
        }
    }

    public function test_entry_points_route_through_toggle(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(5900));
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([false, true] as $uncommon) {
                $items = $this->fixture($class, $random, $uncommon);
                $php = $this->call($class, 'Php', $items);
                foreach ([true, false] as $native) {
                    self::assertSame($php, $class::withW59RustFfi($native, fn (): array => $this->call($class, '', $items)), $class);
                }
                self::assertSame($php, $this->call($class, 'Native', $items), $class);
            }
        }
    }

    public function test_native_helpers_fall_back_to_php_for_unsupported_input(): void
    {
        $this->requireBindings();
        // NUL bytes and keyed or non-sequential columns keep the PHP semantics.
        foreach (array_keys(self::ENTRIES) as $class) {
            $this->assertParity($class, ["fix\0names", 'monitor', '1']);
            $this->assertParity($class, ['k' => 'monitor', 'j' => 'Show.S01-GRP']);
            $this->assertParity($class, [1 => '2', 0 => 'Show.S01E01-GRP']);
            $this->assertParity($class, ["\0"]);
        }
    }

    public function test_exports_guard_null_short_and_malformed_batches(): void
    {
        $this->requireBindings();
        foreach (self::SYMBOLS as $class => $symbol) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[32]');
            $words = 1 + self::TAIL[$class];
            self::assertSame($words, $this->invoke($ffi, $symbol, ['a', 1, 1, $out, 32]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['a', 1, 1, null, 32]), $class);
            self::assertSame(-$words, $this->invoke($ffi, $symbol, ['a', 1, 1, $out, $words - 1]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ["a\0b", 3, 1, $out, 32]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['a', 1, 2, $out, 32]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, [null, 1, 1, $out, 32]), $class);
            self::assertSame(self::TAIL[$class], $this->invoke($ffi, $symbol, [null, 0, 0, $out, 32]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['', 0, 1 << 21, $out, 32]), $class);
        }
    }

    public function test_pane_role_plan_parity(): void
    {
        $this->requireBindings();
        $tokens = ['fix_names', ' Post-TV ', 'BandwidthMonitor', '0.1', '9.9', '', 'SEQUENTIAL', '2.2'];
        $words = [
            $this->word(5 | 1 << 5 | 7 << 8 | 1 << 11 | 1 << 12, 'fix_names', 'fix_names'),
            $this->word(8 | 2 << 5 | 3 << 8 | 1 << 11 | 2 << 12 | 1 << 16, ' Post-TV ', 'post-tv'),
            $this->word(16 | 3 << 5 | 4 << 12 | 4 << 16, 'BandwidthMonitor', 'bandwidthmonitor'),
            $this->word(1 | 4 << 5 | 1 << 8 | 1 << 11 | 1 << 16, '0.1', '0.1'),
            $this->word(31, '9.9', '9.9'), 31,
            $this->word(4 | 2 << 5 | 4 << 8 | 1 << 11 | 1 << 16, 'SEQUENTIAL', 'sequential'),
            $this->word(9 | 4 << 5 | 7 << 8 | 1 << 11 | 2 << 12 | 2 << 16, '2.2', '2.2'),
            6, 2, 4, 3, 3, 1 << 1 | 1 << 4 | 1 << 5 | 1 << 8 | 1 << 9 | 1 << 16, 5,
        ];
        $this->assertWords(TmuxPaneRole::class, $tokens, $words);
        $this->assertParity(TmuxPaneRole::class, $tokens);
        $this->assertRandomParity(TmuxPaneRole::class, 5910);
    }

    public function test_post_process_mode_plan_parity(): void
    {
        $this->requireBindings();
        $tokens = ['3', 'amazon: nfo', 'NON:+01', 'x:2', '4', '-0'];
        $crc = static fn (string $raw): int => crc32(strtolower(trim($raw))) << 32;
        $words = [
            3 | 1 << 2 | 1 << 6 | 15 << 8 | 1 << 13 | $crc('3'), 2 | 3 << 2 | 1 << 4 | 1 << 6 | 18 << 8 | 11 << 13 | $crc('amazon: nfo'),
            1 | 2 << 2 | 2 << 4 | 1 << 6 | 4 << 8 | 7 << 13 | $crc('NON:+01'), 2 | 1 << 2 | 3 << 4 | 1 << 6 | 3 << 13 | $crc('x:2'),
            1 << 13 | $crc('4'), 2 << 2 | 1 << 6 | 2 << 13 | $crc('-0'),
            2, 1, 2, 1, 1, 31, 3, 1, 1, 1,
        ];
        $this->assertWords(TmuxPostProcessMode::class, $tokens, $words);
        $this->assertParity(TmuxPostProcessMode::class, $tokens);
        // A valid mode agrees with the existing single-value normalizer.
        foreach (['0', '1', '2', '3', '4', 'x', ''] as $value) {
            $word = TmuxPostProcessMode::planPostProcessModeBatchPhp([$value])[0];
            $legacy = TmuxPostProcessMode::normalizePostProcessModeValuePhp($value);
            self::assertSame([$legacy['value'], $legacy['is_valid']], [$word & 3, ($word >> 6 & 1) === 1], $value);
        }
        $this->assertRandomParity(TmuxPostProcessMode::class, 5920);
    }

    public function test_sequential_mode_plan_parity(): void
    {
        $this->requireBindings();
        $tokens = ['2@30', 'basic @ 10', '0@x', '7@5'];
        $words = [
            2 | 1 << 2 | 1 << 4 | 1 << 5 | 4 << 6 | 0x30F << 10 | 4 << 20 | 180 << 32,
            1 | 3 << 2 | 1 << 4 | 1 << 5 | 8 << 6 | 0x3FC << 10 | 10 << 20 | 20 << 32,
            1 << 2 | 1 << 4 | 10 << 6 | 0x3FF << 10 | 3 << 20,
            1 << 5 | 10 << 6 | 0x3FF << 10 | 3 << 20 | 5 << 32,
            2, 1, 1, 1, 205, 180, 0x3FF,
        ];
        $this->assertWords(TmuxSequentialMode::class, $tokens, $words);
        $this->assertParity(TmuxSequentialMode::class, $tokens);
        foreach (['0', '1', '2', '3', 'x', ''] as $value) {
            $word = TmuxSequentialMode::planSequentialModeBatchPhp([$value])[0];
            $legacy = TmuxSequentialMode::normalizeSequentialModeValuePhp($value);
            self::assertSame([$legacy['value'], $legacy['is_valid']], [$word & 3, ($word >> 4 & 1) === 1], $value);
        }
        $this->assertRandomParity(TmuxSequentialMode::class, 5930);
    }

    public function test_identity_key_summary_parity(): void
    {
        $this->requireBindings();
        $md5 = 'D41D8CD98F00B204E9800998ECF8427E';
        $keys = ['  Some__Movie. 2019 ', '', "caf\u{e9}", $md5, 'a.b', str_repeat('a', 513)];
        $word = static fn (int $flags, string $canonical): int => $flags | \strlen($canonical) << 8 | crc32($canonical) << 32;
        $words = [
            $word(3 | 4, 'some.movie.2019'), 0, 2, $word(3 | 4 | 16, strtolower($md5)), $word(3, 'a.b'), 1,
            1, 1, 1, 3, 2, 1, 3,
        ];
        $this->assertWords(PredbIdentityKey::class, $keys, $words);
        $this->assertParity(PredbIdentityKey::class, $keys);
        $this->assertRandomParity(PredbIdentityKey::class, 5940);
    }

    /** ASCII canonical tokens are exactly normalizePhp()'s results. */
    public function test_identity_key_canonical_tokens_match_normalize_php(): void
    {
        $checked = 0;
        foreach ([false, true] as $uncommon) {
            $keys = $this->fixture(PredbIdentityKey::class, new Randomizer(new Mt19937(5945 + (int) $uncommon)), $uncommon);
            $keys = [...$keys, " \x0c a\x0bb \x0c", "\tA_B\t", '._.', 'x..y__z  w'];
            $plan = PredbIdentityKey::summarizeIdentityKeyBatchPhp($keys);
            foreach ($keys as $i => $key) {
                if (($plan[$i] & 3) !== 3) {
                    continue;
                }
                $normalized = (string) PredbIdentityKey::normalizePhp($key);
                self::assertSame([\strlen($normalized), crc32($normalized)], [$plan[$i] >> 8 & 0xFFFFFF, $plan[$i] >> 32 & 0xFFFFFFFF], bin2hex($key));
                $checked++;
            }
        }
        self::assertGreaterThan(300, $checked);
    }

    public function test_season_pack_plan_parity(): void
    {
        $this->requireBindings();
        $names = ['Show.S01.1080p-GRP', 'Show.S01-S03.Complete-GRP', 'Show.S01E01E02.720p', 'Show.1x05.HDTV', 'Show.Season.2.D1', 'Movie.2019.1080p',
            'Show [S02] (Disc1)', 'Show.Seasons12.EP3'];
        $words = [
            1 | 3 << 7 | 40 << 10 | 1 << 18 | 4 << 32, 1 | 8 | 32 | 4 << 7 | 85 << 10 | 1 << 18 | 5 << 32, 2 | 4 | 2 << 7 | 1 << 18 | 3 << 32 | 1 << 48,
            2 | 1 << 7 | 1 << 18 | 3 << 32 | 5 << 48, 16 | 64 | 3 << 7 | 40 << 10 | 2 << 18 | 4 << 32, 3 << 32,
            1 | 16 | 3 << 7 | 50 << 10 | 2 << 18 | 3 << 32, 2 | 64 | 1 << 7 | 7 << 10 | 12 << 18 | 3 << 32 | 3 << 48,
            1, 2, 1, 3, 1, 222, 127,
        ];
        $this->assertWords(SeasonPackDetector::class, $names, $words);
        $this->assertParity(SeasonPackDetector::class, $names);
        $this->assertRandomParity(SeasonPackDetector::class, 5950);
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<string>  $items
     */
    #[DataProvider('edgeCases')]
    public function test_edge_case_parity(string $class, array $items): void
    {
        $this->requireBindings();
        $this->assertParity($class, $items);
        // NUL items cannot be framed (the export refuses them and the entry point falls back).
        if ($items !== [] && ! str_contains(implode('', $items), "\0")) {
            self::assertSame($this->call($class, 'Php', $items), $this->exported($class, $items), $class);
        }
    }

    /** @return iterable<string, array{key-of<self::ENTRIES>, list<string>}> */
    public static function edgeCases(): iterable
    {
        $long = str_repeat('Ab.', 25000);
        $binary = "\xff\xfe\x01".str_repeat("\xDB\xB2\x85", 30);
        foreach (array_keys(self::ENTRIES) as $class) {
            yield $class.' empty' => [$class, []];
            yield $class.' huge and binary' => [$class, [$long, $binary, '', ' ', "\x0b", str_repeat(' ', 5000).'1']];
        }
        yield 'pane bounds' => [TmuxPaneRole::class, [
            '', ' ', 'monitor', 'Monitor', ' monitor ', "monitor\n", "\0monitor", 'MONITOR', 'fix-names', 'fix.names', 'fix names', 'FixNames', 'fix__names',
            'f_i_x_names', '_fixnames_', '-fix-names-', 'fix_names ', "fix_names\x0c", '0.0', '0.1', '4.6', '4.7', '5.0', '9.9', '00.1', '0.10', ' 2.1 ',
            '2,1', 'a.b', 'bandwidth monitor', 'Bandwidth-Monitor', 'ircscraper', 'irc', 'post_tv_', 'post__tv', "\xff", "post\xA0tv", 'P0ST_TV',
            str_repeat('x', 5000), 'console', 'Console.', '...', '___',
        ]];
        yield 'post mode bounds' => [TmuxPostProcessMode::class, [
            '', '0', '1', '2', '3', '4', '9', '00', '03', '+3', '-0', '-1', '+-1', '++1', '-', '+', ' 2 ', 'x', 'all', 'ALL', ' Nfo ', 'disabled',
            'additional', 'post:3', 'amazon:3', 'non:3', 'AMAZON : nfo', 'foo:1', ':1', '1:', 'post:', 'amazon:all:3', '99999999999999999999999',
            '0000000000000000000003', "\t3\n", "3\x0c", "\x0b2\x0b", 'Post:All', 'non :  ', ' : ', '0x3', '3.0', '1e0', "\xA03",
        ]];
        yield 'sequential bounds' => [TmuxSequentialMode::class, [
            '', '0', '1', '2', '3', 'full', 'BASIC', ' stripped ', '0@', '0@0', '1@999999', '2@999999', '2@1000000', '@5', '1@ 5 ', '1@5@6', '1@-5',
            '1@+5', 'basic@x', '+2@10', '-0@10', '-1@10', 'Stripped@000001', "2@\t7\n", '2@7 8', '@', '@@', 'full@0000000', "2@\x0c7",
        ]];
        yield 'identity key bounds' => [PredbIdentityKey::class, [
            '', ' ', "\t\n", '.', '..', '_._', 'a', 'A.B', 'Some Movie 2019', ' Some__Movie..2019 ', "a\x0cb", "\x0cab", "a\x0b", "\x0ba", 'ab.',
            'd41d8cd98f00b204e9800998ecf8427e', 'D41D8CD98F00B204E9800998ECF8427E', 'd41d8cd98f00b204e9800998ecf8427', 'd41d8cd98f00b204e9800998ecf8427e0',
            'g41d8cd98f00b204e9800998ecf8427e', str_repeat('ab', 20), str_repeat('0f', 32), 'd41d8cd98f00b204e9800998ecf8427e.', "caf\u{e9}", "\xff",
            str_repeat('a', 512), str_repeat('a', 513), str_repeat(' ', 511).'a', str_repeat('a.', 256), "A\x7fB", "\x01\x02",
        ]];
        yield 'season bounds' => [SeasonPackDetector::class, [
            '', 's', 's1', 'S0001', 'S00001', 'S01E', 'S01E1', 'S01E12345', 'S1E1E2E3', 'S01E01E01', 'S01D1', 'S01D123', 'S01DISC1', 'S01X01', 'E1',
            'EP1', 'EPP1', 'E12345', 'EP', 'e0', '1x01', '1x1', '01x001', '123x01', '1x0001', 'x01', 'D1', 'D', 'DISC12', 'DISK2', 'DISC123',
            'Complete', 'COMPLETE', 'Completed', 'Season', 'Season 1', 'Season.123', 'Seasons1', 'Seasons', 'Seasons 2', 'Season1.Season2', 'season0',
            'Show.S01.S01', 'Show.S01.S02.S01', 'Show.S01E01.S01E01', 'Show.S01E01.E01', 'Show.E01.E02', 'Show.S9999.S0', '((S01))', '[S01]-[E02]',
            'S01E01-E02', 'Show-1x01-1x02', "Show\tS01", 'Show/S01', 'Show,S01', 'S01.Season', 'Season.S01', 'complete.season.3.d1.disc2',
        ]];
    }

    /**
     * Paired randomized benchmark: ROUNDS interleaved rounds visit every class and workload in
     * turn; each round times PAIRS pairs (after WARMUPS) of the PHP and Rust batch entry point on
     * one randomly chosen fixture, alternating which runs first, each side keeping the fastest of
     * REPEATS back-to-back calls. Common batches hold 320 typical records, Uncommon batches
     * 320-640 malformed or adversarial ones. Mean and nearest-rank p99 speedups pool every
     * round's samples; per-round medians are reported too. Every pair must agree; raw pairs are
     * written outside the checkout. A class must default to Rust exactly when all four metrics
     * reach 1.5x. Gated behind NNTMUX_W592_BENCH=1.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W592_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W592_BENCH=1 to run the Wave 59.2 paired benchmark.');
        }
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(59));
        $fixtures = $samples = $records = [];
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach (['Common', 'Uncommon'] as $workload) {
                for ($v = 0; $v < 8; $v++) {
                    $fixtures[$class][$workload][] = $this->fixture($class, $random, $workload === 'Uncommon');
                }
            }
        }
        for ($round = 0; $round < self::ROUNDS; $round++) {
            foreach (array_keys(self::ENTRIES) as $class) {
                foreach (['Common', 'Uncommon'] as $workload) {
                    for ($i = -self::WARMUPS; $i < self::PAIRS; $i++) {
                        $variant = $random->getInt(0, 7);
                        $order = ($i & 1) === 0 ? [false, true] : [true, false];
                        $results = $elapsed = [];
                        foreach ($order as $native) {
                            $items = $fixtures[$class][$workload][$variant];
                            [$results[(int) $native], $elapsed[(int) $native]] = $class::withW59RustFfi($native, fn (): array => $this->timed($class, $items));
                        }
                        self::assertSame($results[0], $results[1], $class.' '.$workload);
                        if ($i >= 0) {
                            $samples[$class][$workload][$round][] = [$elapsed[0], $elapsed[1]];
                            $records[] = [$class, $workload, $round, $variant, $order[0], $elapsed[0], $elapsed[1]];
                        }
                    }
                }
            }
        }
        $summary = [];
        foreach ($samples as $class => $workloads) {
            $metrics = [];
            foreach ($workloads as $workload => $rounds) {
                $php = $rust = $roundMeans = $roundP99s = [];
                foreach ($rounds as $pairs) {
                    $roundPhp = array_column($pairs, 0);
                    $roundRust = array_column($pairs, 1);
                    $roundMeans[] = array_sum($roundPhp) / array_sum($roundRust);
                    $roundP99s[] = $this->p99($roundPhp) / $this->p99($roundRust);
                    array_push($php, ...$roundPhp);
                    array_push($rust, ...$roundRust);
                }
                $summary[$class][$workload] = [
                    'mean_speedup' => round(array_sum($php) / array_sum($rust), 3), 'p99_speedup' => round($this->p99($php) / $this->p99($rust), 3),
                    'round_median_mean_speedup' => round($this->median($roundMeans), 3), 'round_median_p99_speedup' => round($this->median($roundP99s), 3),
                    'php_mean_ns' => (int) (array_sum($php) / \count($php)), 'rust_mean_ns' => (int) (array_sum($rust) / \count($rust)),
                    'php_p99_ns' => $this->p99($php), 'rust_p99_ns' => $this->p99($rust), 'samples' => \count($php),
                ];
                $metrics[] = $summary[$class][$workload]['mean_speedup'];
                $metrics[] = $summary[$class][$workload]['p99_speedup'];
            }
            $summary[$class]['qualifies'] = min($metrics) >= 1.5;
        }
        $artifact = sys_get_temp_dir().'/w592-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS,
            'repeats' => self::REPEATS, 'seed' => 59, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW59.2 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::ENTRIES) as $class) {
            self::assertSame($summary[$class]['qualifies'], $class::usesW59RustFfi(), $class.' default must follow the measured 1.5x gate');
        }
    }

    /**
     * Every rule is byte-level ASCII, so an explicit setlocale() (which makes PHP build PCRE
     * tables from LC_CTYPE) changes neither implementation and the exports keep running.
     * Declared last: once a script sets a locale, later regexes keep the rebuilt tables.
     */
    public function test_parity_holds_under_locale_tables(): void
    {
        $this->requireBindings();
        $locale = setlocale(LC_CTYPE, '0');
        try {
            foreach (['C.UTF-8', 'en_US.ISO8859-1', 'tr_TR.ISO8859-9'] as $candidate) {
                if (setlocale(LC_CTYPE, $candidate) === false) {
                    continue;
                }
                $cases = [
                    TmuxPaneRole::class => ['FIX_NAMES', "F\xCDX_NAMES", 'IRC_SCRAPER', "post\xA0tv", "\xA0monitor\xA0", 'PostMetadata'],
                    TmuxPostProcessMode::class => ['ALL', "N\xC9N:1", 'AMAZON:NFO', "\xA03", 'NON:ADDITIONAL', 'POST:DISABLED'],
                    TmuxSequentialMode::class => ['STRIPPED@5', "B\xC1SIC", 'FULL@ 7', "\xA02@3"],
                    PredbIdentityKey::class => ["S\xC9ME.MOVIE", "TITLE\xA0NAME", 'I.I.I_2019', "A\x85B", 'D41D8CD98F00B204E9800998ECF8427E'],
                    SeasonPackDetector::class => ["SH\xD6W.S01.1080P", 'SEASON.2.COMPLETE', "SHOW\xA0S01E02", 'I.S01-S02.DISC1', 'SHOW.IX01'],
                ];
                foreach ($cases as $class => $items) {
                    $this->assertParity($class, $items);
                    self::assertSame($this->call($class, 'Php', $items), $this->exported($class, $items), $class);
                }
            }
        } finally {
            setlocale(LC_CTYPE, (string) $locale);
        }
    }

    // ---- helpers -----------------------------------------------------------------------------

    /** `flags | min(len(raw), 0xFFF) << 20 | crc32(text) << 32`, the pane-role layout. */
    private function word(int $flags, string $raw, string $text): int
    {
        return $flags | min(\strlen($raw), 0xFFF) << 20 | crc32($text) << 32;
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @return array<array-key, mixed>
     */
    private function constant(string $class, string $name): array
    {
        $value = (new ReflectionClass($class))->getConstant($name);
        self::assertIsArray($value, $class.'::'.$name);

        return $value;
    }

    /**
     * The fastest of REPEATS back-to-back calls of the class's batch entry point.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  array<array-key, string>  $items
     * @return array{list<int>, int}
     */
    private function timed(string $class, array $items): array
    {
        $best = PHP_INT_MAX;
        $result = [];
        for ($r = 0; $r < self::REPEATS; $r++) {
            $start = hrtime(true);
            $result = $this->call($class, '', $items);
            $best = min($best, hrtime(true) - $start);
        }

        return [$result, $best];
    }

    /** @param  list<int>  $values */
    private function p99(array $values): int
    {
        sort($values);

        return $values[max(0, (int) ceil(\count($values) * 0.99) - 1)];
    }

    /** @param  array<int|float>  $values */
    private function median(array $values): int|float
    {
        sort($values);

        return $values[intdiv(\count($values), 2)] ?? 0;
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function default(string $class): bool
    {
        $value = (new ReflectionClass($class))->getConstant('W59_RUST_FFI_DEFAULT');
        self::assertIsBool($value);

        return $value;
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    private function call(string $class, string $suffix, array $items): array
    {
        /** @var list<int> */
        return [$class, self::ENTRIES[$class].$suffix]($items);
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  array<array-key, string>  $items
     */
    private function assertParity(string $class, array $items): void
    {
        $php = $class::withW59RustFfi(false, fn (): array => $this->call($class, '', $items));
        $rust = $class::withW59RustFfi(true, fn (): array => $this->call($class, '', $items));
        self::assertSame($php, $rust, $class);
        self::assertSame($php, $this->call($class, 'Php', $items), $class);
        self::assertSame($php, $this->call($class, 'Native', $items), $class);
        self::assertCount(\count($items) + self::TAIL[$class], $php, $class);
        self::assertSame($this->digest(\array_slice($php, 0, -1)), $php[\count($php) - 1], $class);
    }

    /**
     * Documented words (without the digest) from both implementations.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<string>  $items
     * @param  list<int>  $words
     */
    private function assertWords(string $class, array $items, array $words): void
    {
        foreach (['Php', 'Native'] as $suffix) {
            self::assertSame($words, \array_slice($this->call($class, $suffix, $items), 0, -1), $class.'::'.$suffix);
        }
    }

    /**
     * PARITY_BATCHES Common and Uncommon batches (at least 300 items each) must match, and the
     * Rust export must actually produce them (no silent PHP fallback).
     *
     * @param  key-of<self::ENTRIES>  $class
     */
    private function assertRandomParity(string $class, int $seed): void
    {
        $items = 0;
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < self::PARITY_BATCHES; $i++) {
                $batch = $this->fixture($class, new Randomizer(new Mt19937($seed + 2 * $i + (int) $uncommon)), $uncommon);
                $this->assertParity($class, $batch);
                self::assertSame($this->call($class, 'Php', $batch), $this->exported($class, $batch), $class);
                $items += \count($batch);
            }
        }
        self::assertGreaterThanOrEqual(300 * 2 * self::PARITY_BATCHES, $items, $class);
    }

    /**
     * Calls the export directly with the entry point's framing.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<string>  $items
     * @return list<int>
     */
    private function exported(string $class, array $items): array
    {
        $ffi = $this->ffi($class);
        $count = \count($items);
        $cap = $count + 16;
        $out = $ffi->new('int64_t['.$cap.']');
        $blob = implode("\0", $items);
        $written = $this->invoke($ffi, self::SYMBOLS[$class], [$blob, \strlen($blob), $count, $out, $cap]);
        self::assertIsInt($written);
        self::assertGreaterThan(0, $written, $class);
        /** @var array<int, int> $values */
        $values = unpack('P*', FFI::string($out, $written * 8));

        return array_values($values);
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function ffi(string $class): FFI
    {
        $ffi = (new ReflectionMethod($class, 'w59RustFfiState'))->invoke(null, 'ffi');
        self::assertInstanceOf(FFI::class, $ffi, $class.' must load the nfo_w59 binding');

        return $ffi;
    }

    /** Each Wave 59.2 export must resolve and accept a one-item batch, so parity runs Rust. */
    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        foreach (self::SYMBOLS as $class => $symbol) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[32]');
            self::assertSame(1 + self::TAIL[$class], $this->invoke($ffi, $symbol, ['a', 1, 1, $out, 32]), $class);
        }
    }

    /**
     * Calls an export declared by a class's W59 C declaration.
     *
     * @param  list<mixed>  $args
     */
    private function invoke(FFI $ffi, string $symbol, array $args): mixed
    {
        return $ffi->{$symbol}(...$args);
    }

    /** @param  list<int>  $words */
    private function digest(array $words): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$words), true));

        return $digest[1];
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @return list<string>
     */
    private function fixture(string $class, Randomizer $random, bool $uncommon): array
    {
        $items = [];
        for ($i = $uncommon ? $random->getInt(320, 640) : 320; $i > 0; $i--) {
            $items[] = match ($class) {
                TmuxPaneRole::class => $this->paneToken($random, $uncommon),
                TmuxPostProcessMode::class => $this->postToken($random, $uncommon),
                TmuxSequentialMode::class => $this->sequentialToken($random, $uncommon),
                PredbIdentityKey::class => $this->identityKey($random, $uncommon),
                SeasonPackDetector::class => $this->seasonName($random, $uncommon),
            };
        }

        return $items;
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    private function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    /** Printable noise, or binary noise without NUL bytes. */
    private function noise(Randomizer $random, int $max, bool $binary = false): string
    {
        $length = $random->getInt(0, $max);
        if ($length === 0) {
            return '';
        }

        return $binary ? str_replace("\0", "\1", $random->getBytes($length))
            : $random->getBytesFromString("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-/:@ \t", $length);
    }

    /** Case-flips, pads or separates a token the way hand-edited settings do. */
    private function mangle(Randomizer $random, string $token): string
    {
        return match ($random->getInt(0, 6)) {
            0 => strtoupper($token),
            1 => ucwords(str_replace('_', ' ', $token)),
            2 => $this->pick($random, [' ', "\t", "\n", "\x0b", "\x0c", '']).$token.$this->pick($random, [' ', "\r\n", "\x0b", '']),
            3 => str_replace('_', $this->pick($random, ['-', '.', ' ', '', '__']), $token),
            4 => $token.$this->noise($random, 3),
            5 => str_replace(['a', 'e', 'o'], ["\xE1", "\xC9", '0'], $token),
            default => $token,
        };
    }

    private function paneToken(Randomizer $random, bool $uncommon): string
    {
        $role = $this->pick($random, TmuxPaneRole::cases());
        $token = match ($random->getInt(0, 9)) {
            0 => $random->getInt(0, 5).'.'.$random->getInt(0, 7),
            1 => $role->name,
            default => $role->value,
        };
        if (! $uncommon) {
            return $token;
        }

        return match ($random->getInt(0, 9)) {
            0 => '',
            1 => $this->noise($random, 40, true),
            2 => $this->noise($random, 16),
            3 => str_repeat($token, $random->getInt(2, 300)),
            default => $this->mangle($random, $token),
        };
    }

    private function postToken(Randomizer $random, bool $uncommon): string
    {
        $mode = $this->pick($random, TmuxPostProcessMode::cases());
        $value = $random->getInt(0, 4) === 0 ? strtolower($mode->name) : (string) $mode->value;
        $token = $random->getInt(0, 2) === 0 ? $this->pick($random, ['post', 'amazon', 'non']).':'.$value : $value;
        if (! $uncommon) {
            return $token;
        }

        return match ($random->getInt(0, 11)) {
            0 => '',
            1 => $this->noise($random, 40, true),
            2 => $this->noise($random, 12),
            3 => $this->pick($random, ['+', '-', '']).str_repeat('0', $random->getInt(0, 30)).$random->getInt(0, 99),
            4 => $this->pick($random, ['x', 'POST', ' Amazon ', 'non ', '', 'nfo']).':'.$this->mangle($random, $value),
            5 => (string) $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            6 => $token.':'.$token,
            default => $this->mangle($random, $token),
        };
    }

    private function sequentialToken(Randomizer $random, bool $uncommon): string
    {
        $mode = $this->pick($random, TmuxSequentialMode::cases());
        $value = $random->getInt(0, 4) === 0 ? strtolower($mode->name) : (string) $mode->value;
        $token = $random->getInt(0, 2) === 0 ? $value : $value.'@'.$random->getInt(0, 600);
        if (! $uncommon) {
            return $token;
        }

        return match ($random->getInt(0, 11)) {
            0 => '',
            1 => $this->noise($random, 40, true),
            2 => $this->noise($random, 12),
            3 => $value.'@'.$this->pick($random, ['', ' ', '-1', '+5', '1000000', '999999', str_repeat('9', $random->getInt(1, 30)), 'x', '1@2']),
            4 => $this->pick($random, ['+', '-', '']).str_repeat('0', $random->getInt(0, 30)).$random->getInt(0, 99).'@'.$random->getInt(0, 999999),
            5 => '@'.$this->noise($random, 8),
            default => $this->mangle($random, $token),
        };
    }

    /** A random scene-style release name. */
    private function release(Randomizer $random): string
    {
        if ($random->getInt(0, 2) === 0) {
            return $this->pick($random, self::RELEASES);
        }
        $words = [];
        for ($w = $random->getInt(1, 4); $w > 0; $w--) {
            $words[] = ucfirst($this->pick($random, self::WORDS));
        }
        $words[] = match ($random->getInt(0, 6)) {
            0 => (string) $random->getInt(1950, 2026),
            1 => \sprintf('S%02d', $random->getInt(1, 12)),
            2 => \sprintf('S%02d-S%02d', $random->getInt(1, 5), $random->getInt(6, 12)),
            3 => \sprintf('Season.%d', $random->getInt(1, 12)),
            4 => \sprintf('%dx%02d', $random->getInt(1, 12), $random->getInt(1, 24)),
            5 => \sprintf('S%02dD%d', $random->getInt(1, 12), $random->getInt(1, 6)),
            default => \sprintf('S%02dE%02d', $random->getInt(1, 12), $random->getInt(1, 24)),
        };
        if ($random->getInt(0, 4) === 0) {
            $words[] = 'Complete';
        }
        $words[] = $this->pick($random, ['720p', '1080p', '2160p', 'DVDRip']);
        $words[] = $this->pick($random, ['BluRay', 'WEB-DL', 'HDTV', 'WEBRip', 'BDRip']);
        $words[] = $this->pick($random, ['x264', 'x265', 'HEVC', 'XviD', 'h264']);

        return implode($this->pick($random, ['.', '.', '.', '_', ' ']), $words).'-'.$this->pick($random, self::GROUPS);
    }

    private function identityKey(Randomizer $random, bool $uncommon): string
    {
        $key = match ($random->getInt(0, 5)) {
            0 => bin2hex($random->getBytes($this->pick($random, [16, 20, 32]))),
            1 => strtolower($this->release($random)),
            default => $this->release($random),
        };
        if (! $uncommon) {
            return $key;
        }

        return match ($random->getInt(0, 11)) {
            0 => '',
            1 => $this->noise($random, 120, true),
            2 => str_repeat($key.' ', $random->getInt(10, 40)),
            3 => $this->pick($random, [" \t", "\x0b", "\x0c", '  ', '..', '__']).strtoupper($key).$this->pick($random, [" \n", "\x0c", '._', '']),
            4 => str_replace(['.', 'e'], ["\xC3\xA9", "\xA0"], $key),
            5 => str_replace(['.', '-'], ['..__', "\x0c \t"], $key),
            6 => strtoupper(bin2hex($random->getBytes(32))),
            7 => str_repeat('.', $random->getInt(1, 20)),
            default => $key,
        };
    }

    private function seasonName(Randomizer $random, bool $uncommon): string
    {
        $name = $this->release($random);
        if (! $uncommon) {
            return $name;
        }

        return match ($random->getInt(0, 11)) {
            0 => '',
            1 => $this->noise($random, 200, true),
            2 => str_repeat($name.'.', $random->getInt(5, 40)),
            3 => strtoupper($name),
            4 => str_replace('.', $this->pick($random, ['[', ']', '(', ')', '--', ' . ']), $name),
            5 => $name.'.S'.str_repeat('0', $random->getInt(0, 6)).$random->getInt(0, 99999).'E'.$random->getInt(0, 99999),
            6 => $name.'.'.$this->pick($random, ['E', 'EP', 'Disc', 'Disk', 'D', 'Season', 'Seasons']).$this->pick($random, ['', '.', ' '])
                .$random->getInt(0, 999),
            7 => $this->noise($random, 60),
            default => $name,
        };
    }
}
