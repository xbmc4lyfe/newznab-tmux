<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Categorizers\AbstractCategorizer;
use App\Services\Categorization\Categorizers\MiscCategorizer;
use App\Services\Categorization\Pipes\AbstractCategorizationPipe;
use App\Services\Categorization\Pipes\CategorizationPassable;
use App\Services\Categorization\Pipes\MiscSafetyNetPipe;
use App\Services\Categorization\ReleaseContext;
use App\Support\AdminReleaseNames;
use App\Support\ReleaseNameDisplay;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

final class RustAbstractCategorizerPipeSafetyNetDisplayNamesW46ParityTest extends TestCase
{
    /** Every Wave 46.3 target shares the W463 toggle names and keeps its existing Rust paths separate. */
    private const TARGETS = [
        AbstractCategorizer::class,
        AbstractCategorizationPipe::class,
        MiscSafetyNetPipe::class,
        ReleaseNameDisplay::class,
        AdminReleaseNames::class,
    ];

    /** Production defaults: each helper defaults to Rust only when it clears 1.5x on every measure below. */
    private const DEFAULTS = [
        AbstractCategorizer::class => true,
        AbstractCategorizationPipe::class => false,
        MiscSafetyNetPipe::class => false,
        ReleaseNameDisplay::class => true,
        AdminReleaseNames::class => true,
    ];

    /** Helper per target: [class, method, export]. */
    private const HELPERS = [
        'shape' => [AbstractCategorizer::class, 'summarizeReleaseNameShapeBatch', 'nntmux_w463_categorizer_release_name_shape_batch'],
        'confidence' => [AbstractCategorizationPipe::class, 'summarizeSkipGuardConfidenceBatch', 'nntmux_w463_pipe_confidence_batch'],
        'downgrade' => [MiscSafetyNetPipe::class, 'summarizeDowngradeBatch', 'nntmux_w463_safety_net_downgrade_batch'],
        'display' => [ReleaseNameDisplay::class, 'summarizeDisplayWrapperBatch', 'nntmux_w463_release_name_display_batch'],
        'admin' => [AdminReleaseNames::class, 'summarizeNameDisplayBatch', 'nntmux_w463_admin_name_display_batch'],
    ];

    /** Release names and subjects covering separators, trim sets, group suffixes, years and display wrappers. */
    private const NAMES = [
        '', ' ', "\0", "\t", "\n", "\x0B", "\x0C", "\r", ' \t\0', '.', '_', '[', '(', '-', '-a', '-ab', 'x-ab', 'x-ab.', 'x-'.'abcdefghijklmno',
        'x-abcdefghijklmnop', '1999', '2000', '21999', '19999', '1899', 'x2024y', 'x.2024.y', '2024-GRP', '12024-x',
        'Movie.Title.2024.1080p.WEB-GRP', 'Show Name S01E02 [720p]', 'Artist_-_Album-(CAT01)-WEB-FLAC-2019-GRP', 'Book Title (2020) [EPUB]',
        'Game.Title.v1.2-RUNE', 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6', 'Ünïcödé.FLAC-GRP', "Spiel\u{a0}Titel", "bad.\xff.utf8",
        '[1/2] - "file.part1.rar" yEnc (1/50)', ' [ 01 / 10 ] &quot;Show.S01E01.mkv&QUOT; - YENC', '[1/2] #34;a#34;', "[1/2] &#34;b&#34;\n",
        '[1/2] " " yEnc', '[1/2] "file" extra', '[1/2] "file" yEnc(1/2)', "[1/2] \"fi\nle\"", '[1/2] ""', "[1/2] \"file\" yEnc (1/2) \x0C",
        '[1/2]"x"', '[12/345] - "Movie.2024.1080p.WEB-GRP.mkv" yEnc', '[1/2] "a"b"', "[1/2] \"a\"\n\n", "\t[1/2]\t\"a\"\t", '[1/2] "a" - yEnc (1/2)',
        '[1/2] "a" yEnc (1/2) x', '[1/2] "a"yEnc', "[1/2] \"\0x\0\"", "[1/2] \"a\r\"", '[ /2] "a"', '[1/] "a"', '[1/2] -- "a"', '[1/2] - - "a"',
        '[1/2] "a" -', '[1/2] "a" yEnc (', '[1/2] "a" yEnc (1/2', '[1/2] &quot;a"b&quot;', '[1/2] &QuOt;x&qUoT; yenc', '[1/2] #34;x&#34;',
        '[1/2] &#34;#34;&#34;', '[1/2] "Tom & Jerry.mkv"', '[1/2] "<b>.rar"', "[1/2] \"it's.rar\"", '[1/2] "é.rar"', 'Tom & Jerry', '<b>bold</b>',
        "it's", '"quoted"',
    ];

    /** Fragments for random names and subjects. */
    private const WORDS = ['Movie', 'Show', 'Title', 'GRP', '-GRP', '.2024', '.1999', '.1080p', '.WEB', 'S01E02', ' ', '_', '.', '-', '[', ']', '(', ')',
        '[1/2]', '[ 3 / 40 ]', '"', '#34;', '&#34;', '&quot;', '&QUOT;', 'yEnc', 'YENC', ' (1/2)', '(1/2)', ' - ', "\t", "\n", "\r", "\0", "\x0B", "\x0C",
        '&', '<', '>', "'", '20', '19', '0', '9', 'é', "\xff"];

    private const LABELS = ['group_name_tv', 'group_name_movies', 'group_name_', 'group_name', 'Group_name_x', 'GROUP_NAME_tv', '', 'hash_md5', 'no_match',
        'group_only_low_signal', "group_name_\0", 'xgroup_name_', 'group_name_é'];

    private const CONFIDENCES = [0.95, 0.9499999999999999, 0.95000001, 0.8, 0.7999999999999999, 0.5, 0.49999999999999994, 0.0, -0.0, 1.0e-300, -1.0e-300,
        1.0, 2.0, -1.0, NAN, INF, -INF, 0, 1, -5, PHP_INT_MAX, PHP_INT_MIN, 0.65, 0.97];

    private const UNCOMMON_LENGTHS = [120, 180, 240, 255, 256, 320, 400];

    private const PCRE_SETTINGS = ['pcre.backtrack_limit', 'pcre.recursion_limit', 'pcre.jit'];

    /** @var array<string, string|false> */
    private array $pcre = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::PCRE_SETTINGS as $setting) {
            $this->pcre[$setting] = ini_get($setting);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::TARGETS as $class) {
            $class::resetW463RustFfi();
        }
        foreach ($this->pcre as $setting => $value) {
            if ($value !== false) {
                ini_set($setting, $value);
            }
        }
        AbstractCategorizer::setUseRustFfi(false);
        AbstractCategorizationPipe::setUseRustFfi(false);
        MiscSafetyNetPipe::setUseRustFfi(false);
        ReleaseNameDisplay::$useRustFfi = false;
        AdminReleaseNames::resetRustFfi();
        parent::tearDown();
    }

    public function test_rust_ffi_defaults_and_toggles(): void
    {
        foreach (self::TARGETS as $class) {
            $reflection = new ReflectionClass($class);
            foreach (['useW463RustFfi', 'usesW463RustFfi', 'resetW463RustFfi'] as $method) {
                $this->assertTrue($reflection->hasMethod($method), $class.'::'.$method);
            }
            $this->assertFalse($reflection->isReadOnly(), $class);
            $default = self::DEFAULTS[$class];
            $this->assertSame($default, constant($class.'::W463_RUST_FFI_DEFAULT'), $class);
            $this->assertSame($default, $class::usesW463RustFfi(), $class);
            $class::useW463RustFfi();
            $this->assertTrue($class::usesW463RustFfi(), $class);
            $class::useW463RustFfi(false);
            $this->assertFalse($class::usesW463RustFfi(), $class);
            $class::useW463RustFfi(null);
            $this->assertSame($default, $class::usesW463RustFfi(), $class);
            $class::useW463RustFfi(true);
            $this->assertTrue($class::usesW463RustFfi(), $class);
            $class::resetW463RustFfi();
            $this->assertNull((new ReflectionProperty($class, 'w463RustFfiOverride'))->getValue(), $class);
            $this->assertNull((new ReflectionProperty($class, 'w463RustFfiHandle'))->getValue(), $class);
            $this->assertSame($default, $class::usesW463RustFfi(), $class);
        }

        // MiscSafetyNetPipe keeps its own W463 state, separate from its parent's.
        AbstractCategorizationPipe::useW463RustFfi(true);
        MiscSafetyNetPipe::useW463RustFfi(false);
        $this->assertTrue(AbstractCategorizationPipe::usesW463RustFfi());
        $this->assertFalse(MiscSafetyNetPipe::usesW463RustFfi());
        MiscSafetyNetPipe::resetW463RustFfi();
        $this->assertTrue(AbstractCategorizationPipe::usesW463RustFfi());

        // The W463 toggles never touch the classes' existing Rust toggles or behaviour.
        foreach ([true, false] as $enable) {
            foreach (self::TARGETS as $class) {
                $class::useW463RustFfi($enable);
            }
            $this->assertFalse(AbstractCategorizer::$useRustFfi);
            $this->assertFalse(AbstractCategorizationPipe::$useRustFfi);
            $this->assertFalse(MiscSafetyNetPipe::$useRustFfi);
            $this->assertFalse(ReleaseNameDisplay::$useRustFfi);
            $this->assertFalse(AdminReleaseNames::usesRustFfi());
            $this->assertFalse(MiscSafetyNetPipe::usesPredbTransactionRustFfi());

            $categorizer = new MiscCategorizer;
            $this->assertSame($categorizer->getPriorityPhp(), $categorizer->getPriority());
            $this->assertFalse($categorizer->shouldSkip(new ReleaseContext('Movie.Title.2024-GRP', 1, 'alt.binaries.movies')));
            $pipe = new MiscSafetyNetPipe;
            $this->assertTrue($pipe->evaluateSkipGuard(false, 0.95));
            $this->assertFalse($pipe->evaluateSkipGuard(false, 0.9499));
            $passable = new CategorizationPassable(new ReleaseContext('a1b2c3d4e5f6g7h8', 1, 'alt.binaries.misc'), false);
            $passable->bestResult = new CategorizationResult(5000, 0.7, 'group_name_tv');
            $passable->miscAnalysis = ['lowSignal' => true];
            $this->assertTrue($pipe->shouldDowngradeToMisc($passable));
            $this->assertSame('file.rar', ReleaseNameDisplay::clean('[1/2] "file.rar" yEnc'));
            $this->assertSame(['file.rar', 'Plain'], ReleaseNameDisplay::cleanBatch(['[1/2] "file.rar"', 'Plain']));
        }
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame(
            ['total' => 11, 'empty' => 3, 'dotted' => 2, 'spaced' => 2, 'underscored' => 1, 'bracketed' => 2, 'group_suffix' => 4, 'year' => 2],
            AbstractCategorizer::summarizeReleaseNameShapeBatch(['Movie.Title.2024.1080p.WEB-GRP', 'Show Name S01E02 [720p]',
                'Artist_-_Album-(CAT01)-WEB-FLAC-2019-GRP', '', " \t\0\x0B", "\x0C", '12024-x', 'a-0123456789ABCDEF', '-GRP',
                str_repeat('Movie.Title.', 20).'-GRP', null]),
        );
        // FF is not a trim() character, so "\x0C" is not empty.
        $this->assertSame(0, AbstractCategorizer::summarizeReleaseNameShapeBatchPhp(["\x0C"])['empty']);

        $this->assertSame(
            ['total' => 15, 'stop' => 3, 'high' => 2, 'medium' => 2, 'low' => 1, 'none' => 4, 'nan' => 1, 'invalid' => 2],
            AbstractCategorizationPipe::summarizeSkipGuardConfidenceBatch([0.95, 1.0, 0.9499, 0.8, 0.5, 0.79, 0.01, 0.0, -0.0, -1.0, NAN, INF, -INF,
                '0.99', null]),
        );
        // The stop band is exactly evaluateSkipGuardPhp()'s threshold.
        $pipe = new MiscSafetyNetPipe;
        foreach (self::CONFIDENCES as $confidence) {
            $stop = AbstractCategorizationPipe::summarizeSkipGuardConfidenceBatchPhp([$confidence])['stop'] === 1;
            $this->assertSame($pipe->evaluateSkipGuardPhp(false, (float) $confidence), $stop, var_export($confidence, true));
        }

        $this->assertSame(
            ['total' => 7, 'group_matched' => 3, 'low_signal' => 4, 'locked' => 1, 'downgrade' => 1],
            MiscSafetyNetPipe::summarizeDowngradeBatch(
                ['group_name_tv', 'group_name_movies', 'group_name', '', 'hash_md5', 'group_name_x', 42],
                [true, true, true, true, false, 1, 9 => true],
                [false, true],
            ),
        );
        // Each entry agrees with shouldDowngradeToMiscPhp().
        foreach (self::LABELS as $label) {
            foreach ([[true, false], [true, true], [false, false]] as [$low, $locked]) {
                $passable = new CategorizationPassable(new ReleaseContext('x', 1, 'alt.binaries.misc'), false);
                $passable->bestResult = new CategorizationResult(5000, 0.7, $label);
                $passable->miscAnalysis = ['lowSignal' => $low];
                if ($locked) {
                    $passable->lockToMisc();
                }
                $this->assertSame(
                    (int) $pipe->shouldDowngradeToMiscPhp($passable),
                    MiscSafetyNetPipe::summarizeDowngradeBatchPhp([$label], [$low], [$locked])['downgrade'],
                    $label,
                );
            }
        }

        $this->assertSame(
            ['total' => 13, 'empty' => 2, 'wrapped' => 5, 'blank_file' => 1, 'entity' => 3, 'yenc' => 4],
            ReleaseNameDisplay::summarizeDisplayWrapperBatch(['[1/2] - "file.part1.rar" yEnc (1/50)', ' [ 01 / 10 ] &quot;Show.S01E01.mkv&QUOT; - YENC',
                '[1/2] #34;a#34;', "[1/2] &#34;b&#34;\n", '[1/2] " " yEnc', '[1/2] "file" extra', '[1/2] "file" yEnc(1/2)', "[1/2] \"fi\nle\"",
                '[1/2] ""', 'Plain.Release.Name-GRP', '', "[1/2] \"file\" yEnc (1/2) \x0C", null]),
        );
        // wrapped counts exactly the names cleanPhp() changes.
        $changed = count(array_filter(self::NAMES, static fn (string $name): bool => ReleaseNameDisplay::cleanPhp($name) !== $name));
        $this->assertSame($changed, ReleaseNameDisplay::summarizeDisplayWrapperBatchPhp(self::NAMES)['wrapped']);

        $this->assertSame(
            ['rows' => 7, 'display_cleaned' => 1, 'original_cleaned' => 2, 'empty_display' => 4, 'secondary' => 2, 'escaped' => 1],
            AdminReleaseNames::summarizeNameDisplayBatch([
                ['searchname' => '[1/2] "Movie.mkv" yEnc', 'name' => '[1/2] "Movie.mkv" yEnc'],
                ['searchname' => 'Tom & Jerry', 'name' => '[3/4] "Tom.and.Jerry.rar"'],
                ['searchname' => '', 'name' => ''],
                ['searchname' => 'Same', 'name' => 'Same'],
                ['searchname' => null, 'name' => 'Other'],
                'not a row',
                ['guid' => 'x'],
            ]),
        );

        foreach (self::HELPERS as [$class, $method]) {
            $empty = $class::{$method.'Php'}([]);
            $this->assertSame(array_fill_keys(array_keys($empty), 0), $empty, $class);
        }
    }

    public function test_edge_cases_match_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $names = self::NAMES;
        $ascii = array_values(array_filter($names, static fn (string $name): bool => ! preg_match('/[\x80-\xff]/', $name)));
        foreach ([[], $names, $ascii, ['a' => 'Movie.Title-GRP', 'b' => '[1/2] "x"'], [str_repeat('[1/2] "Movie.Title.2024-GRP" yEnc ', 64)],
            [str_repeat('a', 4096), '[1/2] "'.str_repeat('a', 4088).'"', str_repeat('a', 4097)]] as $list) {
            $this->assertParity('shape', [$list]);
            $this->assertParity('display', [$list]);
            $this->assertParity('admin', [$this->rows($list)]);
        }
        foreach ($names as $index => $name) {
            $variants = [$name, strtoupper($name), strtolower($name), ' '.$name.' ', $name.'-GRP', $name.$name, $name.' yEnc', '[1/2] "'.$name.'"'];
            $this->assertParity('shape', [$variants]);
            $this->assertParity('display', [$variants]);
            $this->assertParity('admin', [$this->rows($variants)]);
            $this->assertParity('admin', [[['searchname' => $name, 'name' => $names[($index + 1) % count($names)]]]]);
            $slice = array_slice([...$ascii, ...$ascii], $index, 1 + $index % 13);
            $this->assertParity('display', [$slice]);
            $this->assertParity('admin', [$this->rows($slice)]);
        }

        $this->assertParity('confidence', [[]]);
        $this->assertParity('confidence', [self::CONFIDENCES]);
        $this->assertParity('confidence', [['x' => 0.5, 'y' => 0.99, 3 => 1]]);
        foreach (self::CONFIDENCES as $confidence) {
            $this->assertParity('confidence', [[$confidence]]);
        }

        $this->assertParity('downgrade', [[]]);
        $this->assertParity('downgrade', [self::LABELS]);
        $this->assertParity('downgrade', [self::LABELS, array_fill(0, count(self::LABELS), true)]);
        $this->assertParity('downgrade', [self::LABELS, array_fill(0, count(self::LABELS), true), array_fill(0, 5, true)]);
        $this->assertParity('downgrade', [self::LABELS, [1, 'true', true, -1 => true, 'x' => true, 100 => true], [2 => true, 3 => 1]]);
        $this->assertParity('downgrade', [['group_name_tv'], [true], [true]]);
    }

    public function test_randomized_inputs_match_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4631));
        $text = function () use ($random): string {
            $out = '';
            for ($i = $random->getInt(0, 14); $i > 0; $i--) {
                $out .= self::WORDS[$random->getInt(0, count(self::WORDS) - 1)];
            }
            $out = match ($random->getInt(0, 7)) {
                0 => strtoupper($out),
                1 => ' '.$out."\t",
                2 => '[1/2] "'.$out.'" yEnc',
                3 => '['.$random->getInt(0, 99).'/'.$random->getInt(0, 99).'] - &quot;'.$out.'&quot;'.($random->getInt(0, 1) === 1 ? ' yEnc ('.$random->getInt(1, 9).'/9)' : ''),
                4 => $out.str_repeat('z', $random->getInt(200, 300)),
                default => $out,
            };

            return $random->getInt(0, 2) === 0 ? $out : str_replace(['é', "\xff"], ['e', 'f'], $out);
        };

        for ($i = 0; $i < 600; $i++) {
            $list = [];
            for ($n = $random->getInt(0, 30); $n > 0; $n--) {
                $list[] = $random->getInt(0, 3) === 0 ? self::NAMES[$random->getInt(0, count(self::NAMES) - 1)] : $text();
            }
            $this->assertParity('shape', [$list]);
            $this->assertParity('display', [$list]);
            $this->assertParity('admin', [$this->rows($list)]);
            $this->assertParity('admin', [$this->rows(array_map(static fn (string $s): string => preg_replace('/[\x80-\xff]/', '', $s) ?? '', $list))]);

            $confidences = [];
            $labels = [];
            $low = [];
            $locked = [];
            for ($n = $random->getInt(0, 40); $n > 0; $n--) {
                $confidences[] = match ($random->getInt(0, 4)) {
                    0 => self::CONFIDENCES[$random->getInt(0, count(self::CONFIDENCES) - 1)],
                    1 => $random->getInt(-2, 2),
                    default => $random->getFloat(-0.25, 1.25),
                };
                $labels[] = $random->getInt(0, 2) === 0 ? self::LABELS[$random->getInt(0, count(self::LABELS) - 1)] : 'group_name_'.$text();
                $low[] = [true, true, false, 1, null][$random->getInt(0, 4)];
                $locked[] = $random->getInt(0, 5) === 0;
            }
            $this->assertParity('confidence', [$confidences]);
            $this->assertParity('downgrade', [$labels, $low, $locked]);
        }
    }

    public function test_malformed_inputs_fall_back_to_php(): void
    {
        foreach (self::TARGETS as $class) {
            $class::useW463RustFfi(true);
        }
        $oversized = str_repeat('Movie.Title.2024.1080p.WEB-GRP.', 600_000);
        $cases = [
            'shape' => [[['Movie', null]], [[1, 'x']], [[1.5]], [[true]], [[['nested']]], [['a', new \stdClass]], [[$oversized]]],
            'confidence' => [[[0.5, '0.5']], [[null]], [[true]], [[[0.5]]], [[0.5, new \stdClass]], [array_fill(0, 2_200_000, 0.5)]],
            'downgrade' => [[['group_name_x', null], [true]], [[1], [true]], [['a' => 'group_name_x'], ['a' => true]], [[1 => 'group_name_x'], [1 => true]],
                [[$oversized], [true]]],
            'display' => [[[1]], [[true]], [[['x']]], [['[1/2] "é"']], [[str_repeat('a', 4097)]], [["[1/2] \"x\xff\""]]],
            'admin' => [[[['searchname' => '[1/2] "é"', 'name' => 'x']]], [[['searchname' => 'x', 'name' => str_repeat('a', 4097)]]],
                [[['searchname' => "Bad\xff", 'name' => '']]]],
        ];
        foreach ($cases as $helper => $list) {
            [$class, $method] = self::HELPERS[$helper];
            foreach ($list as $args) {
                $this->assertNull($class::{$method.'Native'}(...$args), $helper);
                $this->assertSame($class::{$method.'Php'}(...$args), $class::$method(...$args), $helper);
            }
        }

        // Restricted PCRE resources keep the display helpers on PHP's failure behaviour.
        foreach (['pcre.jit' => '0', 'pcre.backtrack_limit' => '999999', 'pcre.recursion_limit' => '99999'] as $setting => $value) {
            if (ini_set($setting, $value) === false) {
                continue;
            }
            $this->assertNull(ReleaseNameDisplay::summarizeDisplayWrapperBatchNative(['[1/2] "x"']), $setting);
            $this->assertNull(AdminReleaseNames::summarizeNameDisplayBatchNative([['searchname' => '[1/2] "x"', 'name' => '']]), $setting);
            $this->assertNotNull(AbstractCategorizer::summarizeReleaseNameShapeBatchNative(['[1/2] "x"']), $setting);
            ini_set($setting, (string) $this->pcre[$setting]);
        }
    }

    public function test_native_exports_validate_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();

        $small = $ffi->new('char[4]');
        $out = $ffi->new('char[256]');
        $cases = [
            'shape' => [['Movie.Title.2024-GRP', 'hash_x'], [['Movie.Title.2024-GRP', 'hash_x']]],
            'confidence' => [[pack('e*', 0.95, 0.5)], [[0.95, 0.5]]],
            'downgrade' => [['group_name_tv', 'hash_x', "\x01\x01"], [['group_name_tv', 'hash_x'], [true, true]]],
            'display' => [['[1/2] "x.rar"', 'Plain'], [['[1/2] "x.rar"', 'Plain']]],
            'admin' => [['[1/2] "x.rar"', 'Plain'], [[['searchname' => '[1/2] "x.rar"', 'name' => 'Plain']]]],
        ];
        foreach ($cases as $helper => [$items, $args]) {
            [$class, $method, $symbol] = self::HELPERS[$helper];
            $expected = implode("\x1F", $class::{$method.'Php'}(...$args));
            $blob = implode('', $items);
            $lens = pack('V*', ...array_map(strlen(...), $items));
            $call = [$blob, strlen($blob), $lens, strlen($lens)];
            // Tiny capacity reports -required without writing, then a retry at that size succeeds.
            FFI::memset($small, ord('Z'), 4);
            $needed = $this->callFfi($ffi, $symbol, ...[...$call, $small, 2]);
            $this->assertSame(-strlen($expected), $needed, $symbol);
            $this->assertSame('ZZZZ', FFI::string($small, 4), $symbol);
            $buffer = $ffi->new('char['.(-$needed).']');
            $this->assertSame(-$needed, $this->callFfi($ffi, $symbol, ...[...$call, $buffer, -$needed]), $symbol);
            $this->assertSame($expected, FFI::string($buffer, -$needed), $symbol);
            // Null buffers, null inputs and inconsistent lengths decline.
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, ...[...$call, null, 64]), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, null, 0, '', 0, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, '', 0, null, 0, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 'x', 1, pack('V', 2), 4, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 'xy', 2, pack('V', 1), 4, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 'x', 1, pack('V', 1).'z', 5, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 'x', 1, pack('V', 0xFFFFFFFF), 4, $out, 256), $symbol);

            // An output buffer overlapping an input declines without writing to it.
            $shared = $ffi->new('char[64]');
            FFI::memcpy($shared, 'sample'.pack('V', 6), 10);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, FFI::addr($shared[0]), 6, FFI::addr($shared[6]), 4, FFI::addr($shared[3]), 40), $symbol);
            $this->assertSame('sample'.pack('V', 6), FFI::string($shared, 10), $symbol);
        }
        // Shape-specific declines: the confidence item must be whole doubles, downgrade flags must match the labels.
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w463_pipe_confidence_batch', 'abc', 3, pack('V', 3), 4, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w463_pipe_confidence_batch', '', 0, '', 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w463_safety_net_downgrade_batch', 'ab', 2, pack('V2', 1, 1), 8, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w463_safety_net_downgrade_batch', "a\x04", 2, pack('V2', 1, 1), 8, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w463_admin_name_display_batch', 'x', 1, pack('V', 1), 4, $out, 256));

        foreach ($cases as $helper => [, $args]) {
            [$class, $method] = self::HELPERS[$helper];
            $class::useW463RustFfi(true);
            $this->assertNotNull($class::{$method.'Native'}(...$args), $class);
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w463RustFfiHandle'))->getValue(), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        $cases = [
            'shape' => [['Movie.Title.2024-GRP', ' ', '']],
            'confidence' => [[0.95, 0.5, 0]],
            'downgrade' => [['group_name_tv', 'hash_x'], [true, true]],
            'display' => [['[1/2] "x.rar"', 'Plain']],
            'admin' => [[['searchname' => '[1/2] "x.rar"', 'name' => 'Plain']]],
        ];
        foreach ($cases as $helper => $args) {
            [$class, $method] = self::HELPERS[$helper];
            (new ReflectionProperty($class, 'w463RustFfiHandle'))->setValue(null, false);
            $class::useW463RustFfi(true);
            $this->assertNull($class::{$method.'Native'}(...$args), $class);
            $this->assertSame($class::{$method.'Php'}(...$args), $class::$method(...$args), $class);
            $class::resetW463RustFfi();
            $this->assertNull((new ReflectionProperty($class, 'w463RustFfiHandle'))->getValue(), $class);
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including
        // the PHP-side type checks, packing, FFI call and decoding (release dylib, 5 warmups, 301
        // samples per helper and workload; 40 iterations per common sample, 4 per uncommon sample).
        // Common = 32 typical inputs for the helper's domain; uncommon = 2000 inputs (120-400 byte
        // names and subjects, rows of them, or mixed confidences and labels).
        // Speedup = PHP latency / Rust latency, ranges over 5 rounds (PHP 8.5 CLI, Apple Silicon,
        // shared host at load average ~5.2-6.5):
        // - shape:      common mean 1.97-2.07x / p99 1.81-1.97x, uncommon mean 3.84-4.12x / p99 3.06-3.58x
        // - confidence: common mean 0.66-0.71x / p99 0.47-0.76x, uncommon mean 1.47-1.63x / p99 1.42-1.60x
        // - downgrade:  common mean 0.84-0.98x / p99 0.77-1.40x, uncommon mean 1.35-1.41x / p99 1.13-1.32x
        // - display:    common mean 2.33-2.60x / p99 2.05-3.08x, uncommon mean 6.25-6.55x / p99 4.35-5.62x
        // - admin:      common mean 5.24-5.61x / p99 4.66-5.96x, uncommon mean 8.60-9.03x / p99 5.62-6.85x
        // AbstractCategorizer, ReleaseNameDisplay and AdminReleaseNames clear 1.5x on every measure
        // in every round and default to Rust. AbstractCategorizationPipe and MiscSafetyNetPipe do
        // per-item work cheaper than the fixed FFI marshaling (~1us), so they keep PHP by default.
        // NNTMUX_W463_BENCH=1 also fails a Rust default that misses the gate on this host.
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99,
        // per .ai/rules/rust-performance.md.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // php artisan test --compact tests/Unit/RustAbstractCategorizerPipeSafetyNetDisplayNamesW46ParityTest.php --filter benchmark
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4632));
        $warmup = 5;
        $samples = 301;
        $sink = 0;

        $speedups = [];
        foreach (self::HELPERS as $helper => [$class, $method]) {
            foreach (['common' => [$this->workload($helper, 32, false), 40], 'uncommon' => [$this->workload($helper, 2000, true), 4]] as $name => [$args, $iterations]) {
                for ($w = 0; $w < $warmup; $w++) {
                    $sink += $this->runWorkload($class, $method, $args, false) + $this->runWorkload($class, $method, $args, true);
                }
                $native = $class::{$method.'Native'}(...$args);
                $this->assertNotNull($native, $helper.' '.$name.' declined');
                $this->assertSame($class::{$method.'Php'}(...$args), $native, $helper.' '.$name);
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += $this->runWorkload($class, $method, $args, $useRust);
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $class::resetW463RustFfi();
                $speedups[$helper][$name] = [
                    'mean' => round($this->mean($times['php']) / $this->mean($times['rust']), 3),
                    'p99' => round($this->p99($times['php']) / $this->p99($times['rust']), 3),
                    'php_mean_us' => round($this->mean($times['php']), 2),
                    'rust_mean_us' => round($this->mean($times['rust']), 2),
                    'samples' => $samples,
                ];
            }
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustAbstractCategorizerPipeSafetyNetDisplayNamesW46 speedup: {$summary}\n");
        foreach (self::HELPERS as $helper => [$class]) {
            $qualifies = true;
            foreach ($speedups[$helper] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
                $this->assertGreaterThan(0.0, $speedup['mean'], $helper);
                $this->assertGreaterThan(0.0, $speedup['p99'], $helper);
            }
            if (! $qualifies) {
                $this->assertFalse(constant($class.'::W463_RUST_FFI_DEFAULT'), $class.' must keep PHP below 1.5x: '.$summary);
                $this->assertFalse($class::usesW463RustFfi(), $class.' must keep PHP below 1.5x: '.$summary);
            }
            if (getenv('NNTMUX_W463_BENCH') === '1' && constant($class.'::W463_RUST_FFI_DEFAULT')) {
                $this->assertTrue($qualifies, $class.' defaults to Rust only at 1.5x or more: '.$summary);
            }
        }
    }

    /**
     * Asserts the PHP and Rust summaries agree, through both helpers and the dispatcher. Rust must
     * decide the batch unless it holds a display name it leaves to PCRE (non-ASCII or over 4096 bytes).
     *
     * @param  list<mixed>  $args
     */
    private function assertParity(string $helper, array $args): void
    {
        [$class, $method] = self::HELPERS[$helper];
        $expected = $class::{$method.'Php'}(...$args);
        $native = $class::{$method.'Native'}(...$args);
        $declines = match ($helper) {
            'display' => $this->undecidedDisplay($args[0]),
            'admin' => $this->undecidedDisplay(array_merge(...array_map(static fn (mixed $row): array => is_array($row)
                ? [$row['searchname'] ?? null, $row['name'] ?? null] : [], $args[0]))),
            'downgrade' => ! array_is_list($args[0]),
            default => false,
        };
        if ($declines) {
            $this->assertNull($native, $helper.' must decline: '.json_encode($this->describe($args)));
        } else {
            $this->assertNotNull($native, $helper.' declined: '.json_encode($this->describe($args)));
            $this->assertSame($expected, $native, $helper.': '.json_encode($this->describe($args)));
        }
        $class::useW463RustFfi(true);
        $this->assertSame($expected, $class::$method(...$args), $helper.' dispatcher');
        $class::useW463RustFfi(false);
        $this->assertSame($expected, $class::$method(...$args), $helper.' php dispatcher');
        $class::resetW463RustFfi();
    }

    /**
     * @param  array<array-key, mixed>  $names
     */
    private function undecidedDisplay(array $names): bool
    {
        foreach ($names as $name) {
            if (is_string($name) && (strlen($name) > 4096 || preg_match('/[\x80-\xff]/', $name) === 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<array{searchname: string, name: string}>
     */
    private function rows(array $names): array
    {
        $names = array_values($names);
        $rows = [];
        foreach ($names as $index => $name) {
            $rows[] = ['searchname' => $name, 'name' => $index % 3 === 0 ? $name : $names[($index + 1) % count($names)]];
        }

        return $rows;
    }

    private function describe(mixed $value): mixed
    {
        return match (true) {
            is_string($value) => bin2hex($value),
            is_float($value) => is_nan($value) ? 'NAN' : (is_infinite($value) ? ($value > 0 ? 'INF' : '-INF') : $value),
            is_array($value) => array_map($this->describe(...), $value),
            default => $value,
        };
    }

    /**
     * Arguments for a helper's workload: typical inputs, or long uncommon ones.
     *
     * @return list<mixed>
     */
    private function workload(string $helper, int $count, bool $uncommon): array
    {
        $names = array_map(static function (int $i) use ($uncommon): string {
            if ($uncommon) {
                $length = self::UNCOMMON_LENGTHS[$i % count(self::UNCOMMON_LENGTHS)];
                $body = substr(str_repeat('Obfuscated.Mirror.Release_Name.', 16).'Part'.$i, -$length + 12);

                return $body.'.Of.2000-GRP';
            }
            $patterns = ['Movie.Title.%d.1080p.WEB-DL.DDP5.1.H.264-GRP', 'Show.Name.S01E%02d.720p.HDTV.x264-GRP', 'Artist_-_Album_%d-(CAT001)-WEB-FLAC-2019-GRP',
                'Some Book Title %d (2020) [EPUB]', 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5%02d', 'Game.Title.v%d.2-RUNE', 'Movie.Title.%d.2160p.UHD.BluRay.x265-GRP',
                'Show.Name.%d.Complete.Season.1080p.WEB-GRP'];

            return sprintf($patterns[$i % 8], $i);
        }, range(1, $count));
        $subjects = array_map(static function (int $i) use ($names, $uncommon): string {
            $name = $names[$i];

            return match ($i % 4) {
                0 => '['.($i % 50 + 1).'/50] - "'.$name.'.part'.($i % 9).'.rar" yEnc ('.($i % 9 + 1).'/99)',
                1 => $uncommon ? '[1/'.$i.'] &quot;'.$name.'.nzb&quot; yEnc' : $name,
                2 => '['.($i % 7 + 1).'/7] "'.$name.'.mkv" yEnc',
                default => $name,
            };
        }, array_keys($names));

        return match ($helper) {
            'shape' => [$names],
            'display' => [$subjects],
            'admin' => [array_map(static fn (int $i): array => ['searchname' => $names[$i], 'name' => $subjects[$i], 'guid' => sha1((string) $i)], array_keys($names))],
            'confidence' => [array_map(static fn (int $i): float|int => $uncommon
                ? [0.0, 0.25, 0.5, 0.65, 0.8, 0.95, 1.0, NAN, 1][$i % 9] + ($i % 9 === 8 ? 0 : 0.001 * ($i % 7))
                : [0.0, 0.65, 0.7, 0.75, 0.8, 0.85, 0.9, 0.95, 0.97, 0.99, 1.0][$i % 11], range(1, $count))],
            default => [
                array_map(static fn (int $i): string => ['group_name_tv', 'hash_md5', 'webdl', 'group_name_movies', 'no_match', 'x265_group'][$i % 6]
                    .($uncommon ? '_'.$names[$i % $count] : ''), range(1, $count)),
                array_map(static fn (int $i): bool => $i % 3 === 0, range(1, $count)),
                array_map(static fn (int $i): bool => $i % 11 === 0, range(1, $count)),
            ],
        };
    }

    /**
     * @param  class-string  $class
     * @param  list<mixed>  $args
     */
    private function runWorkload(string $class, string $method, array $args, bool $useRust): int
    {
        $class::useW463RustFfi($useRust);

        return count($class::$method(...$args));
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
        $cdef = implode("\n", array_map(static fn (string $class): string => constant($class.'::W463_FFI_CDEF'), self::TARGETS));
        $ffi = RustPipelineLibrary::resolveDomain('categorize_w463', $cdef);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the Wave 46.3 exports is not available.');
        }

        return $ffi;
    }
}
