<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Binaries\PerArticleObfuscationGate;
use App\Services\BlacklistService;
use App\Services\Categorization\CategorizationPipeline;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\CategorizationService;
use Closure;
use FFI;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;

final class RustObfuscationBlacklistCatPipelineResultServiceW53ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        PerArticleObfuscationGate::class => 'summarizePartCounts',
        BlacklistService::class => 'firstLiteralRuleMatches',
        CategorizationPipeline::class => 'releaseNameSignals',
        CategorizationResult::class => 'foldResults',
        CategorizationService::class => 'compareCategoryBatch',
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns), the per-metric minimum
     * of three runs of test_paired_randomized_common_and_uncommon_benchmarks: seed 53, 200 pairs
     * per workload after 20 warmups, randomized call order, each side the fastest of three
     * back-to-back calls, release Rust, PHP 8.5.12 CLI without opcache, Apple Silicon macOS.
     * Timings include dispatch, packing, the FFI call and unpack('P*'). A class defaults to Rust
     * only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        PerArticleObfuscationGate::class => [4.676, 3.897, 3.913, 2.507],
        BlacklistService::class => [3.026, 2.742, 3.563, 3.458],
        CategorizationPipeline::class => [4.483, 4.236, 3.226, 2.723],
        CategorizationResult::class => [1.969, 2.245, 2.288, 2.106],
        CategorizationService::class => [2.751, 3.079, 2.135, 2.325],
    ];

    private const PAIRS = 200;

    private const WARMUPS = 20;

    /** Back-to-back calls per side of a pair; the fastest is the sample (drops preemption spikes). */
    private const REPEATS = 3;

    private const SEED = 53;

    private const WORDS = ['alpha', 'Bravo', 'charlie', 'DELTA', 'echo', 'Foxtrot', 'golf', 'hotel', 'India', 'juliet', 'kilo', 'Lima', 'mike', 'November', 'oscar', 'Papa'];

    private const GROUPS = ['GRP', 'NTb', 'FLUX', 'SPARKS', 'rarbg', 'x264', 'EVO', 'CMRG', 'TEPES', 'NOGRP'];

    private const MATCHED_BY = ['tv_title', 'movie_title', 'music_album', 'pc_game', 'console_title', 'book_title', 'xxx_title', 'group_name', 'no_match', 'hash_md5', 'obfuscated_subject', 'gibberish_name', 'group_only_low_signal', 'archive', 'hashed'];

    private const CATEGORIES = [Category::OTHER_MISC, Category::OTHER_HASHED, 1010, 1030, 2040, 2045, 2050, 3010, 3040, 4010, 4050, 5030, 5040, 5045, 6010, 6040, 7010, 7020, 8010];

    public function test_wave_members_exist_and_defaults_match_qualification(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW53RustFfi', 'withW53RustFfi', 'w53RustFfiState', 'w53Buffer', 'w53Decode', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
            }
            foreach (['usesW53RustFfi', 'withW53RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->getMethod($method)->isStatic() && $reflection->getMethod($method)->isPublic(), $class.'::'.$method);
            }
            self::assertTrue($reflection->getMethod('w53RustFfiState')->isPrivate(), $class);
            $constant = $reflection->getReflectionConstant('W53_RUST_FFI_DEFAULT');
            self::assertNotFalse($constant, $class);
            self::assertTrue($constant->isPrivate(), $class);
            self::assertIsBool($constant->getValue(), $class);
            self::assertSame($constant->getValue(), $this->uses($class), $class);
            self::assertStringContainsString('nntmux_w531_', (string) $reflection->getConstant('W53_FFI_CDEF'));
            foreach ($reflection->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w53', $property->getName(), $class.' keeps Wave 53 state in function-local statics');
            }

            $speedups = self::QUALIFICATION[$class];
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->uses($class), $class.' default must follow its recorded qualification');
        }
    }

    public function test_with_w53_rust_ffi_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->uses($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW53RustFfi($enabled, fn (): array => [
                    $this->uses($class),
                    $class::withW53RustFfi(! $enabled, fn (): bool => $this->uses($class)),
                    $this->uses($class),
                ]);
                self::assertSame([$enabled, ! $enabled, $enabled], $seen, $class);
                self::assertSame($default, $this->uses($class), $class);
            }
            try {
                $class::withW53RustFfi(! $default, static function (): never {
                    throw new InvalidArgumentException('boom');
                });
            } catch (InvalidArgumentException) {
            }
            self::assertSame($default, $this->uses($class), $class.' restores routing after an exception');
        }
    }

    public function test_earlier_wave_toggles_are_untouched(): void
    {
        self::assertFalse(PerArticleObfuscationGate::usesRustFfi());
        self::assertFalse(PerArticleObfuscationGate::usesW451RustFfi());
        self::assertFalse(BlacklistService::usesW401RustFfi());
        self::assertFalse(CategorizationPipeline::usesW413RustFfi());
        self::assertFalse(CategorizationResult::usesW413RustFfi());
        self::assertFalse(CategorizationResult::$useRustFfi);
        self::assertFalse(CategorizationService::usesW403RustFfi());
        self::assertFalse(CategorizationService::$useRustFfi);
    }

    public function test_bindings_resolve_and_guard_capacity(): void
    {
        $this->requireBindings();
    }

    /**
     * @return iterable<string, array{0: class-string, 1: list<mixed>}>
     */
    public static function mismatchedColumns(): iterable
    {
        yield 'blacklist posters' => [BlacklistService::class, [[[1, 'x']], ['a', 'b'], ['a'], ['a', 'b']]];
        yield 'blacklist message ids' => [BlacklistService::class, [[[1, 'x']], ['a'], ['a'], []]];
        yield 'result confidences' => [CategorizationResult::class, [[10, 20], [0.5], ['a', 'b']]];
        yield 'result matched by' => [CategorizationResult::class, [[10], [0.5], ['a', 'b']]];
        yield 'service legacy' => [CategorizationService::class, [[10, 20], [10]]];
    }

    /**
     * @param  class-string  $class
     * @param  list<mixed>  $args
     */
    #[DataProvider('mismatchedColumns')]
    public function test_mismatched_columns_are_rejected_on_both_routes(string $class, array $args): void
    {
        $call = $this->entry($class);
        foreach ([false, true] as $native) {
            try {
                $class::withW53RustFfi($native, static fn (): mixed => $call(...$args));
                self::fail('Mismatched columns must be rejected');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('same length', $e->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function invalidRuleColumns(): iterable
    {
        yield 'zero' => [0];
        yield 'four' => [4];
        yield 'negative' => [-1];
    }

    #[DataProvider('invalidRuleColumns')]
    public function test_invalid_rule_columns_are_rejected_on_both_routes(int $column): void
    {
        foreach ([false, true] as $native) {
            try {
                BlacklistService::withW53RustFfi($native, static fn (): array => BlacklistService::firstLiteralRuleMatches([[1, 'a'], [$column, 'b']], ['s'], ['p'], ['<m>']));
                self::fail('An invalid rule column must be rejected');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('column', $e->getMessage());
            }
        }
    }

    public function test_part_count_summary_parity(): void
    {
        $edge = [
            'Movie [01/10] - "a.part01.rar" yEnc (1/50)',
            'x (3/9) y (12/40) z',
            'x (3/9) y (12/40 z',
            '(000000001/000000002)',
            '(1234567890/2)',
            '(1/1234567890)',
            '((1/2)',
            '(/2)',
            '(0/5)',
            '(7/5)',
            '(5/5)',
            'yEnc (999999999/999999999)',
            ' (',
            '',
            'no parens at all',
            "tab\t(4/8)\n(2/8)",
            'é (1/2) ü',
        ];
        $summary = $this->assertParity(PerArticleObfuscationGate::class, [$edge]);
        self::assertSame(['subjects' => 17, 'part_markers' => 6, 'part_counts' => 11, 'first_parts' => 4, 'final_parts' => 2, 'invalid_parts' => 2, 'max_total' => 999_999_999], $summary);
        $this->assertParity(PerArticleObfuscationGate::class, [[]]);
        $this->assertParity(PerArticleObfuscationGate::class, [['a' => 'k (1/2)', 7 => 'k (2/2)']]);
        // A NUL byte splits the joined column, so Rust declines and PHP answers.
        $this->assertParity(PerArticleObfuscationGate::class, [["a\0 (1/2)", 'b (2/2)']]);
        for ($i = 0; $i < 12; $i++) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity(PerArticleObfuscationGate::class, $this->subjectBatch(new Randomizer(new Mt19937(5300 + $i)), $uncommon));
            }
        }
    }

    public function test_literal_rule_parity(): void
    {
        $rules = [[1, 'SPAM'], [2, 'poster@'], [3, 'bad.id'], [1, '']];
        $hits = $this->assertParity(BlacklistService::class, [$rules, ['no spam here', 'clean', 'clean', 'x'], ['a', 'Poster@host', 'b', 'c'], ['<1>', '<2>', '<BAD.ID@x>', '<4>']]);
        self::assertSame([0, 1, 2, 3], $hits);
        self::assertSame([-1, -1], $this->assertParity(BlacklistService::class, [[[1, 'zz']], ['a', ''], ['b', ''], ['c', '']]));
        self::assertSame([-1, -1], $this->assertParity(BlacklistService::class, [[], ['a', 'b'], ['a', 'b'], ['a', 'b']]));
        self::assertSame([], $this->assertParity(BlacklistService::class, [[[1, 'a']], [], [], []]));
        self::assertSame([0], $this->assertParity(BlacklistService::class, [[[1, 'ÉTÉ'], [1, 'abc']], ['ÉTÉ ABC'], [''], ['']]));
        self::assertSame([1], $this->assertParity(BlacklistService::class, [[[1, 'été'], [1, 'abc']], ['ÉTÉ ABC'], [''], ['']]));
        $this->assertParity(BlacklistService::class, [[[1, "a\0b"], [1, 'c']], ['xa', 'c'], ['', ''], ['', '']]);
        $this->assertParity(BlacklistService::class, [[[2, 'b']], ['s', 't'], ["a\0b", 'b'], ['', '']]);
        $this->assertParity(BlacklistService::class, [['x' => [3, 'id'], 'y' => [1, 's']], [5 => 's', 9 => 't'], ['a' => 'p', 'b' => 'q'], ['<id>', '<x>']]);
        for ($i = 0; $i < 12; $i++) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity(BlacklistService::class, $this->ruleBatch(new Randomizer(new Mt19937(5310 + $i)), $uncommon));
            }
        }
    }

    public function test_release_name_signal_parity(): void
    {
        $names = [
            '0123456789abcdefABCDEF0123456789',
            str_repeat('a', 40),
            str_repeat('a', 36),
            'Show.S01E02.1080p.WEB.h264-GRP',
            'Movie.2019.720p-ABCDEFGHIJKLMNOP',
            'S1234E5 11080p 120199',
            'OF.name',
            'of.name',
            'site only-fans x',
            'site Only Fans x',
            'site OnlyFansx',
            'xOnlyFans',
            'OnlyFans_',
            '-ab',
            'ab',
            '',
            's01e1',
            'S001E001x',
            '2160I',
            '1999',
            '19990',
            'Movie (2020) [576p]',
            "Bytes \xFF\xFE 480p-x1",
        ];
        $masks = $this->assertParity(CategorizationPipeline::class, [$names]);
        self::assertSame([1, 1, 0, 14, 24, 0, 32, 0, 32, 32, 0, 0, 0, 2, 0, 0, 4, 4, 8, 16, 0, 24, 10], $masks);
        $this->assertParity(CategorizationPipeline::class, [[]]);
        $this->assertParity(CategorizationPipeline::class, [["a\0b-GRP", 'x']]);
        for ($i = 0; $i < 12; $i++) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity(CategorizationPipeline::class, $this->releaseNameBatch(new Randomizer(new Mt19937(5320 + $i)), $uncommon));
            }
        }
    }

    public function test_result_fold_parity(): void
    {
        $fold = $this->assertParity(CategorizationResult::class, [[10, 10, 5030, 2040], [0.0, 0.5, 0.55, 0.9], ['no_match', 'hash_md5', 'tv_title', 'movie']]);
        self::assertSame(['successful' => 3, 'protected' => 1, 'best_index' => 3], $fold);
        self::assertSame(['successful' => 0, 'protected' => 0, 'best_index' => -1], $this->assertParity(CategorizationResult::class, [[], [], []]));
        self::assertSame(['successful' => 2, 'protected' => 0, 'best_index' => 0], $this->assertParity(CategorizationResult::class, [[10, 5030], [NAN, 0.5], ['a', 'b']]));
        self::assertSame(['successful' => 2, 'protected' => 0, 'best_index' => 1], $this->assertParity(CategorizationResult::class, [[10, 5030], [0.5, 0.5], ['a', 'b']]));
        self::assertSame(['successful' => 0, 'protected' => 0, 'best_index' => 0], $this->assertParity(CategorizationResult::class, [[5030, 10], [-0.0, 0.0], ['a', 'b']]));
        // A protected misc best resists a weak non-misc override but not a 0.6+ one.
        self::assertSame(['successful' => 3, 'protected' => 1, 'best_index' => 2], $this->assertParity(CategorizationResult::class, [[20, 5030, 2040], [0.4, 0.59, 0.6], ['hashed', 'tv', 'movie']]));
        self::assertSame(['successful' => 2, 'protected' => 1, 'best_index' => 1], $this->assertParity(CategorizationResult::class, [[10, 10], [0.3, 0.4], ['obfuscated_subject', 'group_only_low_signal_x']]));
        self::assertSame(['successful' => 2, 'protected' => 2, 'best_index' => 1], $this->assertParity(CategorizationResult::class, [[10, 10], [0.3, 0.4], ['gibberish_name', 'group_only_low_signal']]));
        $this->assertParity(CategorizationResult::class, [[10, 20], [0.5, 0.7], ["hash_\0x", 'a']]);
        $this->assertParity(CategorizationResult::class, [['a' => 2040, 'b' => 10], [3 => INF, 4 => -INF], ['x' => 'movie', 'y' => 'hash_x']]);
        for ($i = 0; $i < 12; $i++) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity(CategorizationResult::class, $this->resultBatch(new Randomizer(new Mt19937(5330 + $i)), $uncommon));
            }
        }
    }

    public function test_category_comparison_parity(): void
    {
        $comparison = $this->assertParity(CategorizationService::class, [[2040, 2040, 5030, -1, 9000, 0, 8999], [2040, 2050, 1000, 5, 9000, 999, 8000]]);
        self::assertSame([2, 5, 3, 2], [$comparison['matches'], $comparison['mismatches'], $comparison['same_root_mismatches'], $comparison['invalid_pairs']]);
        self::assertSame(2, $comparison['root_matrix'][2 * 9 + 2]);
        self::assertSame(1, $comparison['root_matrix'][5 * 9 + 1]);
        self::assertSame(1, $comparison['root_matrix'][0]);
        self::assertSame(1, $comparison['root_matrix'][8 * 9 + 8]);
        self::assertSame(5, array_sum($comparison['root_matrix']));
        self::assertCount(81, $comparison['root_matrix']);
        $this->assertParity(CategorizationService::class, [[], []]);
        $this->assertParity(CategorizationService::class, [[PHP_INT_MIN, PHP_INT_MAX], [PHP_INT_MIN, 0]]);
        $this->assertParity(CategorizationService::class, [['a' => 1010, 'b' => 2020], [7 => 1010, 9 => 3030]]);
        for ($i = 0; $i < 12; $i++) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity(CategorizationService::class, $this->comparisonBatch(new Randomizer(new Mt19937(5340 + $i)), $uncommon));
            }
        }
    }

    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(self::SEED));
        $summary = $records = [];
        foreach (self::ENTRIES as $class => $entry) {
            $call = $this->entry($class);
            foreach (['common', 'uncommon'] as $workload) {
                $fixtures = [];
                for ($v = 0; $v < 8; $v++) {
                    $fixtures[] = $this->fixture($class, $random, $workload === 'uncommon');
                }
                $php = $rust = [];
                for ($i = -self::WARMUPS; $i < self::PAIRS; $i++) {
                    $variant = $random->getInt(0, 7);
                    $args = $fixtures[$variant];
                    $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                    $results = $elapsed = [];
                    foreach ($order as $native) {
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW53RustFfi($native, static function () use ($call, $args): array {
                            $result = null;
                            $best = PHP_INT_MAX;
                            for ($r = 0; $r < self::REPEATS; $r++) {
                                $start = hrtime(true);
                                $result = $call(...$args);
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
        $artifact = sys_get_temp_dir().'/w531-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS, 'repeats' => self::REPEATS, 'seed' => self::SEED, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW53.1 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach ($summary as $class => $workloads) {
            $metrics = [$workloads['common']['mean_speedup'], $workloads['common']['p99_speedup'], $workloads['uncommon']['mean_speedup'], $workloads['uncommon']['p99_speedup']];
            self::assertSame(min($metrics) >= 1.5, $this->uses($class), $class.' default must match the measured qualification '.json_encode($metrics));
        }
        self::assertCount(5, $summary);
    }

    private function uses(string $class): bool
    {
        /** @var callable(): bool $uses */
        $uses = [$class, 'usesW53RustFfi'];

        return $uses();
    }

    private function entry(string $class): Closure
    {
        /** @var callable $entry */
        $entry = [$class, self::ENTRIES[$class]];

        return Closure::fromCallable($entry);
    }

    /**
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, array $args): mixed
    {
        $call = $this->entry($class);
        $php = $class::withW53RustFfi(false, static fn (): mixed => $call(...$args));
        $native = $class::withW53RustFfi(true, static fn (): mixed => $call(...$args));
        self::assertSame($php, $native, $class.' Rust batch must match PHP');
        $method = self::ENTRIES[$class];
        self::assertSame($php, $class::{$method.'Php'}(...$args), $class);
        self::assertSame($php, $class::{$method.'Native'}(...$args), $class);

        return $php;
    }

    /** @return list<mixed> */
    private function fixture(string $class, Randomizer $random, bool $uncommon): array
    {
        return match ($class) {
            PerArticleObfuscationGate::class => $this->subjectBatch($random, $uncommon),
            BlacklistService::class => $this->ruleBatch($random, $uncommon),
            CategorizationPipeline::class => $this->releaseNameBatch($random, $uncommon),
            CategorizationResult::class => $this->resultBatch($random, $uncommon),
            default => $this->comparisonBatch($random, $uncommon),
        };
    }

    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        $calls = [
            PerArticleObfuscationGate::class => ['nntmux_w531_obfuscation_part_count_summary_batch', ['a (1/5)', 7, 1], 6, 6],
            BlacklistService::class => ['nntmux_w531_blacklist_literal_first_match_batch', ['spam', 4, pack('P', 1), 8, 1, 'SPAM', 4, 'p', 1, '<m>', 3, 1], 1, 1],
            CategorizationPipeline::class => ['nntmux_w531_release_name_signal_batch', ['Show.S01E02-GRP', 15, 1], 1, 1],
            CategorizationResult::class => ['nntmux_w531_categorization_result_fold_batch', [pack('P', 2040), 8, pack('e', 0.9), 8, 'movie', 5, 1, Category::OTHER_MISC, Category::OTHER_HASHED], 3, 3],
            CategorizationService::class => ['nntmux_w531_category_comparison_matrix_batch', [pack('P2', 2040, 2050), 16, 1], 85, 85],
        ];
        foreach ($calls as $class => [$symbol, $args, $words, $worst]) {
            $buffer = (new ReflectionMethod($class, 'w53Buffer'))->invoke(null, 4);
            self::assertIsArray($buffer, $class.' must load the obfuscation_blacklist_cat_w53 binding');
            $ffi = $buffer[0];
            self::assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[128]');
            self::assertSame($words, $this->invoke($ffi, $symbol, [...$args, $out, 128]), $symbol);
            self::assertSame(-2, $this->invoke($ffi, $symbol, [...$args, $out, $worst - 1]), $symbol.' short capacity');
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

    /**
     * @param  list<string>  $values
     */
    private function pick(Randomizer $random, array $values): string
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    private function words(Randomizer $random, int $min, int $max, string $glue = '.'): string
    {
        $words = [];
        for ($i = $random->getInt($min, $max); $i > 0; $i--) {
            $words[] = $this->pick($random, self::WORDS);
        }

        return implode($glue, $words);
    }

    /** @return array{0: list<string>} */
    private function subjectBatch(Randomizer $random, bool $uncommon): array
    {
        $subjects = [];
        for ($i = $random->getInt(64, 256); $i > 0; $i--) {
            $total = $random->getInt(1, 400);
            $part = $random->getInt(1, $total);
            $name = $this->words($random, 2, 6);
            $subjects[] = match ($uncommon ? $random->getInt(4, 9) : $random->getInt(0, 4)) {
                0, 1 => '['.$random->getInt(1, 40).'/40] - "'.$name.'.part'.$random->getInt(1, 99).'.rar" yEnc ('.$part.'/'.$total.') '.$random->getInt(100_000, 800_000),
                2 => $name.' - "'.$name.'.vol'.$random->getInt(0, 63).'+'.$random->getInt(1, 64).'.par2" yEnc ('.$part.'/'.$total.')',
                3 => '"'.$name.'.nzb" yEnc (1/1)',
                4 => $name.' plain text subject '.$random->getInt(1, 9999),
                5 => str_repeat('('.$this->words($random, 1, 3, ' ').') ', $random->getInt(10, 40)).'yEnc ('.$part.'/'.$total.')',
                6 => '['.$name.'] yEnc ('.$random->getInt(0, 9).'/'.$random->getInt(0, 5).') '.str_repeat('é ü (', $random->getInt(5, 30)),
                7 => $name.' ('.$random->getInt(1_000_000_000, PHP_INT_MAX).'/'.$total.') ('.$part.'/'.$random->getInt(1_000_000_000, PHP_INT_MAX).')',
                8 => str_repeat($this->words($random, 3, 8, ' ').' ', $random->getInt(8, 20)),
                default => '('.str_repeat('1', $random->getInt(1, 12)).'/'.str_repeat('9', $random->getInt(1, 12)).') '.str_repeat('x', $random->getInt(200, 600)),
            };
        }

        return [$subjects];
    }

    /** @return array{0: list<array{0: int, 1: string}>, 1: list<string>, 2: list<string>, 3: list<string>} */
    private function ruleBatch(Randomizer $random, bool $uncommon): array
    {
        $rules = [];
        for ($r = $uncommon ? $random->getInt(64, 128) : $random->getInt(16, 40); $r > 0; $r--) {
            $column = $random->getInt(1, 3);
            $rules[] = [$column, match ($column) {
                1 => $this->words($random, 1, 2).($random->getInt(0, 1) === 0 ? '' : 'X'.$random->getInt(10, 99)),
                2 => $this->pick($random, self::WORDS).$random->getInt(0, 99).'@',
                default => '@'.$this->pick($random, ['spam.example', 'bad.host', 'Flood.NET', 'junk.org']),
            }];
        }
        $subjects = $posters = $ids = [];
        for ($i = $random->getInt(64, 256); $i > 0; $i--) {
            $subject = $this->words($random, 3, 7).' - "'.$this->words($random, 2, 4).'.rar" yEnc (1/'.$random->getInt(1, 99).')';
            $subjects[] = $uncommon ? str_repeat($subject.' ', $random->getInt(3, 8)) : $subject;
            $posters[] = $this->pick($random, self::WORDS).$random->getInt(0, $uncommon ? 999 : 140).'@'.$this->pick($random, ['news.example', 'Usenet.Farm', 'host.invalid']);
            $ids[] = '<'.bin2hex($random->getBytes(8)).'@'.$this->pick($random, ['news.example', 'Bad.Host', 'spam.example', 'clean.example']).'>';
        }

        return [$rules, $subjects, $posters, $ids];
    }

    /** @return array{0: list<string>} */
    private function releaseNameBatch(Randomizer $random, bool $uncommon): array
    {
        $names = [];
        for ($i = $random->getInt(64, 256); $i > 0; $i--) {
            $group = $this->pick($random, self::GROUPS);
            $title = $this->words($random, 1, 4);
            $names[] = match ($uncommon ? $random->getInt(4, 9) : $random->getInt(0, 4)) {
                0, 1 => $title.'.S'.\sprintf('%02d', $random->getInt(1, 30)).'E'.\sprintf('%02d', $random->getInt(1, 24)).'.'.$this->pick($random, ['720p', '1080p', '2160p', '480p']).'.WEB-DL.x264-'.$group,
                2 => $title.'.'.$random->getInt(1950, 2026).'.'.$this->pick($random, ['1080p', '720p', '576i', 'DVDRip']).'.BluRay-'.$group,
                3 => $this->pick($random, self::WORDS).' - '.$title.' ('.$random->getInt(1960, 2026).') [FLAC]',
                4 => 'OF.'.$title.'.'.$random->getInt(10, 99).'.'.\sprintf('%02d', $random->getInt(1, 12)),
                5 => bin2hex($random->getBytes($random->getInt(0, 1) === 0 ? 16 : 20)),
                6 => strtoupper(bin2hex($random->getBytes($random->getInt(14, 22)))),
                7 => str_repeat($title.'.'.$random->getInt(100_000, 9_999_999).'.', $random->getInt(4, 12)).'Only'.$this->pick($random, ['', '-', '_', ' ', '.']).'Fans'.$this->pick($random, ['', '_x', ' x']),
                8 => str_repeat("\xC3\xA9\xC3\xBC ", $random->getInt(10, 40)).'s'.$random->getInt(1, 9999).'e'.$random->getInt(0, 999).' '.$random->getInt(1, 21_600).'p',
                default => str_repeat($this->words($random, 2, 5).'-', $random->getInt(5, 15)).str_repeat('A', $random->getInt(1, 20)),
            };
        }

        return [$names];
    }

    /** @return array{0: list<int>, 1: list<float>, 2: list<string>} */
    private function resultBatch(Randomizer $random, bool $uncommon): array
    {
        $ids = $confidences = $matched = [];
        for ($i = $random->getInt(64, 256); $i > 0; $i--) {
            if ($uncommon && $random->getInt(0, 1) === 0) {
                $ids[] = [Category::OTHER_MISC, Category::OTHER_HASHED][$random->getInt(0, 1)];
                $matched[] = $this->pick($random, ['hash_', 'obfuscated_', 'gibberish_', 'group_only_low_signal', 'no_match', 'hash']).str_repeat('x', $random->getInt(0, 60));
            } else {
                $ids[] = self::CATEGORIES[$random->getInt(0, \count(self::CATEGORIES) - 1)];
                $matched[] = $this->pick($random, self::MATCHED_BY);
            }
            $confidences[] = $uncommon ? $random->getInt(0, 10) / 10.0 : round($random->getFloat(0.0, 1.0), 2);
        }

        return [$ids, $confidences, $matched];
    }

    /** @return array{0: list<int>, 1: list<int>} */
    private function comparisonBatch(Randomizer $random, bool $uncommon): array
    {
        $pipeline = $legacy = [];
        for ($i = $random->getInt(64, 256); $i > 0; $i--) {
            $id = self::CATEGORIES[$random->getInt(0, \count(self::CATEGORIES) - 1)];
            $pipeline[] = $id;
            $legacy[] = match ($uncommon ? $random->getInt(2, 5) : $random->getInt(0, 3)) {
                0, 1 => $id,
                2 => self::CATEGORIES[$random->getInt(0, \count(self::CATEGORIES) - 1)],
                3 => intdiv($id, 1000) * 1000 + $random->getInt(0, 99),
                4 => $random->getInt(-5_000, 20_000),
                default => $random->getInt(0, 1) === 0 ? PHP_INT_MIN : PHP_INT_MAX,
            };
        }

        return [$pipeline, $legacy];
    }
}
