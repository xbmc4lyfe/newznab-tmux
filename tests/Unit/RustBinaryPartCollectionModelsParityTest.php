<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Binary;
use App\Models\BinaryBlacklist;
use App\Models\Collection;
use App\Models\MissedPart;
use App\Models\Part;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use Tests\TestCase;

final class RustBinaryPartCollectionModelsParityTest extends TestCase
{
    /** @var list<class-string> */
    private const MODELS = [
        Binary::class,
        BinaryBlacklist::class,
        Collection::class,
        MissedPart::class,
        Part::class,
    ];

    /** Strings that exercise trimming, collapsing, brackets, UTF-8 and wire-marker fallbacks. */
    private const TEXTS = [
        '',
        ' ',
        "\0\x0B\x0C\t\r\n",
        'alt.binaries.teevee',
        '  ALT.Binaries.MOVIES  ',
        '^(?!.*\.nfo).*$',
        '/sample/i',
        'My  Post  [01/10] - "file.part01.rar" yEnc (1/50)',
        "Tab\tand\nnewline  subject",
        'Poster <poster@example.com>',
        '<part1of50.abc@news.example>',
        ' < spaced@id > ',
        '<>',
        '<',
        '>',
        '<<double@id>>',
        'Ünïcödé Pöster',
        "\u{00A0}nbsp\u{00A0}",
        '進撃の巨人',
        "emoji \u{1F3B5} subject",
        "bad \xff utf8",
        "\xc3",
        "sep\x1Finside",
        "null\x1Emark",
        "\x1E",
        "nul\0byte",
    ];

    /** Integers that exercise clamps, zero divisors and 64-bit limits. */
    private const INTS = [
        PHP_INT_MIN,
        -1000,
        -1,
        0,
        1,
        2,
        3,
        4,
        5,
        7,
        50,
        254,
        255,
        256,
        10_000,
        4294967295,
        4294967296,
        PHP_INT_MAX,
    ];

    protected function tearDown(): void
    {
        foreach (self::MODELS as $model) {
            $model::setUseRustFfi(null);
        }
        parent::tearDown();
    }

    public function test_rust_ffi_is_disabled_by_default_and_toggles(): void
    {
        foreach (self::MODELS as $model) {
            $this->assertFalse($model::usesRustFfi(), $model);
            $model::setUseRustFfi(true);
            $this->assertTrue($model::usesRustFfi(), $model);
            $model::setUseRustFfi(false);
            $this->assertFalse($model::usesRustFfi(), $model);
            $model::setUseRustFfi(null);
            $this->assertFalse($model::usesRustFfi(), $model);
        }
    }

    public function test_eloquent_model_configuration_is_preserved(): void
    {
        $binary = new Binary;
        $this->assertSame('binaries', $binary->getTable());
        $this->assertFalse($binary->timestamps);

        $blacklist = new BinaryBlacklist;
        $this->assertSame('binaryblacklist', $blacklist->getTable());
        $this->assertFalse($blacklist->timestamps);
        $this->assertSame([], $blacklist->getGuarded());

        $collection = new Collection;
        $this->assertSame('collections', $collection->getTable());
        $this->assertFalse($collection->timestamps);

        $missed = new MissedPart;
        $this->assertSame('missed_parts', $missed->getTable());
        $this->assertSame([], $missed->getGuarded());

        $part = new Part;
        $this->assertSame('parts', $part->getTable());
        $this->assertSame('binaries_id', $part->getKeyName());
        $this->assertFalse($part->timestamps);
        $this->assertSame(['binaries_id', 'partnumber'], $part->compositeKeyColumns());
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame([
            'totalparts' => 10,
            'currentparts' => 4,
            'partsize' => 1000,
            'partcheck' => 0,
            'missing_parts' => 6,
            'progress_basis_points' => 4000,
            'complete' => false,
            'average_part_size' => 250,
        ], Binary::normalizePartProgressPhp(10, 4, 1000, false));
        $this->assertSame([
            'totalparts' => 10,
            'currentparts' => 10,
            'partsize' => 0,
            'partcheck' => 1,
            'missing_parts' => 0,
            'progress_basis_points' => 10000,
            'complete' => true,
            'average_part_size' => 0,
        ], Binary::normalizePartProgressPhp(10, 12, -5, false));
        $this->assertSame(3333, Binary::normalizePartProgressPhp(3, 1, 10, false)['progress_basis_points']);
        $this->assertSame(1, Binary::normalizePartProgressPhp(0, 0, 0, true)['partcheck']);
        $this->assertFalse(Binary::normalizePartProgressPhp(0, 0, 0, true)['complete']);
        $this->assertSame(4294967295, Binary::normalizePartProgressPhp(PHP_INT_MAX, PHP_INT_MAX, 1, false)['totalparts']);

        $this->assertSame([
            'groupname' => 'alt.binaries.test',
            'regex' => '^foo$',
            'msgcol' => 2,
            'optype' => 2,
            'status' => 1,
            'is_global' => false,
            'field' => 'from',
            'composite_key' => 'alt.binaries.test|2|2|^foo$',
        ], BinaryBlacklist::normalizeBlacklistRuleAttributesPhp('  ALT.Binaries.TEST ', ' ^foo$ ', 2, 2, 1));
        $this->assertSame([
            'groupname' => null,
            'regex' => 'x',
            'msgcol' => 1,
            'optype' => 1,
            'status' => 0,
            'is_global' => true,
            'field' => 'subject',
            'composite_key' => '*|1|1|x',
        ], BinaryBlacklist::normalizeBlacklistRuleAttributesPhp(" \t", 'x', 9, 0, 7));
        $this->assertSame('messageid', BinaryBlacklist::normalizeBlacklistRuleAttributesPhp(null, '', 3, 1, 1)['field']);

        $this->assertSame([
            'subject' => 'My Post [1/5]',
            'fromname' => 'Poster@Example',
            'totalfiles' => 5,
            'filesize' => 1001,
            'filecheck' => 1,
            'average_file_size' => 200,
            'summary_key' => 'my post [1/5]|poster@example|5',
        ], Collection::normalizeCollectionSummaryPhp("  My \t Post  [1/5] ", ' Poster@Example ', 5, 1001, true));
        $this->assertSame(255, mb_strlen(Collection::normalizeCollectionSummaryPhp(str_repeat('é', 300), '', 1, 1, false)['subject']));
        $this->assertSame(0, Collection::normalizeCollectionSummaryPhp('', '', -1, -1, false)['average_file_size']);

        $this->assertSame([
            'numberid' => 100,
            'groups_id' => 7,
            'attempts' => 2,
            'max_attempts' => 5,
            'remaining_attempts' => 3,
            'should_retry' => true,
            'next_attempts' => 3,
            'composite_key' => '7:100',
        ], MissedPart::normalizeMissedPartRetryStatePhp(100, 7, 2));
        $exhausted = MissedPart::normalizeMissedPartRetryStatePhp(-1, -1, 999, 0);
        $this->assertSame([0, 0, 255, 1, 0, false, 255, '0:0'], array_values($exhausted));

        $this->assertSame([
            'binaries_id' => 5,
            'messageid' => 'abc@news',
            'number' => 10,
            'partnumber' => 2,
            'size' => 700,
            'valid' => true,
            'composite_key' => '5:2',
        ], Part::normalizePartSegmentPhp(5, ' <abc@news> ', 10, 2, 700));
        $this->assertSame('', Part::normalizePartSegmentPhp(1, '<>', 1, 1, 1)['messageid']);
        $this->assertFalse(Part::normalizePartSegmentPhp(1, '<>', 1, 1, 1)['valid']);
        $this->assertSame('<', Part::normalizePartSegmentPhp(1, '<', 1, 1, 1)['messageid']);
        $this->assertSame('<double@id>', Part::normalizePartSegmentPhp(1, '<<double@id>>', 1, 1, 1)['messageid']);
    }

    public function test_native_and_toggle_match_php_across_edge_inputs(): void
    {
        foreach (self::INTS as $i => $total) {
            foreach (self::INTS as $j => $current) {
                $this->assertBinaryParity($total, $current, self::INTS[($i * 5 + $j) % count(self::INTS)], ($i + $j) % 2 === 0);
            }
        }

        $groups = [...self::TEXTS, null];
        foreach ($groups as $i => $group) {
            foreach (self::TEXTS as $j => $regex) {
                $int = self::INTS[($i + $j) % count(self::INTS)];
                $this->assertBlacklistParity($group, $regex, $int % 5, ($i + $j) % 4, $j % 3 - 1);
            }
        }
        foreach ([PHP_INT_MIN, -1, 0, 1, 2, 3, 4, PHP_INT_MAX] as $value) {
            $this->assertBlacklistParity('alt.binaries.test', '^x$', $value, $value, $value);
        }

        foreach (self::TEXTS as $i => $subject) {
            foreach (self::TEXTS as $j => $from) {
                $this->assertCollectionParity($subject, $from, self::INTS[$i % count(self::INTS)], self::INTS[$j % count(self::INTS)], ($i + $j) % 2 === 1);
            }
        }
        $this->assertCollectionParity(str_repeat('Ünïcödé  Sübject  ', 30), str_repeat('pöster ', 60), 7, 123456789, false);

        foreach (self::INTS as $i => $attempts) {
            foreach (self::INTS as $j => $max) {
                $this->assertMissedPartParity(self::INTS[($i + $j) % count(self::INTS)], self::INTS[($i * 3 + $j) % count(self::INTS)], $attempts, $max);
            }
        }
        $this->assertSame(
            MissedPart::normalizeMissedPartRetryStatePhp(1, 2, 3),
            MissedPart::normalizeMissedPartRetryStateNative(1, 2, 3),
        );

        foreach (self::TEXTS as $i => $messageId) {
            foreach (self::INTS as $j => $int) {
                $this->assertPartParity($int, $messageId, self::INTS[($i + $j) % count(self::INTS)], $j - 3, $int);
            }
        }
        $this->assertPartParity(1, '<'.str_repeat('é', 300).'>', 1, 1, 1);
    }

    public function test_randomized_inputs_match_php(): void
    {
        $random = new Randomizer(new Mt19937(3301));
        $alphabet = ['a', 'Z', '0', '9', ' ', "\t", "\n", "\0", "\x0B", "\x0C", '<', '>', '@', '.', '|', '*', 'é', 'ß', '進', "\u{1F3B5}", "\u{00A0}", "\x1F", "\x1E", "\xff"];
        $text = static function () use ($random, $alphabet): string {
            $out = '';
            $length = $random->getInt(0, 40);
            for ($i = 0; $i < $length; $i++) {
                $out .= $alphabet[$random->getInt(0, count($alphabet) - 1)];
            }

            return $out;
        };
        $int = static fn (): int => match ($random->getInt(0, 3)) {
            0 => $random->getInt(-10, 10),
            1 => $random->getInt(0, 5000),
            2 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            default => $random->getInt(0, 4294967296),
        };
        $bool = static fn (): bool => $random->getInt(0, 1) === 1;

        for ($i = 0; $i < 1500; $i++) {
            $this->assertBinaryParity($int(), $int(), $int(), $bool());
            $this->assertBlacklistParity($random->getInt(0, 4) === 0 ? null : $text(), $text(), $int(), $int(), $int());
            $this->assertCollectionParity($text(), $text(), $int(), $int(), $bool());
            $this->assertMissedPartParity($int(), $int(), $int(), $int());
            $this->assertPartParity($int(), $random->getInt(0, 2) === 0 ? '<'.$text().'>' : $text(), $int(), $int(), $int());
        }
    }

    public function test_native_exports_validate_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();

        $small = $ffi->new('char[4]');
        FFI::memset($small, ord('Z'), 4);
        $input = "My Subject\x1FPoster";
        $needed = $this->callFfi($ffi, 'nntmux_w33_collection_normalize_summary', 2, 100, 1, $input, strlen($input), $small, 4);
        $this->assertLessThan(-1, $needed);
        $this->assertSame('ZZZZ', FFI::string($small, 4));

        $buffer = $ffi->new('char['.(-$needed).']');
        $this->assertSame(-$needed, $this->callFfi($ffi, 'nntmux_w33_collection_normalize_summary', 2, 100, 1, $input, strlen($input), $buffer, -$needed));
        $this->assertSame("My Subject\x1FPoster\x1F2\x1F100\x1F1\x1F50\x1Fmy subject|poster|2", FFI::string($buffer, -$needed));

        $out = $ffi->new('char[256]');
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w33_binary_normalize_part_progress', 1, 1, 1, 2, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w33_binary_normalize_part_progress', 1, 1, 1, 0, null, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w33_binary_blacklist_normalize_rule', 1, 1, 1, 1, "group\x1Fregex", 11, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w33_binary_blacklist_normalize_rule', 2, 1, 1, 1, "\x1Fregex", 6, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w33_binary_blacklist_normalize_rule', 0, 1, 1, 1, 'regex', 5, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w33_binary_blacklist_normalize_rule', 0, 1, 1, 1, "\x1E\x1Fregex", 7, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w33_collection_normalize_summary', 1, 1, 0, "a\x1Fb\x1Fc", 5, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w33_collection_normalize_summary', 1, 1, 0, "\xff\x1Fb", 3, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w33_collection_normalize_summary', 1, 1, 3, "a\x1Fb", 3, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w33_missed_part_normalize_retry_state', 1, 1, 1, 1, null, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w33_part_normalize_segment', 1, 1, 1, 1, "id\x1Fx", 4, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w33_part_normalize_segment', 1, 1, 1, 1, "\x1E", 1, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w33_part_normalize_segment', 1, 1, 1, 1, null, 0, $out, 256));

        $written = $this->callFfi($ffi, 'nntmux_w33_binary_blacklist_normalize_rule', 1, 3, 2, 1, "\x1F abc ", 6, $out, 256);
        $this->assertSame("\x1E\x1Fabc\x1F3\x1F2\x1F1\x1F1\x1Fmessageid\x1F*|2|3|abc", FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_w33_missed_part_normalize_retry_state', 9, 4, 3, 3, $out, 256);
        $this->assertSame("9\x1F4\x1F3\x1F3\x1F0\x1F0\x1F4", FFI::string($out, $written));

        foreach (self::MODELS as $model) {
            $model::setUseRustFfi(true);
        }
        Binary::normalizePartProgress(10, 5, 100, false);
        BinaryBlacklist::normalizeBlacklistRuleAttributes(null, '^x$', 1, 1, 1);
        Collection::normalizeCollectionSummary('Subject', 'Poster', 1, 1, false);
        MissedPart::normalizeMissedPartRetryState(1, 1, 0);
        Part::normalizePartSegment(1, '<id@x>', 1, 1, 1);
        foreach (self::MODELS as $model) {
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($model, 'ffi'))->getValue(), $model.' must resolve its native exports');
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of the five model helpers through their public dispatchers,
        // including wire packing, the FFI call and decoding (release dylib, 5 warmups, 301 samples x 40 iterations).
        // Common = typical binary/part/collection/blacklist/missed-part rows; uncommon = long, Unicode,
        // padded, bracketed, clamped and fallback (marker / invalid UTF-8) inputs.
        // Speedup = PHP latency / Rust latency per workload pass (PHP 8.5.12 NTS, Apple M5, 3 rounds):
        // - Round 1: common mean 0.34x / p99 0.37x, uncommon mean 0.43x / p99 0.42x
        // - Round 2: common mean 0.34x / p99 0.37x, uncommon mean 0.44x / p99 0.41x
        // - Round 3: common mean 0.34x / p99 0.36x, uncommon mean 0.43x / p99 0.45x
        // Reproduce: php vendor/bin/phpunit tests/Unit/RustBinaryPartCollectionModelsParityTest.php --filter benchmark
        // Sub-microsecond PHP integer/string work is cheaper than the FFI round trip, so every model stays
        // below the 1.5x bar in .ai/rules/rust-performance.md and keeps RUST_FFI_DEFAULT = false.
        $this->ffiOrSkip();
        $workloads = ['common' => $this->commonWorkload(), 'uncommon' => $this->uncommonWorkload()];
        $random = new Randomizer(new Mt19937(3301));
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
            foreach (self::MODELS as $model) {
                $model::setUseRustFfi(null);
            }
            $speedups[$name] = [
                'mean' => $this->mean($times['php']) / $this->mean($times['rust']),
                'p99' => $this->p99($times['php']) / $this->p99($times['rust']),
            ];
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, 'RustBinaryPartCollectionModels speedups: '.$summary.PHP_EOL);
        if ($speedups['common']['mean'] < 1.5 || $speedups['common']['p99'] < 1.5 || $speedups['uncommon']['p99'] < 1.5) {
            foreach (self::MODELS as $model) {
                $this->assertFalse($model::usesRustFfi(), $model.' must keep PHP below 1.5x: '.$summary);
            }
        }
        foreach ($speedups as $name => $speedup) {
            $this->assertGreaterThan(0.0, $speedup['mean'], $name);
            $this->assertGreaterThan(0.0, $speedup['p99'], $name);
        }
    }

    private function assertBinaryParity(int $total, int $current, int $size, bool $check): void
    {
        $expected = Binary::normalizePartProgressPhp($total, $current, $size, $check);
        $context = json_encode([$total, $current, $size, $check]) ?: '';
        $this->assertSameAttributes($expected, Binary::normalizePartProgressNative($total, $current, $size, $check), $context);
        Binary::setUseRustFfi(true);
        $this->assertSameAttributes($expected, Binary::normalizePartProgress($total, $current, $size, $check), $context);
        Binary::setUseRustFfi(null);
        $this->assertSameAttributes($expected, Binary::normalizePartProgress($total, $current, $size, $check), $context);
    }

    private function assertBlacklistParity(?string $group, string $regex, int $msgCol, int $opType, int $status): void
    {
        $expected = BinaryBlacklist::normalizeBlacklistRuleAttributesPhp($group, $regex, $msgCol, $opType, $status);
        $context = json_encode([$group === null ? null : bin2hex($group), bin2hex($regex), $msgCol, $opType, $status]) ?: '';
        $this->assertSameAttributes($expected, BinaryBlacklist::normalizeBlacklistRuleAttributesNative($group, $regex, $msgCol, $opType, $status), $context);
        BinaryBlacklist::setUseRustFfi(true);
        $this->assertSameAttributes($expected, BinaryBlacklist::normalizeBlacklistRuleAttributes($group, $regex, $msgCol, $opType, $status), $context);
        BinaryBlacklist::setUseRustFfi(null);
        $this->assertSameAttributes($expected, BinaryBlacklist::normalizeBlacklistRuleAttributes($group, $regex, $msgCol, $opType, $status), $context);
    }

    private function assertCollectionParity(string $subject, string $from, int $files, int $size, bool $check): void
    {
        $expected = Collection::normalizeCollectionSummaryPhp($subject, $from, $files, $size, $check);
        $context = json_encode([bin2hex($subject), bin2hex($from), $files, $size, $check]) ?: '';
        $this->assertSameAttributes($expected, Collection::normalizeCollectionSummaryNative($subject, $from, $files, $size, $check), $context);
        Collection::setUseRustFfi(true);
        $this->assertSameAttributes($expected, Collection::normalizeCollectionSummary($subject, $from, $files, $size, $check), $context);
        Collection::setUseRustFfi(null);
        $this->assertSameAttributes($expected, Collection::normalizeCollectionSummary($subject, $from, $files, $size, $check), $context);
    }

    private function assertMissedPartParity(int $number, int $group, int $attempts, int $max): void
    {
        $expected = MissedPart::normalizeMissedPartRetryStatePhp($number, $group, $attempts, $max);
        $context = json_encode([$number, $group, $attempts, $max]) ?: '';
        $this->assertSameAttributes($expected, MissedPart::normalizeMissedPartRetryStateNative($number, $group, $attempts, $max), $context);
        MissedPart::setUseRustFfi(true);
        $this->assertSameAttributes($expected, MissedPart::normalizeMissedPartRetryState($number, $group, $attempts, $max), $context);
        MissedPart::setUseRustFfi(null);
        $this->assertSameAttributes($expected, MissedPart::normalizeMissedPartRetryState($number, $group, $attempts, $max), $context);
    }

    private function assertPartParity(int $binary, string $messageId, int $number, int $partNumber, int $size): void
    {
        $expected = Part::normalizePartSegmentPhp($binary, $messageId, $number, $partNumber, $size);
        $context = json_encode([$binary, bin2hex($messageId), $number, $partNumber, $size]) ?: '';
        $this->assertSameAttributes($expected, Part::normalizePartSegmentNative($binary, $messageId, $number, $partNumber, $size), $context);
        Part::setUseRustFfi(true);
        $this->assertSameAttributes($expected, Part::normalizePartSegment($binary, $messageId, $number, $partNumber, $size), $context);
        Part::setUseRustFfi(null);
        $this->assertSameAttributes($expected, Part::normalizePartSegment($binary, $messageId, $number, $partNumber, $size), $context);
    }

    /**
     * Strict comparison through a widened signature so each dispatcher result is checked independently.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function assertSameAttributes(array $expected, array $actual, string $context): void
    {
        $this->assertSame($expected, $actual, $context);
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function commonWorkload(): array
    {
        return [
            ['binary', [50, 37, 28_000_000, false]],
            ['binary', [120, 120, 90_000_000, true]],
            ['blacklist', ['alt.binaries.teevee', '^(?!.*\.nfo).*$', 1, 1, 1]],
            ['blacklist', [null, 'sample', 2, 2, 1]],
            ['collection', ['My Post [01/10] - "file.part01.rar" yEnc', 'poster@example.com', 10, 750_000_000, false]],
            ['missed', [123_456_789, 42, 1, 3]],
            ['part', [987_654, '<part1of50.abc@news.example>', 123_456_789, 1, 768_000]],
            ['part', [987_654, '<part2of50.abc@news.example>', 123_456_790, 2, 768_000]],
        ];
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function uncommonWorkload(): array
    {
        $long = str_repeat('Ünïcödé  Sübject  ', 30);

        return [
            ['binary', [PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX, false]],
            ['binary', [0, 5, -1, true]],
            ['blacklist', ['  '.str_repeat('ALT.BINARIES.', 30).'  ', "  \t".str_repeat('(a|b)', 80)."\n", 9, 7, 3]],
            ['blacklist', ["group\x1Fsep", 'x', 3, 2, 0]],
            ['collection', [$long, str_repeat(' Pöster ', 40), PHP_INT_MAX, PHP_INT_MAX, true]],
            ['collection', ["bad \xff subject", 'poster', 1, 1, false]],
            ['missed', [PHP_INT_MIN, PHP_INT_MAX, 999, -5]],
            ['part', [-1, '  < '.str_repeat('é', 300).' >  ', -1, 0, PHP_INT_MIN]],
            ['part', [1, "<id\x1E@x>", 1, 1, 1]],
        ];
    }

    /**
     * @param  list<array{string, list<mixed>}>  $workload
     */
    private function runWorkload(array $workload, bool $useRust): int
    {
        foreach (self::MODELS as $model) {
            $model::setUseRustFfi($useRust);
        }
        $count = 0;
        foreach ($workload as [$kind, $args]) {
            $result = match ($kind) {
                'binary' => Binary::normalizePartProgress(...$args),
                'blacklist' => BinaryBlacklist::normalizeBlacklistRuleAttributes(...$args),
                'collection' => Collection::normalizeCollectionSummary(...$args),
                'missed' => MissedPart::normalizeMissedPartRetryState(...$args),
                default => Part::normalizePartSegment(...$args),
            };
            $count += count($result);
        }

        return $count;
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

    private function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $cdef = implode("\n", [
            Binary::FFI_CDEF,
            BinaryBlacklist::FFI_CDEF,
            Collection::FFI_CDEF,
            MissedPart::FFI_CDEF,
            Part::FFI_CDEF,
        ]);
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', $cdef);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the binary/part/collection model exports is not available.');
        }

        return $ffi;
    }
}
