<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Events\ReleaseNameFixed;
use App\Facades\Categorization;
use App\Models\ReleaseInform;
use App\Providers\CategorizationServiceProvider;
use App\Providers\ProcessingServiceProvider;
use App\Services\Categorization\CategorizationPipeline;
use App\Services\PostProcessService;
use App\Services\ReleaseProcessingService;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use Tests\TestCase;

final class RustCategorizationProvidersEventsInformParityTest extends TestCase
{
    /** @var list<class-string> */
    private const CLASSES = [
        CategorizationServiceProvider::class,
        ProcessingServiceProvider::class,
        Categorization::class,
        ReleaseNameFixed::class,
        ReleaseInform::class,
    ];

    /** Strings that exercise trimming, class/alias syntax, group parsing, UTF-8 and wire-marker fallbacks. */
    private const TEXTS = [
        '',
        ' ',
        "\0\x0B\x0C\t\r\n",
        'categorization',
        ' Categorization ',
        'cat.alias-1_x',
        '1alias',
        'App\\Services\\Categorization\\CategorizationPipeline',
        '\\App\\Services\\PostProcessService',
        '  \\\\App\\Services\\ReleaseProcessingService  ',
        'app\\services\\postprocessservice',
        'App\\\\Double',
        'Trailing\\',
        '9Lead\\X',
        '_Under\\Score_9',
        'Bad Name',
        'Some.Release.Name.2026.1080p.WEB-DL-GRP',
        ' Some.Release.Name.2026.1080p.WEB-DL-GRP ',
        'some.release.name.2026.1080p.web-dl-grp',
        'Some.Release.Name.2026.1080p.WEB-DL-GRP.REPACK',
        'poster@example.com (Poster)',
        'abc123DEF',
        'abc-123',
        '0',
        '00042',
        ' 42 ',
        '-5',
        '+5',
        '1e3',
        '9223372036854775807',
        '9223372036854775808',
        '000000000000000000000000000009223372036854775807',
        '99999999999999999999',
        'Ünïcödé  Nämé',
        "\u{00A0}nbsp\u{00A0}",
        '日本',
        "emoji \u{1F3AC} name",
        "bad \xff utf8",
        "\xc3",
        "\xed\xa0\x80",
        "sep\x1Finside",
        "null\x1Emark",
        "\x1E",
        "nul\0byte",
    ];

    private const INTS = [PHP_INT_MIN, -1, 0, 1, 7, 5040, 1700000000, PHP_INT_MAX];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
        }
        parent::tearDown();
    }

    public function test_rust_ffi_is_disabled_by_default_and_toggles(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi(), $class);
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi(), $class);
        }
    }

    public function test_existing_container_bindings_are_unchanged(): void
    {
        $this->assertSame('categorization', CategorizationServiceProvider::DEFAULT_ALIAS);
        $this->assertSame(CategorizationPipeline::class, $this->app->getAlias('categorization'));
        $this->assertTrue($this->app->isShared(CategorizationPipeline::class));
        $this->assertTrue($this->app->isShared(PostProcessService::class));
        $this->assertTrue($this->app->isShared(ReleaseProcessingService::class));
        $this->assertSame([PostProcessService::class, ReleaseProcessingService::class], ProcessingServiceProvider::SERVICES);
        $this->assertSame('release_informs', (new ReleaseInform)->getTable());

        $event = new ReleaseNameFixed(1, 'old', 'new', 10, '5');
        $this->assertSame('5', $event->groupId);
        $this->assertSame('', $event->poster);
        $this->assertNull($event->expectedSnapshot);
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame([
            'service_class' => CategorizationPipeline::class,
            'alias' => 'categorization',
            'short_name' => 'CategorizationPipeline',
            'namespace' => 'App\\Services\\Categorization',
            'class_valid' => true,
            'alias_valid' => true,
            'is_default' => true,
        ], CategorizationServiceProvider::normalizeBindingMetadataPhp(' \\'.CategorizationPipeline::class.' ', ' Categorization '));
        $meta = CategorizationServiceProvider::normalizeBindingMetadataPhp('App\\\\Bad', '1alias');
        $this->assertFalse($meta['class_valid'] || $meta['alias_valid'] || $meta['is_default']);
        $this->assertFalse(CategorizationServiceProvider::normalizeBindingMetadataPhp('X', str_repeat('a', 65))['alias_valid']);
        $this->assertTrue(CategorizationServiceProvider::normalizeBindingMetadataPhp('X', str_repeat('a', 64))['alias_valid']);

        $this->assertSame([
            'services' => [PostProcessService::class, ReleaseProcessingService::class],
            'short_names' => ['PostProcessService', 'ReleaseProcessingService'],
            'count' => 2,
            'duplicates' => 1,
            'invalid' => 3,
            'is_default' => true,
        ], ProcessingServiceProvider::normalizeServiceRegistryPhp([
            '\\'.PostProcessService::class, 'app\\services\\postprocessservice', 42, ' '.ReleaseProcessingService::class, '', 'Bad Name',
        ]));
        $this->assertFalse(ProcessingServiceProvider::normalizeServiceRegistryPhp([ReleaseProcessingService::class, PostProcessService::class])['is_default']);
        $this->assertSame(0, ProcessingServiceProvider::normalizeServiceRegistryPhp([])['count']);

        $this->assertSame([
            'group_id' => 42,
            'release_name' => 'Some.Release-GRP',
            'poster' => 'poster',
            'debug' => true,
            'has_group' => true,
            'has_poster' => true,
            'name_length' => 16,
            'is_valid' => true,
        ], Categorization::normalizeCategorizeCallArgsPhp(' 0042 ', "  Some.Release-GRP\n", ' poster ', true));
        $this->assertSame(0, Categorization::normalizeCategorizeCallArgsPhp(-5, 'x')['group_id']);
        $this->assertSame(PHP_INT_MAX, Categorization::normalizeCategorizeCallArgsPhp('9223372036854775807', 'x')['group_id']);
        $this->assertSame(0, Categorization::normalizeCategorizeCallArgsPhp('9223372036854775808', 'x')['group_id']);
        $this->assertSame(0, Categorization::normalizeCategorizeCallArgsPhp('1e3', 'x')['group_id']);
        $this->assertFalse(Categorization::normalizeCategorizeCallArgsPhp(1, ' ', null)['is_valid']);
        $this->assertSame('', Categorization::normalizeCategorizeCallArgsPhp(1, 'x', null)['poster']);

        $this->assertSame([
            'release_id' => 9,
            'old_name' => 'Old.Name',
            'new_name' => 'Old.Name.REPACK',
            'old_category_id' => 5040,
            'group_id' => 12,
            'changed' => true,
            'case_only' => false,
            'length_delta' => 7,
            'common_prefix_length' => 8,
            'is_valid' => true,
        ], ReleaseNameFixed::summarizeRenameDeltaPhp(9, ' Old.Name ', 'Old.Name.REPACK', 5040, '12'));
        $delta = ReleaseNameFixed::summarizeRenameDeltaPhp(9, 'abc', 'ABC', 0, 3);
        $this->assertTrue($delta['changed'] && $delta['case_only']);
        $this->assertSame(0, $delta['common_prefix_length']);
        $this->assertFalse(ReleaseNameFixed::summarizeRenameDeltaPhp(9, 'same', ' same ', 0, 3)['is_valid']);
        $this->assertFalse(ReleaseNameFixed::summarizeRenameDeltaPhp(0, 'a', 'b', 0, 3)['is_valid']);

        $this->assertSame([
            'relOName' => 'Obf.Name',
            'relPName' => 'Proper.Name',
            'api_token' => 'abc123',
            'names_fit' => true,
            'token_valid' => true,
            'is_rename' => true,
            'is_valid' => true,
        ], ReleaseInform::normalizeInformRecordPhp(' Obf.Name ', 'Proper.Name', ' abc123 '));
        $this->assertFalse(ReleaseInform::normalizeInformRecordPhp('a', 'a', 't')['is_valid']);
        $this->assertFalse(ReleaseInform::normalizeInformRecordPhp('a', 'b', 't-1')['token_valid']);
        $this->assertFalse(ReleaseInform::normalizeInformRecordPhp("\xff", 'b', 't')['names_fit']);
        $this->assertTrue(ReleaseInform::normalizeInformRecordPhp(str_repeat('é', 255), 'b', 't')['names_fit']);
        $this->assertFalse(ReleaseInform::normalizeInformRecordPhp(str_repeat('é', 256), 'b', 't')['names_fit']);
    }

    public function test_categorization_service_provider_native_and_dispatch_match_php(): void
    {
        foreach (self::TEXTS as $class) {
            foreach (self::TEXTS as $alias) {
                $this->assertBindingParity($class, $alias);
            }
        }
        $this->assertBindingParity(str_repeat('Segment\\', 500).'Last', str_repeat('a', 64));
    }

    public function test_processing_service_provider_native_and_dispatch_match_php(): void
    {
        $this->assertRegistryParity([]);
        $this->assertRegistryParity(ProcessingServiceProvider::SERVICES);
        $this->assertRegistryParity([...ProcessingServiceProvider::SERVICES, PostProcessService::class]);
        $this->assertRegistryParity([null, 1, 2.5, true, [], PostProcessService::class, ReleaseProcessingService::class]);
        $this->assertRegistryParity(['k' => PostProcessService::class, 9 => ReleaseProcessingService::class]);
        $this->assertRegistryParity(self::TEXTS);
        foreach (self::TEXTS as $text) {
            $this->assertRegistryParity([$text]);
            $this->assertRegistryParity([$text, PostProcessService::class, $text]);
        }
        $this->assertRegistryParity(array_map(static fn (int $i): string => 'App\\Svc'.($i % 300), range(1, 2000)));
    }

    public function test_categorization_facade_native_and_dispatch_match_php(): void
    {
        foreach (self::TEXTS as $text) {
            foreach ([$text, ...self::INTS] as $group) {
                $this->assertCallArgsParity($group, 'Some.Release-GRP', 'poster', false);
            }
            $this->assertCallArgsParity(5, $text, $text, true);
            $this->assertCallArgsParity('7', $text, null, false);
            $this->assertCallArgsParity($text, $text, $text, true);
        }
        $this->assertCallArgsParity(str_repeat('0', 4000).'1', str_repeat("Ü \t", 4000), str_repeat('p', 300), true);
    }

    public function test_release_name_fixed_native_and_dispatch_match_php(): void
    {
        foreach (self::TEXTS as $old) {
            foreach (self::TEXTS as $new) {
                $this->assertRenameParity(9, $old, $new, 5040, 3);
            }
            foreach (self::INTS as $int) {
                $this->assertRenameParity($int, $old, 'New.Name', $int, $int);
                $this->assertRenameParity($int, 'Old.Name', $old, $int, $old);
            }
        }
        $this->assertRenameParity(1, str_repeat('ab', 5000), str_repeat('ab', 4999).'aB', 1, '00001');
    }

    public function test_release_inform_native_and_dispatch_match_php(): void
    {
        foreach (self::TEXTS as $relO) {
            foreach (self::TEXTS as $relP) {
                $this->assertInformParity($relO, $relP, 'abc123');
            }
            $this->assertInformParity('Obf', 'Proper', $relO);
        }
        $this->assertInformParity(str_repeat('é', 255), str_repeat('日', 256), str_repeat('a', 255));
        $this->assertInformParity('o', 'p', str_repeat('a', 256));
    }

    public function test_randomized_inputs_match_php(): void
    {
        $random = new Randomizer(new Mt19937(3503));
        $alphabet = ['a', 'Z', 'p', 'S', '_', '.', '-', '\\', '0', '1', '9', ' ', "\t", "\n", "\0", "\x0B", "\x0C", 'é', 'ß', '日', "\u{1F3AC}", "\u{00A0}", "\x1F", "\x1E", "\xff", "\xc3"];
        $text = static function (int $max = 30) use ($random, $alphabet): string {
            $out = '';
            $length = $random->getInt(0, $max);
            for ($i = 0; $i < $length; $i++) {
                $out .= $alphabet[$random->getInt(0, count($alphabet) - 1)];
            }

            return $out;
        };
        $int = static fn (): int => match ($random->getInt(0, 2)) {
            0 => $random->getInt(-10, 10),
            1 => $random->getInt(0, 100_000),
            default => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
        };
        $group = static fn (): int|string => match ($random->getInt(0, 2)) {
            0 => $int(),
            1 => str_repeat(' ', $random->getInt(0, 2)).$random->getInt(0, PHP_INT_MAX).str_repeat('0', $random->getInt(0, 3)),
            default => $text(8),
        };

        for ($i = 0; $i < 1500; $i++) {
            $this->assertBindingParity($text(), $text(12));
            $entries = [];
            for ($n = $random->getInt(0, 6); $n > 0; $n--) {
                $entries[] = $random->getInt(0, 9) === 0 ? $int() : ($random->getInt(0, 1) === 1 ? 'App\\S'.$random->getInt(0, 3) : $text(12));
            }
            $this->assertRegistryParity($entries);
            $this->assertCallArgsParity($group(), $text(), $random->getInt(0, 3) === 0 ? null : $text(10), $random->getInt(0, 1) === 1);
            $old = $text();
            $this->assertRenameParity($int(), $old, $random->getInt(0, 2) === 0 ? strtoupper($old) : $old.$text(5), $int(), $group());
            $this->assertInformParity($text(), $text(), $random->getInt(0, 1) === 1 ? bin2hex($random->getBytes(8)) : $text(8));
        }
    }

    public function test_native_exports_validate_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();

        $small = $ffi->new('char[4]');
        FFI::memset($small, ord('Z'), 4);
        $input = "o\x1Fp\x1Ftok";
        $needed = $this->callFfi($ffi, 'nntmux_release_inform_normalize_record', $input, strlen($input), $small, 4);
        $this->assertLessThan(-1, $needed);
        $this->assertSame('ZZZZ', FFI::string($small, 4));
        $buffer = $ffi->new('char['.(-$needed).']');
        $this->assertSame(-$needed, $this->callFfi($ffi, 'nntmux_release_inform_normalize_record', $input, strlen($input), $buffer, -$needed));
        $this->assertSame("o\x1Fp\x1Ftok\x1F1\x1F1\x1F1\x1F1", FFI::string($buffer, -$needed));

        $needed = $this->callFfi($ffi, 'nntmux_processing_service_provider_normalize_registry', 0, '', 0, $small, 4);
        $this->assertLessThan(-1, $needed);
        $this->assertSame('ZZZZ', FFI::string($small, 4));

        $out = $ffi->new('char[512]');
        $input = '\\'.CategorizationPipeline::class."\x1FCategorization";
        $written = $this->callFfi($ffi, 'nntmux_categorization_service_provider_binding_metadata', $input, strlen($input), $out, 512);
        $this->assertSame(CategorizationPipeline::class."\x1Fcategorization\x1FCategorizationPipeline\x1FApp\\Services\\Categorization\x1F1\x1F1\x1F1", FFI::string($out, $written));
        $input = "App\\A\x1Fapp\\a\x1F1bad";
        $written = $this->callFfi($ffi, 'nntmux_processing_service_provider_normalize_registry', 3, $input, strlen($input), $out, 512);
        $this->assertSame("1\x1F1\x1F1\x1F0\x1FApp\\A\x1FA", FFI::string($out, $written));
        $input = PostProcessService::class."\x1F".ReleaseProcessingService::class;
        $written = $this->callFfi($ffi, 'nntmux_processing_service_provider_normalize_registry', 2, $input, strlen($input), $out, 512);
        $this->assertStringStartsWith("2\x1F0\x1F0\x1F1\x1F", FFI::string($out, $written));
        $input = " 12 \x1F Name \x1Fposter";
        $written = $this->callFfi($ffi, 'nntmux_categorization_normalize_call_args', 7, 0, $input, strlen($input), $out, 512);
        $this->assertSame("12\x1FName\x1Fposter\x1F1\x1F1\x1F1\x1F4\x1F1", FFI::string($out, $written));
        $input = "\x1Fold\x1Fnew";
        $written = $this->callFfi($ffi, 'nntmux_release_name_fixed_summarize_delta', 0, 9, 5000, -3, $input, strlen($input), $out, 512);
        $this->assertSame("9\x1Fold\x1Fnew\x1F5000\x1F0\x1F1\x1F0\x1F0\x1F0\x1F1", FFI::string($out, $written));

        // Wrong field counts, null markers, unknown flag bits and text for absent fields decline with -1.
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_categorization_service_provider_binding_metadata', 'only', 4, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_categorization_service_provider_binding_metadata', "a\x1Fb\x1Fc", 5, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_categorization_service_provider_binding_metadata', "a\x1E\x1Fb", 4, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_processing_service_provider_normalize_registry', 0, 'x', 1, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_processing_service_provider_normalize_registry', 2, 'x', 1, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_processing_service_provider_normalize_registry', 1, "x\x1Fy", 3, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_processing_service_provider_normalize_registry', 1, "\x1E", 1, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_categorization_normalize_call_args', 0, 0, "1\x1Fn\x1F", 4, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_categorization_normalize_call_args', 0, 0, "\x1Fn\x1Fp", 4, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_categorization_normalize_call_args', 8, 0, "\x1Fn\x1F", 3, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_categorization_normalize_call_args', 0, 0, "\x1Fn", 2, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_release_name_fixed_summarize_delta', 0, 1, 1, 1, "1\x1Fa\x1Fb", 5, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_release_name_fixed_summarize_delta', 2, 1, 1, 1, "\x1Fa\x1Fb", 4, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_release_inform_normalize_record', "a\x1Fb", 3, $out, 512));

        // Null pointers decline instead of crashing.
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_categorization_service_provider_binding_metadata', null, 0, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_processing_service_provider_normalize_registry', 0, null, 0, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_categorization_normalize_call_args', 0, 0, null, 0, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_release_name_fixed_summarize_delta', 0, 0, 0, 0, null, 0, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_release_inform_normalize_record', null, 0, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_release_inform_normalize_record', "o\x1Fp\x1Ft", 5, null, 512));

        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(true);
        }
        CategorizationServiceProvider::normalizeBindingMetadata(CategorizationPipeline::class, 'categorization');
        ProcessingServiceProvider::normalizeServiceRegistry(ProcessingServiceProvider::SERVICES);
        Categorization::normalizeCategorizeCallArgs(1, 'name');
        ReleaseNameFixed::summarizeRenameDelta(1, 'a', 'b', 1, 1);
        ReleaseInform::normalizeInformRecord('a', 'b', 't');
        foreach (self::CLASSES as $class) {
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'ffi'))->getValue(), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        $property = static fn (string $class): ReflectionProperty => new ReflectionProperty($class, 'ffi');
        $saved = [];
        foreach (self::CLASSES as $class) {
            $saved[$class] = $property($class)->getValue();
            $property($class)->setValue(null, false);
            $class::setUseRustFfi(true);
        }

        try {
            $this->assertSame(
                CategorizationServiceProvider::normalizeBindingMetadataPhp(' \\App\\X ', ' Alias '),
                CategorizationServiceProvider::normalizeBindingMetadata(' \\App\\X ', ' Alias '),
            );
            $this->assertSame(
                ProcessingServiceProvider::normalizeServiceRegistryPhp([PostProcessService::class, 3, 'bad name']),
                ProcessingServiceProvider::normalizeServiceRegistry([PostProcessService::class, 3, 'bad name']),
            );
            $this->assertSame(
                Categorization::normalizeCategorizeCallArgsPhp(' 7 ', ' Name ', null, true),
                Categorization::normalizeCategorizeCallArgs(' 7 ', ' Name ', null, true),
            );
            $this->assertSame(
                ReleaseNameFixed::summarizeRenameDeltaPhp(1, 'Old', 'New', 2, '3'),
                ReleaseNameFixed::summarizeRenameDelta(1, 'Old', 'New', 2, '3'),
            );
            $this->assertSame(
                ReleaseInform::normalizeInformRecordPhp('o', 'p', 'tok'),
                ReleaseInform::normalizeInformRecord('o', 'p', 'tok'),
            );
        } finally {
            foreach (self::CLASSES as $class) {
                $property($class)->setValue(null, $saved[$class]);
            }
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of the five helpers through their public dispatchers,
        // including wire packing, the FFI call and decoding (release dylib, 5 warmups, 301 samples x 40 iterations).
        // Common = default bindings, registry, categorize args, renames and inform rows; uncommon = long,
        // Unicode, padded, out-of-range, invalid and fallback inputs.
        // Speedup = PHP latency / Rust latency per workload pass (PHP 8.5.12 NTS, Apple M5, 4 rounds):
        // - Round 1: common mean 0.38x / p99 0.40x, uncommon mean 0.53x / p99 0.56x
        // - Round 2: common mean 0.39x / p99 0.41x, uncommon mean 0.53x / p99 0.54x
        // - Round 3: common mean 0.39x / p99 0.45x, uncommon mean 0.53x / p99 0.54x
        // - Round 4: common mean 0.39x / p99 0.41x, uncommon mean 0.53x / p99 0.53x
        // Reproduce: php vendor/bin/phpunit tests/Unit/RustCategorizationProvidersEventsInformParityTest.php --filter benchmark
        // Microsecond PHP string/int work is cheaper than the FFI round trip, so every helper stays below the
        // 1.5x bar in .ai/rules/rust-performance.md and keeps RUST_FFI_DEFAULT = false.
        $this->ffiOrSkip();
        $workloads = ['common' => $this->commonWorkload(), 'uncommon' => $this->uncommonWorkload()];
        $random = new Randomizer(new Mt19937(3503));
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
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi(null);
            }
            $speedups[$name] = [
                'mean' => $this->mean($times['php']) / $this->mean($times['rust']),
                'p99' => $this->p99($times['php']) / $this->p99($times['rust']),
            ];
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustCategorizationProvidersEventsInform speedup: {$summary}\n");
        if ($speedups['common']['mean'] < 1.5 || $speedups['common']['p99'] < 1.5 || $speedups['uncommon']['p99'] < 1.5) {
            foreach (self::CLASSES as $class) {
                $this->assertFalse($class::usesRustFfi(), $class.' must keep PHP below 1.5x: '.$summary);
            }
        }
        foreach ($speedups as $name => $speedup) {
            $this->assertGreaterThan(0.0, $speedup['mean'], $name);
            $this->assertGreaterThan(0.0, $speedup['p99'], $name);
        }
    }

    private function assertBindingParity(string $class, string $alias): void
    {
        $expected = CategorizationServiceProvider::normalizeBindingMetadataPhp($class, $alias);
        $context = json_encode([bin2hex($class), bin2hex($alias)]) ?: '';
        $this->assertSameAttributes($expected, CategorizationServiceProvider::normalizeBindingMetadataNative($class, $alias), $context);
        CategorizationServiceProvider::setUseRustFfi(true);
        $this->assertSameAttributes($expected, CategorizationServiceProvider::normalizeBindingMetadata($class, $alias), $context);
        CategorizationServiceProvider::setUseRustFfi(false);
        $this->assertSameAttributes($expected, CategorizationServiceProvider::normalizeBindingMetadata($class, $alias), $context);
        CategorizationServiceProvider::setUseRustFfi(null);
    }

    /**
     * @param  array<mixed>  $entries
     */
    private function assertRegistryParity(array $entries): void
    {
        $expected = ProcessingServiceProvider::normalizeServiceRegistryPhp($entries);
        $context = json_encode(array_map(static fn (mixed $e): mixed => is_string($e) ? bin2hex($e) : get_debug_type($e), $entries)) ?: '';
        $this->assertSameAttributes($expected, ProcessingServiceProvider::normalizeServiceRegistryNative($entries), $context);
        ProcessingServiceProvider::setUseRustFfi(true);
        $this->assertSameAttributes($expected, ProcessingServiceProvider::normalizeServiceRegistry($entries), $context);
        ProcessingServiceProvider::setUseRustFfi(false);
        $this->assertSameAttributes($expected, ProcessingServiceProvider::normalizeServiceRegistry($entries), $context);
        ProcessingServiceProvider::setUseRustFfi(null);
    }

    private function assertCallArgsParity(int|string $groupId, string $releaseName, ?string $poster, bool $debug): void
    {
        $expected = Categorization::normalizeCategorizeCallArgsPhp($groupId, $releaseName, $poster, $debug);
        $context = json_encode([is_string($groupId) ? bin2hex($groupId) : $groupId, bin2hex($releaseName), $poster === null ? null : bin2hex($poster), $debug]) ?: '';
        $this->assertSameAttributes($expected, Categorization::normalizeCategorizeCallArgsNative($groupId, $releaseName, $poster, $debug), $context);
        Categorization::setUseRustFfi(true);
        $this->assertSameAttributes($expected, Categorization::normalizeCategorizeCallArgs($groupId, $releaseName, $poster, $debug), $context);
        Categorization::setUseRustFfi(false);
        $this->assertSameAttributes($expected, Categorization::normalizeCategorizeCallArgs($groupId, $releaseName, $poster, $debug), $context);
        Categorization::setUseRustFfi(null);
    }

    private function assertRenameParity(int $releaseId, string $oldName, string $newName, int $oldCategoryId, int|string $groupId): void
    {
        $args = [$releaseId, $oldName, $newName, $oldCategoryId, $groupId];
        $expected = ReleaseNameFixed::summarizeRenameDeltaPhp(...$args);
        $context = json_encode([$releaseId, bin2hex($oldName), bin2hex($newName), $oldCategoryId, is_string($groupId) ? bin2hex($groupId) : $groupId]) ?: '';
        $this->assertSameAttributes($expected, ReleaseNameFixed::summarizeRenameDeltaNative(...$args), $context);
        ReleaseNameFixed::setUseRustFfi(true);
        $this->assertSameAttributes($expected, ReleaseNameFixed::summarizeRenameDelta(...$args), $context);
        ReleaseNameFixed::setUseRustFfi(false);
        $this->assertSameAttributes($expected, ReleaseNameFixed::summarizeRenameDelta(...$args), $context);
        ReleaseNameFixed::setUseRustFfi(null);
    }

    private function assertInformParity(string $relO, string $relP, string $token): void
    {
        $expected = ReleaseInform::normalizeInformRecordPhp($relO, $relP, $token);
        $context = json_encode([bin2hex($relO), bin2hex($relP), bin2hex($token)]) ?: '';
        $this->assertSameAttributes($expected, ReleaseInform::normalizeInformRecordNative($relO, $relP, $token), $context);
        ReleaseInform::setUseRustFfi(true);
        $this->assertSameAttributes($expected, ReleaseInform::normalizeInformRecord($relO, $relP, $token), $context);
        ReleaseInform::setUseRustFfi(false);
        $this->assertSameAttributes($expected, ReleaseInform::normalizeInformRecord($relO, $relP, $token), $context);
        ReleaseInform::setUseRustFfi(null);
    }

    /**
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
            ['binding', [CategorizationPipeline::class, 'categorization']],
            ['registry', [[PostProcessService::class, ReleaseProcessingService::class]]],
            ['call', [12, 'Some.Show.S01E02.1080p.WEB.h264-GRP', 'poster@example.com (Poster)', false]],
            ['call', ['45', 'Some.Movie.2026.2160p.UHD.BluRay.x265-GRP', '', false]],
            ['rename', [123456, 'a1b2c3d4e5f6a7b8c9d0', 'Some.Show.S01E02.1080p.WEB.h264-GRP', 7010, 12]],
            ['rename', [123457, 'Some.Movie.2026.1080p', 'Some.Movie.2026.1080p.REPACK', 2040, '45']],
            ['inform', ['a1b2c3d4e5f6a7b8c9d0', 'Some.Show.S01E02.1080p.WEB.h264-GRP', 'd41d8cd98f00b204e9800998ecf8427e']],
        ];
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function uncommonWorkload(): array
    {
        $long = str_repeat('Ünïcödé.Rëlëäsë.', 60);

        return [
            ['binding', ['  \\\\'.str_repeat('Deep\\', 20).'Service  ', ' Very-Long.Alias_'.str_repeat('x', 60).' ']],
            ['registry', [[...array_map(static fn (int $i): string => 'App\\Svc'.($i % 7), range(1, 30)), 1, null, 'bad name']]],
            ['call', ['  000000000000000000000009223372036854775808 ', "  {$long}\n", null, true]],
            ['call', [PHP_INT_MIN, "bad \xff utf8\x1Fwith marker", ' ', true]],
            ['rename', [PHP_INT_MAX, "  {$long}  ", strtoupper($long), PHP_INT_MIN, ' 99999999999999999999 ']],
            ['rename', [0, '', ' ', 0, '1e3']],
            ['inform', [str_repeat('é', 255), str_repeat('日', 256), str_repeat('a', 256)]],
        ];
    }

    /**
     * @param  list<array{string, list<mixed>}>  $workload
     */
    private function runWorkload(array $workload, bool $useRust): int
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi($useRust);
        }
        $count = 0;
        foreach ($workload as [$kind, $args]) {
            $result = match ($kind) {
                'binding' => CategorizationServiceProvider::normalizeBindingMetadata(...$args),
                'registry' => ProcessingServiceProvider::normalizeServiceRegistry(...$args),
                'call' => Categorization::normalizeCategorizeCallArgs(...$args),
                'rename' => ReleaseNameFixed::summarizeRenameDelta(...$args),
                default => ReleaseInform::normalizeInformRecord(...$args),
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
            CategorizationServiceProvider::FFI_CDEF,
            ProcessingServiceProvider::FFI_CDEF,
            Categorization::FFI_CDEF,
            ReleaseNameFixed::FFI_CDEF,
            ReleaseInform::FFI_CDEF,
        ]);
        $ffi = RustPipelineLibrary::resolveDomain('categorize', $cdef);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the categorization provider/event/inform exports is not available.');
        }

        return $ffi;
    }
}
