<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CategoryRegex;
use App\Models\CollectionRegex;
use App\Models\IngestionConfiguration;
use App\Models\ReleaseNamingRegex;
use App\Models\ReleaseRegex;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use Tests\TestCase;

final class RustRegexIngestionConfigModelsParityTest extends TestCase
{
    /** @var list<class-string> */
    private const MODELS = [
        CollectionRegex::class,
        ReleaseNamingRegex::class,
        CategoryRegex::class,
        ReleaseRegex::class,
        IngestionConfiguration::class,
    ];

    /** Group patterns, regexes and descriptions covering trimming, delimiters, named groups, UTF-8 and wire markers. */
    private const TEXTS = [
        '',
        ' ',
        "\0\x0B\x0C\t\r\n",
        '^alt\.binaries\.teevee$',
        '  ^ALT\.Binaries\.(MOVIES|hdtv)$  ',
        '/^(?P<match0>.+?)\s+\[\d+\/\d+\]/i',
        '#(?<match1>[\w.-]+)(?P<reqid>\d+)(?\'parts\'\d+)#iu',
        '~^\[(?P<match0>\d+)\]-\[FULL\]-\[(?P<match1>#a\.b\.[\w.-]+)\]~',
        '/(?<=foo)(?<!bar)\(?<escaped>x\)/',
        '/(?P<match0>a)(?P<match0>b)/J',
        '(abc)i',
        '{a{1,3}}x',
        '<tag>',
        '/unterminated',
        '/bad/modifier!',
        'no delimiter',
        '\\a\\',
        '/',
        '//',
        "Tab\tand\nnewline  description",
        'Ünïcödé Gröup',
        "\u{00A0}nbsp\u{00A0}",
        '進撃の巨人',
        "emoji \u{1F3B5} desc",
        "bad \xff utf8",
        "\xc3",
        "sep\x1Finside",
        "null\x1Emark",
        "nul\0byte",
    ];

    /** Integers that exercise clamps, enum fallbacks, hour overflow guards and 64-bit limits. */
    private const INTS = [
        PHP_INT_MIN,
        -1000,
        -1,
        0,
        1,
        2,
        3,
        48,
        95,
        100,
        101,
        36500,
        36501,
        876000,
        876001,
        2147483647,
        2147483648,
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
        foreach ([CollectionRegex::class, ReleaseNamingRegex::class, CategoryRegex::class] as $model) {
            $this->assertFalse((new $model)->timestamps, $model);
        }
        $this->assertSame('collection_regexes', (new CollectionRegex)->getTable());
        $this->assertSame('release_naming_regexes', (new ReleaseNamingRegex)->getTable());
        $this->assertSame('category_regexes', (new CategoryRegex)->getTable());

        $link = new ReleaseRegex;
        $this->assertSame('releases_id', $link->getKeyName());
        $this->assertFalse($link->timestamps);
        $this->assertSame([], $link->getGuarded());
        $this->assertSame(['releases_id', 'collection_regex_id', 'naming_regex_id'], $link->compositeKeyColumns());

        $config = new IngestionConfiguration;
        $this->assertSame(['id'], $config->getGuarded());
        $this->assertSame('boolean', $config->getCasts()['grab_status']);
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame([
            'group_regex' => '^alt\.binaries\.TEEVEE$',
            'regex' => '/(?P<match0>a)(?P<parts>b)(?P<match0>c)/i',
            'status' => 1,
            'description' => 'Some desc',
            'ordinal' => 0,
            'is_active' => true,
            'delimiter_valid' => true,
            'modifiers' => 'i',
            'sort_key' => '0000000000|^alt\.binaries\.teevee$',
            'named_groups' => ['match0', 'parts', 'match0'],
            'usable_named_groups' => 2,
            'is_usable' => true,
        ], CollectionRegex::normalizeCollectionRegexRulePhp('  ^alt\.binaries\.TEEVEE$ ', " /(?P<match0>a)(?P<parts>b)(?P<match0>c)/i \n", 1, "  Some \t desc  ", -4));

        $rule = CollectionRegex::normalizeCollectionRegexRulePhp(str_repeat('é', 300), '', 7, null, PHP_INT_MAX);
        $this->assertSame(255, mb_strlen($rule['group_regex']));
        $this->assertSame([0, '', 2147483647, false, false], [$rule['status'], $rule['description'], $rule['ordinal'], $rule['is_active'], $rule['is_usable']]);
        $this->assertSame(1000, strlen(CollectionRegex::normalizeCollectionRegexRulePhp('g', '/x/', 1, str_repeat('x', 1200), 1)['description']));

        $this->assertSame([true, 'iu'], CollectionRegex::regexDelimiterPhp('#^x$#iu'));
        $this->assertSame([true, ''], CollectionRegex::regexDelimiterPhp('//'));
        $this->assertSame([true, 'x'], CollectionRegex::regexDelimiterPhp('{a{1}}x'));
        $this->assertSame([false, ''], CollectionRegex::regexDelimiterPhp('/abc/q'));
        $this->assertSame([false, ''], CollectionRegex::regexDelimiterPhp('/abc'));
        $this->assertSame([false, ''], CollectionRegex::regexDelimiterPhp('abc'));
        $this->assertSame([false, ''], CollectionRegex::regexDelimiterPhp('ééé'));
        $this->assertSame(['match0', 'match1', 'reqid'], CollectionRegex::namedCaptureGroupsPhp('/(?P<match0>a)(?<match1>b)(?\'reqid\'c)(?<=x)(?<!y)\(?<esc>z)/'));
        $this->assertSame([], CollectionRegex::namedCaptureGroupsPhp('/(?<1bad>x)(?P\'no\'y)(?<>z)(?<open/'));

        $naming = ReleaseNamingRegex::normalizeReleaseNamingRegexRulePhp('g', '/(?P<match1>a)(?P<reqid>b)(?P<match0>c)(?P<match1>d)/', 1, null, 5);
        $this->assertSame(['match1', 'reqid', 'match0', 'match1'], $naming['named_groups']);
        $this->assertSame(['match0', 'match1'], $naming['concat_order']);
        $this->assertTrue($naming['is_usable']);
        $this->assertFalse(ReleaseNamingRegex::normalizeReleaseNamingRegexRulePhp('g', '/(?P<reqid>b)/', 1, null, 5)['is_usable']);

        $category = CategoryRegex::normalizeCategoryRegexRulePhp('g', '/x/', 1, 'd', 3, 2040);
        $this->assertSame([2040, true, '0000000003|g'], [$category['categories_id'], $category['is_usable'], $category['sort_key']]);
        $this->assertFalse(CategoryRegex::normalizeCategoryRegexRulePhp('g', '/x/', 1, 'd', 3, -1)['is_usable']);

        $this->assertSame([
            'releases_id' => 10,
            'collection_regex_id' => 0,
            'naming_regex_id' => 0,
            'has_collection_regex' => false,
            'has_naming_regex' => false,
            'valid' => true,
            'composite_key' => '10:0:0',
        ], ReleaseRegex::normalizeReleaseRegexLinkPhp(10, 0, -3));

        $this->assertSame([
            'completion_percent' => 95,
            'min_files_to_form_release' => 1,
            'min_size_to_form_release' => 0,
            'max_size_to_form_release' => 0,
            'size_window_valid' => true,
            'collection_delay_seconds' => 7200,
            'collection_timeout_seconds' => 172800,
            'cross_post_seconds' => 7200,
            'timeout_after_delay' => true,
            'new_group_scan_method' => 0,
            'new_group_scan_unit' => 'posts',
            'new_group_scan_target' => 100000,
        ], IngestionConfiguration::normalizeReleaseFormationRulesPhp(95, 1, 0, 0, 2, 48, 2, 0, 1, 100000));
        $extreme = IngestionConfiguration::normalizeReleaseFormationRulesPhp(PHP_INT_MAX, PHP_INT_MIN, 10, 5, PHP_INT_MAX, -1, 3, 1, 0, 9);
        $this->assertSame([100, 0, 10, 5, false, 3153600000, 0, 10800, false, 1, 'days', 1], array_values($extreme));
    }

    public function test_native_and_toggle_match_php_across_edge_inputs(): void
    {
        $descriptions = [...self::TEXTS, null];
        foreach (self::TEXTS as $i => $group) {
            foreach (self::TEXTS as $j => $regex) {
                $description = $descriptions[($i * 7 + $j) % count($descriptions)];
                $int = self::INTS[($i + $j) % count(self::INTS)];
                $this->assertRegexRuleParity($group, $regex, ($i + $j) % 3 - 1, $description, $int, self::INTS[($i * 3 + $j) % count(self::INTS)]);
            }
        }
        foreach ($descriptions as $description) {
            $this->assertRegexRuleParity('^alt\.binaries\..*$', '/(?P<match0>x)/', 1, $description, 1, 2040);
        }
        foreach (self::INTS as $value) {
            $this->assertRegexRuleParity('g', '/(?P<match0>x)/i', $value, 'd', $value, $value);
        }
        $long = str_repeat('Ünïcödé  Gröup  ', 30);
        $this->assertRegexRuleParity($long, '/'.str_repeat('(?P<match0>a)', 400).'/', 1, str_repeat($long, 5), 1, 1);

        foreach (self::INTS as $i => $releases) {
            foreach (self::INTS as $j => $collection) {
                $this->assertReleaseRegexParity($releases, $collection, self::INTS[($i * 5 + $j) % count(self::INTS)]);
            }
        }

        $count = count(self::INTS);
        foreach (self::INTS as $i => $a) {
            foreach (self::INTS as $j => $b) {
                $this->assertIngestionParity(
                    $a,
                    $b,
                    self::INTS[($i + $j) % $count],
                    self::INTS[($i * 2 + $j) % $count],
                    self::INTS[($i * 3 + $j) % $count],
                    self::INTS[($i + $j * 3) % $count],
                    self::INTS[($i * 7 + $j) % $count],
                    [0, 1, 2, -1][($i + $j) % 4],
                    self::INTS[($i * 11 + $j) % $count],
                    self::INTS[($i + $j * 13) % $count],
                );
            }
        }
    }

    public function test_randomized_inputs_match_php(): void
    {
        $random = new Randomizer(new Mt19937(3401));
        $alphabet = ['a', 'Z', '0', '9', '_', ' ', "\t", "\n", "\0", "\x0B", "\x0C", '/', '#', '~', '(', ')', '[', ']', '{', '}', '<', '>', '?', 'P', "'", '\\', '=', '!', '.', '+', '*', 'i', 'u', 'x', 'é', '進', "\u{1F3B5}", "\u{00A0}", "\x1F", "\x1E", "\xff"];
        $fragments = ['(?P<match0>', '(?<match1>', "(?'reqid'", '(?P<parts>', '(?<=', '(?<!', '\\(?<', ')', '/', '#iu'];
        $text = static function () use ($random, $alphabet, $fragments): string {
            $out = '';
            $length = $random->getInt(0, 40);
            for ($i = 0; $i < $length; $i++) {
                $out .= $random->getInt(0, 5) === 0
                    ? $fragments[$random->getInt(0, count($fragments) - 1)]
                    : $alphabet[$random->getInt(0, count($alphabet) - 1)];
            }

            return $out;
        };
        $int = static fn (): int => match ($random->getInt(0, 3)) {
            0 => $random->getInt(-10, 10),
            1 => $random->getInt(0, 100000),
            2 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            default => $random->getInt(0, 4294967296),
        };

        for ($i = 0; $i < 1500; $i++) {
            $regex = $random->getInt(0, 2) === 0 ? $text() : '/'.$text().'/'.$text();
            $this->assertRegexRuleParity($text(), $regex, $random->getInt(-1, 2), $random->getInt(0, 4) === 0 ? null : $text(), $int(), $int());
            $this->assertReleaseRegexParity($int(), $int(), $int());
            $this->assertIngestionParity($int(), $int(), $int(), $int(), $int(), $int(), $int(), $random->getInt(-1, 2), $int(), $int());
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        $snapshots = [];
        foreach (self::MODELS as $model) {
            $property = new ReflectionProperty($model, 'ffi');
            $snapshots[$model] = $property->getValue();
            $property->setValue(null, false);
            $model::setUseRustFfi(true);
        }

        try {
            $this->assertSame(CollectionRegex::normalizeCollectionRegexRulePhp('g', '/(?P<match0>x)/', 1, null, 1), CollectionRegex::normalizeCollectionRegexRule('g', '/(?P<match0>x)/', 1, null, 1));
            $this->assertSame(ReleaseNamingRegex::normalizeReleaseNamingRegexRulePhp('g', '/(?P<match0>x)/', 1, 'd', 1), ReleaseNamingRegex::normalizeReleaseNamingRegexRule('g', '/(?P<match0>x)/', 1, 'd', 1));
            $this->assertSame(CategoryRegex::normalizeCategoryRegexRulePhp('g', '/x/', 1, 'd', 1, 2040), CategoryRegex::normalizeCategoryRegexRule('g', '/x/', 1, 'd', 1, 2040));
            $this->assertSame(ReleaseRegex::normalizeReleaseRegexLinkPhp(1, 2, 3), ReleaseRegex::normalizeReleaseRegexLink(1, 2, 3));
            $this->assertSame(
                IngestionConfiguration::normalizeReleaseFormationRulesPhp(95, 1, 0, 0, 2, 48, 2, 0, 1, 100000),
                IngestionConfiguration::normalizeReleaseFormationRules(95, 1, 0, 0, 2, 48, 2, 0, 1, 100000),
            );
        } finally {
            foreach ($snapshots as $model => $value) {
                (new ReflectionProperty($model, 'ffi'))->setValue(null, $value);
            }
        }
    }

    public function test_native_exports_validate_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();

        $small = $ffi->new('char[4]');
        FFI::memset($small, ord('Z'), 4);
        $input = "grp\x1F/(?P<match0>x)/i\x1Fdesc";
        $needed = $this->callFfi($ffi, 'nntmux_w34_collection_regex_normalize_rule', 1, 2, 0, $input, strlen($input), $small, 4);
        $this->assertLessThan(-1, $needed);
        $this->assertSame('ZZZZ', FFI::string($small, 4));

        $buffer = $ffi->new('char['.(-$needed).']');
        $this->assertSame(-$needed, $this->callFfi($ffi, 'nntmux_w34_collection_regex_normalize_rule', 1, 2, 0, $input, strlen($input), $buffer, -$needed));
        $this->assertSame("grp\x1F/(?P<match0>x)/i\x1F1\x1Fdesc\x1F2\x1F1\x1F1\x1Fi\x1F0000000002|grp\x1Fmatch0\x1F1\x1F1", FFI::string($buffer, -$needed));

        $out = $ffi->new('char[256]');
        foreach (['nntmux_w34_collection_regex_normalize_rule', 'nntmux_w34_release_naming_regex_normalize_rule'] as $symbol) {
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 1, 1, 2, "a\x1Fb\x1Fc", 5, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 1, 1, 1, "a\x1Fb\x1Fdesc", 8, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 1, 1, 0, "a\x1Fb", 3, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 1, 1, 0, "a\x1Fb\x1Fc\x1Fd", 7, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 1, 1, 0, "\x1E\x1Fb\x1Fc", 5, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 1, 1, 0, "\xff\x1Fb\x1Fc", 5, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 1, 1, 0, null, 0, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 1, 1, 0, "a\x1Fb\x1Fc", 5, null, 256), $symbol);
        }
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w34_category_regex_normalize_rule', 1, 1, 1, 3, "a\x1Fb\x1Fc", 5, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w34_category_regex_normalize_rule', 1, 1, 1, 1, "a\x1Fb\x1Fc", 5, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w34_category_regex_normalize_rule', 1, 1, 1, 0, "a\x1Fb", 3, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w34_release_regex_normalize_link', 1, 1, 1, null, 256));
        $this->assertSame(-17, $this->callFfi($ffi, 'nntmux_w34_release_regex_normalize_link', 1, 1, 1, $small, 4));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w34_ingestion_configuration_normalize_release_rules', 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, null, 256));

        $written = $this->callFfi($ffi, 'nntmux_w34_category_regex_normalize_rule', 1, 3, 2040, 1, " g \x1F/x/\x1F", 8, $out, 256);
        $this->assertSame("g\x1F/x/\x1F1\x1F\x1F3\x1F1\x1F1\x1F\x1F0000000003|g\x1F2040\x1F1", FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_w34_release_regex_normalize_link', 5, 6, 7, $out, 256);
        $this->assertSame("5\x1F6\x1F7\x1F1\x1F1\x1F1\x1F5:6:7", FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_w34_ingestion_configuration_normalize_release_rules', 95, 1, 0, 0, 2, 48, 2, 1, 40000, 7, $out, 256);
        $this->assertSame("95\x1F1\x1F0\x1F0\x1F1\x1F7200\x1F172800\x1F7200\x1F1\x1F1\x1Fdays\x1F36500", FFI::string($out, $written));

        foreach (self::MODELS as $model) {
            $model::setUseRustFfi(true);
        }
        CollectionRegex::normalizeCollectionRegexRule('g', '/x/', 1, null, 1);
        ReleaseNamingRegex::normalizeReleaseNamingRegexRule('g', '/x/', 1, null, 1);
        CategoryRegex::normalizeCategoryRegexRule('g', '/x/', 1, null, 1, 1);
        ReleaseRegex::normalizeReleaseRegexLink(1, 1, 1);
        IngestionConfiguration::normalizeReleaseFormationRules(1, 1, 1, 1, 1, 1, 1, 1, 1, 1);
        foreach (self::MODELS as $model) {
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($model, 'ffi'))->getValue(), $model.' must resolve its native exports');
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of the five model helpers through their public dispatchers,
        // including wire packing, the FFI call and decoding (release dylib, 5 warmups, 301 samples x 40 iterations).
        // Common = typical collection/naming/category regex rows, release-regex links and default ingestion
        // settings; uncommon = long, Unicode, padded, many-named-group, invalid-delimiter, clamped and
        // fallback (marker / invalid UTF-8) inputs.
        // Speedup = PHP latency / Rust latency per workload pass (PHP 8.5.12 NTS, Apple M5, 3 rounds):
        // - Round 1: common mean 0.96x / p99 0.97x, uncommon mean 4.88x / p99 4.79x
        // - Round 2: common mean 0.97x / p99 0.93x, uncommon mean 5.00x / p99 4.98x
        // - Round 3: common mean 0.97x / p99 0.97x, uncommon mean 4.88x / p99 4.82x
        // Reproduce: php vendor/bin/phpunit tests/Unit/RustRegexIngestionConfigModelsParityTest.php --filter benchmark
        // Rust wins only on long patterns where the PHP byte-by-byte named-group scan dominates; on common rows
        // the FFI round trip cancels it out, so the common path misses the 1.5x bar in
        // .ai/rules/rust-performance.md and every model keeps RUST_FFI_DEFAULT = false.
        $this->ffiOrSkip();
        $workloads = ['common' => $this->commonWorkload(), 'uncommon' => $this->uncommonWorkload()];
        $random = new Randomizer(new Mt19937(3401));
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
        fwrite(STDERR, 'RustRegexIngestionConfigModels speedups: '.$summary.PHP_EOL);
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

    private function assertRegexRuleParity(string $group, string $regex, int $status, ?string $description, int $ordinal, int $categoriesId): void
    {
        $context = json_encode([bin2hex($group), bin2hex($regex), $status, $description === null ? null : bin2hex($description), $ordinal, $categoriesId]) ?: '';

        $expected = CollectionRegex::normalizeCollectionRegexRulePhp($group, $regex, $status, $description, $ordinal);
        $this->assertSameAttributes($expected, CollectionRegex::normalizeCollectionRegexRuleNative($group, $regex, $status, $description, $ordinal), $context);
        CollectionRegex::setUseRustFfi(true);
        $this->assertSameAttributes($expected, CollectionRegex::normalizeCollectionRegexRule($group, $regex, $status, $description, $ordinal), $context);
        CollectionRegex::setUseRustFfi(null);
        $this->assertSameAttributes($expected, CollectionRegex::normalizeCollectionRegexRule($group, $regex, $status, $description, $ordinal), $context);

        $expected = ReleaseNamingRegex::normalizeReleaseNamingRegexRulePhp($group, $regex, $status, $description, $ordinal);
        $this->assertSameAttributes($expected, ReleaseNamingRegex::normalizeReleaseNamingRegexRuleNative($group, $regex, $status, $description, $ordinal), $context);
        ReleaseNamingRegex::setUseRustFfi(true);
        $this->assertSameAttributes($expected, ReleaseNamingRegex::normalizeReleaseNamingRegexRule($group, $regex, $status, $description, $ordinal), $context);
        ReleaseNamingRegex::setUseRustFfi(null);
        $this->assertSameAttributes($expected, ReleaseNamingRegex::normalizeReleaseNamingRegexRule($group, $regex, $status, $description, $ordinal), $context);

        $expected = CategoryRegex::normalizeCategoryRegexRulePhp($group, $regex, $status, $description, $ordinal, $categoriesId);
        $this->assertSameAttributes($expected, CategoryRegex::normalizeCategoryRegexRuleNative($group, $regex, $status, $description, $ordinal, $categoriesId), $context);
        CategoryRegex::setUseRustFfi(true);
        $this->assertSameAttributes($expected, CategoryRegex::normalizeCategoryRegexRule($group, $regex, $status, $description, $ordinal, $categoriesId), $context);
        CategoryRegex::setUseRustFfi(null);
        $this->assertSameAttributes($expected, CategoryRegex::normalizeCategoryRegexRule($group, $regex, $status, $description, $ordinal, $categoriesId), $context);
    }

    private function assertReleaseRegexParity(int $releases, int $collection, int $naming): void
    {
        $expected = ReleaseRegex::normalizeReleaseRegexLinkPhp($releases, $collection, $naming);
        $context = json_encode([$releases, $collection, $naming]) ?: '';
        $this->assertSameAttributes($expected, ReleaseRegex::normalizeReleaseRegexLinkNative($releases, $collection, $naming), $context);
        ReleaseRegex::setUseRustFfi(true);
        $this->assertSameAttributes($expected, ReleaseRegex::normalizeReleaseRegexLink($releases, $collection, $naming), $context);
        ReleaseRegex::setUseRustFfi(null);
        $this->assertSameAttributes($expected, ReleaseRegex::normalizeReleaseRegexLink($releases, $collection, $naming), $context);
    }

    private function assertIngestionParity(int ...$args): void
    {
        $this->assertCount(10, $args);
        $values = array_values($args);
        $expected = IngestionConfiguration::normalizeReleaseFormationRulesPhp(...$values);
        $context = json_encode($values) ?: '';
        $this->assertSameAttributes($expected, IngestionConfiguration::normalizeReleaseFormationRulesNative(...$values), $context);
        IngestionConfiguration::setUseRustFfi(true);
        $this->assertSameAttributes($expected, IngestionConfiguration::normalizeReleaseFormationRules(...$values), $context);
        IngestionConfiguration::setUseRustFfi(null);
        $this->assertSameAttributes($expected, IngestionConfiguration::normalizeReleaseFormationRules(...$values), $context);
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
            ['collection', ['^alt\.binaries\.teevee$', '/^(?P<match0>.+?)\s+\[\d+\/\d+\]/i', 1, 'Generic TV collection', 10]],
            ['collection', ['^alt\.binaries\.(movies|hdtv)$', '#^\[(?P<match0>\d+)\]-\[(?P<parts>\d+)\]#', 1, null, 25]],
            ['naming', ['^alt\.binaries\.boneless$', '/^(?P<match0>[\w.-]+)\s+yEnc/i', 1, 'Boneless names', 5]],
            ['category', ['^alt\.binaries\.sounds\.flac$', '/\bFLAC\b/i', 1, 'FLAC audio', 50, 3040]],
            ['link', [123_456_789, 42, 17]],
            ['ingestion', [95, 1, 0, 0, 2, 48, 2, 0, 1, 100_000]],
        ];
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function uncommonWorkload(): array
    {
        $long = str_repeat('Ünïcödé  Gröup  ', 30);

        return [
            ['collection', ["  \t".$long."\n", '/'.str_repeat('(?P<match0>a)(?<reqid>b)', 80).'/iu', 1, str_repeat($long, 5), PHP_INT_MAX]],
            ['collection', ["group\x1Fsep", '/x/', 1, null, 1]],
            ['naming', ['g', '/unterminated(?P<match0>', -3, "bad \xff desc", PHP_INT_MIN]],
            ['naming', ['  '.$long, '~'.str_repeat('(?P<match9>x)(?P<match1>y)', 60).'~x', 1, '', 7]],
            ['category', [$long, 'no delimiter', 1, null, -5, PHP_INT_MAX]],
            ['link', [PHP_INT_MIN, PHP_INT_MAX, -1]],
            ['ingestion', [PHP_INT_MAX, PHP_INT_MIN, 10, 5, PHP_INT_MAX, -1, 876_001, 1, 0, PHP_INT_MAX]],
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
                'collection' => CollectionRegex::normalizeCollectionRegexRule(...$args),
                'naming' => ReleaseNamingRegex::normalizeReleaseNamingRegexRule(...$args),
                'category' => CategoryRegex::normalizeCategoryRegexRule(...$args),
                'link' => ReleaseRegex::normalizeReleaseRegexLink(...$args),
                default => IngestionConfiguration::normalizeReleaseFormationRules(...$args),
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
            CollectionRegex::FFI_CDEF,
            ReleaseNamingRegex::FFI_CDEF,
            CategoryRegex::FFI_CDEF,
            ReleaseRegex::FFI_CDEF,
            IngestionConfiguration::FFI_CDEF,
        ]);
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', $cdef);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the regex/ingestion configuration model exports is not available.');
        }

        return $ffi;
    }
}
