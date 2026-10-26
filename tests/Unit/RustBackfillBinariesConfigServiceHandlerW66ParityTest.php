<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Backfill\BackfillConfig;
use App\Services\Backfill\BackfillService;
use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\Binaries\BinaryHandler;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Tests\TestCase;

final class RustBackfillBinariesConfigServiceHandlerW66ParityTest extends TestCase
{
    /** Per class: the batch entry, its Rust export, its FFI declaration constant, the trailer word count and the FFI domain. */
    private const TARGETS = [
        BackfillConfig::class => ['w66BackfillConfigPlanBatch', 'nntmux_w661_backfill_config_plan_batch', 'W66_BACKFILL_CONFIG_FFI_CDEF', 32,
            'backfill_config_w661'],
        BackfillService::class => ['w66BackfillServicePlanBatch', 'nntmux_w661_backfill_service_plan_batch', 'W66_BACKFILL_SERVICE_FFI_CDEF', 20,
            'backfill_service_w661'],
        BinariesConfig::class => ['w66BinariesConfigPlanBatch', 'nntmux_w661_binaries_config_plan_batch', 'W66_BINARIES_CONFIG_FFI_CDEF', 27,
            'binaries_config_w661'],
        BinariesService::class => ['w66BinariesServicePlanBatch', 'nntmux_w661_binaries_service_plan_batch', 'W66_BINARIES_SERVICE_FFI_CDEF', 21,
            'binaries_service_w661'],
        BinaryHandler::class => ['w66BinaryHandlerPlanBatch', 'nntmux_w661_binary_handler_plan_batch', 'W66_BINARY_HANDLER_FFI_CDEF', 21,
            'binary_handler_w661'],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        BackfillConfig::class => true,
        BackfillService::class => true,
        BinariesConfig::class => true,
        BinariesService::class => true,
        BinaryHandler::class => true,
    ];

    /** A realistic `|`-separated item per class. */
    private const TEMPLATES = [
        BackfillConfig::class => ['1', '0', '2012-08-14', 'backfill', '0', '20000', '4', '30'],
        BackfillService::class => ['481234567', '100000000', '512345678', '', '465000000', '20000'],
        BinariesConfig::class => ['20000', '1', '1', '0', '50000', '3', '15000', '3', '500', '500', '16'],
        BinariesService::class => ['512300000', '100000000', '512345678', '0', '20000', '50000', '120', '15000'],
        BinaryHandler::class => ['[01/80] - "Some.Show.S01E02.1080p.mkv" yEnc (12/980)', 'Poster <poster@example.com>', 'alt.binaries.teevee', '', '', '0'],
    ];

    /** Edge cases shared by every helper: separators, whitespace, NUL and control bytes, non-ASCII, boundary and malformed values. */
    private const EDGE = ['', ' ', '  ', "\t", "\0", "\n", "\x0B", "\x0C", "\r", "\r\n", "\x7f", "\x01", "\xff", "caf\xc3\xa9", "\xe2\x80\x8b", '|', '||',
        '|||', '||||', '|||||', '||||||', '|||||||', '||||||||', '|||||||||', '||||||||||', '|||||||||||', ' | | ', "\t|\t|\t", "\0|\0|\0",
        'a|b|c|d|e|f|g|h|i|j|k|l', '.', '..', '...', '-', '_', '+', ':', '/', '//', '(', ')', '()', '(1/2)', '(01/20)', ' (1/2)', '(0/0)', '(2/1)',
        '(1234567890/2)', '(1/1234567890)', '(123456789/987654321)', '(1/2) ', 'x (1/2)', 'x(1/2)', 'x (1/2', 'x 1/2)', 'x (1/)', 'x (/2)', 'x (a/2)',
        '0', '00', '1', '2', '3', '9', '10', '15', '16', '30', '64', '65', '99', '100', '101', '365', '366', '499', '500', '501', '999', '1000', '1001',
        '3650', '3651', '9999', '10000', '15000', '19999', '20000', '20001', '40000', '40001', '50000', '99999', '100000', '100001', '999999',
        '1000000', '1000001', '9999999', '10000000', '10000001', '4294967295', '4294967296', '999999999999999', '1000000000000000', '-1', '-0',
        '--1', '+1', '1-', ' 1 ', '1 2', '1.5', '1e3', '0x10', 'true', 'TRUE', 'false', 'False', 'yes', 'no', 'on', 'OFF', 'maybe', 'tru', 'backfill',
        'BACKFILL', 'update', 'Update', 'repair', '2012-08-14', '2024-02-29', '2023-02-29', '2000-02-29', '1900-02-29', '2100-02-28', '2012-8-14',
        '2012/08/14', '2012-08-14T00:00', '2012-13-01', '2012-00-10', '2012-04-31', '2012-04-30', '1989-12-31', '1990-01-01', '2099-12-31',
        '2100-01-01', '0000-01-01', '0000-03-01', '9999-12-31', '1970-01-01', '1969-12-31', 'alt.binaries.test', 'ALT.BINARIES.TEST',
        'alt.binaries.teevee', 'a.b', 'alt', '.alt', 'alt.', 'alt..binaries', 'alt.binaries.x+y_z-w', 'alt.binaries.test!', 'alt binaries',
        'Poster <p@x.com>', 'poster  <p@x.com>', 'POSTER', 'yEnc', '"movie.mkv" yEnc (1/50)', '[1/9] - "movie.mkv" yEnc (01/50)',
        "a\tb\nc\x0Bd\x0Ce", '  spaced   subject  (3/9) ', 'Movie.MKV', 'movie.mkv', 'x|y|z', '1|2|3', 'x|1', '1|x'];

    /**
     * EDGE plus long runs and boundary lengths.
     *
     * @return list<string>
     */
    private static function edgeCases(): array
    {
        return [...self::EDGE, str_repeat('a', 15), str_repeat('9', 15), str_repeat('9', 16), str_repeat('a', 255), str_repeat('a', 256),
            str_repeat('a', 1023), str_repeat('x', 5000), str_repeat('.', 300), str_repeat('|', 300), str_repeat(' ', 100), str_repeat("\t ", 60),
            str_repeat('(1/2)', 30), str_repeat('a.', 70).'b', str_repeat("\xc3\xa9", 200), str_repeat("\x01", 80), str_repeat('A', 120),
            str_repeat('1', 9), str_repeat('1', 10), 'subject ('.str_repeat('9', 9).'/'.str_repeat('9', 9).')'];
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $class::resetW66RustFfi();
        }
        parent::tearDown();
    }

    public function test_w66_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$entry, , $cdef, $trailer]) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW66RustFfi', 'enableW66RustFfi', 'disableW66RustFfi', 'resetW66RustFfi', 'withW66RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()
                    || $reflection->getMethod($method)->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$method;
                }
            }
            $default = $reflection->getReflectionConstant('W66_RUST_FFI_DEFAULT');
            if ($default === false || ! $default->isPublic() || $default->getDeclaringClass()->getName() !== $class || ! \is_bool($default->getValue())) {
                $missing[] = $class.'::W66_RUST_FFI_DEFAULT';
            }
            $declaration = $reflection->getReflectionConstant($cdef);
            if ($declaration === false || ! $declaration->isPublic() || $declaration->getDeclaringClass()->getName() !== $class) {
                $missing[] = $class.'::'.$cdef;
            }
            foreach (['w66RustFfiState', 'w66Invoke'] as $private) {
                $method = new ReflectionMethod($class, $private);
                if (! $method->isPrivate() || ! $method->isStatic() || $method->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$private;
                }
            }
            $state = new ReflectionMethod($class, 'w66RustFfiState');
            $params = array_map(static fn ($p): string => $p->getName().':'.$p->getType().'='.var_export($p->getDefaultValue(), true), $state->getParameters());
            if ($params !== ['op:?string=NULL', 'val:mixed=NULL'] || (string) $state->getReturnType() !== 'mixed') {
                $missing[] = $class.'::w66RustFfiState signature';
            }
            $uses = new ReflectionMethod($class, 'usesW66RustFfi');
            $with = new ReflectionMethod($class, 'withW66RustFfi');
            $withParams = array_map(static fn ($p): string => $p->getName().':'.$p->getType(), $with->getParameters());
            if (! $uses->getReturnType() instanceof ReflectionNamedType || $uses->getReturnType()->getName() !== 'bool' || $uses->getNumberOfParameters() !== 0
                || $withParams !== ['enabled:bool', 'callback:callable'] || (string) $with->getReturnType() !== 'mixed') {
                $missing[] = $class.' usesW66RustFfi/withW66RustFfi signatures';
            }
            foreach (['enableW66RustFfi', 'disableW66RustFfi', 'resetW66RustFfi'] as $toggle) {
                $method = new ReflectionMethod($class, $toggle);
                if ($method->getNumberOfParameters() !== 0 || (string) $method->getReturnType() !== 'void') {
                    $missing[] = $class.'::'.$toggle.' signature';
                }
            }
            // Wave 66 state lives in function-local statics only, never in properties.
            foreach ($reflection->getProperties() as $property) {
                if (str_contains(strtolower($property->getName()), 'w66')) {
                    $missing[] = $class.'::$'.$property->getName();
                }
            }
            $this->assertStringContainsString(self::TARGETS[$class][1], \constant($class.'::'.$cdef));
            $this->assertSame($trailer, \constant($class.'::'.str_replace('_FFI_CDEF', '_TRAILER', $cdef)), $class);
            $this->assertSame(array_fill(0, $trailer, 0), $class::{$entry.'Php'}([]), $class);
        }
        foreach ([BackfillConfig::class, BinariesConfig::class] as $dto) {
            $reflection = new ReflectionClass($dto);
            $this->assertTrue($reflection->isReadOnly() && $reflection->isFinal(), $dto.' stays a final readonly class');
        }
        $cdefs = array_column(array_values(self::TARGETS), 2);
        $this->assertSame($cdefs, array_values(array_unique($cdefs)));
        $this->assertSame([], $missing);
    }

    public function test_w66_defaults_and_toggles(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], (bool) (new ReflectionClass($class))->getConstant('W66_RUST_FFI_DEFAULT'), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW66RustFfi(), $class);
            $class::enableW66RustFfi();
            $this->assertTrue($class::usesW66RustFfi(), $class);
            $this->assertFalse($class::withW66RustFfi(false, static fn (): bool => $class::usesW66RustFfi()));
            $this->assertTrue($class::usesW66RustFfi(), $class.' restores the enabled override');
            $class::disableW66RustFfi();
            $this->assertFalse($class::usesW66RustFfi(), $class);
            $this->assertTrue($class::withW66RustFfi(true, static fn (): bool => $class::usesW66RustFfi()));
            $this->assertFalse($class::usesW66RustFfi(), $class.' restores the disabled override');
            $class::resetW66RustFfi();
            $this->assertSame(self::DEFAULTS[$class], $class::usesW66RustFfi(), $class);
            $this->assertTrue($class::withW66RustFfi(false, static fn (): bool => $class::withW66RustFfi(true, static fn (): bool => $class::usesW66RustFfi())));
            $this->assertFalse($class::withW66RustFfi(true, static fn (): bool => $class::withW66RustFfi(false, static fn (): bool => $class::usesW66RustFfi())));
            try {
                $class::withW66RustFfi(! $class::usesW66RustFfi(), $this->throwing('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW66RustFfi(), $class.' restores after an exception');
            // Flipping one class's toggle leaves the others at their defaults.
            foreach ([true, false] as $enabled) {
                $class::withW66RustFfi($enabled, function () use ($class, $enabled): void {
                    $this->assertSame($enabled, $class::usesW66RustFfi(), $class);
                    foreach (array_keys(self::TARGETS) as $other) {
                        if ($other !== $class) {
                            $this->assertSame(self::DEFAULTS[$other], $other::usesW66RustFfi(), $class.' vs '.$other);
                        }
                    }
                });
            }
        }
        // Both dispatch paths return the PHP reference words.
        foreach (self::TARGETS as $class => [$entry]) {
            $items = [...array_map(static fn (array $fields): string => implode('|', $fields), array_values(self::TEMPLATES)), "caf\xc3\xa9", ''];
            $php = $class::{$entry.'Php'}($items);
            $this->assertSame($php, $class::withW66RustFfi(true, static fn (): array => $class::$entry($items)), $class);
            $this->assertSame($php, $class::withW66RustFfi(false, static fn (): array => $class::$entry($items)), $class);
        }
    }

    public function test_w66_existing_public_api_is_unchanged(): void
    {
        $earlier = static fn (): array => [
            BackfillConfig::usesW421RustFfi(), BackfillConfig::usesW52RustFfi(),
            BackfillService::usesRustFfi(), BackfillService::usesW421RustFfi(), BackfillService::usesW52RustFfi(),
            BinariesConfig::usesRustFfi(), BinariesConfig::usesW411RustFfi(), BinariesConfig::usesW52RustFfi(),
            BinariesService::usesRustFfi(), BinariesService::usesW411RustFfi(), BinariesService::usesW52RustFfi(),
            BinaryHandler::usesW411RustFfi(), BinaryHandler::usesW52RustFfi(),
        ];
        $before = $earlier();
        $this->assertSame([BackfillConfig::W421_RUST_FFI_DEFAULT, BackfillService::W421_RUST_FFI_DEFAULT, BinariesConfig::W411_RUST_FFI_DEFAULT,
            BinariesService::W411_RUST_FFI_DEFAULT, BinaryHandler::W411_RUST_FFI_DEFAULT], [$before[0], $before[3], $before[6], $before[9], $before[11]]);
        foreach (array_keys(self::TARGETS) as $class) {
            foreach ([true, false] as $enabled) {
                $class::withW66RustFfi($enabled, function () use ($earlier, $before, $class): void {
                    $this->assertSame($before, $earlier(), $class.' leaves earlier-wave toggles alone');
                });
            }
            $class::resetW66RustFfi();
            $this->assertSame($before, $earlier(), $class.' reset leaves earlier-wave toggles alone');
        }
        $this->assertSame(1 << 53, BinariesConfig::W52_MAX_ARTICLE);
        $this->assertSame(90, BackfillService::targetArticlePhp(100, 10, 50));
        $this->assertSame([80, 99], BackfillService::backfillChunkPhp(100, 50, 20));
        $this->assertSame([11, 30], BinariesService::scanChunkPhp(10, 50, 50, 40, 20));
        $this->assertEquals(new BinariesConfig, BinariesConfig::defaultsPhp());
        $this->assertSame(['compressedHeaders' => true, 'safePartRepair' => 'backfill'],
            ['compressedHeaders' => (new BackfillConfig)->compressedHeaders, 'safePartRepair' => (new BackfillConfig)->safePartRepair]);
        $this->assertSame(md5('file:3'), (new BinaryHandler)->identityHashPhp([], 3));
        $this->assertSame(BinaryHandler::planBinaryKeysPhp([], [], [], []), BinaryHandler::planBinaryKeys([], [], [], []));
    }

    public function test_w66_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, $symbol, $cdef, $trailer, $domain]) {
            $ffi = RustPipelineLibrary::resolveDomain($domain, \constant($class.'::'.$cdef));
            $this->assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[128]');
            $items = [implode('|', self::TEMPLATES[$class]), 'x|1|1|3'];
            $blob = implode("\0", $items);
            $words = $trailer + 2;
            $this->assertSame($words, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, 2, $out, 128), $class);
            $this->assertSame($class::{$entry.'Php'}($items), array_values((array) unpack('P*', FFI::string($out, $words * 8))), $class);
            // A length column splits unseparated items, so NUL bytes stay inside an item.
            $nul = ["a\0b", 'x|y', "x\0"];
            $lens = $ffi->new('int64_t[3]');
            FFI::memcpy($lens, pack('P*', 3, 3, 2), 24);
            $joined = implode('', $nul);
            $this->assertSame($trailer + 3, $this->callExport($ffi, $symbol, $joined, \strlen($joined), FFI::addr($lens[0]), 3, $out, 128), $class);
            $this->assertSame($class::{$entry.'Php'}($nul), array_values((array) unpack('P*', FFI::string($out, ($trailer + 3) * 8))), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $joined, \strlen($joined) - 1, FFI::addr($lens[0]), 3, $out, 128), $class.' lengths must tile the blob');
            // A NUL inside an item, a short buffer, a null output, a null blob and a count mismatch all decline.
            $this->assertSame(-1, $this->callExport($ffi, $symbol, "a\0b", 3, null, 1, $out, 128), $class);
            $this->assertSame(-$words, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, 2, $out, $words - 1), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, 2, null, 128), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, null, 8, null, 1, $out, 128), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, 'a', 1, null, 2, $out, 128), $class);
            $this->assertSame($trailer, $this->callExport($ffi, $symbol, null, 0, null, 0, $out, 128), $class);
            // Output buffers overlapping the input or the length column decline.
            $shared = $ffi->new('int64_t[128]');
            FFI::memcpy($shared, str_repeat('a', 64), 64);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $ffi->cast('char *', FFI::addr($shared[0])), 64, null, 1, $shared, 128), $class);
            FFI::memcpy($shared, pack('P', 1), 8);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, 'a', 1, FFI::addr($shared[0]), 1, $shared, 128), $class);
            // A misaligned output pointer declines.
            $bytes = $ffi->new('char[1100]');
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, 2, $ffi->cast('int64_t *', FFI::addr($bytes[1])), 128), $class);
        }
    }

    public function test_w66_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, , , $trailer]) {
            $items = [...array_map(static fn (array $fields): string => implode('|', $fields), array_values(self::TEMPLATES)),
                '1|0|2023-02-29|update|yes|5|500|9999', 'x (1/2)|p|a.b|3|9', "caf\xc3\xa9", ''];
            $invoke = new ReflectionMethod($class, 'w66Invoke');
            $raw = $invoke->invoke(null, $items, $trailer + \count($items));
            $this->assertIsArray($raw, $class.' stays on Rust');
            $this->assertSame($class::{$entry.'Php'}($items), $raw, $class);
            $this->assertNull($invoke->invoke(null, $items, $trailer + \count($items) + 1), $class.' rejects a word count mismatch');
            $nul = ["a\0b", 'ok', "x\0", "\0"];
            $this->assertSame($class::{$entry.'Php'}($nul), $invoke->invoke(null, $nul, $trailer + 4), $class.' keeps NUL items on Rust through the length column');
            $this->assertSame($class::{$entry.'Php'}([]), $invoke->invoke(null, [], $trailer), $class.' empty batch');
            $this->assertCount($trailer, $class::{$entry.'Native'}([]), $class);
            // A batch larger than the cached buffer grows it.
            $large = array_fill(0, 3000, implode('|', self::TEMPLATES[$class]));
            $this->assertSame($class::{$entry.'Php'}($large), $invoke->invoke(null, $large, $trailer + 3000), $class.' grows its buffers');
            // reset drops the cached handle and buffers; the next call resolves them again.
            $class::resetW66RustFfi();
            $this->assertSame($class::{$entry.'Php'}($items), $invoke->invoke(null, $items, $trailer + \count($items)), $class.' after reset');
        }
    }

    public function test_known_case_fields(): void
    {
        $bits = static fn (int $word, int $shift, int $width): int => $word >> $shift & ((1 << $width) - 1);

        $config = BackfillConfig::w66BackfillConfigPlanBatchPhp(['1|0|2012-08-14|backfill|0|20000|4|0', '||||||', 'maybe|0|2012-08-14|backfill|0|20000|4|0',
            '1|0|2012-13-01|backfill|0|20000|4|0', '1|0|2012-08-14|sometimes|0|20000|4|0', '1|0|2012-08-14|update|yes|5|500|9999', '',
            '1|0|2012-08-14|BACKFILL|0|20000|4|0', 'on|TRUE|1960-01-01|update|off|1000|1|0', '1|0|2024-02-29||0|1000000|64|3650']);
        $this->assertSame([5, 1, 1, 20000, 4, 0, 42], [$bits($config[0], 0, 3), $bits($config[0], 3, 3), $bits($config[0], 6, 2), $bits($config[0], 17, 20),
            $bits($config[0], 37, 7), $bits($config[0], 44, 12), $bits($config[0], 56, 7)]);
        $this->assertSame([5, 0, 0, 1], [$bits($config[1], 0, 3), $bits($config[1], 3, 3), $bits($config[1], 6, 2), $bits($config[1], 8, 9)]);
        $this->assertSame([1, 2, 3, 3], [$bits($config[2], 0, 3), $bits($config[3], 0, 3), $bits($config[3], 3, 3), $bits($config[4], 0, 3)]);
        $this->assertSame([4, 2, 1 | 4 | 8 | 16 | 32, 1000, 64, 3650], [$bits($config[5], 0, 3), $bits($config[5], 6, 2), $bits($config[5], 8, 9),
            $bits($config[5], 17, 20), $bits($config[5], 37, 7), $bits($config[5], 44, 12)]);
        $this->assertSame([0, 256], [$bits($config[6], 0, 3), $bits($config[7], 8, 9) & 256]);
        $this->assertSame([4, 4, 1 | 2, 0], [$bits($config[8], 0, 3), $bits($config[8], 3, 3), $bits($config[8], 8, 9), $bits($config[8], 56, 7)]);
        $this->assertSame([5, 1, 54], [$bits($config[9], 0, 3), $bits($config[9], 3, 3), $bits($config[9], 56, 7)]);
        $this->assertSame([0, 19782, 8], [$config[10 + 29], $config[10 + 30], $config[10 + 31]]);

        $service = BackfillService::w66BackfillServicePlanBatchPhp(['1000000|1|2000000|50000||20000', '1000000|1|2000000||900000|0', 'x|1|1', '0|1|2000000|5',
            '1000000|1500000|1400000|5', '1000000|1000000|2000000|5', '1000000|1|2000000|0', '1000000|990000|2000000|50000|', '', '3000000|1|2000000|10|1|5']);
        $this->assertSame([6, 1, 3, 20000, 16, 20], [$bits($service[0], 0, 3), $bits($service[0], 3, 8), $bits($service[0], 11, 16), $bits($service[0], 27, 20),
            $bits($service[0], 47, 6), $bits($service[0], 53, 6)]);
        $this->assertSame([6, 2 | 4, 5], [$bits($service[1], 0, 3), $bits($service[1], 3, 8), $bits($service[1], 11, 16)]);
        $this->assertSame([1, 2, 3, 4, 5, 6, 1 | 16 | 32, 10000, 0, 2], [$bits($service[2], 0, 3), $bits($service[3], 0, 3), $bits($service[4], 0, 3),
            $bits($service[5], 0, 3), $bits($service[6], 0, 3), $bits($service[7], 0, 3), $bits($service[7], 3, 8), $bits($service[7], 27, 20),
            $bits($service[8], 0, 3), $bits($service[8], 3, 8)]);
        $this->assertSame([6, 1 | 8, 2, 5], [$bits($service[9], 0, 3), $bits($service[9], 3, 8), $bits($service[9], 11, 16), $bits($service[9], 27, 20)]);
        $this->assertSame([3 + 5 + 1 + 2, 50000 + 100000 + 10000 + 10, 100000, 5, 900000], [$service[10 + 15], $service[10 + 16], $service[10 + 17],
            $service[10 + 18], $service[10 + 19]]);

        $binaries = BinariesConfig::w66BinariesConfigPlanBatchPhp(['20000|1|1|0|50000|3|15000|3|500|500|16', '||||||||||', '20000|1|1|0|50000|3|15000|3|900|500|16',
            '5|1|1|0|50000|3|15000|3|500|500|1', '20000|nope', '', '20000|1|1|0|500|3|15000|3|500|500|0', '200000|no|off|yes|99999999|999|1|99|0|0|5000']);
        $this->assertSame([4, 0, 1 | 2 | 8, 20000, 3, 3, 500], [$bits($binaries[0], 0, 3), $bits($binaries[0], 3, 8), $bits($binaries[0], 11, 8),
            $bits($binaries[0], 19, 17), $bits($binaries[0], 36, 9), $bits($binaries[0], 45, 4), $bits($binaries[0], 49, 10)]);
        $this->assertSame([4, 2, 3, 1 | 128, 1, 0], [$bits($binaries[1], 0, 3), $bits($binaries[2], 0, 3), $bits($binaries[3], 0, 3), $bits($binaries[3], 3, 8),
            $bits($binaries[4], 0, 3), $bits($binaries[5], 0, 3)]);
        $this->assertSame([3, 2, 1 | 2 | 128], [$bits($binaries[6], 0, 3), $bits($binaries[6], 3, 8), $bits($binaries[6], 11, 8)]);
        $this->assertSame([3, 255, 4 | 8, 100000, 365, 10, 1], [$bits($binaries[7], 0, 3), $bits($binaries[7], 3, 8), $bits($binaries[7], 11, 8),
            $bits($binaries[7], 19, 17), $bits($binaries[7], 36, 9), $bits($binaries[7], 45, 4), $bits($binaries[7], 49, 10)]);
        $this->assertSame([7, 8, 100000, 15000], [$binaries[8 + 26], array_sum(\array_slice($binaries, 8, 5)), $binaries[8 + 22], $binaries[8 + 24]]);

        $scan = BinariesService::w66BinariesServicePlanBatchPhp(['1000|1|101000|0|20000', '0|1|1000000|0|20000|50000', '0|1|1000000|10000|20000|50000', '1000|1|1000',
            '1|5|2', 'x', '1000|1|101000|0|0|0|20000|15000', '', '1000|5000|50000|0|20000||0|0', '100|1|141|0|20']);
        $this->assertSame([6, 4, 80000, 0], [$bits($scan[0], 0, 3), $bits($scan[0], 11, 16), $bits($scan[0], 27, 20), $bits($scan[0], 3, 8)]);
        $this->assertSame([4, 1, 50000, 3, 5, 1 | 2, 10000, 1], [$bits($scan[1], 0, 3), $bits($scan[1], 3, 8), $bits($scan[1], 27, 20), $bits($scan[1], 11, 16),
            $bits($scan[2], 0, 3), $bits($scan[2], 3, 8), $bits($scan[2], 27, 20), $bits($scan[2], 11, 16)]);
        $this->assertSame([3, 2, 1, 4 | 8, 15000, 0], [$bits($scan[3], 0, 3), $bits($scan[4], 0, 3), $bits($scan[5], 0, 3), $bits($scan[6], 3, 8),
            $bits($scan[6], 47, 15), $bits($scan[7], 0, 3)]);
        $this->assertSame([6, 32, 6, 20, 1], [$bits($scan[8], 0, 3), $bits($scan[8], 3, 8), $bits($scan[9], 0, 3), $bits($scan[9], 27, 20), $bits($scan[9], 11, 16)]);

        $handler = BinaryHandler::w66BinaryHandlerPlanBatchPhp(['Some.File.mkv yEnc (01/20)|Poster <p@x>|alt.binaries.test', 'some.file.mkv  yenc (02/20)|poster <p@x>|ALT.BINARIES.TEST',
            'some.file.mkv yenc (02/20)|poster <p@x>|alt.binaries.test', 'x|p|alt.binaries.test|30|20', 'x|p|alt.binaries.test', 'x|p|nogroup|1|1', '(1/2)|p|alt.binaries.test',
            'x|p|a.b|z', '', 'x|p|a.b|1|1|7', 'y (3/9)|  |a.b|4|9']);
        $this->assertSame([7, 1 | 8, 1], [$bits($handler[0], 0, 3), $bits($handler[0], 3, 8), $bits($handler[0], 51, 11)]);
        $this->assertSame((int) hexdec(hash('fnv1a32', "subject:some.file.mkv yenc\0poster:poster <p@x>")) & 0xFFFFF, $bits($handler[0], 11, 20));
        $this->assertSame((int) hexdec(hash('fnv1a32', "collection:some.file.mkv yenc\0poster <p@x>\0alt.binaries.test")) & 0xFFFFF, $bits($handler[0], 31, 20));
        $this->assertSame([7, 1 | 4, $bits($handler[0], 11, 40), 6], [$bits($handler[1], 0, 3), $bits($handler[1], 3, 8), $bits($handler[1], 11, 40), $bits($handler[2], 0, 3)]);
        $this->assertSame([4, 5, 3, 2, 1, 0], [$bits($handler[3], 0, 3), $bits($handler[4], 0, 3), $bits($handler[5], 0, 3), $bits($handler[6], 0, 3),
            $bits($handler[7], 0, 3), $bits($handler[8], 0, 3)]);
        $this->assertSame([7, 64, (int) hexdec(hash('fnv1a32', 'file:7')) & 0xFFFFF], [$bits($handler[9], 0, 3), $bits($handler[9], 3, 8), $bits($handler[9], 11, 20)]);
        $this->assertSame([7, 1 | 2 | 128, 4], [$bits($handler[10], 0, 3), $bits($handler[10], 3, 8), $bits($handler[10], 51, 11)]);
        $this->assertSame([1 + 2 + 1 + 4, 20, 4, 4, 2], [$handler[11 + 16], $handler[11 + 17], $handler[11 + 18], $handler[11 + 19], $handler[11 + 20]]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_edge_case_parity(string $class): void
    {
        $edges = self::edgeCases();
        $this->assertGreaterThanOrEqual(190, \count($edges));
        $template = implode('|', self::TEMPLATES[$class]);
        $this->assertParity($class, [$template, ...$edges], 'edge batch');
        foreach ($edges as $item) {
            $this->assertParity($class, [$item, $item], 'edge item '.bin2hex(substr($item, 0, 64)));
            $this->assertParity($class, [$template, $item], 'edge item vs work item '.bin2hex(substr($item, 0, 64)));
        }
        // Edge values in every field position of every class template, so each helper sees the others' formats too.
        foreach (self::TEMPLATES as $fields) {
            for ($slot = 0; $slot < \count($fields); $slot++) {
                $rows = [];
                foreach ($edges as $edge) {
                    $row = $fields;
                    $row[$slot] = $edge;
                    $rows[] = implode('|', $row);
                }
                $this->assertParity($class, $rows, 'edge field '.$slot);
            }
        }
        // Edges glued to realistic items with every separator.
        foreach ([' ', '.', '-', '_', ':', '|', '/', '(', ')', '"', ',', "\t", ''] as $glue) {
            $this->assertParity($class, array_map(static fn (string $e): string => $template.$glue.$e, $edges), 'glued '.bin2hex($glue));
            $this->assertParity($class, array_map(static fn (string $e): string => $e.$glue.$template, $edges), 'prefixed '.bin2hex($glue));
        }
        // Keys are ignored and the per-item words keep the input order.
        $keyed = ['b' => $template, 7 => 'x|1', 'a' => '', 'c' => "caf\xc3\xa9"];
        $this->assertParity($class, $keyed, 'keyed batch');
        $this->assertCount(self::TARGETS[$class][3] + 4, $class::{self::TARGETS[$class][0].'Php'}($keyed));
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_randomized_realistic_parity(string $class): void
    {
        // 3 seeds x 2 workloads x 320 items, plus 400 byte-soup items, 400 token-soup items and 300 random slices per class.
        foreach ([661, 662, 663] as $seed) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity($class, $this->benchItems($class, new Randomizer(new Mt19937($seed)), $uncommon), 'seed '.$seed);
            }
        }
        $random = new Randomizer(new Mt19937(6611));
        $soup = [];
        for ($i = 0; $i < 400; $i++) {
            $length = $random->getInt(0, 48);
            $soup[] = $length === 0 ? '' : $random->getBytesFromString("aAbByYtTeEsSoOnNfF()/0123456789|||||..--_+ :\0\t\r\n\x0B\x0C\x01\x7f\xff\xc3\xa9", $length);
        }
        $this->assertParity($class, $soup, 'byte soup');
        $this->assertParity($class, array_values(array_filter($soup, static fn (string $s): bool => ! str_contains($s, "\0"))), 'NUL-free soup');
        for ($i = 0; $i < 300; $i++) {
            $this->assertParity($class, \array_slice($soup, $i, $random->getInt(0, 5)), 'soup slice '.$i);
        }
        $tokens = array_values(array_filter(self::EDGE, static fn (string $s): bool => $s !== ''));
        $tokenSoup = [];
        for ($i = 0; $i < 400; $i++) {
            $parts = [];
            for ($j = 0, $n = $random->getInt(1, 11); $j < $n; $j++) {
                $parts[] = $this->pick($random, $tokens);
            }
            $tokenSoup[] = implode($this->pick($random, ['|', '|', '|', ' ', '.', '/', '(', '']), $parts);
        }
        $this->assertParity($class, $tokenSoup, 'token soup');
        // Every class's generator through every helper.
        $mixed = [];
        foreach (array_keys(self::TARGETS) as $other) {
            array_push($mixed, ...\array_slice($this->benchItems($other, new Randomizer(new Mt19937(6612)), true), 0, 80));
        }
        $this->assertParity($class, $mixed, 'cross-class items');
        // Repeated headers across few binaries exercise the duplicate-part and distinct-key sets.
        $headers = [];
        for ($i = 0; $i < 400; $i++) {
            $headers[] = 'file'.$random->getInt(1, 4).'.rar yEnc ('.$random->getInt(1, 6).'/5)|p'.$random->getInt(1, 2).'|alt.binaries.'.$random->getInt(1, 3)
                .'|'.$this->pick($random, ['', '', '3', '9']).'|'.$this->pick($random, ['', '', '5', '4']).'|'.$this->pick($random, ['', '', '0', '2']);
        }
        $this->assertParity($class, $headers, 'clustered headers');
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function targetClasses(): array
    {
        $cases = [];
        foreach (array_keys(self::TARGETS) as $class) {
            $cases[(new ReflectionClass($class))->getShortName()] = [$class];
        }

        return $cases;
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including joining, the FFI
        // call, decoding and any PHP fallback (release dylib, 5 warmups, NNTMUX_W661_BENCH_ROUNDS interleaved paired
        // rounds per helper and workload (default 301, minimum 60), 5 iterations per round). Common = one batch of 320
        // well-formed items of the class's format; uncommon = 320 items mixing edge cases, malformed, long, NUL,
        // case-changed and non-ASCII items.
        // Speedup = PHP latency / Rust latency, minimum over 3 runs at 301 rounds (PHP 8.5.12 NTS, rustc 1.99.1 release dylib, Apple
        // Silicon, shared host at load average ~6-7), common mean / p99, uncommon mean / p99:
        // - BackfillConfig:  6.91x / 3.33x, 6.07x / 5.24x
        // - BackfillService: 8.80x / 7.91x, 7.00x / 5.55x
        // - BinariesConfig:  9.07x / 7.16x, 8.01x / 6.61x
        // - BinariesService: 8.03x / 6.40x, 7.08x / 5.39x
        // - BinaryHandler:   3.47x / 2.77x, 3.75x / 3.12x
        // Every helper clears every gate in all three runs and defaults to Rust.
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per
        // .ai/rules/rust-performance.md; the assertion below requires every default to match its gates.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // RUN_RUST_FFI_BENCHMARKS=1 php artisan test --compact tests/Unit/RustBackfillBinariesConfigServiceHandlerW66ParityTest.php --filter benchmark
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            $this->markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the Wave 66.1 benchmark.');
        }
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(6616));
        $warmup = 5;
        $rounds = max(60, (int) (getenv('NNTMUX_W661_BENCH_ROUNDS') ?: 301));
        $iterations = 5;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => $this->benchItems($class, new Randomizer(new Mt19937(6617)), false),
                'uncommon' => $this->benchItems($class, new Randomizer(new Mt19937(6618)), true),
            ];
            foreach ($workloads as $name => $items) {
                $this->assertGreaterThanOrEqual(300, \count($items));
                $this->assertSame($class::{$entry.'Php'}($items), $class::{$entry.'Native'}($items), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $sink += \count($class::withW66RustFfi($useRust, static fn (): array => $class::$entry($items)));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $rounds; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $useRust ? $class::enableW66RustFfi() : $class::disableW66RustFfi();
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += \count($class::$entry($items));
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $this->setOverride($class, null);
                $speedups[$class][$name] = [
                    'mean' => round($this->mean($times['php']) / $this->mean($times['rust']), 3),
                    'p99' => round($this->p99($times['php']) / $this->p99($times['rust']), 3),
                    'php_mean_us' => round($this->mean($times['php']), 2),
                    'rust_mean_us' => round($this->mean($times['rust']), 2),
                ];
            }
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES) ?: '';
        fwrite(STDERR, "\nRustBackfillBinariesConfigServiceHandlerW66 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW66RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
        }
    }

    /**
     * @param  array<array-key, string>  $items
     */
    private function assertParity(string $class, array $items, string $message): void
    {
        $entry = self::TARGETS[$class][0];
        $php = $class::{$entry.'Php'}($items);
        $this->assertCount(self::TARGETS[$class][3] + \count($items), $php, $class.' '.$message);
        foreach ($php as $word) {
            $this->assertIsInt($word, $class.' '.$message);
            $this->assertGreaterThanOrEqual(0, $word, $class.' '.$message);
        }
        $this->assertSame($php, $class::{$entry.'Native'}($items), $class.' '.$message);
        $this->assertSame($php, $class::withW66RustFfi(true, static fn (): array => $class::$entry($items)), $class.' '.$message);
        $this->assertSame($php, $class::withW66RustFfi(false, static fn (): array => $class::$entry($items)), $class.' '.$message);
    }

    /**
     * 320 well-formed items in each class's format; uncommon batches mix in edge cases, malformed and long items,
     * case changes, NUL and non-ASCII bytes.
     *
     * @return list<string>
     */
    private function benchItems(string $class, Randomizer $random, bool $uncommon): array
    {
        $rows = [];
        for ($i = 0; $i < 320; $i++) {
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $rows[] = $this->pick($random, self::edgeCases());

                continue;
            }
            $fields = match ($class) {
                BackfillConfig::class => $this->backfillConfigFields($random),
                BackfillService::class => $this->backfillServiceFields($random),
                BinariesConfig::class => $this->binariesConfigFields($random),
                BinariesService::class => $this->binariesServiceFields($random),
                default => $this->headerFields($random),
            };
            if ($uncommon && $random->getInt(0, 2) === 0) {
                $fields[$random->getInt(0, \count($fields) - 1)] = $this->pick($random, ['', ' ', 'x', '-1', '1e3', "caf\xc3\xa9", "a\x01b", str_repeat('n', 300),
                    '9999999999999999', 'maybe', '2023-02-29', 'TRUE', 'repair', '(1/2)', 'alt..x', '0']);
            }
            $row = implode('|', $uncommon && $random->getInt(0, 4) === 0 ? \array_slice($fields, 0, $random->getInt(1, \count($fields))) : $fields);
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $row = $this->pick($random, [strtoupper($row), strtolower($row), ' '.$row, $row."\t", $row."\0x", "caf\xc3\xa9".$row, $row.'|x', $row.'||',
                    str_repeat($row, 3), str_replace('.', ' ', $row), str_replace(' ', "\t\t", $row), str_replace('|', '| ', $row)]);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return list<string> */
    private function backfillConfigFields(Randomizer $random): array
    {
        return [$this->pick($random, ['1', '1', '0', 'true', '']), $this->pick($random, ['0', '0', '1', 'false', '']),
            $this->pick($random, ['2012-08-14', '', sprintf('%04d-%02d-%02d', $random->getInt(1995, 2026), $random->getInt(1, 12), $random->getInt(1, 28))]),
            $this->pick($random, ['backfill', 'backfill', 'update', '']), $this->pick($random, ['0', '0', '1', '']),
            $this->pick($random, ['20000', '20000', '', (string) $random->getInt(500, 2_000_000)]), (string) $random->getInt(0, 80),
            $this->pick($random, ['', '0', (string) $random->getInt(1, 4000)])];
    }

    /** @return list<string> */
    private function backfillServiceFields(Randomizer $random): array
    {
        $oldest = $random->getInt(0, 900_000_000);
        $newest = $oldest + $random->getInt(0, 600_000_000);
        $first = $this->pick($random, [$random->getInt($oldest, $newest), $random->getInt($oldest, $newest), 0, $oldest]);
        $articles = $random->getInt(0, 2) === 0 ? '' : (string) $random->getInt(0, 500_000);

        return [(string) $first, (string) $oldest, (string) $newest, $articles, $articles === '' ? (string) $random->getInt(0, $newest) : '',
            $this->pick($random, ['20000', '', '10000', '50000', (string) $random->getInt(0, 100_000)])];
    }

    /** @return list<string> */
    private function binariesConfigFields(Randomizer $random): array
    {
        return [$this->pick($random, ['20000', '', (string) $random->getInt(500, 150_000)]), $this->pick($random, ['1', '0', '']), $this->pick($random, ['1', '0', '']),
            $this->pick($random, ['0', '1', '']), $this->pick($random, ['50000', '', (string) $random->getInt(500, 20_000_000)]), (string) $random->getInt(0, 400),
            $this->pick($random, ['15000', '', (string) $random->getInt(50, 150_000)]), (string) $random->getInt(0, 12),
            $this->pick($random, ['500', '', (string) $random->getInt(0, 1200)]), $this->pick($random, ['500', '', (string) $random->getInt(0, 1200)]),
            $this->pick($random, ['16', '0', '', (string) $random->getInt(0, 1500)])];
    }

    /** @return list<string> */
    private function binariesServiceFields(Randomizer $random): array
    {
        $oldest = $random->getInt(0, 900_000_000);
        $newest = $oldest + $random->getInt(0, 3_000_000);
        $last = $this->pick($random, [0, $random->getInt($oldest, $newest), $random->getInt($oldest, $newest), $random->getInt(0, $newest + 100)]);

        return [(string) $last, (string) $oldest, (string) $newest, $this->pick($random, ['0', '', '100000', (string) $random->getInt(0, 1_000_000)]),
            $this->pick($random, ['20000', '', '10000', (string) $random->getInt(0, 60_000)]), $this->pick($random, ['50000', '', (string) $random->getInt(0, 2_000_000)]),
            (string) $random->getInt(0, 30_000), $this->pick($random, ['15000', '', (string) $random->getInt(0, 40_000)])];
    }

    /** @return list<string> */
    private function headerFields(Randomizer $random): array
    {
        $title = $this->pick($random, ['Some.Show.S01E02', 'The.Movie', 'Artist-Album', 'Great.Documentary', 'Linux.Distro', 'Big.Game', 'Old.Film'])
            .'.'.$random->getInt(1990, 2026).'.'.$this->pick($random, ['1080p', '720p', 'WEB-DL', 'BluRay', 'FLAC']).'-'.$this->pick($random, ['GRP', 'NTb', 'FLUX']);
        $files = $random->getInt(1, 90);
        $total = $random->getInt(1, 400);
        $subject = $this->pick($random, ['['.$random->getInt(1, $files).'/'.$files.'] - "'.$title.'.part'.$random->getInt(1, 40).'.rar" yEnc ('.$random->getInt(1, $total).'/'.$total.')',
            '"'.$title.'.mkv" yEnc ('.$random->getInt(1, $total).'/'.$total.')', $title.' - "'.$title.'.nfo" yEnc', $title.'  [Re-Post]  yEnc ('.$random->getInt(1, 3).'/3)']);

        return [$subject, $this->pick($random, ['Poster <poster@example.com>', 'Anon <anon@nowhere.invalid>', 'NNTP User <u'.$random->getInt(1, 99).'@host.net>']),
            $this->pick($random, ['alt.binaries.teevee', 'alt.binaries.moovee', 'alt.binaries.boneless', 'a.b.multimedia', 'alt.binaries.sounds.flac']),
            $this->pick($random, ['', '', (string) $random->getInt(1, 400)]), $this->pick($random, ['', '', (string) $random->getInt(1, 400)]),
            $this->pick($random, ['0', '', '', (string) $random->getInt(1, 90)])];
    }

    /**
     * A callback that throws a RuntimeException with $message.
     *
     * @return callable(): mixed
     */
    private function throwing(string $message): callable
    {
        return static function () use ($message): mixed {
            throw new RuntimeException($message);
        };
    }

    /**
     * Calls a Wave 66.1 export by name.
     */
    private function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionMethod($class, 'w66RustFfiState'))->invoke(null, 'set', $enabled);
    }

    private function requireLibrary(): void
    {
        if (! \extension_loaded('FFI')) {
            $this->markTestSkipped('FFI is not available.');
        }
        foreach (self::TARGETS as $class => [, , $cdef, , $domain]) {
            if (RustPipelineLibrary::resolveDomain($domain, \constant($class.'::'.$cdef)) === null) {
                $this->markTestSkipped('libnntmux_pipeline is not available.');
            }
        }
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, \count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(\count($values) - 1, (int) ceil(\count($values) * 0.99) - 1)];
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
}
