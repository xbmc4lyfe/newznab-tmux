<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Pipes\AbstractCategorizationPipe;
use App\Services\Categorization\Pipes\BookPipe;
use App\Services\Categorization\Pipes\CategorizationPassable;
use App\Services\Categorization\Pipes\MiscSafetyNetPipe;
use App\Services\Categorization\Pipes\MoviePipe;
use App\Services\Categorization\ReleaseContext;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class RustCategorizationPipesAbstractBookPassableMiscMovieW53ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        AbstractCategorizationPipe::class => 'planAbstractPipeBatch',
        BookPipe::class => 'planBookResultBatch',
        CategorizationPassable::class => 'planBestResultBatch',
        MiscSafetyNetPipe::class => 'planSafetyNetBatch',
        MoviePipe::class => 'planMovieResultBatch',
    ];

    /** Class => Wave 53.2 export. */
    private const SYMBOLS = [
        AbstractCategorizationPipe::class => 'nntmux_w532_pipe_guard_plan_batch',
        BookPipe::class => 'nntmux_w532_book_result_plan_batch',
        CategorizationPassable::class => 'nntmux_w532_passable_best_result_plan_batch',
        MiscSafetyNetPipe::class => 'nntmux_w532_misc_safety_net_plan_batch',
        MoviePipe::class => 'nntmux_w532_movie_result_plan_batch',
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns): the per-metric median of
     * three runs of test_paired_randomized_common_and_uncommon_benchmarks (seed 53, each metric
     * the median of 3 rounds of 1000 pairs after 50 warmups, each side the fastest of 3
     * back-to-back calls, randomized fixtures, alternating call order, release Rust, PHP 8.5.12
     * CLI without opcache, Apple Silicon macOS on a shared host at load average 8-9). Timings
     * include dispatch, packing, the FFI call and unpack('P*'). The runs' low was
     * CategorizationPassable Common p99 1.992x in one run (2.651x and 2.701x in the others). A
     * class defaults to Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        AbstractCategorizationPipe::class => [2.685, 2.544, 4.25, 4.374],
        BookPipe::class => [4.303, 5.176, 6.247, 6.226],
        CategorizationPassable::class => [2.771, 2.651, 3.436, 4.397],
        MiscSafetyNetPipe::class => [4.211, 4.492, 5.407, 5.648],
        MoviePipe::class => [4.351, 5.216, 5.849, 5.651],
    ];

    private const ROUNDS = 3;

    private const PAIRS = 1000;

    private const WARMUPS = 50;

    /** Back-to-back calls per side of a pair; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    /** Matched-by labels the categorizers emit, across domains. */
    private const LABELS = [
        'no_match', 'comic_format', 'comic_publisher', 'manga', 'technical_publisher', 'technical_subject', 'magazine_frequency',
        'magazine_title', 'ebook_format', 'ebook_indicator', 'ebook_pdf_format', 'ebook_platform', 'ebook_book_signals', 'foreign_language',
        'foreign_pattern', 'uhd', 'bluray', 'dvd', 'webdl', 'hd', 'hd_webdl_fallback', 'sd', 'cam', 'group_name_alt.binaries.teevee',
        'group_name_alt.binaries.movies', 'group_name_alt.binaries.e-book', 'hash_md5', 'hash_sha1', 'hashed_name', 'obfuscated_uuid',
        'gibberish_random', 'group_only_low_signal', 'tv_episode', 'tv_season_pack', 'music_flac', 'music_fallback', 'console_ps5',
        'pc_game', 'pc_iso_fallback', 'xxx_clip', 'audiobook_marker', 'anime_episode',
    ];

    /** Category ids across domains, misc and hashed included. */
    private const CATEGORIES = [10, 20, 1010, 1999, 2010, 2030, 2040, 2045, 2050, 2060, 2070, 2080, 2090, 2999, 3010, 3040, 4050, 5030, 5040, 5045, 6010, 7010, 7020, 7030, 7040, 7060, 7999];

    private const CONFIDENCES = [0.0, 0.5, 0.6, 0.65, 0.7, 0.75, 0.8, 0.85, 0.9, 0.95, 0.99, 1.0];

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW53RustFfi', 'withW53RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
                self::assertTrue($reflection->getMethod($method)->isPublic() && $reflection->getMethod($method)->isStatic(), $class.'::'.$method);
            }
            // Each class declares its own private state and default, so child pipes stay independent.
            $state = $reflection->getMethod('w53RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame($class, $state->getDeclaringClass()->getName(), $class);
            foreach (['usesW53RustFfi', 'withW53RustFfi', $entry] as $method) {
                self::assertSame($class, $reflection->getMethod($method)->getDeclaringClass()->getName(), $class.'::'.$method);
            }
            self::assertTrue($reflection->hasConstant('W53_RUST_FFI_DEFAULT'), $class);
            self::assertIsBool($reflection->getConstant('W53_RUST_FFI_DEFAULT'), $class);
            self::assertTrue($reflection->getReflectionConstant('W53_RUST_FFI_DEFAULT')?->isPrivate(), $class);
            self::assertSame($class, $reflection->getReflectionConstant('W53_RUST_FFI_DEFAULT')->getDeclaringClass()->getName(), $class);
            self::assertStringContainsString(self::SYMBOLS[$class], (string) $reflection->getConstant('W53_FFI_CDEF'), $class);
            foreach (array_keys($reflection->getStaticProperties()) as $property) {
                self::assertStringNotContainsString('w53', strtolower((string) $property), 'Wave 53 state lives in w53RustFfiState().');
            }
        }
        // The abstract pipe's helper name stays distinct from every child pipe's batch helper.
        foreach ([BookPipe::class, MiscSafetyNetPipe::class, MoviePipe::class] as $child) {
            self::assertNotSame(self::ENTRIES[AbstractCategorizationPipe::class], self::ENTRIES[$child]);
            self::assertSame(AbstractCategorizationPipe::class, (new ReflectionClass($child))->getParentClass()?->getName(), $child);
        }
    }

    public function test_defaults_match_recorded_qualification(): void
    {
        foreach (self::QUALIFICATION as $class => $speedups) {
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->default($class), $class);
            self::assertSame($this->default($class), $class::usesW53RustFfi(), $class);
        }
    }

    public function test_with_toggle_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->default($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW53RustFfi($enabled, static function () use ($class, $enabled): array {
                    $inner = $class::withW53RustFfi(! $enabled, static fn (): bool => $class::usesW53RustFfi());

                    return [$class::usesW53RustFfi(), $inner];
                });
                self::assertSame([$enabled, ! $enabled], $seen, $class);
                self::assertSame($default, $class::usesW53RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW53RustFfi(! $default, static fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown, 'The callback exception must propagate.');
            self::assertSame($default, $class::usesW53RustFfi(), $class);
        }
    }

    public function test_child_pipes_keep_independent_routing(): void
    {
        $parent = $this->default(AbstractCategorizationPipe::class);
        foreach ([BookPipe::class, MiscSafetyNetPipe::class, MoviePipe::class] as $child) {
            $childDefault = $this->default($child);
            $seen = $child::withW53RustFfi(! $childDefault, static fn (): array => [$child::usesW53RustFfi(), AbstractCategorizationPipe::usesW53RustFfi()]);
            self::assertSame([! $childDefault, $parent], $seen, $child);
            $seen = AbstractCategorizationPipe::withW53RustFfi(! $parent, static fn (): array => [$child::usesW53RustFfi(), AbstractCategorizationPipe::usesW53RustFfi()]);
            self::assertSame([$childDefault, ! $parent], $seen, $child);
        }
        // The Wave 53 toggles leave the earlier-wave Rust switches untouched.
        AbstractCategorizationPipe::withW53RustFfi(true, static function (): void {
            BookPipe::withW53RustFfi(true, static function (): void {
                CategorizationPassable::withW53RustFfi(true, static function (): void {
                    self::assertFalse(AbstractCategorizationPipe::usesW463RustFfi());
                    self::assertFalse(MiscSafetyNetPipe::usesW463RustFfi());
                    self::assertFalse(BookPipe::usesW403RustFfi());
                    self::assertFalse(MoviePipe::usesW403RustFfi());
                    self::assertFalse(CategorizationPassable::usesW453RustFfi());
                    self::assertFalse(BookPipe::usesRustFfi());
                    self::assertFalse(MoviePipe::usesRustFfi());
                });
            });
        });
    }

    public function test_entry_points_route_through_toggle(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(5300));
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([false, true] as $uncommon) {
                $args = $this->fixture($class, $random, $uncommon);
                $php = $this->call($class, 'Php', $args);
                foreach ([true, false] as $native) {
                    self::assertSame($php, $class::withW53RustFfi($native, fn (): array => $this->call($class, '', $args)), $class);
                }
                self::assertSame($php, $this->call($class, 'Native', $args), $class);
            }
        }
    }

    public function test_native_helpers_fall_back_to_php_for_unsupported_input(): void
    {
        $this->requireBindings();
        // NUL bytes, ragged columns and keyed arrays keep the PHP semantics.
        $this->assertParity(AbstractCategorizationPipe::class, [[10, 2040], [0.5, 0.9], ["a\0b", 'hd'], [0, 0]]);
        $this->assertParity(AbstractCategorizationPipe::class, [[10], [0.5, 0.9], ['a', 'hd'], [0, 0]]);
        $this->assertParity(AbstractCategorizationPipe::class, [[1 => 10, 2 => 20], [1 => 0.5, 2 => 0.9], [1 => 'a', 2 => 'hash_x'], [1 => 0, 2 => 1]]);
        $this->assertParity(BookPipe::class, [[7020, 7030], [0.9, 0.9], ["ebook_\0", 'comic_format']]);
        $this->assertParity(BookPipe::class, [[7020, 7030], [0.9], ['ebook_x', 'comic_format']]);
        $this->assertParity(MoviePipe::class, [[2040, 2045], [0.9, 0.96], ["hd\0", 'uhd']]);
        $this->assertParity(MoviePipe::class, [['a' => 2040], ['a' => 0.9], ['a' => 'hd']]);
        $this->assertParity(CategorizationPassable::class, [[2040, 2045], [0.9, 0.96], ["hd\0", 'uhd'], [1, 1], [0, 0]]);
        $this->assertParity(CategorizationPassable::class, [[2040, 2045], [0.9, 0.96], ['hd', 'uhd'], [1], [0, 0]]);
        $this->assertParity(MiscSafetyNetPipe::class, [[5040, 5040], ["group_name_\0", 'group_name_x'], [2, 2]]);
        $this->assertParity(MiscSafetyNetPipe::class, [[5040, 5040], ['group_name_y', 'group_name_x'], [2]]);
    }

    public function test_exports_guard_null_short_and_malformed_batches(): void
    {
        $this->requireBindings();
        $guard = $this->ffi(AbstractCategorizationPipe::class);
        $out = $guard->new('int64_t[32]');
        $symbol = self::SYMBOLS[AbstractCategorizationPipe::class];
        $ints = pack('P*', 10, 0);
        $conf = pack('e', 0.5);
        self::assertSame(11, $this->invoke($guard, $symbol, ['a', 1, $ints, 16, $conf, 8, 1, $out, 32]));
        self::assertSame(-1, $this->invoke($guard, $symbol, ['a', 1, $ints, 16, $conf, 8, 1, null, 32]));
        self::assertSame(-11, $this->invoke($guard, $symbol, ['a', 1, $ints, 16, $conf, 8, 1, $out, 4]));
        self::assertSame(-1, $this->invoke($guard, $symbol, ["a\0b", 3, $ints, 16, $conf, 8, 1, $out, 32]));
        self::assertSame(-1, $this->invoke($guard, $symbol, ['a', 1, $ints, 8, $conf, 8, 1, $out, 32]));
        self::assertSame(-1, $this->invoke($guard, $symbol, ['a', 1, null, 16, $conf, 8, 1, $out, 32]));
        self::assertSame(10, $this->invoke($guard, $symbol, [null, 0, null, 0, null, 0, 0, $out, 32]));

        foreach ([BookPipe::class => 14, MoviePipe::class => 18] as $class => $empty) {
            $ffi = $this->ffi($class);
            $classOut = $ffi->new('int64_t[32]');
            $classSymbol = self::SYMBOLS[$class];
            self::assertSame($empty + 1, $this->invoke($ffi, $classSymbol, ['a', 1, pack('P', 7020), 8, $conf, 8, 1, $classOut, 32]), $class);
            self::assertSame(-1, $this->invoke($ffi, $classSymbol, ['a', 1, pack('P', 7020), 8, $conf, 4, 1, $classOut, 32]), $class);
            self::assertSame(-1, $this->invoke($ffi, $classSymbol, ['a', 1, pack('P', 7020), 8, null, 8, 1, $classOut, 32]), $class);
            self::assertSame(-($empty + 1), $this->invoke($ffi, $classSymbol, ['a', 1, pack('P', 7020), 8, $conf, 8, 1, $classOut, 2]), $class);
            self::assertSame($empty, $this->invoke($ffi, $classSymbol, [null, 0, null, 0, null, 0, 0, $classOut, 32]), $class);
        }

        $passable = $this->ffi(CategorizationPassable::class);
        $passableOut = $passable->new('int64_t[32]');
        $passableSymbol = self::SYMBOLS[CategorizationPassable::class];
        $table = pack('P*', 10, 1, 0);
        self::assertSame(7, $this->invoke($passable, $passableSymbol, ['a', 1, $table, 24, $conf, 8, 1, $passableOut, 32]));
        self::assertSame(-1, $this->invoke($passable, $passableSymbol, ['a', 1, $table, 16, $conf, 8, 1, $passableOut, 32]));
        self::assertSame(-1, $this->invoke($passable, $passableSymbol, ['a', 1, $table, 24, $conf, 8, 2, $passableOut, 32]));

        $misc = $this->ffi(MiscSafetyNetPipe::class);
        $miscOut = $misc->new('int64_t[32]');
        $miscSymbol = self::SYMBOLS[MiscSafetyNetPipe::class];
        self::assertSame(12, $this->invoke($misc, $miscSymbol, ['group_name_x', 12, $ints, 16, 1, $miscOut, 32]));
        self::assertSame(-1, $this->invoke($misc, $miscSymbol, ["group_name_x\0", 13, $ints, 16, 1, $miscOut, 32]));
        self::assertSame(-1, $this->invoke($misc, $miscSymbol, ['group_name_x', 12, $ints, 16, 1, null, 32]));
        self::assertSame(11, $this->invoke($misc, $miscSymbol, [null, 0, null, 0, 0, $miscOut, 32]));
    }

    public function test_abstract_pipe_plan_parity(): void
    {
        $this->requireBindings();
        $args = [[10, 2040, 10, 20, 7020, 10], [0.0, 0.97, 0.9, 0.55, NAN, 0.3], ['no_match', 'hd', 'hash_md5', '', 'ebook_format', 'group_only_low_signal'], [0, 0, 1, 2, 0, 0]];
        $words = [
            4 | crc32('no_match') << 32,
            1 | 2 | 4 << 8 | crc32('hd') << 32,
            1 | 2 | 8 | 3 << 8 | crc32('hash_md5') << 32,
            1 | 2 | 8 | 16 | 2 << 8,
            2 | 5 << 8 | crc32('ebook_format') << 32,
            2 | 8 | 1 << 8 | crc32('group_only_low_signal') << 32,
            3, 5, 3, 1, 1, 1, 1, 1, 1,
        ];
        self::assertSame([...$words, $this->digest($words)], AbstractCategorizationPipe::planAbstractPipeBatchPhp(...$args));
        $this->assertParity(AbstractCategorizationPipe::class, $args);
        $this->assertRandomParity(AbstractCategorizationPipe::class, 5310);
    }

    public function test_book_result_plan_parity(): void
    {
        $this->requireBindings();
        $args = [[7030, 7020, 7020, 10, 7500, 7999], [0.9, 0.85, 0.96, 0.0, 0.97, -1.0], ['comic_format', 'manga', 'ebook_pdf_format', 'no_match', 'technical_subject', 'x']];
        $words = [
            1 | 2 | 8 | 3 << 8 | 1 << 16 | crc32('comic_format') << 32,
            1 | 2 | 2 << 8 | 2 << 16 | crc32('manga') << 32,
            1 | 2 | 4 | 8 | 2 << 8 | 5 << 16 | crc32('ebook_pdf_format') << 32,
            crc32('no_match') << 32,
            1 | 2 | 4 | 7 << 8 | 3 << 16 | crc32('technical_subject') << 32,
            2 | 6 << 8 | crc32('x') << 32,
            4, 5, 2, 2, 2,
            1, 0, 2, 1, 0, 0, 1, 1,
        ];
        self::assertSame([...$words, $this->digest($words)], BookPipe::planBookResultBatchPhp(...$args));
        $this->assertParity(BookPipe::class, $args);
        foreach (array_keys($args[0]) as $row) {
            $mask = BookPipe::evaluateResultPhp($args[0][$row], $args[1][$row], $args[2][$row]);
            self::assertSame($mask, BookPipe::planBookResultBatchPhp(...$args)[$row] & 7, 'evaluateResultPhp() mask');
        }
        $this->assertRandomParity(BookPipe::class, 5320);
    }

    public function test_movie_result_plan_parity(): void
    {
        $this->requireBindings();
        $args = [[2040, 2045, 2000, PHP_INT_MAX, PHP_INT_MIN], [0.8, 0.95, 0.5, 1.0, 0.1], ['hd_webdl_fallback', 'uhd', 'sd', 'cam', '']];
        $words = [
            1 | 2 | 8 | 4 << 8 | 6 << 16 | crc32('hd_webdl_fallback') << 32,
            1 | 2 | 4 | 8 | 5 << 8 | 2 << 16 | crc32('uhd') << 32,
            1 | 2 | 11 << 8 | 7 << 16 | crc32('sd') << 32,
            1 | 4 | 8 << 16 | crc32('cam') << 32,
            1,
            5, 3, 2, 2, 1,
            2, 0, 0, 0, 1, 1, 0, 0, 0, 0, 0, 1,
        ];
        self::assertSame([...$words, $this->digest($words)], MoviePipe::planMovieResultBatchPhp(...$args));
        $this->assertParity(MoviePipe::class, $args);
        foreach (array_keys($args[0]) as $row) {
            $mask = MoviePipe::evaluateResultPhp($args[0][$row], $args[1][$row], $args[2][$row]);
            self::assertSame($mask, MoviePipe::planMovieResultBatchPhp(...$args)[$row] & 7, 'evaluateResultPhp() mask');
        }
        $this->assertRandomParity(MoviePipe::class, 5330);
    }

    public function test_passable_best_result_plan_parity(): void
    {
        $this->requireBindings();
        $labels = ['hash_md5', 'hd', 'hd', 'uhd', 'no_match', 'x', 'y', 'z'];
        $args = [[10, 2040, 2040, 2045, 10, 2030, 10, 2030], [0.7, 0.5, 0.7, 0.96, 0.99, 0.5, 0.5, 0.5], $labels, [1, 1, 1, 1, 1, 2, 2, 3], [0, 0, 0, 0, 0, 0, 0, 1]];
        $none = CategorizationPassable::W53_NO_BEST;
        $words = [
            1 | 2 | 16 | 64 | crc32('hash_md5') << 32,
            1 | 8 | crc32('hd') << 32,
            1 | 2 | 2 << 8 | crc32('hd') << 32,
            1 | 2 | 4 | 3 << 8 | crc32('uhd') << 32,
            32 | 4 | 3 << 8 | crc32('no_match') << 32,
            1 | 2 | 64 | 5 << 8 | crc32('x') << 32,
            1 | 5 << 8 | crc32('y') << 32,
            64 | 32 | 4 | $none << 8 | crc32('z') << 32,
            3, 4, 1, 2, 2,
        ];
        self::assertSame([...$words, $this->digest($words)], CategorizationPassable::planBestResultBatchPhp(...$args));
        $this->assertParity(CategorizationPassable::class, $args);
        $this->assertRandomParity(CategorizationPassable::class, 5340);
    }

    public function test_passable_plan_matches_update_best_result(): void
    {
        $random = new Randomizer(new Mt19937(5345));
        for ($batch = 0; $batch < 12; $batch++) {
            [$categories, $confidences, $labels, $groups, $states] = $this->passableBatch($random, $batch % 2 === 1);
            $words = CategorizationPassable::planBestResultBatchPhp($categories, $confidences, $labels, $groups, $states);
            $passable = null;
            $previous = null;
            foreach ($labels as $row => $label) {
                if ($passable === null || $groups[$row] !== $previous) {
                    $passable = new CategorizationPassable(new ReleaseContext('release', 0));
                    $previous = $groups[$row];
                }
                if (($states[$row] & 1) !== 0) {
                    $passable->lockToMisc();
                }
                if (! $passable->shouldStopProcessingPhp()) {
                    $passable->updateBestResultPhp(new CategorizationResult($categories[$row], (float) $confidences[$row], $label), 'pipe');
                }
                $best = ($words[$row] >> 8) & 0xFFFFFF;
                $expected = $best === CategorizationPassable::W53_NO_BEST ? ['no_match', 0.0] : [$labels[$best], (float) $confidences[$best]];
                self::assertSame($expected[0], $passable->bestResult->matchedBy, "batch {$batch} row {$row}");
                self::assertSame($passable->shouldStopProcessingPhp(), ($words[$row] & 4) !== 0, "batch {$batch} row {$row}");
            }
        }
    }

    public function test_misc_safety_net_plan_parity(): void
    {
        $this->requireBindings();
        $args = [[5010, 5010, 5010, 20, 10, 10, 3000], ['group_name_tv', 'group_name_tv', 'group_name_tv', 'hash_md5', 'no_match', 'music_fallback', ''], [2, 3, 0, 2, 2, 2, 1]];
        $words = [
            1 | 4 | 8 | 16 | 1 << 8 | crc32('group_only_low_signal') << 32,
            2 | 4 | 16 | 1 << 8 | crc32('group_name_tv') << 32,
            1 << 8 | crc32('group_name_tv') << 32,
            4 | 8 | 2 << 8 | crc32('hash_md5') << 32,
            4 | 5 << 8 | crc32('no_match') << 32,
            4 | 4 << 8 | crc32('music_fallback') << 32,
            2 | 16 | 32,
            1, 3, 2, 1,
            1, 3, 1, 0, 1, 1,
        ];
        self::assertSame([...$words, $this->digest($words)], MiscSafetyNetPipe::planSafetyNetBatchPhp(...$args));
        $this->assertParity(MiscSafetyNetPipe::class, $args);
        $this->assertRandomParity(MiscSafetyNetPipe::class, 5350);
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     */
    #[DataProvider('edgeCases')]
    public function test_edge_case_parity(string $class, array $args): void
    {
        $this->requireBindings();
        $this->assertParity($class, $args);
    }

    /** @return iterable<string, array{key-of<self::ENTRIES>, list<mixed>}> */
    public static function edgeCases(): iterable
    {
        $long = str_repeat('k', 70000);
        $floats = [0.0, -0.0, NAN, INF, -INF, 0.95, 0.9499999999999999, 0.8, 0.5, 0.6, 0.5999999999999999, 1e-300, -1e-300, PHP_FLOAT_MAX, -1.0];
        $n = \count($floats);
        $labels = ['', 'no_match', 'no_match ', 'hash', 'hash_', 'obfuscated_', 'gibberish_', 'group_only_low_signal', 'group_name_', 'fallback', 'xfallbackx', "\xff\xfe", $long, 'comic_', 'hd'];
        $cats = [10, 20, 0, -1, PHP_INT_MIN, PHP_INT_MAX, 1999, 2000, 2999, 3000, 6999, 7000, 7999, 8000, 2040];
        $states = [0, 1, 2, 3, -1, PHP_INT_MIN, PHP_INT_MAX, 4, 5, 6, 7, 8, 9, 10, 11];
        yield 'guard empty' => [AbstractCategorizationPipe::class, [[], [], [], []]];
        yield 'guard bounds' => [AbstractCategorizationPipe::class, [$cats, $floats, $labels, $states]];
        yield 'guard int confidences' => [AbstractCategorizationPipe::class, [[10, 10, 20], [0, 1, -5], ['no_match', 'hash_x', 'y'], [0, 0, 0]]];
        yield 'book empty' => [BookPipe::class, [[], [], []]];
        yield 'book bounds' => [BookPipe::class, [$cats, $floats, $labels]];
        yield 'book families' => [BookPipe::class, [[7030, 7030, 7040, 7010, 7020, 7020, 7999], [0.9, 0.9, 0.9, 0.9, 0.9, 0.9, 0.9], ['comic_', 'mangaka', 'technical_', 'magazine_', 'ebook_', 'ebook', 'Comic_x']]];
        yield 'movie empty' => [MoviePipe::class, [[], [], []]];
        yield 'movie bounds' => [MoviePipe::class, [$cats, $floats, $labels]];
        yield 'movie families' => [MoviePipe::class, [[2010, 2045, 2060, 2070, 2080, 2040, 2030, 2999, 2050, 2090], array_fill(0, 10, 0.85), ['foreign_', 'uhdtv', 'bluray', 'dvdrip', 'webdl', 'hdr', 'sdtv', 'camrip', '3d', 'x265']]];
        yield 'passable empty' => [CategorizationPassable::class, [[], [], [], [], []]];
        yield 'passable bounds' => [CategorizationPassable::class, [$cats, $floats, $labels, array_fill(0, $n, 1), $states]];
        yield 'passable group edges' => [CategorizationPassable::class, [[2040, 2040, 2040, 2040, 2040], [0.5, 0.6, 0.7, 0.8, 0.9], ['a', 'b', 'c', 'd', 'e'], [PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MAX, 0, 0], [0, 0, 0, 0, 0]]];
        yield 'passable ties and nan' => [CategorizationPassable::class, [[10, 2040, 10, 20, 2040, 10], [0.5, 0.5, NAN, 0.5, 0.5, 0.95], ['a', 'b', 'c', 'gibberish_x', 'd', 'e'], [1, 1, 1, 2, 2, 2], [0, 0, 0, 0, 0, 0]]];
        yield 'passable protected guard' => [CategorizationPassable::class, [[20, 2040, 2040, 10, 10, 5040, 5040], [0.7, 0.59, 0.6, 0.8, 0.9, 0.5999999999999999, 0.95], ['x', 'a', 'b', 'group_only_low_signal', 'obfuscated_y', 'c', 'd'], [1, 1, 1, 2, 2, 2, 2], [0, 0, 0, 0, 0, 0, 0]]];
        yield 'misc empty' => [MiscSafetyNetPipe::class, [[], [], []]];
        yield 'misc bounds' => [MiscSafetyNetPipe::class, [$cats, $labels, $states]];
        yield 'misc repeats' => [MiscSafetyNetPipe::class, [[1, 2, 3, 4, 5], ['group_name_a', 'group_name_a', 'group_name_b', 'group_name_123', 'group_name_0123'], [2, 2, 2, 2, 2]]];
    }

    /**
     * Paired randomized benchmark: per class and workload, each pair times the PHP and Rust
     * batch entry point on one randomly chosen fixture, alternating which runs first; each side
     * keeps the fastest of REPEATS back-to-back calls. Common batches hold 64 typical records,
     * Uncommon batches 128-256 long, malformed or adversarial ones. Each of ROUNDS rounds times
     * PAIRS pairs after WARMUPS warmups; a metric is the median of its round values (nearest-rank
     * p99 per round). Every pair must agree; raw pairs are written outside the checkout. A class
     * must default to Rust exactly when all four metrics reach 1.5x.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(53));
        $summary = $records = [];
        foreach (array_keys(self::ENTRIES) as $class) {
            $metrics = [];
            foreach (['Common', 'Uncommon'] as $workload) {
                $fixtures = [];
                for ($v = 0; $v < 8; $v++) {
                    $fixtures[] = $this->fixture($class, $random, $workload === 'Uncommon');
                }
                $rounds = [];
                for ($round = 0; $round < self::ROUNDS; $round++) {
                    $php = $rust = [];
                    for ($i = -self::WARMUPS; $i < self::PAIRS; $i++) {
                        $variant = $random->getInt(0, 7);
                        $order = ($i & 1) === 0 ? [false, true] : [true, false];
                        $results = $elapsed = [];
                        foreach ($order as $native) {
                            $args = $fixtures[$variant];
                            [$results[(int) $native], $elapsed[(int) $native]] = $class::withW53RustFfi($native, fn (): array => $this->timed($class, $args));
                        }
                        self::assertSame($results[0], $results[1], $class.' '.$workload);
                        if ($i >= 0) {
                            $php[] = $elapsed[0];
                            $rust[] = $elapsed[1];
                            $records[] = [$class, $workload, $round, $variant, $order[0], $elapsed[0], $elapsed[1]];
                        }
                    }
                    sort($php);
                    sort($rust);
                    $p99 = (int) ceil(\count($php) * 0.99) - 1;
                    $rounds[] = [
                        'mean_speedup' => round(array_sum($php) / array_sum($rust), 3), 'p99_speedup' => round($php[$p99] / $rust[$p99], 3),
                        'php_mean_ns' => (int) (array_sum($php) / \count($php)), 'rust_mean_ns' => (int) (array_sum($rust) / \count($rust)),
                        'php_p99_ns' => $php[$p99], 'rust_p99_ns' => $rust[$p99],
                    ];
                }
                $summary[$class][$workload] = [
                    'mean_speedup' => $this->median(array_column($rounds, 'mean_speedup')), 'p99_speedup' => $this->median(array_column($rounds, 'p99_speedup')),
                    'php_mean_ns' => $this->median(array_column($rounds, 'php_mean_ns')), 'rust_mean_ns' => $this->median(array_column($rounds, 'rust_mean_ns')),
                    'php_p99_ns' => $this->median(array_column($rounds, 'php_p99_ns')), 'rust_p99_ns' => $this->median(array_column($rounds, 'rust_p99_ns')),
                    'rounds' => $rounds,
                ];
                $metrics[] = $summary[$class][$workload]['mean_speedup'];
                $metrics[] = $summary[$class][$workload]['p99_speedup'];
            }
            $summary[$class]['qualifies'] = min($metrics) >= 1.5;
        }
        $artifact = sys_get_temp_dir().'/w532-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS, 'repeats' => self::REPEATS, 'seed' => 53, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW53.2 paired benchmark artifact: {$artifact}\n".json_encode(array_map(static fn (array $class): array => array_map(static fn (mixed $w): mixed => \is_array($w) ? array_diff_key($w, ['rounds' => true]) : $w, $class), $summary), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::ENTRIES) as $class) {
            self::assertSame($summary[$class]['qualifies'], $class::usesW53RustFfi(), $class.' default must follow the measured 1.5x gate');
        }
    }

    // ---- helpers -----------------------------------------------------------------------------

    /**
     * The fastest of REPEATS back-to-back calls of the class's batch entry point.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     * @return array{list<int>, int}
     */
    private function timed(string $class, array $args): array
    {
        $best = PHP_INT_MAX;
        $result = [];
        for ($r = 0; $r < self::REPEATS; $r++) {
            $start = hrtime(true);
            $result = $this->call($class, '', $args);
            $best = min($best, hrtime(true) - $start);
        }

        return [$result, $best];
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
        $value = (new ReflectionClass($class))->getConstant('W53_RUST_FFI_DEFAULT');
        self::assertIsBool($value);

        return $value;
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     * @return list<int>
     */
    private function call(string $class, string $suffix, array $args): array
    {
        /** @var list<int> */
        return [$class, self::ENTRIES[$class].$suffix](...$args);
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, array $args): void
    {
        $php = $class::withW53RustFfi(false, fn (): array => $this->call($class, '', $args));
        $rust = $class::withW53RustFfi(true, fn (): array => $this->call($class, '', $args));
        self::assertSame($php, $rust, $class);
        self::assertSame($php, $this->call($class, 'Php', $args), $class);
        self::assertSame($php, $this->call($class, 'Native', $args), $class);
        self::assertSame($this->digest(\array_slice($php, 0, -1)), $php[\count($php) - 1], $class);
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function assertRandomParity(string $class, int $seed): void
    {
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $this->assertParity($class, $this->fixture($class, new Randomizer(new Mt19937($seed + $i)), $uncommon));
            }
        }
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function ffi(string $class): FFI
    {
        $ffi = (new ReflectionMethod($class, 'w53RustFfiState'))->invoke(null, 'ffi');
        self::assertInstanceOf(FFI::class, $ffi, $class.' must load the nfo_w53 binding');

        return $ffi;
    }

    /** Each Wave 53.2 export must resolve and accept a one-item batch, so parity runs Rust. */
    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        $conf = pack('e', 0.5);
        $calls = [
            AbstractCategorizationPipe::class => [['a', 1, pack('P*', 10, 0), 16, $conf, 8, 1], 11],
            BookPipe::class => [['a', 1, pack('P', 7020), 8, $conf, 8, 1], 15],
            CategorizationPassable::class => [['a', 1, pack('P*', 10, 1, 0), 24, $conf, 8, 1], 7],
            MiscSafetyNetPipe::class => [['a', 1, pack('P*', 10, 0), 16, 1], 12],
            MoviePipe::class => [['a', 1, pack('P', 2040), 8, $conf, 8, 1], 19],
        ];
        foreach ($calls as $class => [$args, $words]) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[32]');
            self::assertSame($words, $this->invoke($ffi, self::SYMBOLS[$class], [...$args, $out, 32]), $class);
        }
    }

    /**
     * Calls an export declared by a class's W53_FFI_CDEF.
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
     * @return list<mixed>
     */
    private function fixture(string $class, Randomizer $random, bool $uncommon): array
    {
        return match ($class) {
            AbstractCategorizationPipe::class => $this->guardBatch($random, $uncommon),
            BookPipe::class, MoviePipe::class => $this->resultBatch($random, $uncommon, $class === BookPipe::class ? 7000 : 2000),
            CategorizationPassable::class => $this->passableBatch($random, $uncommon),
            MiscSafetyNetPipe::class => $this->miscBatch($random, $uncommon),
        };
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

    private function noise(Randomizer $random, int $max, bool $binary = false): string
    {
        $length = $random->getInt(0, $max);
        if ($length === 0) {
            return '';
        }

        return $binary ? str_replace("\0", "\1", $random->getBytes($length))
            : $random->getBytesFromString('abcdefghijklmnopqrstuvwxyz0123456789._-', $length);
    }

    private function label(Randomizer $random, bool $uncommon): string
    {
        $label = $this->pick($random, self::LABELS);
        if (! $uncommon) {
            return $label;
        }

        return match ($random->getInt(0, 11)) {
            0 => '',
            1 => $this->noise($random, 80),
            2 => $this->noise($random, 60, true),
            3 => $label.str_repeat('_x', $random->getInt(20, 200)),
            4 => strtoupper($label),
            5 => $this->pick($random, ['hash', 'hash_', 'group_name_', 'gibberish_', 'obfuscated_', 'fallback', 'no_match ', 'comic_', 'hd', 'uhd']),
            6 => $this->noise($random, 20).'fallback'.$this->noise($random, 20),
            default => $label,
        };
    }

    private function category(Randomizer $random, bool $uncommon, int $root = 0): int
    {
        if ($uncommon && $random->getInt(0, 4) === 0) {
            return $this->pick($random, [PHP_INT_MIN, PHP_INT_MAX, 0, -1, $root - 1, $root, $root + 999, $root + 1000, $random->getInt(-1_000_000, 1_000_000)]);
        }
        if ($root > 0 && $random->getInt(0, 1) === 0) {
            return $this->pick($random, $root === 7000 ? [7010, 7020, 7030, 7040, 7060, 7999, 7000] : [2010, 2030, 2040, 2045, 2050, 2060, 2070, 2080, 2090, 2999, 2000]);
        }

        return $this->pick($random, self::CATEGORIES);
    }

    private function confidence(Randomizer $random, bool $uncommon): float|int
    {
        if ($uncommon && $random->getInt(0, 3) === 0) {
            return $this->pick($random, [NAN, INF, -INF, -0.0, -1.0, 1e-300, 0.9499999999999999, 0.5999999999999999, $random->nextFloat(), 1, 0]);
        }

        return $this->pick($random, self::CONFIDENCES);
    }

    private function state(Randomizer $random, bool $uncommon): int
    {
        if ($uncommon) {
            return $random->getInt(0, 5) === 0 ? $this->pick($random, [PHP_INT_MIN, PHP_INT_MAX, -1, -2]) : $random->getInt(0, 7);
        }

        return $random->getInt(0, 9) === 0 ? $random->getInt(1, 3) : 0;
    }

    private function batchSize(Randomizer $random, bool $uncommon): int
    {
        return $uncommon ? $random->getInt(128, 256) : 64;
    }

    /** @return list<mixed> */
    private function guardBatch(Randomizer $random, bool $uncommon): array
    {
        $categories = $confidences = $labels = $states = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $categories[] = $this->category($random, $uncommon);
            $confidences[] = $this->confidence($random, $uncommon);
            $labels[] = $this->label($random, $uncommon);
            $states[] = $this->state($random, $uncommon);
        }

        return [$categories, $confidences, $labels, $states];
    }

    /** @return list<mixed> */
    private function resultBatch(Randomizer $random, bool $uncommon, int $root): array
    {
        $categories = $confidences = $labels = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $categories[] = $this->category($random, $uncommon, $root);
            $confidences[] = $this->confidence($random, $uncommon);
            $labels[] = $this->label($random, $uncommon);
        }

        return [$categories, $confidences, $labels];
    }

    /** @return array{list<int>, list<float|int>, list<string>, list<int>, list<int>} */
    private function passableBatch(Randomizer $random, bool $uncommon): array
    {
        $categories = $confidences = $labels = $groups = $states = [];
        $group = $random->getInt(1, 1_000_000);
        $left = 0;
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            if ($left === 0) {
                $group = $uncommon && $random->getInt(0, 3) === 0 ? $this->pick($random, [PHP_INT_MIN, PHP_INT_MAX, 0, $group]) : ($group === PHP_INT_MAX ? PHP_INT_MIN : $group + 1);
                $left = $uncommon ? $random->getInt(1, 40) : $random->getInt(3, 8);
            }
            $left--;
            $categories[] = $this->category($random, $uncommon);
            $confidences[] = $this->confidence($random, $uncommon);
            $labels[] = $this->label($random, $uncommon);
            $groups[] = $group;
            $states[] = $random->getInt(0, $uncommon ? 6 : 30) === 0 ? 1 : 0;
        }

        return [$categories, $confidences, $labels, $groups, $states];
    }

    /** @return list<mixed> */
    private function miscBatch(Randomizer $random, bool $uncommon): array
    {
        $categories = $labels = $states = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $categories[] = $this->category($random, $uncommon);
            $labels[] = $random->getInt(0, 2) === 0 ? 'group_name_alt.binaries.'.$this->pick($random, ['teevee', 'movies', 'e-book', 'mp3', 'hdtv.x264']) : $this->label($random, $uncommon);
            $states[] = $uncommon ? $this->state($random, true) : $random->getInt(0, 3);
        }

        return [$categories, $labels, $states];
    }
}
