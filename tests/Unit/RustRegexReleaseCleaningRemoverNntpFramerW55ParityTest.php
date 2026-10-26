<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NNTP\NNTPService;
use App\Services\NNTP\RustVerificationFramer;
use App\Services\RegexService;
use App\Services\ReleaseCleaningService;
use App\Services\ReleaseRemoverService;
use Closure;
use FFI;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;

final class RustRegexReleaseCleaningRemoverNntpFramerW55ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        RegexService::class => 'collectionFileCounters',
        ReleaseCleaningService::class => 'subjectCleaningMasks',
        ReleaseRemoverService::class => 'removalPatternMasks',
        NNTPService::class => 'verificationMessageIdCodes',
        RustVerificationFramer::class => 'articleIdCodes',
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns), the per-metric minimum
     * of four runs of test_paired_randomized_common_and_uncommon_benchmarks: seed 55, 200 pairs
     * per workload after 20 warmups, 64-256 rows per batch, randomized call order, each side the
     * fastest of three back-to-back calls, release Rust, PHP CLI without opcache, Apple Silicon
     * macOS. Timings include dispatch, implode(), the FFI call and unpack('P*'). A class defaults
     * to Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        RegexService::class => [2.557, 2.161, 1.886, 1.572],
        ReleaseCleaningService::class => [6.547, 5.694, 7.237, 5.248],
        ReleaseRemoverService::class => [6.484, 4.585, 4.544, 3.534],
        NNTPService::class => [3.397, 3.313, 2.947, 2.277],
        RustVerificationFramer::class => [2.295, 1.639, 3.773, 3.57],
    ];

    private const PAIRS = 200;

    private const WARMUPS = 20;

    /** Back-to-back calls per side of a pair; the fastest is the sample (drops preemption spikes). */
    private const REPEATS = 3;

    private const SEED = 55;

    /** Byte budget the NNTP message-id batch runs under unless a test picks another. */
    private const BUDGET = 512;

    private const WORDS = ['alpha', 'Bravo', 'charlie', 'DELTA', 'echo', 'Foxtrot', 'golf', 'hotel', 'India', 'juliet', 'kilo', 'Lima', 'mike', 'November', 'oscar', 'Papa'];

    private const TAGS = ['720p', '1080p', '2160p', 'WEB-DL', 'BluRay', 'HDTV', 'x264', 'x265', 'DTS', 'AAC', 'REPACK', 'PROPER'];

    private const EXTENSIONS = ['rar', 'r01', 'par2', 'vol03+04.par2', 'nfo', 'sfv', 'mkv', 'nzb', 'part01.rar', '7z', 'zip'];

    public function test_wave_members_exist_and_defaults_match_qualification(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW55RustFfi', 'withW55RustFfi', 'w55RustFfiState', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
            }
            foreach (['usesW55RustFfi', 'withW55RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->getMethod($method)->isStatic() && $reflection->getMethod($method)->isPublic(), $class.'::'.$method);
            }
            self::assertTrue($reflection->getMethod('w55RustFfiState')->isPrivate(), $class);
            $constant = $reflection->getReflectionConstant('W55_RUST_FFI_DEFAULT');
            self::assertNotFalse($constant, $class);
            self::assertTrue($constant->isPrivate(), $class);
            self::assertIsBool($constant->getValue(), $class);
            self::assertSame($constant->getValue(), $this->uses($class), $class);
            self::assertStringContainsString('nntmux_w551_', (string) $reflection->getConstant('W55_FFI_CDEF'));
            foreach ($reflection->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w55', $property->getName(), $class.' keeps Wave 55 state in function-local statics');
            }

            $speedups = self::QUALIFICATION[$class];
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->uses($class), $class.' default must follow its recorded qualification');
        }
    }

    public function test_with_w55_rust_ffi_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->uses($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW55RustFfi($enabled, fn (): array => [
                    $this->uses($class),
                    $class::withW55RustFfi(! $enabled, fn (): bool => $this->uses($class)),
                    $this->uses($class),
                ]);
                self::assertSame([$enabled, ! $enabled, $enabled], $seen, $class);
                self::assertSame($default, $this->uses($class), $class);
            }
            try {
                $class::withW55RustFfi(! $default, static function (): never {
                    throw new InvalidArgumentException('boom');
                });
            } catch (InvalidArgumentException) {
            }
            self::assertSame($default, $this->uses($class), $class.' restores routing after an exception');
        }
    }

    public function test_earlier_wave_toggles_are_untouched(): void
    {
        self::assertFalse(RegexService::usesW461RustFfi());
        self::assertFalse(ReleaseCleaningService::usesW401RustFfi());
        self::assertFalse(ReleaseRemoverService::usesW401RustFfi());
        self::assertFalse(NNTPService::usesRustFfi());
        self::assertTrue(NNTPService::usesW461RustFfi());
        self::assertFalse(RustVerificationFramer::usesW461RustFfi());
    }

    public function test_bindings_resolve_and_guard_capacity(): void
    {
        $this->requireBindings();
    }

    /**
     * @return iterable<string, array{0: class-string, 1: string, 2: int}>
     */
    public static function knownCodes(): iterable
    {
        $counter = static fn (int $current, int $total, int $separator): int => ($current << RegexService::W55_COUNTER_SHIFT) | ($total << RegexService::W55_TOTAL_SHIFT) | $separator;
        yield 'counter slash' => [RegexService::class, 'Show [01/10] - "a.rar"', $counter(1, 10, RegexService::W55_SEPARATOR_SLASH)];
        yield 'counter of' => [RegexService::class, 'x (3 of 7) yEnc', $counter(3, 7, RegexService::W55_SEPARATOR_OF)];
        yield 'counter underscore OF' => [RegexService::class, 'x 2_OF_9 y', $counter(2, 9, RegexService::W55_SEPARATOR_OF)];
        yield 'counter dash' => [RegexService::class, 'x [12-99999]', $counter(12, 99999, RegexService::W55_SEPARATOR_DASH)];
        yield 'counter dollar closer' => [RegexService::class, 'x [1/2$', $counter(1, 2, RegexService::W55_SEPARATOR_SLASH)];
        yield 'counter leftmost' => [RegexService::class, '[1/2] (00003/00009)', $counter(1, 2, RegexService::W55_SEPARATOR_SLASH)];
        yield 'counter after failed opener' => [RegexService::class, 'a1/2 (00003/00009)', $counter(3, 9, RegexService::W55_SEPARATOR_SLASH)];
        yield 'counter six digits' => [RegexService::class, 'x [123456/7]', -1];
        yield 'counter unclosed' => [RegexService::class, 'x [1/2', -1];
        yield 'counter empty' => [RegexService::class, '', -1];

        yield 'cleaning counter file yEnc' => [ReleaseCleaningService::class, '[01/10] - "abcd" yEnc', ReleaseCleaningService::W55_CLEAN_COUNTER_FILE | ReleaseCleaningService::W55_CLEAN_YENC_TAIL];
        yield 'cleaning scrambled' => [ReleaseCleaningService::class, '[1/2] - "Fubj.ext" lRap', ReleaseCleaningService::W55_CLEAN_COUNTER_FILE | ReleaseCleaningService::W55_CLEAN_SCRAMBLED];
        yield 'cleaning html quote' => [ReleaseCleaningService::class, '[1/2] #34;file.ext#34; x', ReleaseCleaningService::W55_CLEAN_COUNTER_FILE];
        yield 'cleaning short file' => [ReleaseCleaningService::class, '[1/2] "abc"', 0];
        yield 'cleaning file with LF' => [ReleaseCleaningService::class, "[1/2] \"ab\ncd\"", ReleaseCleaningService::W55_CLEAN_NON_PRINTABLE];
        yield 'cleaning yEnc before LF' => [ReleaseCleaningService::class, "Name yEnc\n", ReleaseCleaningService::W55_CLEAN_YENC_TAIL | ReleaseCleaningService::W55_CLEAN_NON_PRINTABLE];
        yield 'cleaning lowercase yenc' => [ReleaseCleaningService::class, 'Name yenc', 0];
        yield 'cleaning prefix' => [ReleaseCleaningService::class, 'RELEASE NAME x', ReleaseCleaningService::W55_CLEAN_NAME_PREFIX];
        yield 'cleaning suffix' => [ReleaseCleaningService::class, 'x.Thumbs', ReleaseCleaningService::W55_CLEAN_MARKER_SUFFIX];
        yield 'cleaning spaces' => [ReleaseCleaningService::class, 'a  b', ReleaseCleaningService::W55_CLEAN_MULTI_SPACE];
        yield 'cleaning DEL is printable' => [ReleaseCleaningService::class, "\x7f", 0];

        yield 'remover gibberish' => [ReleaseRemoverService::class, 'abcdefghij12345', ReleaseRemoverService::W55_REMOVE_GIBBERISH];
        yield 'remover hashed' => [ReleaseRemoverService::class, 'abcdefghijklmnopqrstuvwxy', ReleaseRemoverService::W55_REMOVE_GIBBERISH | ReleaseRemoverService::W55_REMOVE_HASHED];
        yield 'remover empty' => [ReleaseRemoverService::class, '', ReleaseRemoverService::W55_REMOVE_SHORT];
        yield 'remover passworded' => [ReleaseRemoverService::class, 'My.PASSWORD.Here', ReleaseRemoverService::W55_REMOVE_PASSWORDED];
        yield 'remover password reset' => [ReleaseRemoverService::class, 'Password.Reset.Tool', 0];
        yield 'remover scr' => [ReleaseRemoverService::class, 'x.SCR y', ReleaseRemoverService::W55_REMOVE_SCR];
        yield 'remover exe' => [ReleaseRemoverService::class, 'setup.EXE', ReleaseRemoverService::W55_REMOVE_EXE];
        yield 'remover password url' => [ReleaseRemoverService::class, 'password.url', ReleaseRemoverService::W55_REMOVE_PASSWORDED | ReleaseRemoverService::W55_REMOVE_PASSWORD_URL];

        yield 'message id bracketed' => [NNTPService::class, '<a@b>', NNTPService::W55_MESSAGE_ID_WITHIN];
        yield 'message id bare' => [NNTPService::class, 'a@b', NNTPService::W55_MESSAGE_ID_WITHIN | NNTPService::W55_MESSAGE_ID_NEEDS_FORMAT];
        yield 'message id brackets only' => [NNTPService::class, '<>', 2];
        yield 'message id empty' => [NNTPService::class, '', 2];
        yield 'message id space' => [NNTPService::class, '<a b>', 3 | (2 << NNTPService::W55_MESSAGE_ID_INVALID_SHIFT)];
        yield 'message id inner bracket' => [NNTPService::class, '<<a<b', 3 | NNTPService::W55_MESSAGE_ID_NEEDS_FORMAT | (2 << NNTPService::W55_MESSAGE_ID_INVALID_SHIFT)];

        yield 'article id valid' => [RustVerificationFramer::class, 'abc@d', RustVerificationFramer::W55_ARTICLE_ID_VALID];
        yield 'article id empty' => [RustVerificationFramer::class, '', RustVerificationFramer::W55_ARTICLE_ID_EMPTY];
        yield 'article id invalid utf8' => [RustVerificationFramer::class, "\xff", RustVerificationFramer::W55_ARTICLE_ID_INVALID_UTF8];
        yield 'article id space' => [RustVerificationFramer::class, 'a b', RustVerificationFramer::W55_ARTICLE_ID_REJECTED | RustVerificationFramer::W55_ARTICLE_ID_SPACE];
        yield 'article id tab' => [RustVerificationFramer::class, "a\tb", RustVerificationFramer::W55_ARTICLE_ID_REJECTED | RustVerificationFramer::W55_ARTICLE_ID_SPACE | RustVerificationFramer::W55_ARTICLE_ID_CONTROL];
        yield 'article id brackets' => [RustVerificationFramer::class, '<ab>', RustVerificationFramer::W55_ARTICLE_ID_REJECTED | RustVerificationFramer::W55_ARTICLE_ID_BRACKET];
        yield 'article id ideographic space' => [RustVerificationFramer::class, "a\u{3000}b", RustVerificationFramer::W55_ARTICLE_ID_REJECTED | RustVerificationFramer::W55_ARTICLE_ID_SPACE];
        yield 'article id NEL' => [RustVerificationFramer::class, "a\u{85}b", RustVerificationFramer::W55_ARTICLE_ID_REJECTED | RustVerificationFramer::W55_ARTICLE_ID_SPACE | RustVerificationFramer::W55_ARTICLE_ID_CONTROL];
        yield 'article id accented' => [RustVerificationFramer::class, 'café@x', RustVerificationFramer::W55_ARTICLE_ID_VALID];
        yield 'article id oversize' => [RustVerificationFramer::class, str_repeat('a', 131073), RustVerificationFramer::W55_ARTICLE_ID_OVERSIZE];
        yield 'article id at limit' => [RustVerificationFramer::class, str_repeat('a', 131072), RustVerificationFramer::W55_ARTICLE_ID_VALID];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('knownCodes')]
    public function test_known_codes(string $class, string $row, int $expected): void
    {
        self::assertSame([$expected], $this->assertParity($class, $this->args($class, [$row])));
    }

    /**
     * @return iterable<string, array{0: class-string, 1: array<array-key, string>}>
     */
    public static function edgeBatches(): iterable
    {
        $rows = ['', ' ', "\t\n", '<', '>', '<>', '[]', '"', '""', '[1/2]', 'yEnc', "yEnc\n", "\n", 'a  b', "caf\u{e9}", "\xc3\x28", "\xf0\x9f\x8e\xac", "\u{2028}", "\u{a0}x",
            str_repeat('9', 40), str_repeat('[1', 30), '[01/10] - "'.str_repeat('a', 300).'" yEnc', '[1/2] "abcd" lrap_', '[1/2] "abcd" LRAP!', '#34;#34;', 'Release Namex', 'sample-', 'SAMPLE_x.proof',
            'x.sample', 'x.thumbs', 'x thumb', '.scr', '.scr$', '.scr"', 'a.exe', '.EXE', 'install.bin', 'PASSWORD.URL', 'xx.nzbyy', 'no password here', 'not password', 'passwor', 'Advanced passworded',
            '(1/2)', '[00000/00000]', 'a [99999 of 99999]', 'a_of_1', ' 1 OF 2 ', 'a -1-2-', '[1/2:', "x\x01y", "x\x7fy", '<<<a@b>>>', '<a@b', 'a@b>', str_repeat('<', 7)];
        foreach (array_keys(self::ENTRIES) as $class) {
            yield $class.' mixed edges' => [$class, $rows];
            yield $class.' single empty row' => [$class, ['']];
            yield $class.' empty batch' => [$class, []];
            yield $class.' keyed rows' => [$class, ['k' => '[1/2] "abcd" yEnc', 7 => 'x  y', 'z' => '<a@b>']];
        }
    }

    /**
     * @param  class-string  $class
     * @param  array<array-key, string>  $rows
     */
    #[DataProvider('edgeBatches')]
    public function test_edge_batch_parity(string $class, array $rows): void
    {
        $result = $this->assertParity($class, $this->args($class, $rows));
        self::assertIsArray($result);
        self::assertTrue(array_is_list($result), $class);
        self::assertCount(\count($rows), $result, $class);
    }

    public function test_rows_holding_nul_fall_back_to_php(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $rows = ["a\0b", '[1/2] "abcd" yEnc', "\0", '<x@y>'];
            self::assertCount(4, $this->assertParity($class, $this->args($class, $rows)), $class);
        }
    }

    public function test_message_id_budgets_parity(): void
    {
        $rows = ['<a@b>', 'a@b', '<>', '', '<a b>', str_repeat('x', 30), '<'.str_repeat('y', 9).'>'];
        foreach ([PHP_INT_MIN, -1, 0, 1, 2, 3, 10, 11, 12, 32, PHP_INT_MAX] as $budget) {
            $this->assertParity(NNTPService::class, [$rows, $budget]);
        }
    }

    public function test_realistic_dataset_parity(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED + 1));
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([false, true] as $uncommon) {
                for ($i = 0; $i < 12; $i++) {
                    $this->assertParity($class, $this->fixture($class, $random, $uncommon));
                }
            }
        }
    }

    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(self::SEED));
        $summary = $records = [];
        foreach (array_keys(self::ENTRIES) as $class) {
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
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW55RustFfi($native, static function () use ($call, $args): array {
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
        $artifact = sys_get_temp_dir().'/w551-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS, 'repeats' => self::REPEATS, 'seed' => self::SEED, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW55.1 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach ($summary as $class => $workloads) {
            $metrics = [$workloads['common']['mean_speedup'], $workloads['common']['p99_speedup'], $workloads['uncommon']['mean_speedup'], $workloads['uncommon']['p99_speedup']];
            self::assertSame(min($metrics) >= 1.5, $this->uses($class), $class.' default must match the measured qualification '.json_encode($metrics));
        }
        self::assertCount(5, $summary);
    }

    private function uses(string $class): bool
    {
        /** @var callable(): bool $uses */
        $uses = [$class, 'usesW55RustFfi'];

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
     * @return list<mixed>
     */
    private function args(string $class, array $rows): array
    {
        return $class === NNTPService::class ? [$rows, self::BUDGET] : [$rows];
    }

    /**
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, array $args): mixed
    {
        $call = $this->entry($class);
        $php = $class::withW55RustFfi(false, static fn (): mixed => $call(...$args));
        $native = $class::withW55RustFfi(true, static fn (): mixed => $call(...$args));
        self::assertSame($php, $native, $class.' Rust batch must match PHP');
        $method = self::ENTRIES[$class];
        self::assertSame($php, $class::{$method.'Php'}(...$args), $class);
        self::assertSame($php, $class::{$method.'Native'}(...$args), $class);

        return $php;
    }

    /** @return list<mixed> */
    private function fixture(string $class, Randomizer $random, bool $uncommon): array
    {
        $count = $random->getInt(64, 256);
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = match ($class) {
                RegexService::class => $this->counterSubject($random, $uncommon),
                ReleaseCleaningService::class => $this->cleaningSubject($random, $uncommon),
                ReleaseRemoverService::class => $this->releaseName($random, $uncommon),
                NNTPService::class => $this->messageId($random, $uncommon),
                default => $this->articleId($random, $uncommon),
            };
        }

        return $this->args($class, $rows);
    }

    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        $calls = [
            RegexService::class => ['nntmux_w551_regex_file_counter_batch', ["a [1/2]\0b", 9, 2]],
            ReleaseCleaningService::class => ['nntmux_w551_release_cleaning_mask_batch', ["a yEnc\0b", 8, 2]],
            ReleaseRemoverService::class => ['nntmux_w551_removal_pattern_mask_batch', ["a.exe\0b", 7, 2]],
            NNTPService::class => ['nntmux_w551_message_id_code_batch', ["<a@b>\0c", 7, 2, 512]],
            RustVerificationFramer::class => ['nntmux_w551_article_id_code_batch', ["a@b\0c d", 7, 2]],
        ];
        foreach ($calls as $class => [$symbol, $args]) {
            $buffer = (new ReflectionMethod($class, 'w55RustFfiState'))->invoke(null, 'out', 4);
            self::assertIsArray($buffer, $class.' must load its Wave 55 binding');
            $ffi = $buffer[0];
            self::assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[16]');
            self::assertSame(2, $this->invoke($ffi, $symbol, [...$args, $out, 16]), $symbol);
            self::assertSame(-2, $this->invoke($ffi, $symbol, [...$args, $out, 1]), $symbol.' short capacity');
            $miscounted = $args;
            $miscounted[2] = 3;
            self::assertSame(-1, $this->invoke($ffi, $symbol, [...$miscounted, $out, 16]), $symbol.' row count mismatch');
        }
    }

    /**
     * Calls an export declared by a class's W55_FFI_CDEF.
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
        $count = $random->getInt($min, $max);
        for ($i = 0; $i < $count; $i++) {
            $words[] = $this->pick($random, self::WORDS);
        }

        return implode($glue, $words);
    }

    private function releaseTitle(Randomizer $random): string
    {
        return $this->words($random, 2, 5).'.S0'.$random->getInt(1, 9).'E'.sprintf('%02d', $random->getInt(1, 24)).'.'.$this->pick($random, self::TAGS).'.'.$this->pick($random, self::TAGS).'-'.strtoupper($this->pick($random, self::WORDS));
    }

    private function counterSubject(Randomizer $random, bool $uncommon): string
    {
        $title = $this->releaseTitle($random);
        $total = $random->getInt(1, 999);
        $current = $random->getInt(1, $total);
        if (! $uncommon) {
            return match ($random->getInt(0, 3)) {
                0 => sprintf('%s [%02d/%02d] - "%s.%s" yEnc (1/%d)', $title, $current, $total, strtolower($title), $this->pick($random, self::EXTENSIONS), $random->getInt(1, 400)),
                1 => sprintf('[%d/%d] - "%s.%s" yEnc', $current, $total, $title, $this->pick($random, self::EXTENSIONS)),
                2 => sprintf('%s - File %d of %d - %s yEnc', $title, $current, $total, $this->pick($random, self::EXTENSIONS)),
                default => sprintf('(%s) [%d/%d] %s', $this->words($random, 1, 2, ' '), $current, $total, $title),
            };
        }

        return match ($random->getInt(0, 4)) {
            // No counter: the whole subject is scanned.
            0 => $title.' '.$this->words($random, 8, 20, ' ').' '.$random->getInt(100000, 9999999).' yEnc',
            1 => sprintf('%s [%d/%d %s', $title, $random->getInt(100000, 999999), $total, str_repeat($this->words($random, 1, 1).' ', $random->getInt(4, 12))),
            2 => sprintf('%s_%d_OF_%d_%s', $title, $current, $total, $this->words($random, 2, 6, '_')),
            3 => str_repeat(' 1', $random->getInt(10, 40)).' ['.$current.'-'.$total.':',
            default => $title.' '.str_repeat('[', $random->getInt(1, 5)).'x'.$current.'/'.$total.' '.$this->words($random, 3, 10, ' '),
        };
    }

    private function cleaningSubject(Randomizer $random, bool $uncommon): string
    {
        $title = $this->releaseTitle($random);
        $total = $random->getInt(1, 99);
        $quote = $random->getInt(0, 5) === 0 ? '#34;' : '"';
        if (! $uncommon) {
            return match ($random->getInt(0, 3)) {
                0 => sprintf('[%02d/%02d] - %s%s.%s%s yEnc', $random->getInt(1, $total), $total, $quote, $title, $this->pick($random, self::EXTENSIONS), $quote),
                1 => sprintf('%s - [%d/%d] - "%s.%s" - %d.%d MB - yEnc', $this->words($random, 1, 3, ' '), $random->getInt(1, $total), $total, $title, $this->pick($random, self::EXTENSIONS), $random->getInt(1, 999), $random->getInt(0, 99)),
                2 => $title.'.'.$this->pick($random, ['sample', 'proof', 'thumbs', 'nfo', 'mkv']),
                default => 'Release Name '.$title.' yEnc',
            };
        }

        return match ($random->getInt(0, 4)) {
            0 => sprintf('[%d / %d]   %s%s%s  lRap %s', $random->getInt(1, $total), $total, $quote, str_rot13($title), $quote, $this->words($random, 3, 8, '  ')),
            1 => $title."\t\t".$this->words($random, 5, 15, " \xc3\xa9 ").' yEnc'."\n",
            2 => '[1/'.$total.'] "ab'."\n".'cd" '.$this->words($random, 10, 25, ' '),
            3 => 'sample_'.$this->words($random, 6, 20, '-').'_THUMB',
            default => str_repeat($this->words($random, 3, 6, ' '), $random->getInt(2, 4)).' '.$quote.'x'.$quote,
        };
    }

    private function releaseName(Randomizer $random, bool $uncommon): string
    {
        $hex = bin2hex($random->getBytes($random->getInt(8, 24)));
        if (! $uncommon) {
            return match ($random->getInt(0, 5)) {
                0, 1 => $this->releaseTitle($random),
                2 => $hex,
                3 => $this->releaseTitle($random).'.'.$this->pick($random, ['mkv', 'nfo', 'rar', 'exe', 'nzb']),
                4 => $this->words($random, 1, 1),
                default => $this->words($random, 2, 4).'.Password.'.$this->pick($random, self::TAGS),
            };
        }

        return match ($random->getInt(0, 4)) {
            0 => $this->words($random, 10, 30).'.Sample.'.$hex.'.SCR "x',
            1 => 'Install.Bin.'.$this->words($random, 8, 20, ' ').' Password.url.Recovery',
            2 => str_repeat($hex, $random->getInt(2, 6)),
            3 => $this->words($random, 5, 15, '_').'.'.$this->pick($random, ['exe', 'EXE', 'nzb', 'scr$']).' no password',
            default => $this->words($random, 20, 40, '-').' passworded',
        };
    }

    private function messageId(Randomizer $random, bool $uncommon): string
    {
        $local = 'part'.$random->getInt(1, 999).'of'.$random->getInt(1, 999).'.'.bin2hex($random->getBytes(8));
        $host = $this->pick($random, ['news.example.com', 'powerpost2000AA.local', 'nyuu.example', 'ngPost.com']);
        if (! $uncommon) {
            return $random->getInt(0, 2) === 0 ? $local.'@'.$host : '<'.$local.'@'.$host.'>';
        }

        return match ($random->getInt(0, 4)) {
            0 => '<<<'.$local.' '.$host.'>>>',
            1 => '<'.str_repeat($local, $random->getInt(4, 12)).'@'.$host.'>',
            2 => $local."\x01@".$host.'>',
            3 => str_repeat('<', $random->getInt(1, 6)),
            default => '<'.$local.'<@'.$host,
        };
    }

    private function articleId(Randomizer $random, bool $uncommon): string
    {
        $local = 'part'.$random->getInt(1, 999).'of'.$random->getInt(1, 999).'.'.bin2hex($random->getBytes(8));
        $host = $this->pick($random, ['news.example.com', 'powerpost2000AA.local', 'nyuu.example', 'ngPost.com']);
        if (! $uncommon) {
            return $local.'@'.$host;
        }

        return match ($random->getInt(0, 5)) {
            0 => $local."\u{3000}@".$host,
            1 => 'caf'."\u{e9}".str_repeat($local, $random->getInt(2, 8)).'@'.$host,
            2 => $local."\xff\xfe@".$host,
            3 => '<'.$local.'@'.$host.'>',
            4 => $local."\t".$host,
            default => '',
        };
    }
}
