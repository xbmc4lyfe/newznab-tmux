<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\SevenZipListingParser;
use App\Services\AdditionalProcessing\State\MediaByteBudget;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\State\ProcessingMetrics;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
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

final class RustAdditional7zParserBudgetPersistenceMetricsContextW65ParityTest extends TestCase
{
    /** Per class: the batch entry, its Rust export, its FFI declaration constant, its trailer constant and the trailer word count. */
    private const TARGETS = [
        SevenZipListingParser::class => ['w65SevenZipListingPlanBatch', 'nntmux_w653_seven_zip_listing_batch', 'W65_SEVEN_ZIP_LISTING_FFI_CDEF', 'W65_SEVEN_ZIP_LISTING_TRAILER', 17],
        MediaByteBudget::class => ['w65MediaByteBudgetPlanBatch', 'nntmux_w653_media_byte_budget_batch', 'W65_MEDIA_BYTE_BUDGET_FFI_CDEF', 'W65_MEDIA_BYTE_BUDGET_TRAILER', 14],
        PersistenceMetricsCollector::class => ['w65PersistenceCollectorPlanBatch', 'nntmux_w653_persistence_collector_batch', 'W65_PERSISTENCE_COLLECTOR_FFI_CDEF', 'W65_PERSISTENCE_COLLECTOR_TRAILER', 21],
        ProcessingMetrics::class => ['w65ProcessingMetricsPlanBatch', 'nntmux_w653_processing_metrics_batch', 'W65_PROCESSING_METRICS_FFI_CDEF', 'W65_PROCESSING_METRICS_TRAILER', 22],
        ReleaseProcessingContext::class => ['w65ReleaseContextPlanBatch', 'nntmux_w653_release_context_batch', 'W65_RELEASE_CONTEXT_FFI_CDEF', 'W65_RELEASE_CONTEXT_TRAILER', 27],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        SevenZipListingParser::class => true,
        MediaByteBudget::class => true,
        PersistenceMetricsCollector::class => true,
        ProcessingMetrics::class => true,
        ReleaseProcessingContext::class => true,
    ];

    /** One well-formed NUL-free item per class, in the order of TARGETS. */
    private const SAMPLES = [
        "Path = Movie/Movie.mkv\nSize = 2097152\nPacked Size = 1048576\nModified = 2024-01-01 10:00:00\nAttributes = A\nCRC = DEADBEEF\nEncrypted = -\nMethod = LZMA2:24\nBlock = 0",
        '1000000|200000|500000|reserve',
        'statement|42|1500',
        'archive-downloads|2000000000|4|10|2|1',
        'downloading|0|video,sample|5|10|30|60',
    ];

    /** Edge cases shared by every helper: separators, whitespace, NUL and control bytes, non-ASCII, boundary and malformed items. */
    private const EDGE = ['', ' ', '  ', "\t", "\0", "\n", "\r", "\x0B", "\x0C", "\r\n", "\n\n", "\r\n\r\n", " \t\n\t ", "\x01", "\x7f", "\xff", "caf\xc3\xa9",
        '|', '||', '|||', '||||', '|||||', '||||||', '|||||||', '||||||||', ' | | | | | | | ', "\t|\t|\t", "\0|\0|\0|\0|\0|\0|\0", 'a|b|c|d|e|f|g|h|i', ',',
        ',,', ' , ', '.', '-', '_', '0', '00', '01', '1', '2', '9', '10', '99', '100', '999', '1000', '9999', '65535', '999999', '1000000', '1048575',
        '1048576', '999999999', '1000000000', '999999999999', '1000000000000', '999999999999999', '1000000000000000', '-1', '+1', '+', ' 12 ', '1e3',
        '0x10', '0.5', '123456789012345678', 'reserve', 'RESERVE', ' Consume ', 'probe', 'release', 'refund', 'begin', 'BEGIN', 'statement', 'Statement',
        'request', 'execution', 'finish', 'end', 'workspace-preparation', 'nzb-parsing', 'Archive-Downloads', 'finalization', 'workspace-cleanup',
        'timeout-handling', 'media sources', 'pending', 'downloading', 'extracting', 'media', 'finalizing', 'done', 'DONE', 'failed', 'stuck', 'video',
        'mediaInfo', 'MEDIAINFO', 'audioInfo', 'audioSample', 'jpgSample', 'sample', 'par2Info', 'nfo', 'NFO', 'video,mediaInfo,audioInfo,audioSample',
        'jpgSample,sample', ' video , sample ', 'video,,x', 'Path = x', 'Path = x.mkv', "Path = x.mkv\nSize = 10", "Path = \nSize = 1", 'Size = 1',
        "Path = a\x01b\nSize = 1", "Path = a\xc2\x85b\nSize = 1", "Path = a\xc2\xa0b.mkv\nSize = 1", "Path = \xc3\x85\xe4\xb9\x85.mkv\nSize = 1",
        "Path = d\nSize = 0\nAttributes = D....", "Path = d\nSize = 0\nFolder = +", "Path = d\nSize = 0\nFolder = -", "Path = x.r00\nSize = 5",
        "Path = x.Z01\nSize = 5", "Path = x.001\nSize = 5", "Path = x.r0\nSize = 5", "Path = x\nPath = y\nSize = 1", "Path = x\nSize = 1\nSize = 2",
        "Path = x\nSize = 1\nbad line", '1Path = x', 'Path=x', 'Path =', 'Path = ', ' = ', ' =', '=', 'a = = b', 'Method = AES-256 LZMA2:24',
        'Method = Copy', 'Method = copy', 'Method = BCJ2 LZMA:24 ', 'Method = PPMD:o6:mem24', 'Method = :', 'Method = aes', 'Encrypted = +',
        'Encrypted = -', 'Encrypted = ?', 'Packed Size = 999999999999999', 'Size = 9999999999999999', 'Size = x', 'Offset = 12', 'Offset = -1',
        "Path = x.mkv\r\nSize = 10\r", "Path = x.mkv\r\r\nSize = 10", "Path = dir/sub\\Sample.MKV\nSize = 10", "Path = .mkv\nSize = 10",
        "Path = x.\nSize = 10", "Path = x.nfo\nSize = 10\nPacked Size = 20", "Path = x.flac\nSize = 0\nPacked Size = 0", 'Key (x)_y.z-w = v',
        "Host OS = FAT\nPath = x.jpg\nSize = 3", ...self::SAMPLES, '1000|800|500|consume', '1000|1000|1|', '0|0|0|', '10|20|5|release', 'x|0|0|',
        'begin|7|', 'begin||', 'request|7|', 'execution|8|', 'finish||', 'statement||x', 'Finalization|1000000|1|0|0|0', 'bogus|1|1|1|1|1',
        'media|1|||||', 'done|0|video,mediaInfo,audioInfo,audioSample,jpgSample,sample|||10|0', 'failed|2|nfo|1|1|5|1', 'extracting|1|||||',
        'pending|0|video,mediaInfo,audioInfo,audioSample|0|0|100|99'];

    /**
     * EDGE plus long runs and boundary lengths.
     *
     * @return list<string>
     */
    private static function edgeCases(): array
    {
        return [...self::EDGE, str_repeat('a', 255), str_repeat('a', 256), str_repeat('a ', 300), str_repeat('.', 600), str_repeat('|', 300),
            str_repeat(',', 300), str_repeat('9', 40), str_repeat('9', 15), str_repeat('9', 16), str_repeat('9', 6), str_repeat('9', 7),
            str_repeat("\xc3\xa9", 200), str_repeat("\n", 300), str_repeat("Key = v\n", 300), str_repeat("Path = x\n", 40),
            'Path = '.str_repeat('x/', 200)."a.mkv\nSize = 1", str_repeat('video,', 80), str_repeat('sample ', 60).'.mkv', str_repeat("\xff", 300),
            str_repeat('begin|1|', 50), 'Method = '.str_repeat('AES ', 100)];
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $class::resetW65RustFfi();
        }
        parent::tearDown();
    }

    public function test_w65_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$entry, , $cdef, $trailerConstant, $trailer]) {
            $reflection = new ReflectionClass($class);
            foreach (['enableW65RustFfi', 'disableW65RustFfi', 'resetW65RustFfi', 'usesW65RustFfi', 'withW65RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()
                    || $reflection->getMethod($method)->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$method;
                }
            }
            foreach (['W65_RUST_FFI_DEFAULT', $cdef, $trailerConstant] as $constant) {
                $declared = $reflection->getReflectionConstant($constant);
                if ($declared === false || ! $declared->isPublic() || $declared->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$constant;
                }
            }
            if (! \is_bool($reflection->getConstant('W65_RUST_FFI_DEFAULT')) || $reflection->getConstant($trailerConstant) !== $trailer) {
                $missing[] = $class.' constant values';
            }
            foreach (['w65RustFfiState', 'w65Invoke'] as $private) {
                $method = new ReflectionMethod($class, $private);
                if (! $method->isPrivate() || ! $method->isStatic() || $method->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$private;
                }
            }
            $state = new ReflectionMethod($class, 'w65RustFfiState');
            $params = array_map(static fn ($p): string => $p->getName().':'.$p->getType().'='.var_export($p->getDefaultValue(), true), $state->getParameters());
            if ($params !== ['op:?string=NULL', 'val:mixed=NULL'] || (string) $state->getReturnType() !== 'mixed') {
                $missing[] = $class.'::w65RustFfiState signature';
            }
            $uses = new ReflectionMethod($class, 'usesW65RustFfi');
            $with = new ReflectionMethod($class, 'withW65RustFfi');
            $withParams = array_map(static fn ($p): string => $p->getName().':'.$p->getType(), $with->getParameters());
            if (! $uses->getReturnType() instanceof ReflectionNamedType || $uses->getReturnType()->getName() !== 'bool' || $uses->getNumberOfParameters() !== 0
                || $withParams !== ['enabled:bool', 'callback:callable'] || (string) $with->getReturnType() !== 'mixed') {
                $missing[] = $class.' usesW65RustFfi/withW65RustFfi signatures';
            }
            foreach (['enableW65RustFfi', 'disableW65RustFfi', 'resetW65RustFfi'] as $toggle) {
                $method = new ReflectionMethod($class, $toggle);
                if ($method->getNumberOfParameters() !== 0 || (string) $method->getReturnType() !== 'void') {
                    $missing[] = $class.'::'.$toggle.' signature';
                }
            }
        }
        $this->assertSame(array_column(array_values(self::TARGETS), 1), array_values(array_unique(array_column(array_values(self::TARGETS), 1))));
        $this->assertSame([], $missing);
    }

    public function test_w65_adds_no_properties(): void
    {
        // Property sets as of Wave 64 (static and instance), so Wave 65 cannot add state outside w65RustFfiState().
        $expected = [
            SevenZipListingParser::class => ['files', 'libraryPath', 'modifiedCache', 'partialLine', 'record', 'recordMalformed', 'reliable'],
            MediaByteBudget::class => ['ffi', 'limit', 'spent', 'useRustFfi'],
            PersistenceMetricsCollector::class => ['counters', 'databaseMilliseconds', 'databaseStatements', 'ffi', 'milliseconds', 'releaseId',
                'searchSyncExecutions', 'searchSyncRequests', 'useRustFfi', 'w466Counters', 'w466Ffi', 'w466Milliseconds', 'w466RustFfi'],
            ProcessingMetrics::class => ['ffi', 'stageNanoseconds', 'startedAtNanoseconds', 'useRustFfi', 'w466Ffi', 'w466RustFfi'],
        ];
        foreach ($expected as $class => $names) {
            $actual = array_map(static fn ($p): string => $p->getName(), (new ReflectionClass($class))->getProperties());
            sort($actual);
            sort($names);
            $this->assertSame($names, $actual, $class);
        }
        // ReleaseProcessingContext keeps its 70 properties, none of them Wave 65 state.
        $context = array_map(static fn ($p): string => $p->getName(), (new ReflectionClass(ReleaseProcessingContext::class))->getProperties());
        $this->assertCount(70, $context);
        $this->assertSame([], preg_grep('/w65/i', $context));
        $static = array_map(static fn ($p): string => $p->getName(), (new ReflectionClass(ReleaseProcessingContext::class))->getProperties(\ReflectionProperty::IS_STATIC));
        sort($static);
        $this->assertSame(['useRustFfi', 'w466Ffi', 'w466RustFfi'], $static);
    }

    public function test_w65_defaults_and_toggles(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], (new ReflectionClass($class))->getConstant('W65_RUST_FFI_DEFAULT'), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW65RustFfi(), $class);
            $this->assertTrue($class::withW65RustFfi(true, static fn (): bool => $class::usesW65RustFfi()));
            $this->assertFalse($class::withW65RustFfi(false, static fn (): bool => $class::usesW65RustFfi()));
            $this->assertTrue($class::withW65RustFfi(false, static fn (): bool => $class::withW65RustFfi(true, static fn (): bool => $class::usesW65RustFfi())));
            $this->assertFalse($class::withW65RustFfi(true, static fn (): bool => $class::withW65RustFfi(false, static fn (): bool => $class::usesW65RustFfi())));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW65RustFfi(), $class);
            try {
                $class::withW65RustFfi(! $class::usesW65RustFfi(), $this->throwing('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW65RustFfi(), $class.' restores after an exception');
            $class::disableW65RustFfi();
            $this->assertFalse($class::usesW65RustFfi(), $class);
            $this->assertTrue($class::withW65RustFfi(true, static fn (): bool => $class::usesW65RustFfi()));
            $this->assertFalse($class::usesW65RustFfi(), $class.' scoped toggle restores an explicit disable');
            $class::enableW65RustFfi();
            $this->assertTrue($class::usesW65RustFfi(), $class);
            $class::resetW65RustFfi();
            $this->assertSame(self::DEFAULTS[$class], $class::usesW65RustFfi(), $class.' reset restores the default');
        }
        // Toggles are independent between classes.
        foreach (array_keys(self::TARGETS) as $class) {
            $flipped = ! $class::usesW65RustFfi();
            $class::withW65RustFfi($flipped, function () use ($class, $flipped): void {
                foreach (array_keys(self::TARGETS) as $other) {
                    $this->assertSame($other === $class ? $flipped : self::DEFAULTS[$other], $other::usesW65RustFfi(), $class.' vs '.$other);
                }
            });
            $class::disableW65RustFfi();
            foreach (array_keys(self::TARGETS) as $other) {
                $this->assertSame($other === $class ? false : self::DEFAULTS[$other], $other::usesW65RustFfi(), $class.' disable vs '.$other);
            }
            $class::resetW65RustFfi();
        }
        // Both dispatch paths return the PHP reference words, also after a reset drops the cached library.
        foreach (self::TARGETS as $class => [$entry]) {
            $items = [...self::SAMPLES, ''];
            $php = $class::{$entry.'Php'}($items);
            $this->assertSame($php, $class::withW65RustFfi(true, static fn (): array => $class::$entry($items)), $class);
            $this->assertSame($php, $class::withW65RustFfi(false, static fn (): array => $class::$entry($items)), $class);
            $class::resetW65RustFfi();
            $this->assertSame($php, $class::withW65RustFfi(true, static fn (): array => $class::$entry($items)), $class.' after reset');
            $this->assertSame($class::{$entry.'Php'}([]), $class::withW65RustFfi(true, static fn (): array => $class::$entry([])), $class);
        }
    }

    public function test_w65_existing_public_api_is_unchanged(): void
    {
        // Earlier-wave toggles keep their own defaults.
        $this->assertFalse(SevenZipListingParser::usesW426RustFfi());
        $this->assertTrue(SevenZipListingParser::usesW52RustFfi());
        $this->assertFalse(MediaByteBudget::usesRustFfi());
        $this->assertFalse(MediaByteBudget::usesW45RustFfi());
        $this->assertTrue(MediaByteBudget::usesW53RustFfi());
        $this->assertFalse(PersistenceMetricsCollector::usesW466RustFfi());
        $this->assertTrue(PersistenceMetricsCollector::usesW55RustFfi());
        $this->assertFalse(ProcessingMetrics::usesRustFfi());
        $this->assertFalse(ProcessingMetrics::usesW466RustFfi());
        $this->assertTrue(ProcessingMetrics::usesW55RustFfi());
        $this->assertFalse(ReleaseProcessingContext::usesW466RustFfi());
        $this->assertTrue(ReleaseProcessingContext::usesW55RustFfi());
        foreach ([true, false] as $enabled) {
            foreach (array_keys(self::TARGETS) as $class) {
                $class::withW65RustFfi($enabled, function (): void {
                    $parser = new SevenZipListingParser;
                    $parser->feedPhp("Path = a.mkv\nSize = 10\nMethod = Copy\n\n");
                    $parser->finishPhp();
                    $this->assertSame([['name' => 'a.mkv', 'size' => 10, 'date' => 0, 'pass' => 0, 'compressed' => 0]], $parser->files);
                    $this->assertSame(SevenZipListingParser::linePlanBatchPhp(['Path = x', '']), SevenZipListingParser::linePlanBatch(['Path = x', '']));
                    $budget = new MediaByteBudget(100);
                    $budget->spend(40);
                    $this->assertSame([60, 60, false], [$budget->remaining(), $budget->clampRequestBytes(80), $budget->exhausted()]);
                    $this->assertSame(MediaByteBudget::spendPlanBatchPhp(100, 10, [50, 60]), MediaByteBudget::spendPlanBatch(100, 10, [50, 60]));
                    $this->assertSame([1, 2.5, 0, 0], PersistenceMetricsCollector::applyEventPhp(PersistenceMetricsCollector::EVENT_DATABASE_STATEMENT, 1, 0, 2.5, [0, 0.0, 0, 0]));
                    $this->assertSame(PersistenceMetricsCollector::foldScopedEventBatchPhp([0, 1], [1, 1], [1, 1], [10, 0]),
                        PersistenceMetricsCollector::foldScopedEventBatch([0, 1], [1, 1], [1, 1], [10, 0]));
                    $this->assertSame(ProcessingMetrics::summarizeStageBatchPhp(['nzb-parsing'], [1000]), ProcessingMetrics::summarizeStageBatch(['nzb-parsing'], [1000]));
                    $this->assertSame(ReleaseProcessingContext::summarizeContextBatchPhp([1], [10], [5], [1], [2]),
                        ReleaseProcessingContext::summarizeContextBatch([1], [10], [5], [1], [2]));
                });
            }
        }
    }

    public function test_w65_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, $symbol, $cdef, , $trailer]) {
            $declaration = \constant($class.'::'.$cdef);
            $this->assertStringContainsString($symbol, $declaration);
            $ffi = RustPipelineLibrary::resolveDomain('additional_w653', $declaration);
            $this->assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[128]');
            $items = self::SAMPLES;
            $blob = implode("\0", $items);
            $words = $trailer + \count($items);
            $this->assertSame($words, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, \count($items), $out, 128), $class);
            $this->assertSame($class::{$entry.'Php'}($items), array_values((array) unpack('P*', FFI::string($out, $words * 8))), $class);
            // A length column splits unseparated items, so NUL bytes stay inside an item.
            $nul = ["a\0b", 'x|y', "x\0"];
            $lens = $ffi->new('int64_t[3]');
            FFI::memcpy($lens, pack('P*', 3, 3, 2), 24);
            $joined = implode('', $nul);
            $this->assertSame($trailer + 3, $this->callExport($ffi, $symbol, $joined, \strlen($joined), FFI::addr($lens[0]), 3, $out, 128), $class);
            $this->assertSame($class::{$entry.'Php'}($nul), array_values((array) unpack('P*', FFI::string($out, ($trailer + 3) * 8))), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $joined, \strlen($joined) - 1, FFI::addr($lens[0]), 3, $out, 128), $class.' lengths must tile the blob');
            // A NUL inside an item, a short buffer, a null output, a null blob with a length and a count mismatch all decline.
            $this->assertSame(-1, $this->callExport($ffi, $symbol, "a\0b", 3, null, 1, $out, 128), $class);
            $this->assertSame(-$words, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, \count($items), $out, $words - 1), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, \count($items), null, 128), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, null, 8, null, 1, $out, 128), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, 'a', 1, null, 2, $out, 128), $class);
            $this->assertSame($trailer, $this->callExport($ffi, $symbol, null, 0, null, 0, $out, 128), $class);
            // Output overlapping the length column declines.
            $shared = $ffi->new('int64_t[64]');
            FFI::memcpy($shared, pack('P', 1), 8);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, 'a', 1, FFI::addr($shared[0]), 1, $shared, 64), $class.' overlap');
        }
    }

    public function test_w65_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, , , , $trailer]) {
            $items = [...self::SAMPLES, "Path = \xc3\x85.flac\nSize = 9", "caf\xc3\xa9", ''];
            $invoke = new ReflectionMethod($class, 'w65Invoke');
            $raw = $invoke->invoke(null, $items, $trailer + \count($items));
            $this->assertIsArray($raw, $class.' stays on Rust');
            $this->assertSame($class::{$entry.'Php'}($items), $raw, $class);
            $this->assertNull($invoke->invoke(null, $items, $trailer + \count($items) + 1), $class.' rejects a word count mismatch');
            $nul = ["a\0b", 'begin|1|', "Path = x\0.mkv\nSize = 1", "\0"];
            $this->assertSame($class::{$entry.'Php'}($nul), $invoke->invoke(null, $nul, $trailer + 4), $class.' keeps NUL items on Rust through the length column');
            $this->assertSame($class::{$entry.'Php'}(["\0"]), $invoke->invoke(null, ["\0"], $trailer + 1), $class.' single NUL item');
            $this->assertSame($class::{$entry.'Php'}([]), $invoke->invoke(null, [], $trailer), $class.' empty batch');
            $this->assertCount($trailer, $class::{$entry.'Native'}([]), $class);
        }
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $items
     * @param  array<int, int>  $expected  Expected words by position.
     */
    #[DataProvider('knownCases')]
    public function test_known_cases(string $class, array $items, array $expected): void
    {
        $words = $class::{self::TARGETS[$class][0].'Php'}($items);
        $this->assertSame($expected, array_intersect_key($words, $expected));
        $this->assertParity($class, $items, 'known case');
    }

    /**
     * @return array<string, array{class-string, list<string>, array<int, int>}>
     */
    public static function knownCases(): array
    {
        return [
            '7z listing: encrypted media, directory, empty and duplicate-key records' => [SevenZipListingParser::class,
                ["Path = Movie/Movie.mkv\r\nSize = 2097152\r\nPacked Size = 1048576\r\nEncrypted = +\r\nMethod = AES-256 LZMA2:24\r\n", "Path = Movie\nSize = 0\nFolder = +\n",
                    '', "Path = a\nPath = b\nSize = 1\n"],
                [0 => 3 | 1 << 4 | 1 << 6 | 1 << 8 | 1 << 12 | 3 << 14 | 1 << 18 | 1 << 19 | 5 << 22 | 500 << 30 | 2 << 40,
                    1 => 2 | 1 << 4 | 1 << 6 | 3 << 22, 2 => 0, 3 => 1 | 1 << 4 | 1 << 6 | 1 << 21 | 2 << 22,
                    4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 0, 12 => 0, 13 => 1, 14 => 1, 15 => 1, 16 => 2097152, 17 => 1048576, 18 => 1, 19 => 1, 20 => 1]],
            '7z listing: volumes, NFO, sample and control-character paths' => [SevenZipListingParser::class,
                ["Path = x.r00\nSize = 5", "Path = x.NFO\nSize = 10", "Path = Sample.mkv\nSize = 10", "Path = a\xc2\x85b\nSize = 1", "Path = b.mkv\nSize = 3145728"],
                [0 => 6 | 1 << 4 | 1 << 6 | 2 << 22, 1 => 5 | 1 << 4 | 1 << 6 | 2 << 22, 2 => 3 | 1 << 4 | 1 << 6 | 1 << 20 | 2 << 22,
                    3 => 1 | 2 << 4 | 1 << 6 | 2 << 22, 4 => 3 | 1 << 4 | 1 << 6 | 2 << 22 | 3 << 40, 6 => 1, 8 => 2, 10 => 1, 11 => 1, 17 => 3145753, 19 => 5, 20 => 5, 21 => 4]],
            'media byte budget: reserve, consume, denied and invalid' => [MediaByteBudget::class, ['1000|200|500|reserve', '1000|800|500|consume', '1000|1000|1|', 'x|0|0|'],
                [0 => 1 | 1 << 2 | 1 << 4 | 1 << 6 | 1 << 12 | 700 << 15, 1 => 1 | 1 << 2 | 1 << 4 | 2 << 6 | 1 << 9 | 1 << 14 | 1000 << 15,
                    2 => 1 | 1 << 2 | 1 << 4 | 2 << 9 | 1 << 13 | 1 << 14 | 1000 << 15, 3 => 2 | 1 << 2 | 1 << 4 | 4 << 9 | 1 << 12 | 1 << 13 | 1 << 14 | 1000 << 15,
                    4 => 1, 5 => 1, 6 => 1, 7 => 0, 8 => 1, 9 => 2, 10 => 1, 11 => 1, 15 => 700, 16 => 300, 17 => 3]],
            'media byte budget: KiB grant and release' => [MediaByteBudget::class, ['10485760|0|1048576|', '100|60|80|release', '100|0|0|probe'],
                [0 => 1 | 1 << 2 | 1 << 4 | 1 << 12 | 100 << 15 | 1024 << 25, 1 => 1 | 1 << 2 | 1 << 4 | 4 << 6 | 3 << 9],
            ],
            'persistence collector: scoped stream' => [PersistenceMetricsCollector::class,
                ['begin|7|', 'statement||1500', 'request|7|', 'execution|8|', 'finish||', 'statement||5'],
                [0 => 1 | 1 << 3 | 4 << 7 | 1 << 11, 1 => 2 | 1 << 5 | 1 << 10 | 1 << 11 | 1 << 12, 2 => 3 | 1 << 3 | 1 << 10 | 1 << 11 | 1 << 12 | 1 << 32,
                    3 => 4 | 1 << 3 | 2 << 7 | 1 << 10 | 1 << 11 | 1 << 12 | 1 << 32, 4 => 5 | 5 << 7, 5 => 2 | 1 << 5 | 1 << 7,
                    6 => 2, 7 => 1, 8 => 1, 9 => 0, 10 => 1, 11 => 1, 12 => 0, 13 => 1, 14 => 2, 15 => 1, 16 => 1, 17 => 1, 18 => 0,
                    19 => 1, 20 => 0, 21 => 1, 22 => 1500, 23 => 1, 24 => 0, 25 => 0, 26 => 0]],
            'persistence collector: abandoned and open scope' => [PersistenceMetricsCollector::class, ['begin|1|', 'request|1|', 'begin|2|', 'execution|2|', 'bogus|2|'],
                [4 => 6 | 1 << 3 | 3 << 7 | 1 << 10 | 1 << 11 | 1 << 44, 5 => 2, 8 => 1, 9 => 2, 12 => 2, 17 => 1, 19 => 1, 24 => 3, 25 => 1]],
            'processing metrics: stage totals and throughput' => [ProcessingMetrics::class,
                ['archive-downloads|2000000000|4|10|2|1', 'Finalization|1000000|1|0|0|0', 'bogus|1|1|1|1|1'],
                [0 => 7 | 1 << 4 | 1 << 6 | 1 << 8 | 1 << 10 | 1 << 12 | 2000 << 15 | 2000 << 35, 1 => 12 | 1 << 4 | 1 << 6 | 1 << 8 | 1 << 10 | 1 << 12 | 1_000_000 << 15 | 1 << 35,
                    2 => 14 | 1 << 4 | 1 << 6 | 1 << 8 | 1 << 10 | 1 << 12 | 1 << 14 | 1_048_575 << 15,
                    9 => 2_000_000_000, 14 => 1_000_000, 16 => 1, 17 => 2_001_000_000, 18 => 5, 19 => 10, 20 => 2, 21 => 1, 22 => 7, 23 => 2498, 24 => 2]],
            'release context: progress, passworded and done' => [ReleaseProcessingContext::class,
                ['downloading|0|video,sample|5|10|30|60', 'media|1|||||', 'done|0|video,mediaInfo,audioInfo,audioSample,jpgSample,sample|||10|0'],
                [0 => 2 | 3 << 4 | 1 << 8 | 1 << 10 | 0b100001 << 13 | 1 << 25 | 1 << 27 | 1 << 29 | 1 << 31 | 1 << 33 | 1 << 34 | 1 << 36 | 500 << 39 | 30 << 49,
                    1 => 4 | 7 << 4 | 1 << 8 | 2 << 10 | 1 << 33 | 1 << 34 | 4 << 36, 2 => 6 | 6 << 4 | 1 << 8 | 1 << 10 | 0b111111 << 13 | 1 << 29 | 1 << 31 | 2 << 36,
                    3 => 0, 4 => 1, 5 => 1, 6 => 0, 7 => 1, 8 => 0, 18 => 2, 19 => 1, 20 => 1, 21 => 1, 22 => 1, 23 => 2, 24 => 0, 25 => 0,
                    26 => 5, 27 => 10, 28 => 0, 29 => 1]],
            'release context: timeout, media skip and unknown found types' => [ReleaseProcessingContext::class,
                ['extracting|0|video,mediaInfo,audioInfo,audioSample,Video,x|||100|99', 'pending|5||||5|10', 'stuck||||||'],
                [0 => 3 | 7 << 4 | 1 << 8 | 1 << 10 | 0b1111 << 13 | 2 << 21 | 1 << 29 | 1 << 31 | 1 << 34 | 1 << 35 | 3 << 36,
                    1 => 1 | 2 << 4 | 1 << 8 | 3 << 10 | 1 << 29 | 1 << 31 | 1 << 33 | 1 << 34 | 1 << 36 | 5 << 49, 2 => 8 | 1 << 33 | 1 << 34 | 5 << 36,
                    28 => 1, 29 => 1]],
            '7z listing empty batch' => [SevenZipListingParser::class, [], array_fill(0, 17, 0)],
            'persistence collector empty batch' => [PersistenceMetricsCollector::class, [], array_fill(0, 21, 0)],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_edge_case_parity(string $class): void
    {
        $this->assertParity($class, [...self::SAMPLES, ...self::edgeCases()], 'edge batch');
        foreach (self::edgeCases() as $item) {
            $label = bin2hex(substr($item, 0, 64));
            $this->assertParity($class, [$item, $item], 'edge item '.$label);
            $this->assertParity($class, [self::SAMPLES[0], $item], 'edge item vs work item '.$label);
            $this->assertParity($class, [$item], 'single edge item '.$label);
        }
        // Edge values in every field position (pipe field or listing line) of every class's sample.
        $edges = self::edgeCases();
        foreach (self::SAMPLES as $sample) {
            $separator = str_contains($sample, "\n") ? "\n" : '|';
            $fields = explode($separator, $sample);
            for ($slot = 0; $slot < \count($fields); $slot++) {
                $rows = [];
                foreach ($edges as $edge) {
                    $row = $fields;
                    $row[$slot] = $edge;
                    $rows[] = implode($separator, $row);
                }
                $this->assertParity($class, $rows, 'edge field '.$slot.' of '.$sample);
            }
        }
        // Keys are ignored and the per-item words keep the input order.
        $keyed = ['b' => self::SAMPLES[1], 7 => self::SAMPLES[3], 'a' => '', 'c' => self::SAMPLES[4]];
        $this->assertParity($class, $keyed, 'keyed batch');
        $this->assertCount(self::TARGETS[$class][4] + 4, $class::{self::TARGETS[$class][0].'Php'}($keyed));
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_randomized_realistic_parity(string $class): void
    {
        // 3 seeds x 2 workloads x 320 items, plus 400 byte-soup items and 300 random slices per class.
        foreach ([651, 652, 653] as $seed) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity($class, $this->benchItems($class, new Randomizer(new Mt19937($seed)), $uncommon), 'seed '.$seed);
            }
        }
        $random = new Randomizer(new Mt19937(6533));
        $soup = [];
        for ($i = 0; $i < 400; $i++) {
            $length = $random->getInt(0, 64);
            $soup[] = $length === 0 ? '' : $random->getBytesFromString("PpSsAaMmDdEeTtHhZz0123456789||||,,==  \n\n\r+-.:/\\\0\t\x01\x7f\xff\xc2\x85\xc3\xa9", $length);
        }
        $this->assertParity($class, $soup, 'byte soup');
        $this->assertParity($class, array_values(array_filter($soup, static fn (string $s): bool => ! str_contains($s, "\0"))), 'NUL-free soup');
        for ($i = 0; $i < 300; $i++) {
            $this->assertParity($class, \array_slice($soup, $i, $random->getInt(0, 5)), 'soup slice '.$i);
        }
        // Every class's generator through every helper.
        $mixed = [];
        foreach (array_keys(self::TARGETS) as $other) {
            array_push($mixed, ...\array_slice($this->benchItems($other, new Randomizer(new Mt19937(6534)), true), 0, 80));
        }
        $this->assertParity($class, $mixed, 'cross-class items');
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
        // call, decoding and any PHP fallback (release dylib, 5 warmups, NNTMUX_W653_BENCH_ROUNDS interleaved paired
        // rounds per helper and workload (default 301, minimum 60), 5 iterations per round). Common = one batch of 320
        // well-formed items of the class's format; uncommon = 320 items mixing edge cases, malformed, long, NUL, CR/LF,
        // case-changed and non-ASCII items. Each helper defaults to Rust only when it clears 1.5x on both workloads'
        // mean and p99, per .ai/rules/rust-performance.md; the assertion below requires every default to match its gates.
        // Speedup = PHP latency / Rust latency, minimum over 3 runs at 301 rounds (PHP 8.5 NTS, release dylib, Apple
        // Silicon, shared host at load average ~4.8-6.0), common mean / p99, uncommon mean / p99:
        // - SevenZipListingParser:       5.08x / 3.76x, 6.18x / 4.39x
        // - MediaByteBudget:             9.10x / 6.38x, 7.31x / 4.96x
        // - PersistenceMetricsCollector: 7.52x / 5.93x, 5.59x / 4.17x
        // - ProcessingMetrics:           8.77x / 6.40x, 7.60x / 4.42x
        // - ReleaseProcessingContext:    9.30x / 5.94x, 9.10x / 5.61x
        // Every helper clears every gate in all three runs and defaults to Rust.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // RUN_RUST_FFI_BENCHMARKS=1 php artisan test --compact tests/Unit/RustAdditional7zParserBudgetPersistenceMetricsContextW65ParityTest.php --filter benchmark
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            $this->markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the Wave 65.3 benchmark.');
        }
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(6536));
        $warmup = 5;
        $rounds = max(60, (int) (getenv('NNTMUX_W653_BENCH_ROUNDS') ?: 301));
        $iterations = 5;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => $this->benchItems($class, new Randomizer(new Mt19937(6537)), false),
                'uncommon' => $this->benchItems($class, new Randomizer(new Mt19937(6538)), true),
            ];
            foreach ($workloads as $name => $items) {
                $this->assertGreaterThanOrEqual(300, \count($items));
                $this->assertSame($class::{$entry.'Php'}($items), $class::{$entry.'Native'}($items), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $sink += \count($class::withW65RustFfi($useRust, static fn (): array => $class::$entry($items)));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $rounds; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $useRust ? $class::enableW65RustFfi() : $class::disableW65RustFfi();
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
        fwrite(STDERR, "\nRustAdditional7zParserBudgetPersistenceMetricsContextW65 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW65RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
        }
    }

    /**
     * @param  array<array-key, string>  $items
     */
    private function assertParity(string $class, array $items, string $message): void
    {
        $entry = self::TARGETS[$class][0];
        $php = $class::{$entry.'Php'}($items);
        $this->assertCount(self::TARGETS[$class][4] + \count($items), $php, $class.' '.$message);
        foreach ($php as $word) {
            $this->assertIsInt($word, $class.' '.$message);
            $this->assertGreaterThanOrEqual(0, $word, $class.' '.$message);
        }
        $this->assertSame($php, $class::{$entry.'Native'}($items), $class.' '.$message);
        $this->assertSame($php, $class::withW65RustFfi(true, static fn (): array => $class::$entry($items)), $class.' '.$message);
        $this->assertSame($php, $class::withW65RustFfi(false, static fn (): array => $class::$entry($items)), $class.' '.$message);
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
        $scope = 0;
        for ($i = 0; $i < 320; $i++) {
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $rows[] = $this->pick($random, self::edgeCases());

                continue;
            }
            $row = match ($class) {
                SevenZipListingParser::class => $this->listingItem($random, $uncommon),
                MediaByteBudget::class => $this->budgetItem($random, $uncommon),
                PersistenceMetricsCollector::class => $this->persistenceItem($random, $uncommon, $scope),
                ProcessingMetrics::class => $this->metricsItem($random, $uncommon),
                default => $this->contextItem($random, $uncommon),
            };
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $row = $this->pick($random, [strtoupper($row), strtolower($row), ' '.$row, $row."\t", $row."\0x", "caf\xc3\xa9".$row, $row.'|x', $row.'||',
                    str_repeat($row, 4), str_replace('.', ' ', $row), str_replace('|', ' | ', $row), "\x01".$row, $row."\r\n", str_replace("\n", "\r\n", $row),
                    str_replace(' = ', '=', $row)]);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function releaseName(Randomizer $random): string
    {
        return $this->pick($random, ['Movie', 'Some.Show', 'The.Film', 'Another_Release', 'Doc', 'Concert Live', "Caf\xc3\xa9.Film"])
            .'.'.$random->getInt(1990, 2025).'.'.$this->pick($random, ['720p', '1080p', '2160p', 'DVDRip']).'.'
            .$this->pick($random, ['x264', 'x265', 'HEVC', 'XviD', 'AV1', 'h264', 'web']).'-'.$this->pick($random, ['GRP', 'NTb', 'FLUX', 'SPARKS']);
    }

    private function listingItem(Randomizer $random, bool $noisy): string
    {
        $base = $this->releaseName($random);
        $folder = $random->getInt(0, 9) === 0;
        $name = $folder ? $base : $base.'/'.$this->pick($random, [$base, 'sample-'.$base, 'Proof'])
            .$this->pick($random, ['.mkv', '.mp4', '.avi', '.nfo', '.sfv', '.jpg', '.flac', '.mp3', '.rar', '.r00', '.part01.rar', '.7z.001', '.srt', '.txt']);
        $size = $folder ? 0 : $random->getInt(0, 9_000_000_000);
        $packed = $folder ? 0 : intdiv($size * $random->getInt(10, 100), 100);
        $method = $this->pick($random, ['LZMA2:24', 'LZMA2:26', 'Copy', 'AES-256 LZMA2:24', 'BCJ2 LZMA:24', 'PPMD:o6:mem24', 'Deflate', 'BZip2', 'Store', '']);
        $lines = ['Path = '.$name];
        if ($folder) {
            $lines[] = 'Folder = +';
        }
        array_push($lines, 'Size = '.$size, 'Packed Size = '.$packed, 'Modified = 20'.$random->getInt(10, 25).'-0'.$random->getInt(1, 9).'-1'.$random->getInt(0, 9).' 1'.$random->getInt(0, 9).':00:00',
            'Attributes = '.($folder ? 'D....' : $this->pick($random, ['A', '....A', 'A -rw-r--r--'])), 'CRC = '.strtoupper(bin2hex($random->getBytes(4))),
            'Encrypted = '.$this->pick($random, ['-', '-', '+']), 'Method = '.$method, 'Block = '.$random->getInt(0, 40));
        if ($random->getInt(0, 4) === 0) {
            $lines[] = 'Offset = '.$random->getInt(0, 1_000_000);
        }
        if ($noisy && $random->getInt(0, 2) === 0) {
            $lines[] = $this->pick($random, ['Size = 1', 'garbage line', 'Path = dup', '1bad = x', 'Comment =', '', " \t "]);
        }

        return implode($noisy && $random->getInt(0, 3) === 0 ? "\r\n" : "\n", $lines);
    }

    private function budgetItem(Randomizer $random, bool $noisy): string
    {
        $limit = $this->pick($random, [0, 1_048_576, 10_485_760, 104_857_600, $random->getInt(0, 8_589_934_592)]);
        $spent = $random->getInt(0, max(1, intdiv($limit * 6, 5)));

        return implode('|', [$limit, $spent, $this->pick($random, [0, 65_536, 1_048_576, $random->getInt(0, 200_000_000)]),
            $this->pick($random, ['reserve', 'consume', 'consume', 'probe', 'release', '', $noisy ? 'refund' : 'reserve'])]);
    }

    private function persistenceItem(Randomizer $random, bool $noisy, int &$scope): string
    {
        $roll = $random->getInt(0, 19);
        if ($scope === 0 || $roll === 0) {
            $scope = $random->getInt(1, 9_999_999);

            return 'begin|'.$scope.'|';
        }
        if ($roll === 1) {
            $scope = 0;

            return 'finish||';
        }
        if ($roll <= 11) {
            return 'statement||'.$random->getInt(0, 250_000);
        }

        return $this->pick($random, ['request', 'execution']).'|'.($random->getInt(0, 5) === 0 ? $random->getInt(1, 9_999_999) : $scope).'|'
            .($noisy && $random->getInt(0, 3) === 0 ? 'x' : '');
    }

    private function metricsItem(Randomizer $random, bool $noisy): string
    {
        $stage = $this->pick($random, ['workspace-preparation', 'nzb-parsing', 'release-initialization', 'message-id-selection', 'media-source-planning',
            'direct-downloads', 'archive-downloads', 'extracted-files', 'media-sources', 'archive-fallbacks', 'timeout-handling', 'finalization',
            'workspace-cleanup', $noisy ? 'unknown-stage' : 'finalization']);

        return implode('|', [$stage, $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1_000, 900_000_000_000), $random->getInt(0, 500), $random->getInt(0, 5000),
            $random->getInt(0, 200), $random->getInt(0, 50)]);
    }

    private function contextItem(Randomizer $random, bool $noisy): string
    {
        $types = ['video', 'mediaInfo', 'audioInfo', 'audioSample', 'jpgSample', 'sample', 'par2Info', 'nfo'];
        $found = [];
        foreach ($types as $type) {
            if ($random->getInt(0, 2) > 0) {
                $found[] = $type;
            }
        }
        if ($noisy && $random->getInt(0, 3) === 0) {
            $found[] = 'Video';
        }
        $total = $random->getInt(0, 400);

        return implode('|', [$this->pick($random, ['pending', 'downloading', 'extracting', 'media', 'finalizing', 'done', 'failed', '']),
            $this->pick($random, [0, 0, 0, 1, 2, 10, '']), implode(',', $found), $random->getInt(0, $total), $total, $random->getInt(0, 900),
            $this->pick($random, [0, 300, 600, 120])]);
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
     * Calls a Wave 65.3 export by name.
     */
    private function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionMethod($class, 'w65RustFfiState'))->invoke(null, 'set', $enabled);
    }

    private function requireLibrary(): void
    {
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('additional_w653', ReleaseProcessingContext::W65_RELEASE_CONTEXT_FFI_CDEF) === null) {
            $this->markTestSkipped('libnntmux_pipeline is not available.');
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
