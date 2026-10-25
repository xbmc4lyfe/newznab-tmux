<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NNTP\NNTPService;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\NntpArticleSource;
use App\Services\Releases\Verification\NzbManifestReader;
use App\Services\Releases\Verification\OversizedCollectionDeferrals;
use App\Services\Releases\Verification\PayloadVerifier;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class RustVerificationManifestNntpReaderDeferralsPayloadW51ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        ManifestStore::class => 'planManifestImport',
        NntpArticleSource::class => 'planPrefetchIds',
        NzbManifestReader::class => 'summarizeSubjects',
        OversizedCollectionDeferrals::class => 'classifyDeferrals',
        PayloadVerifier::class => 'planRequiredNames',
    ];

    /** Class => Wave 51.2 export. */
    private const SYMBOLS = [
        ManifestStore::class => 'nntmux_w512_manifest_import_plan_batch',
        NntpArticleSource::class => 'nntmux_w512_article_prefetch_plan_batch',
        NzbManifestReader::class => 'nntmux_w512_nzb_subject_batch',
        OversizedCollectionDeferrals::class => 'nntmux_w512_oversized_deferral_batch',
        PayloadVerifier::class => 'nntmux_w512_payload_required_name_batch',
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns): the per-metric median of
     * three runs of test_paired_randomized_common_and_uncommon_benchmarks (seed 51, each metric
     * the median of 3 rounds of 1000 pairs after 50 warmups, randomized fixtures, alternating
     * call order, release Rust, PHP 8.5.12 CLI, Apple Silicon macOS on a shared host). Timings
     * include dispatch, packing, the FFI call and unpack('P*'). The runs' lows were
     * OversizedCollectionDeferrals Common p99 1.595x and ManifestStore Common p99 1.810x; an
     * earlier deferral layout passing three separate string columns measured 1.573x-1.615x on
     * Common p99, which the single 3n-entry column improved slightly. A class defaults to Rust
     * only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        ManifestStore::class => [2.629, 2.412, 3.300, 2.611],
        NntpArticleSource::class => [2.955, 2.590, 3.367, 4.375],
        NzbManifestReader::class => [4.232, 3.152, 3.292, 2.885],
        OversizedCollectionDeferrals::class => [2.376, 1.623, 2.425, 1.899],
        PayloadVerifier::class => [3.814, 2.907, 3.479, 2.925],
    ];

    private const ROUNDS = 3;

    private const PAIRS = 1000;

    private const WARMUPS = 50;

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW51RustFfi', 'withW51RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
                self::assertTrue($reflection->getMethod($method)->isPublic() && $reflection->getMethod($method)->isStatic(), $class.'::'.$method);
            }
            self::assertTrue($reflection->hasMethod('w51RustFfiState'), $class);
            self::assertTrue($reflection->getMethod('w51RustFfiState')->isPrivate(), $class);
            self::assertTrue($reflection->hasConstant('W51_RUST_FFI_DEFAULT'), $class);
            self::assertIsBool($reflection->getConstant('W51_RUST_FFI_DEFAULT'), $class);
            self::assertTrue($reflection->getReflectionConstant('W51_RUST_FFI_DEFAULT')?->isPrivate(), $class);
            self::assertStringContainsString(self::SYMBOLS[$class], (string) $reflection->getConstant('W51_FFI_CDEF'), $class);
            foreach (array_keys($reflection->getStaticProperties()) as $property) {
                self::assertStringNotContainsString('w51', strtolower((string) $property), 'Wave 51 state lives in w51RustFfiState().');
            }
        }
    }

    public function test_defaults_match_recorded_qualification(): void
    {
        foreach (self::QUALIFICATION as $class => $speedups) {
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->default($class), $class);
            self::assertSame($this->default($class), $class::usesW51RustFfi(), $class);
        }
    }

    public function test_with_toggle_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->default($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW51RustFfi($enabled, static function () use ($class, $enabled): array {
                    $inner = $class::withW51RustFfi(! $enabled, static fn (): bool => $class::usesW51RustFfi());

                    return [$class::usesW51RustFfi(), $inner];
                });
                self::assertSame([$enabled, ! $enabled], $seen, $class);
                self::assertSame($default, $class::usesW51RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW51RustFfi(! $default, static fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown, 'The callback exception must propagate.');
            self::assertSame($default, $class::usesW51RustFfi(), $class);
        }
        // The Wave 51 toggles leave the earlier Rust switches untouched.
        self::assertFalse(NntpArticleSource::usesRustFfi());
        self::assertFalse(ManifestStore::usesW396RustFfi());
    }

    public function test_entry_points_route_through_toggle(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(5100));
        foreach (array_keys(self::ENTRIES) as $class) {
            $args = $this->fixture($class, $random, false);
            $php = $this->call($class, 'Php', $args);
            foreach ([true, false] as $native) {
                self::assertSame($php, $class::withW51RustFfi($native, fn (): array => $this->call($class, '', $args)), $class);
            }
            self::assertSame($php, $this->call($class, 'Native', $args), $class);
        }
    }

    public function test_exports_guard_null_short_and_malformed_batches(): void
    {
        $this->requireBindings();
        $ffi = $this->ffi(PayloadVerifier::class);
        $out = $ffi->new('int64_t[16]');
        $symbol = self::SYMBOLS[PayloadVerifier::class];
        self::assertSame(-1, $this->invoke($ffi, $symbol, ['"a"', 3, 1, null, 16]));
        self::assertSame(-7, $this->invoke($ffi, $symbol, ['"a"', 3, 1, $out, 6]));
        self::assertSame(-1, $this->invoke($ffi, $symbol, ["a\0b", 3, 1, $out, 16]));
        self::assertSame(-1, $this->invoke($ffi, $symbol, [null, 1, 1, $out, 16]));
        self::assertSame(5, $this->invoke($ffi, $symbol, [null, 0, 0, $out, 16]));

        $manifest = $this->ffi(ManifestStore::class);
        $manifestOut = $manifest->new('int64_t[16]');
        $manifestSymbol = self::SYMBOLS[ManifestStore::class];
        self::assertSame(7, $this->invoke($manifest, $manifestSymbol, ['a', 1, pack('P', 1), 8, 1, 10, $manifestOut, 16]));
        self::assertSame(-1, $this->invoke($manifest, $manifestSymbol, ['a', 1, pack('P', 1), 4, 1, 10, $manifestOut, 16]));
        self::assertSame(-1, $this->invoke($manifest, $manifestSymbol, ['a', 1, null, 8, 1, 10, $manifestOut, 16]));

        $source = $this->ffi(NntpArticleSource::class);
        $sourceOut = $source->new('int64_t[16]');
        $sourceSymbol = self::SYMBOLS[NntpArticleSource::class];
        self::assertSame(6, $this->invoke($source, $sourceSymbol, [null, 0, 0, 1, 1, $sourceOut, 16]));
        self::assertSame(-7, $this->invoke($source, $sourceSymbol, ['a', 1, 1, 1, 1, $sourceOut, 3]));
        self::assertSame(-1, $this->invoke($source, $sourceSymbol, ['a', 1, 2, 1, 1, $sourceOut, 16]));

        $reader = $this->ffi(NzbManifestReader::class);
        $readerOut = $reader->new('int64_t[16]');
        $readerSymbol = self::SYMBOLS[NzbManifestReader::class];
        self::assertSame(7, $this->invoke($reader, $readerSymbol, ['a (1/2)', 7, 1, $readerOut, 16]));
        self::assertSame(-1, $this->invoke($reader, $readerSymbol, ['a (1/2)', 7, 1, null, 16]));

        $deferrals = $this->ffi(OversizedCollectionDeferrals::class);
        $deferralsOut = $deferrals->new('int64_t[16]');
        $deferralsSymbol = self::SYMBOLS[OversizedCollectionDeferrals::class];
        $table = pack('P*', 1, 10, 0);
        self::assertSame(7, $this->invoke($deferrals, $deferralsSymbol, [$table, 24, "h\0h\0z", 5, 1, 10, 'm', 1, $deferralsOut, 16]));
        self::assertSame(-1, $this->invoke($deferrals, $deferralsSymbol, [$table, 16, "h\0h\0z", 5, 1, 10, 'm', 1, $deferralsOut, 16]));
        self::assertSame(-1, $this->invoke($deferrals, $deferralsSymbol, [$table, 24, "h\0h\0z", 5, 1, 10, null, 1, $deferralsOut, 16]));
        self::assertSame(-1, $this->invoke($deferrals, $deferralsSymbol, [$table, 24, "h\0h", 3, 1, 10, 'm', 1, $deferralsOut, 16]));
        self::assertSame(-7, $this->invoke($deferrals, $deferralsSymbol, [$table, 24, "h\0h\0z", 5, 1, 10, 'm', 1, $deferralsOut, 2]));
    }

    public function test_manifest_import_plan_parity(): void
    {
        $this->requireBindings();
        [$a, $b, $c] = [hash('sha256', 'a').'.json.gz', hash('sha256', 'b').'.json.gz', hash('sha256', 'c').'.json.gz'];
        $words = [
            9 | 72 << 8 | crc32($a) << 32,
            5 | 72 << 8 | crc32($b) << 32,
            3 | 72 << 8 | crc32($a) << 32,
            3 << 8 | crc32('bad') << 32,
            17 | 72 << 8 | crc32($c) << 32,
            4, 1, 1, 10, 4,
        ];
        $args = [[$a, $b, $a, 'bad', $c], [10, 0, 5, 7, 20], 25];
        self::assertSame([...$words, $this->digest($words)], ManifestStore::planManifestImportPhp(...$args));
        $this->assertParity(ManifestStore::class, $args);
        foreach ($this->edgeCases() as [$class, $edge]) {
            if ($class === ManifestStore::class) {
                $this->assertParity($class, $edge);
            }
        }
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $args = $this->manifestBatch(new Randomizer(new Mt19937(5110 + $i)), $uncommon);
                $this->assertParity(ManifestStore::class, $args);
                $words = ManifestStore::planManifestImportPhp(...$args);
                foreach ($args[0] as $row => $name) {
                    self::assertSame(preg_match('/\A[0-9a-f]{64}\.json\.gz\z/', $name) === 1, ($words[$row] & 1) === 1);
                }
            }
        }
    }

    public function test_article_prefetch_plan_parity(): void
    {
        $this->requireBindings();
        $ids = ['<a@x>', 'b@x', 'a@x', '<>', 'c d', 'c@x', 'd@x'];
        $words = [
            19 | 3 << 8 | crc32('a@x') << 32,
            17 | 3 << 8 | crc32('b@x') << 32,
            5 | 3 << 8 | crc32('a@x') << 32,
            10,
            3 << 8 | crc32('c d') << 32,
            17 | 3 << 8 | 1 << 24 | crc32('c@x') << 32,
            17 | 3 << 8 | 1 << 24 | crc32('d@x') << 32,
            5, 4, 3, 2, 3,
        ];
        self::assertSame([...$words, $this->digest($words)], NntpArticleSource::planPrefetchIdsPhp($ids, 100, 3));
        $this->assertParity(NntpArticleSource::class, [$ids, 100, 3]);
        foreach ($this->edgeCases() as [$class, $edge]) {
            if ($class === NntpArticleSource::class) {
                $this->assertParity($class, $edge);
            }
        }
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $args = $this->prefetchBatch(new Randomizer(new Mt19937(5120 + $i)), $uncommon);
                $this->assertParity(NntpArticleSource::class, $args);
                $words = NntpArticleSource::planPrefetchIdsPhp(...$args);
                foreach ($args[0] as $row => $id) {
                    $valid = true;
                    try {
                        NNTPService::verificationMessageId($id, $args[1]);
                    } catch (RuntimeException) {
                        $valid = false;
                    }
                    self::assertSame($valid, ($words[$row] & 1) === 1, $id);
                }
            }
        }
    }

    public function test_nzb_subject_parity(): void
    {
        $this->requireBindings();
        $subject = '[1/3] "a.rar" yEnc (1/50)';
        $words = [3, 50, 18 << 1 | crc32('[1/3] "a.rar" yEnc') << 32, 0, -1, 10 << 1 | crc32('no counter') << 32, 0, -1, 1 | 10 << 1 | crc32('no counter') << 32, 3, 1, 1];
        self::assertSame([...$words, $this->digest($words)], NzbManifestReader::summarizeSubjectsPhp([$subject, 'no counter', 'no counter']));
        $this->assertParity(NzbManifestReader::class, [[$subject, 'no counter', 'no counter']]);
        foreach ($this->edgeCases() as [$class, $edge]) {
            if ($class === NzbManifestReader::class) {
                $this->assertParity($class, $edge);
            }
        }
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $args = $this->subjectBatch(new Randomizer(new Mt19937(5130 + $i)), $uncommon);
                $this->assertParity(NzbManifestReader::class, $args);
                $words = NzbManifestReader::summarizeSubjectsPhp(...$args);
                foreach ($args[0] as $row => $subject) {
                    self::assertSame(NzbManifestReader::declaredFileCount($subject), $words[3 * $row]);
                }
            }
        }
    }

    public function test_oversized_deferral_parity(): void
    {
        $this->requireBindings();
        $args = [[1, 2, 3, 4, 1], ['h1', 'h2', '', 'h4', 'h1'], ['h1', 'hx', '', 'h4', 'h1'], [100, 100, 100, 50, 100], [2, 0, 0, 99, -4],
            ['2026-02', '2026-02', '2026-02', '2025-12', '2026-02'], 100, '2026-01'];
        $words = [
            15 | 128 | crc32('h1') << 32, 86_400,
            38 | crc32('hx') << 32, 21_600,
            38, 21_600,
            17 | crc32('h4') << 32, 21_600,
            15 | 64 | 128 | crc32('h1') << 32, 21_600,
            2, 1, 2, 1,
        ];
        self::assertSame([...$words, $this->digest($words)], OversizedCollectionDeferrals::classifyDeferralsPhp(...$args));
        $this->assertParity(OversizedCollectionDeferrals::class, $args);
        foreach ($this->edgeCases() as [$class, $edge]) {
            if ($class === OversizedCollectionDeferrals::class) {
                $this->assertParity($class, $edge);
            }
        }
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $args = $this->deferralBatch(new Randomizer(new Mt19937(5140 + $i)), $uncommon);
                $this->assertParity(OversizedCollectionDeferrals::class, $args);
                $words = OversizedCollectionDeferrals::classifyDeferralsPhp(...$args);
                foreach ($args[0] as $row => $id) {
                    // lookup()'s deferral rule, with strcmp for the bytewise time comparison.
                    $deferred = $args[1][$row] !== '' && $args[1][$row] === $args[2][$row] && $args[3][$row] >= $args[6] && strcmp($args[5][$row], $args[7]) > 0;
                    self::assertSame($deferred, ($words[2 * $row] & 8) === 8, (string) $id);
                }
            }
        }
    }

    public function test_payload_required_name_parity(): void
    {
        $this->requireBindings();
        $subjects = ['"a.PAR2" yEnc', 'x [1/2] yEnc', 'a.PAR2', "\"\xff.bin\""];
        $words = [
            1 | 2 | 4 | 16 | 6 << 8 | crc32('a.PAR2') << 32, 1,
            0, -1,
            2 | 4 | 8 | 16 | 6 << 8 | crc32('a.PAR2') << 32, 0,
            1 | 2 | 5 << 8 | crc32("\xff.bin") << 32, 1,
            3, 2, 1, 2,
        ];
        self::assertSame([...$words, $this->digest($words)], PayloadVerifier::planRequiredNamesPhp($subjects));
        $this->assertParity(PayloadVerifier::class, [$subjects]);
        foreach ($this->edgeCases() as [$class, $edge]) {
            if ($class === PayloadVerifier::class) {
                $this->assertParity($class, $edge);
            }
        }
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $args = $this->requiredNameBatch(new Randomizer(new Mt19937(5150 + $i)), $uncommon);
                $this->assertParity(PayloadVerifier::class, $args);
                $words = PayloadVerifier::planRequiredNamesPhp(...$args);
                foreach ($args[0] as $row => $subject) {
                    $name = PayloadVerifier::requiredNamePhp($subject);
                    self::assertSame($name !== null, ($words[2 * $row] & 2) === 2, $subject);
                    if ($name !== null) {
                        self::assertSame($name, substr($subject, $words[2 * $row + 1], \strlen($name)));
                    }
                }
            }
        }
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

    /** @return iterable<string, array{class-string, list<mixed>}> */
    public static function edgeCases(): iterable
    {
        $name = hash('sha256', 'manifest').'.json.gz';
        yield 'manifest empty' => [ManifestStore::class, [[], [], 10]];
        yield 'manifest mismatched sizes' => [ManifestStore::class, [[$name, $name], [5], 10]];
        yield 'manifest nul name' => [ManifestStore::class, [["a\0b", $name], [1, 2], 10]];
        yield 'manifest bounds' => [ManifestStore::class, [[$name, strtoupper($name), substr($name, 0, 71), $name.'x', '', '../'.$name, $name], [PHP_INT_MAX, 1, -1, 0, PHP_INT_MIN, 5, 1], PHP_INT_MAX]];
        yield 'manifest negative budget' => [ManifestStore::class, [[$name, hash('sha256', 'x').'.json.gz'], [1, 1], PHP_INT_MIN]];
        yield 'manifest numeric names' => [ManifestStore::class, [['1', '01', '1', '-0', '-1', '1.0'], [1, 1, 1, 1, 1, 1], 3]];
        yield 'prefetch empty' => [NntpArticleSource::class, [[], 1, 1]];
        yield 'prefetch nul id' => [NntpArticleSource::class, [["a\0b", 'c'], 1, 1]];
        yield 'prefetch budget' => [NntpArticleSource::class, [['<a@b>', 'a@b'], 0, 4]];
        yield 'prefetch bytes' => [NntpArticleSource::class, [['<<>>', '<a<b>', "a\x7f", "a\tb", "\xff\xfe@x", '>a@b<', ' a', 'a ', "a\x1f", '<'.str_repeat('m', 70000).'>'], PHP_INT_MAX, PHP_INT_MIN]];
        yield 'prefetch connections' => [NntpArticleSource::class, [array_map(static fn (int $i): string => "<p{$i}@x>", range(1, 37)), 1, PHP_INT_MAX]];
        yield 'prefetch numeric ids' => [NntpArticleSource::class, [['1', '<1>', '01', '-1'], 5, 2]];
        yield 'subject empty' => [NzbManifestReader::class, [[]]];
        yield 'subject nul' => [NzbManifestReader::class, [["a\0 (1/2)"]]];
        yield 'subject counters' => [NzbManifestReader::class, [['', ' ', '(1/5)', 'a(1/5)', "a (1/5)\n", "a (1/5)\x0b\x0c\r\t ", 'a (1/5) x', 'a ( 1/5)', 'a (/5)', 'a (1/)', 'a ((1/5)', 'a (1/(2/3)', 'a [1/5]']]];
        yield 'subject declared' => [NzbManifestReader::class, [['[01/12] "x [1/99].rar" yEnc (1/5)', 'x [3/2] (4/7)', 'x(1/3) [2/4]', '"open [1/9]', "'a\n' [1/6]", '[0/0] [1/0] [0/3]', 'a [1/2)  b (3/4]', "x [1/7]\n", '"a" "b [1/4]" [2/8] (1/1)', "[1/3]\r[2/5]", "'' [1/2]"]]];
        yield 'subject huge numbers' => [NzbManifestReader::class, [['[1/'.str_repeat('9', 30).']', '[1/'.str_repeat('9', 400).'] (1/'.str_repeat('9', 400).')', '['.str_repeat('9', 25).'/5] (2/'.str_repeat('9', 25).')', '[1/'.str_repeat('9', 308).']']]];
        yield 'subject repeated' => [NzbManifestReader::class, [['1', '1', '01', '1.0', "\xff (1/2)", "\xff (1/2)"]]];
        yield 'deferral empty' => [OversizedCollectionDeferrals::class, [[], [], [], [], [], [], 0, '']];
        yield 'deferral mismatched' => [OversizedCollectionDeferrals::class, [[1, 2], ['a'], ['a', 'b'], [1, 1], [1, 1], ['x', 'y'], 1, 'm']];
        yield 'deferral nul' => [OversizedCollectionDeferrals::class, [[1], ["a\0"], ["a\0"], [1], [1], ['x'], 1, 'm']];
        yield 'deferral nul now' => [OversizedCollectionDeferrals::class, [[1], ['a'], ['a'], [1], [1], ['x'], 1, "m\0"]];
        yield 'deferral bounds' => [OversizedCollectionDeferrals::class, [[PHP_INT_MIN, PHP_INT_MAX, 0, -1, 0], ['h', 'h', '', 'H', 'h'], ['h', 'h', '', 'h', 'h'], [PHP_INT_MAX, PHP_INT_MIN, 0, 5, 5],
            [PHP_INT_MAX, PHP_INT_MIN, 10, 11, 3], ['', "\xff", '10', '9', '2026-01-01 00:00:00'], 5, '2026-01-01 00:00:00']];
        yield 'deferral numeric times' => [OversizedCollectionDeferrals::class, [[1, 2, 3], ['a', 'a', 'a'], ['a', 'a', 'a'], [1, 1, 1], [1, 2, 3], ['10', '9', '010'], 1, '9']];
        yield 'names empty' => [PayloadVerifier::class, [[]]];
        yield 'names nul' => [PayloadVerifier::class, [["\"a\0\""]]];
        yield 'names quoting' => [PayloadVerifier::class, [['', '""', '"" "x"', "\"a\nb\" \".x\"", '"a', 'a"', '"a"b"c"', "\"a\rb\"", '" "', '"\\a"', '"a/b"', '"a/"', '"/"', '".x"', '"-x"', '"[x]"', '"a yenc b"', '"YENC"']]];
        yield 'names bytes' => [PayloadVerifier::class, [["\xff.bin", "\xc3\xa9.par2", "\xed\xa0\x80.par2", "\xf4\x90\x80\x80.par2", "a\x7f", "a\x1fb", 'a.PaR2', '.par2', 'x.par', str_repeat('n', 200), str_repeat('n', 201), '"'.str_repeat('n', 70000).'"']]];
        yield 'names repeated' => [PayloadVerifier::class, [['"1" yEnc', '1', '01', '"1"', 'a.par2', '"a.par2"']]];
    }

    /**
     * Paired randomized benchmark: per class and workload, each pair times the PHP and Rust
     * batch entry point on one randomly chosen fixture, alternating which runs first. Common
     * batches hold 64 typical records, Uncommon batches 128-256 long, malformed or adversarial
     * ones. Each of ROUNDS rounds times PAIRS pairs after WARMUPS warmups; a metric is the median
     * of its round values (nearest-rank p99 per round). Every pair must agree; raw pairs are
     * written outside the checkout. A class must default to Rust exactly when all four metrics
     * reach 1.5x.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(51));
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
                            [$results[(int) $native], $elapsed[(int) $native]] = $class::withW51RustFfi($native, fn (): array => $this->timed($class, $args));
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
        $artifact = sys_get_temp_dir().'/w512-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS, 'seed' => 51, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW51.2 paired benchmark artifact: {$artifact}\n".json_encode(array_map(static fn (array $class): array => array_map(static fn (mixed $w): mixed => \is_array($w) ? array_diff_key($w, ['rounds' => true]) : $w, $class), $summary), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::ENTRIES) as $class) {
            self::assertSame($summary[$class]['qualifies'], $class::usesW51RustFfi(), $class.' default must follow the measured 1.5x gate');
        }
    }

    // ---- helpers -----------------------------------------------------------------------------

    /**
     * One timed call of the class's batch entry point.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     * @return array{list<int>, int}
     */
    private function timed(string $class, array $args): array
    {
        $start = hrtime(true);
        $result = $this->call($class, '', $args);

        return [$result, hrtime(true) - $start];
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
        $value = (new ReflectionClass($class))->getConstant('W51_RUST_FFI_DEFAULT');
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
        $php = $class::withW51RustFfi(false, fn (): array => $this->call($class, '', $args));
        $rust = $class::withW51RustFfi(true, fn (): array => $this->call($class, '', $args));
        self::assertSame($php, $rust, $class);
        self::assertSame($php, $this->call($class, 'Php', $args), $class);
        self::assertSame($php, $this->call($class, 'Native', $args), $class);
        self::assertSame($this->digest(\array_slice($php, 0, -1)), $php[\count($php) - 1], $class);
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function ffi(string $class): FFI
    {
        $ffi = (new ReflectionMethod($class, 'w51RustFfiState'))->invoke(null, 'ffi');
        self::assertInstanceOf(FFI::class, $ffi, $class.' must load the nfo_w51 binding');

        return $ffi;
    }

    /** Each Wave 51.2 export must resolve and accept a one-item batch, so parity runs Rust. */
    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        $calls = [
            ManifestStore::class => ['a', 1, pack('P', 1), 8, 1, 10],
            NntpArticleSource::class => ['a', 1, 1, 1, 1],
            NzbManifestReader::class => ['a', 1, 1],
            OversizedCollectionDeferrals::class => [pack('P*', 1, 1, 1), 24, "h\0h\0z", 5, 1, 1, 'm', 1],
            PayloadVerifier::class => ['a', 1, 1],
        ];
        $words = [
            ManifestStore::class => 7,
            NntpArticleSource::class => 7,
            NzbManifestReader::class => 7,
            OversizedCollectionDeferrals::class => 7,
            PayloadVerifier::class => 7,
        ];
        foreach ($calls as $class => $args) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[16]');
            self::assertSame($words[$class], $this->invoke($ffi, self::SYMBOLS[$class], [...$args, $out, 16]), $class);
        }
    }

    /**
     * Calls an export declared by a class's W51_FFI_CDEF.
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
            ManifestStore::class => $this->manifestBatch($random, $uncommon),
            NntpArticleSource::class => $this->prefetchBatch($random, $uncommon),
            NzbManifestReader::class => $this->subjectBatch($random, $uncommon),
            OversizedCollectionDeferrals::class => $this->deferralBatch($random, $uncommon),
            PayloadVerifier::class => $this->requiredNameBatch($random, $uncommon),
        };
    }

    /** @param  list<string>  $values */
    private function pick(Randomizer $random, array $values): string
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
            : $random->getBytesFromString('abcdefghijklmnopqrstuvwxyz0123456789.-_:/ "\'()[]<>@', $length);
    }

    /** A release file name as uploaders post it. */
    private function fileName(Randomizer $random): string
    {
        $stem = $this->pick($random, ['Some.Show.S01E02.1080p.WEB.h264-GRP', 'Linux.Distro.2026.x64.ISO', 'Album-Artist-2026-FLAC', 'Movie.2026.2160p.UHD.BluRay.x265']);

        return $stem.$this->pick($random, ['.part'.str_pad((string) $random->getInt(1, 99), 2, '0', STR_PAD_LEFT).'.rar', '.vol'.$random->getInt(0, 63).'+'.$random->getInt(1, 64).'.par2', '.par2', '.nfo', '.sfv', '.7z.'.str_pad((string) $random->getInt(1, 999), 3, '0', STR_PAD_LEFT)]);
    }

    /** A posted file subject: `[nn/total] - "name" yEnc (part/parts)` and close variants. */
    private function subject(Randomizer $random): string
    {
        $total = $random->getInt(1, 120);
        $index = $random->getInt(1, $total);
        $parts = $random->getInt(1, 400);

        return match ($random->getInt(0, 3)) {
            0 => "[{$index}/{$total}] - \"{$this->fileName($random)}\" yEnc ({$random->getInt(1, $parts)}/{$parts})",
            1 => "Group.Post [{$index}/{$total}] \"{$this->fileName($random)}\" - ".$random->getInt(1, 9_999_999)." bytes yEnc ({$random->getInt(1, $parts)}/{$parts})",
            2 => "({$index}/{$total}) \"{$this->fileName($random)}\" yEnc ({$random->getInt(1, $parts)}/{$parts}) ",
            default => "{$this->fileName($random)} yEnc ({$random->getInt(1, $parts)}/{$parts})",
        };
    }

    /** @return list<mixed> */
    private function manifestBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : 64;
        $names = $sizes = [];
        for ($i = 0; $i < $count; $i++) {
            $name = hash('sha256', (string) $random->getInt(0, PHP_INT_MAX)).'.json.gz';
            $names[] = $random->getInt(0, 15) === 0 ? ($names[$random->getInt(0, max(0, $i - 1))] ?? $name) : (! $uncommon ? $name : match ($random->getInt(0, 7)) {
                0 => strtoupper($name),
                1 => substr($name, 0, $random->getInt(0, 71)),
                2 => $name.$this->pick($random, ['x', '.tmp', "\n"]),
                3 => $this->noise($random, 90, true),
                4 => '../'.$name,
                default => $name,
            });
            $sizes[] = ! $uncommon ? $random->getInt(200, 400_000) : match ($random->getInt(0, 7)) {
                0 => 0,
                1 => $random->getInt(PHP_INT_MIN, -1),
                2 => $random->getInt(PHP_INT_MAX - 1000, PHP_INT_MAX),
                default => $random->getInt(1, 4_000_000),
            };
        }
        $budget = $uncommon ? (int) $this->pick($random, ['0', '-1', (string) PHP_INT_MIN, (string) PHP_INT_MAX, '67108864', (string) $random->getInt(1, 10_000_000)])
            : (int) $this->pick($random, ['67108864', '8388608', (string) $random->getInt(1_000_000, 20_000_000)]);

        return [$names, $sizes, $budget];
    }

    /** @return list<mixed> */
    private function prefetchBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : 64;
        $host = $this->pick($random, ['news.example.com', 'powerpost2000AA.local', 'ngPost.com', 'JBinUp.local']);
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $id = 'part'.$random->getInt(1, 400).'of'.$random->getInt(1, 400).'.'.bin2hex($random->getBytes(8)).'@'.$host;
            $id = $random->getInt(0, 1) === 0 ? '<'.$id.'>' : $id;
            $ids[] = $random->getInt(0, 15) === 0 ? ($ids[$random->getInt(0, max(0, $i - 1))] ?? $id) : (! $uncommon ? $id : match ($random->getInt(0, 9)) {
                0 => $this->pick($random, ['', '<>', '<<>>', '<', '>', ' ', '<a b>', "<a\tb>", "a\x7f@b", '<a<b@c>']),
                1 => str_replace('@', ' @', $id),
                2 => $this->noise($random, 80, true),
                3 => '<'.str_repeat('m', $random->getInt(500, 2000)).'@'.$host.'>',
                4 => '<<<'.trim($id, '<>').'>>>',
                default => $id,
            });
        }
        $maximum = $uncommon ? (int) $this->pick($random, ['0', '-1', '1', (string) PHP_INT_MAX, '1048576']) : (int) $this->pick($random, ['1048576', '2097152', '786432']);
        $connections = $uncommon ? (int) $this->pick($random, ['0', '-3', '1', '9', '64', (string) PHP_INT_MAX, (string) PHP_INT_MIN]) : $random->getInt(1, 8);

        return [$ids, $maximum, $connections];
    }

    /** @return list<mixed> */
    private function subjectBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : 64;
        $subjects = [];
        for ($i = 0; $i < $count; $i++) {
            $subject = $this->subject($random);
            $subjects[] = $random->getInt(0, 15) === 0 ? ($subjects[$random->getInt(0, max(0, $i - 1))] ?? $subject) : (! $uncommon ? $subject : match ($random->getInt(0, 11)) {
                0 => $this->noise($random, 160),
                1 => $this->noise($random, 120, true),
                2 => str_replace('"', $this->pick($random, ["'", "\n", '', '""']), $subject),
                3 => $subject.$this->pick($random, ["\n", "\r\n", "\x0b\x0c", ' x', ')', ' (1/)', ' (/2)', ' (01/02)']),
                4 => '['.$random->getInt(0, 3).'/'.$random->getInt(0, 3).'] ['.str_repeat('9', $random->getInt(18, 25)).'/'.str_repeat('9', $random->getInt(18, 25)).'] '.$subject,
                5 => $subject.' (1/'.str_repeat('9', $random->getInt(18, 320)).')',
                6 => "'{$this->fileName($random)} [1/4]' [2/3] (".$random->getInt(1, 9).'/9)',
                7 => '(x) [1/2]x [3/4]( [5/6] '.$subject,
                default => $subject,
            });
        }

        return [$subjects];
    }

    /** @return list<mixed> */
    private function deferralBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : 64;
        $base = 1_790_000_000 + $random->getInt(0, 1_000_000);
        $now = date('Y-m-d H:i:s', $base);
        $budget = (int) $this->pick($random, ['268435456', '134217728', '536870912']);
        $firstId = $random->getInt(1, 50_000_000);
        $ids = $current = $recorded = $budgets = $attempts = $retry = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $random->getInt(0, 15) === 0 ? $firstId + $random->getInt(0, max(0, $i - 1)) : $firstId + $i;
            $hash = sha1((string) $random->getInt(0, PHP_INT_MAX));
            $recorded[] = $hash;
            $current[] = match ($random->getInt(0, 7)) {
                0 => '',
                1 => sha1($hash),
                default => $hash,
            };
            $budgets[] = $this->pick($random, [(string) $budget, (string) $budget, (string) intdiv($budget, 2), (string) ($budget * 2)]) === (string) $budget ? $budget : (int) $this->pick($random, [(string) intdiv($budget, 2), (string) ($budget * 2)]);
            $attempts[] = $random->getInt(1, 6);
            $retry[] = date('Y-m-d H:i:s', $base + $random->getInt(-86_400, 604_800));
            if ($uncommon) {
                $slot = $i;
                match ($random->getInt(0, 11)) {
                    0 => $attempts[$slot] = $random->getInt(PHP_INT_MIN, 0),
                    1 => $attempts[$slot] = $random->getInt(11, PHP_INT_MAX),
                    2 => $budgets[$slot] = (int) $this->pick($random, [(string) PHP_INT_MIN, (string) PHP_INT_MAX, '0', '-1']),
                    3 => $retry[$slot] = $this->pick($random, ['', '0', '9999-12-31 23:59:59', $now, "\xff"]),
                    4 => $recorded[$slot] = $current[$slot] = $this->noise($random, 60, true),
                    5 => $recorded[$slot] = strtoupper($recorded[$slot]),
                    6 => $ids[$slot] = (int) $this->pick($random, ['0', '-1', (string) PHP_INT_MAX, (string) PHP_INT_MIN]),
                    default => null,
                };
            }
        }
        if ($uncommon && $random->getInt(0, 3) === 0) {
            $now = $this->pick($random, ['', '0', "\xff", '2026']);
            $budget = (int) $this->pick($random, ['0', '-5', (string) PHP_INT_MAX, (string) PHP_INT_MIN]);
        }

        return [$ids, $current, $recorded, $budgets, $attempts, $retry, $budget, $now];
    }

    /** @return list<mixed> */
    private function requiredNameBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : 64;
        $subjects = [];
        for ($i = 0; $i < $count; $i++) {
            $subject = $this->subject($random);
            $subjects[] = $random->getInt(0, 15) === 0 ? ($subjects[$random->getInt(0, max(0, $i - 1))] ?? $subject) : (! $uncommon ? $subject : match ($random->getInt(0, 11)) {
                0 => $this->noise($random, 160),
                1 => $this->noise($random, 120, true),
                2 => '"'.$this->pick($random, ['.hidden', '-flag', 'a/b.rar', 'a\\b.rar', '[x].rar', 'yEnc.bin', 'x.YENC', "a\x7f", "a\x1fb", "\xff\xfe.par2", "\xc3\xa9t\xc3\xa9.PAR2", ' ']).'" yEnc (1/2)',
                3 => '"'.str_repeat('n', $random->getInt(150, 260)).'.par2" yEnc',
                4 => str_replace('"', $this->pick($random, ["'", "\n", '', '""']), $subject),
                5 => '"a'.$this->pick($random, ["\r", "\n"]).'b" "'.$this->fileName($random).'"',
                6 => $this->fileName($random),
                default => $subject,
            });
        }

        return [$subjects];
    }
}
