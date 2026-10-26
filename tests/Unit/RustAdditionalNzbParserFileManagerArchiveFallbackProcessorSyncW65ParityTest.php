<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\AdditionalProcessing\ReleaseFilesArchiveFallback;
use App\Services\AdditionalProcessing\ReleaseProcessor;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use FFI;
use FFI\CData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use RuntimeException;

final class RustAdditionalNzbParserFileManagerArchiveFallbackProcessorSyncW65ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        NzbContentParser::class => 'w65NzbContentParserPlanBatch',
        ReleaseFileManager::class => 'w65ReleaseFileManagerPlanBatch',
        ReleaseFilesArchiveFallback::class => 'w65ArchiveFallbackPlanBatch',
        ReleaseProcessor::class => 'w65ReleaseProcessorPlanBatch',
        ReleaseSearchSyncCoordinator::class => 'w65SearchSyncCoordinatorPlanBatch',
    ];

    /** Class => Wave 65.2 export. */
    private const SYMBOLS = [
        NzbContentParser::class => 'nntmux_w652_nzb_content_plan_batch',
        ReleaseFileManager::class => 'nntmux_w652_release_file_plan_batch',
        ReleaseFilesArchiveFallback::class => 'nntmux_w652_archive_fallback_plan_batch',
        ReleaseProcessor::class => 'nntmux_w652_release_processor_plan_batch',
        ReleaseSearchSyncCoordinator::class => 'nntmux_w652_search_sync_plan_batch',
    ];

    /** Class => class-distinct C declaration constant. */
    private const CDEFS = [
        NzbContentParser::class => 'W65_NZB_CONTENT_PARSER_FFI_CDEF',
        ReleaseFileManager::class => 'W65_RELEASE_FILE_MANAGER_FFI_CDEF',
        ReleaseFilesArchiveFallback::class => 'W65_ARCHIVE_FALLBACK_FFI_CDEF',
        ReleaseProcessor::class => 'W65_RELEASE_PROCESSOR_FFI_CDEF',
        ReleaseSearchSyncCoordinator::class => 'W65_SEARCH_SYNC_COORDINATOR_FFI_CDEF',
    ];

    /** Class => summary words after the per-row words (the digest included). */
    private const TAIL = [
        NzbContentParser::class => 30,
        ReleaseFileManager::class => 26,
        ReleaseFilesArchiveFallback::class => 27,
        ReleaseProcessor::class => 51,
        ReleaseSearchSyncCoordinator::class => 35,
    ];

    /** Class => public tail-size constant. */
    private const TAIL_CONSTANTS = [
        NzbContentParser::class => 'W65_NZB_CONTENT_PARSER_TAIL',
        ReleaseFileManager::class => 'W65_RELEASE_FILE_MANAGER_TAIL',
        ReleaseFilesArchiveFallback::class => 'W65_ARCHIVE_FALLBACK_TAIL',
        ReleaseProcessor::class => 'W65_RELEASE_PROCESSOR_TAIL',
        ReleaseSearchSyncCoordinator::class => 'W65_SEARCH_SYNC_COORDINATOR_TAIL',
    ];

    /** Class => static properties declared before Wave 65 (the wave adds none). */
    private const STATIC_PROPERTIES = [
        NzbContentParser::class => ['w416UseRustFfi', 'w416Ffi'],
        ReleaseFileManager::class => ['ffi', 'ffiResolved', 'useRustFfi', 'fileSizeOut', 'useW52RustFfi', 'w52Ffi', 'w52Out', 'w52OutCap'],
        ReleaseFilesArchiveFallback::class => ['ffi', 'ffiResolved', 'useRustFfi', 'useW52RustFfi', 'w52Ffi', 'w52Out', 'w52OutCap'],
        ReleaseProcessor::class => ['ffi', 'ffiResolved', 'w406UseRustFfi', 'useW52RustFfi', 'w52Ffi', 'w52Out', 'w52OutCap'],
        ReleaseSearchSyncCoordinator::class => ['useRustFfi', 'ffi', 'decision', 'useW52RustFfi', 'w52Ffi', 'w52Out', 'w52OutCap'],
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns), each the minimum of
     * three runs of test_paired_randomized_common_and_uncommon_benchmarks (seed 65, 40
     * interleaved rounds of 10 pairs per class and workload after 2 warmups, 400 pooled samples
     * per metric, each side the fastest of 3 back-to-back calls, batches of 320 Common or
     * 320-640 Uncommon rows, release Rust, PHP 8.5.12 CLI, Apple Silicon macOS on a shared host
     * at load average 7-8). Timings include dispatch, implode, the FFI call and unpack('P*'). A
     * class defaults to Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        NzbContentParser::class => [6.948, 5.571, 6.557, 5.662],
        ReleaseFileManager::class => [9.799, 8.740, 10.278, 7.585],
        ReleaseFilesArchiveFallback::class => [5.164, 4.549, 5.373, 4.421],
        ReleaseProcessor::class => [5.588, 4.642, 6.675, 5.524],
        ReleaseSearchSyncCoordinator::class => [8.632, 6.741, 8.281, 4.922],
    ];

    /** Interleaved benchmark rounds; each times PAIRS pairs per class and workload. */
    private const ROUNDS = 40;

    private const PAIRS = 10;

    private const WARMUPS = 2;

    /** Back-to-back calls per side of a pair; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    /** Batches per class and workload in the randomized parity sweep. */
    private const PARITY_BATCHES = 6;

    private const WORDS = ['the', 'great', 'night', 'big', 'movie', 'show', 'pro', 'saga', 'old', 'new', 'dark', 'star', 'wild', 'hunt', 'city', 'last'];

    private const NZB_EXTENSIONS = ['.part01.rar', '.part02.rar', '.part10.rar', '.rar', '.r00', '.r01', '.7z', '.7z.001', '.zip', '.001', '.mkv', '.mp4', '.avi',
        '.sample.mkv', '.flac', '.mp3', '.par2', '.vol00+01.par2', '.vol03+04.PAR2', '.nfo', '.jpg', '.png', '.sfv', '.srr', '.nzb', '.txt', '.exe', ''];

    private const MEMBER_EXTENSIONS = ['.jpg', '.JPG', '.png', '.nfo', '.diz', '.mkv', '.mp4', '.avi', '.mp3', '.flac', '.rar', '.r00', '.7z', '.zip', '.sfv',
        '.srr', '.url', '.exe', '.db', '.par2', '.txt', '.iso', ''];

    private const MEMBER_WORDS = ['cover', 'poster', 'folder', 'proof', 'sample', 'screen', 'thumbs', 'cd1', 'disc', 'extras'];

    private const STAGES = ['workspace-preparation', 'nzb-parsing', 'release-initialization', 'message-id-selection', 'media-source-planning', 'direct-downloads',
        'archive-downloads', 'extracted-files', 'media-sources', 'archive-fallbacks', 'timeout-handling', 'finalization', 'workspace-cleanup'];

    private const ERRORS = ['', '', '', 'Read timed out', 'Connection reset by peer', '430 No Such Article', 'Archive is password protected', 'CRC mismatch',
        'unrar exited with 3', 'Socket closed', 'Operation timeout after 30s'];

    private const SYNC_FIELDS = ['name', 'searchname', 'fromname', 'categories_id', 'filename', 'size', 'postdate', 'adddate', 'nfo', 'imdbid', 'tvdb',
        'haspreview', 'passwordstatus'];

    protected function tearDown(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $class::resetW65RustFfi();
        }
        parent::tearDown();
    }

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['enableW65RustFfi', 'disableW65RustFfi', 'resetW65RustFfi', 'usesW65RustFfi', 'withW65RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
                self::assertTrue($reflection->getMethod($method)->isPublic() && $reflection->getMethod($method)->isStatic(), $class.'::'.$method);
                self::assertSame($class, $reflection->getMethod($method)->getDeclaringClass()->getName(), $class.'::'.$method);
            }
            $state = $reflection->getMethod('w65RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            self::assertSame(['enabled', 'ffi', 'out', 'capacity'], array_keys($state->getStaticVariables()), $class.' keeps Wave 65 state in function-local statics');
            $default = $reflection->getReflectionConstant('W65_RUST_FFI_DEFAULT');
            self::assertInstanceOf(ReflectionClassConstant::class, $default, $class);
            self::assertIsBool($default->getValue(), $class);
            self::assertTrue($default->isPublic(), $class);
            self::assertSame($class, $reflection->getReflectionConstant(self::CDEFS[$class])?->getDeclaringClass()->getName(), $class);
            self::assertStringContainsString(self::SYMBOLS[$class], (string) $reflection->getConstant(self::CDEFS[$class]), $class);
            self::assertSame(self::TAIL[$class], $reflection->getConstant(self::TAIL_CONSTANTS[$class]), $class);
            self::assertSame(self::STATIC_PROPERTIES[$class], array_keys($reflection->getStaticProperties()), $class.' adds no static property');
            foreach ($reflection->getProperties() as $property) {
                self::assertStringNotContainsString('w65', strtolower($property->getName()), $class.' adds no Wave 65 property');
            }
        }
        self::assertTrue((new ReflectionClass(ReleaseSearchSyncCoordinator::class))->isFinal());
        self::assertCount(5, array_unique(self::CDEFS));
    }

    public function test_existing_public_api_is_preserved(): void
    {
        $expected = [
            NzbContentParser::class => ['__construct', 'parseNzb', 'sourceFileInventory', 'sourceFileInventoryPhp', 'repairNzb', 'extractMessageIDs', 'extractMessageIDsPhp',
                'getNzbPath', 'useW416RustFfi', 'usesW416RustFfi', 'resetW416RustFfi', 'summarizeNzbSegmentBatch', 'summarizeNzbSegmentBatchPhp',
                'summarizeNzbSegmentBatchNative', 'usesW52RustFfi', 'withW52RustFfi', 'subjectNameBatch', 'subjectNameBatchPhp', 'subjectNameBatchNative'],
            ReleaseFileManager::class => ['__construct', 'addFileInfo', 'updateSearchIndex', 'processReleaseNameFromNzbContents', 'finalizeRelease',
                'archiveNamingIsAuthoritative', 'handleReleaseTimeout', 'deleteRelease', 'processPar2File', 'processNfoFile', 'isNfoFilename', 'isNfoFilenamePhp',
                'isNfoFilenameNative', 'useRustFfi', 'matchFileNamesToPreDb', 'processReleaseNameFromRar', 'extractReleaseNameFromFilePhp',
                'extractReleaseNameFromFileNative', 'normalizeFileSizePhp', 'normalizeFileSizeNative', 'useW426RustFfi', 'usesW426RustFfi', 'resetW426RustFfi',
                'summarizeFilePersistenceBatch', 'summarizeFilePersistenceBatchPhp', 'summarizeFilePersistenceBatchNative', 'usesW52RustFfi', 'withW52RustFfi',
                'w52FileNameSizeBatch', 'w52FileNameSizeBatchNative', 'w52FileNameSizeBatchPhp'],
            ReleaseFilesArchiveFallback::class => ['__construct', 'processJpgFromArchiveFileList', 'processJpgFromReleaseFiles', 'processNfoFromReleaseFiles',
                'processNfoFromDownloadedArchives', 'isArchiveImageName', 'isArchiveImageNamePhp', 'isArchiveImageNameNative', 'extractedFileSuffix',
                'extractedFileSuffixPhp', 'extractedFileSuffixNative', 'useRustFfi', 'useW426RustFfi', 'usesW426RustFfi', 'resetW426RustFfi',
                'summarizeArchiveFallbackBatch', 'summarizeArchiveFallbackBatchPhp', 'summarizeArchiveFallbackBatchNative', 'usesW52RustFfi', 'withW52RustFfi',
                'w52ArchiveEntryBatch', 'w52ArchiveEntryBatchNative', 'w52ArchiveEntryBatchPhp'],
            ReleaseProcessor::class => ['__construct', 'process', 'parseReleaseFiles', 'parseReleaseFilesPhp', 'parseReleaseFilesNative', 'extractMessageIdCandidates',
                'extractMessageIdCandidatesPhp', 'extractMessageIdCandidatesNative', 'normalizeSubject', 'normalizeSubjectPhp', 'normalizeSubjectNative',
                'containsVideoPattern', 'containsVideoPatternPhp', 'containsVideoPatternNative', 'hasUsableSampleFilename', 'hasUsableSampleFilenamePhp',
                'hasUsableSampleFilenameNative', 'isAuxiliaryNzbSubject', 'isAuxiliaryNzbSubjectPhp', 'isAuxiliaryNzbSubjectNative', 'subjectMatchesRelease',
                'subjectMatchesReleasePhp', 'subjectMatchesReleaseNative', 'useW406RustFfi', 'usesW406RustFfi', 'resetW406RustFfi',
                'summarizePostProcessBatchTelemetry', 'summarizePostProcessBatchTelemetryPhp', 'summarizePostProcessBatchTelemetryNative', 'usesW52RustFfi',
                'withW52RustFfi', 'w52SubjectClassBatch', 'w52SubjectClassBatchNative', 'w52SubjectClassBatchPhp'],
            ReleaseSearchSyncCoordinator::class => ['__construct', 'setUseRustFfi', 'additionalFfi', 'beginReleaseScope', 'request', 'discard', 'finishReleaseScope',
                'decide', 'decidePhp', 'decideNative', 'useW426RustFfi', 'usesW426RustFfi', 'resetW426RustFfi', 'summarizeSearchSyncBatch',
                'summarizeSearchSyncBatchPhp', 'summarizeSearchSyncBatchNative', 'usesW52RustFfi', 'withW52RustFfi', 'w52DecideSequenceBatch',
                'w52DecideSequenceBatchNative', 'w52DecideSequenceBatchPhp'],
        ];
        foreach ($expected as $class => $methods) {
            $reflection = new ReflectionClass($class);
            foreach ($methods as $method) {
                self::assertTrue($reflection->hasMethod($method) && $reflection->getMethod($method)->isPublic(), $class.'::'.$method);
            }
        }
        self::assertSame(3, ReleaseSearchSyncCoordinator::OP_FINISH);
        // The earlier-wave helpers answer the same whichever way the Wave 65 toggle points.
        $earlier = static fn (): array => [
            NzbContentParser::subjectNameBatchPhp(['"Movie.part01.rar" yEnc (1/50)', 'x']),
            NzbContentParser::summarizeNzbSegmentBatchPhp([['segments' => 3, 'bytes' => 9], 'bad']),
            ReleaseFileManager::isNfoFilenamePhp('Movie.nfo'), ReleaseFileManager::normalizeFileSizePhp('1234'),
            ReleaseFileManager::w52FileNameSizeBatchPhp(['a.mkv', 'b.nfo'], ['10', '20']),
            ReleaseFilesArchiveFallback::isArchiveImageNamePhp('cover.jpg'), ReleaseFilesArchiveFallback::extractedFileSuffixPhp('Movie.NFO'),
            ReleaseFilesArchiveFallback::w52ArchiveEntryBatchPhp(['cover.jpg', 'a.nfo']),
            ReleaseProcessor::normalizeSubjectPhp('"Movie.mkv" yEnc (1/5)'), ReleaseProcessor::containsVideoPatternPhp('"a.mkv"'),
            ReleaseProcessor::w52SubjectClassBatchPhp(['"Movie.mkv" yEnc (1/5)', '"a.par2"'], 'Movie'),
            ReleaseSearchSyncCoordinator::decidePhp(ReleaseSearchSyncCoordinator::OP_REQUEST, true, null, false, 5),
            ReleaseSearchSyncCoordinator::w52DecideSequenceBatchPhp([0, 1, 3], [5, 5, 0]),
        ];
        $baseline = $earlier();
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([true, false] as $enabled) {
                self::assertEquals($baseline, $class::withW65RustFfi($enabled, $earlier), $class);
            }
        }
    }

    public function test_defaults_match_recorded_qualification(): void
    {
        foreach (self::QUALIFICATION as $class => $speedups) {
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->default($class), $class);
            self::assertSame($this->default($class), $class::usesW65RustFfi(), $class);
        }
    }

    public function test_enable_disable_reset_and_with_toggle_scope_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->default($class);
            $class::enableW65RustFfi();
            self::assertTrue($class::usesW65RustFfi(), $class);
            $class::disableW65RustFfi();
            self::assertFalse($class::usesW65RustFfi(), $class);
            self::assertTrue($class::withW65RustFfi(true, static fn (): bool => $class::usesW65RustFfi()), $class);
            self::assertFalse($class::usesW65RustFfi(), $class.' with restores an explicit override');
            $class::resetW65RustFfi();
            self::assertSame($default, $class::usesW65RustFfi(), $class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW65RustFfi($enabled, static function () use ($class, $enabled): array {
                    $inner = $class::withW65RustFfi(! $enabled, static fn (): bool => $class::usesW65RustFfi());

                    return [$class::usesW65RustFfi(), $inner];
                });
                self::assertSame([$enabled, ! $enabled], $seen, $class);
                self::assertSame($default, $class::usesW65RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW65RustFfi(! $default, static fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown, 'The callback exception must propagate.');
            self::assertSame($default, $class::usesW65RustFfi(), $class);
        }
    }

    public function test_toggles_stay_independent_of_each_other_and_earlier_switches(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $seen = $class::withW65RustFfi(! $this->default($class), static fn (): array => array_map(
                static fn (string $other): bool => $other::usesW65RustFfi(),
                array_keys(self::ENTRIES),
            ));
            foreach (array_keys(self::ENTRIES) as $i => $other) {
                self::assertSame($other === $class ? ! $this->default($other) : $this->default($other), $seen[$i], $class.' vs '.$other);
            }
        }
        $earlier = [
            NzbContentParser::class => ['usesW416RustFfi', 'usesW52RustFfi'],
            ReleaseFileManager::class => ['usesW426RustFfi', 'usesW52RustFfi'],
            ReleaseFilesArchiveFallback::class => ['usesW426RustFfi', 'usesW52RustFfi'],
            ReleaseProcessor::class => ['usesW406RustFfi', 'usesW52RustFfi'],
            ReleaseSearchSyncCoordinator::class => ['usesW426RustFfi', 'usesW52RustFfi'],
        ];
        foreach ($earlier as $class => $methods) {
            $before = array_map(static fn (string $method): bool => $class::$method(), $methods);
            foreach ([true, false] as $enabled) {
                $inside = $class::withW65RustFfi($enabled, static fn (): array => array_map(static fn (string $method): bool => $class::$method(), $methods));
                self::assertSame($before, $inside, $class);
            }
            $class::resetW65RustFfi();
            self::assertSame($before, array_map(static fn (string $method): bool => $class::$method(), $methods), $class.' reset leaves earlier toggles alone');
        }
        try {
            foreach ([true, false] as $enabled) {
                NzbContentParser::useW416RustFfi($enabled);
                ReleaseFileManager::useW426RustFfi($enabled);
                ReleaseFilesArchiveFallback::useW426RustFfi($enabled);
                ReleaseProcessor::useW406RustFfi($enabled);
                ReleaseSearchSyncCoordinator::useW426RustFfi($enabled);
                foreach (array_keys(self::ENTRIES) as $class) {
                    self::assertSame($this->default($class), $class::usesW65RustFfi(), $class);
                    self::assertSame($this->default($class), $class::withW52RustFfi($enabled, static fn (): bool => $class::usesW65RustFfi()), $class);
                }
            }
        } finally {
            NzbContentParser::resetW416RustFfi();
            ReleaseFileManager::resetW426RustFfi();
            ReleaseFilesArchiveFallback::resetW426RustFfi();
            ReleaseProcessor::resetW406RustFfi();
            ReleaseSearchSyncCoordinator::resetW426RustFfi();
        }
    }

    public function test_entry_points_route_through_toggle(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(6500));
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([false, true] as $uncommon) {
                $items = $this->fixture($class, $random, $uncommon);
                $php = $this->call($class, 'Php', $items);
                foreach ([true, false] as $native) {
                    self::assertSame($php, $class::withW65RustFfi($native, fn (): array => $this->call($class, '', $items)), $class);
                }
                self::assertSame($php, $this->call($class, 'Native', $items), $class);
            }
        }
    }

    public function test_native_helpers_fall_back_to_php_for_unsupported_input(): void
    {
        $this->requireBindings();
        // NUL bytes and keyed or non-sequential batches keep the PHP semantics.
        foreach (array_keys(self::ENTRIES) as $class) {
            $this->assertParity($class, ["\"a.\0rar\" (1/2)\t1\t1", "1\ta\0.mkv\t1\t\t\t\t", "x/\0.jpg\t2000\t", "1\tfinalization\t\t\0\t\t\t\t", "1\tupdate\tname\0\t1"]);
            $this->assertParity($class, ['k' => "1\tupdate\tname\t1", 'j' => "\"a.rar\" (1/2)\t2\t10"]);
            $this->assertParity($class, [1 => "1\ta.mkv\t10\t\t\t\t", 0 => "cover.jpg\t5000\t"]);
            $this->assertParity($class, ["\0"]);
        }
    }

    public function test_exports_guard_null_short_overlapping_and_malformed_batches(): void
    {
        $this->requireBindings();
        foreach (self::SYMBOLS as $class => $symbol) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[64]');
            $words = 1 + self::TAIL[$class];
            self::assertSame($words, $this->invoke($ffi, $symbol, ['a', 1, 1, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['a', 1, 1, null, 64]), $class);
            self::assertSame(-$words, $this->invoke($ffi, $symbol, ['a', 1, 1, $out, $words - 1]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ["a\0b", 3, 1, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['a', 1, 2, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, [null, 1, 1, $out, 64]), $class);
            self::assertSame(self::TAIL[$class], $this->invoke($ffi, $symbol, [null, 0, 0, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['', 0, 1 << 21, $out, 64]), $class);
            // An output aliasing the input is refused; an unaligned output is written byte-wise.
            $shared = $ffi->new('char[1024]');
            FFI::memcpy($shared, 'a', 1);
            $base = $ffi->cast('char *', FFI::addr($shared));
            self::assertSame(-1, $this->invoke($ffi, $symbol, [$base, 1, 1, $ffi->cast('int64_t *', $base), 64]), $class);
            self::assertSame($words, $this->invoke($ffi, $symbol, [$base, 1, 1, $ffi->cast('int64_t *', $this->offset($ffi, $shared, 512)), 64]), $class);
            $raw = $ffi->new('char['.(8 * $words + 8).']');
            $unaligned = $this->offset($ffi, $raw, 1);
            self::assertSame($words, $this->invoke($ffi, $symbol, ['a', 1, 1, $ffi->cast('int64_t *', $unaligned), $words]), $class);
            self::assertSame($this->call($class, 'Php', ['a']), array_values((array) unpack('P*', FFI::string($unaligned, 8 * $words))), $class);
        }
    }

    public function test_nzb_content_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["\"Movie.part01.rar\" yEnc (1/50)\t50\t1000", "\"movie.vol00+01.par2\" (1/3)\t2\t10", "Movie.Sample.mkv\tx\t", "\"Movie.part01.rar\"\t1\t1"];
        $this->assertFields(NzbContentParser::class, $rows, (1 << 53) - 1, [3 | 1 << 5 | 1 << 6 | 1 << 9 | 50 << 21, 4 | 1 << 4 | 1 << 5 | 1 << 9 | 3 << 21,
            1 | 1 << 3 | 1 << 7 | 2 << 33, 3 | 1 << 5 | 1 << 8], [0, 1, 0, 2, 1, 0, 0, 0, 0, 0, 0, 1001, 10, 0, 0, 0, 2, 1, 1, 0, 1, 1, 1, 1, 1, 1, 53, 1011, 50]);
        self::assertSame(crc32('movie') & 0x3FF, NzbContentParser::w65NzbContentParserPlanBatchPhp($rows)[0] >> 53);
        $this->assertRandomParity(NzbContentParser::class, 6510);
    }

    public function test_release_file_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["1\tA.mkv\t100\t0xDEADBEEF\t2023-11-14 22:13:20\tno\t", "1\ta.MKV\t100\t\t\t\t", "1\tb.nfo\t50\tzz\t1700000100\tyes\t50", "1\tc.nfo\t70\t1\tbad\t\t50",
            "0\tx\t1\t\t\t\t"];
        $fields = [1 << 3 | 2 << 5 | 2 << 7 | 0xDEADBEEF << 10 | 5 << 42 | 1 << 54, 3 | 5 << 42, 2 | 2 << 3 | 1 << 5 | 1 << 7 | 5 << 42, 1 | 1 << 3 | 3 << 5 | 1 << 10 | 5 << 42,
            4 | 1 << 42];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = "2\tf{$i}\t\t\t\t\t";
            $fields[] = ($i === 11 ? 5 : 0) | 1 << 9 | \strlen("f{$i}") << 42 | ($i + 1) << 54;
        }
        $this->assertFields(ReleaseFileManager::class, $rows, -1, $fields, [12, 1, 1, 1, 1, 1, 14, 2, 1, 14, 1, 1, 1, 15, 1, 1, 0, 2, 100, 70, 20, 1700000000,
            1700000100, 47, 12]);
        // The datetime and Unix forms of one instant normalize alike.
        $words = ReleaseFileManager::w65ReleaseFileManagerPlanBatchPhp(["1\ta\t1\t\t2000-02-29T00:00:00\t\t", "1\tb\t1\t\t951782400\t\t"]);
        self::assertSame([951782400, 951782400], \array_slice($words, 2 + 17 + 4, 2));
        $this->assertRandomParity(ReleaseFileManager::class, 6520);
    }

    public function test_archive_fallback_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["Movie/Cover.JPG\t200000", "movie\\movie.nfo\t900", "Movie.Sample.mkv\t50000000", "movie.mkv\t900000000", "__MACOSX/._cover.jpg\t5000",
            "cover.jpg\t200000\tyes", "x.sfv\tx"];
        $this->assertFields(ReleaseFilesArchiveFallback::class, $rows, (1 << 23) - 1, [1 | 1 << 6 | 1 << 9 | 148 << 13, 2 | 1 << 6 | 1 << 9 | 290 << 13,
            3 | 1 << 4 | 1 << 6 | 52 << 13, 3 | 1 << 6 | 253 << 13, 1 | 1 << 3 | 1 << 9 | 145 << 13, 1 | 1 << 5 | 1 << 7 | 153 << 13, 6 | 1 << 3 | 1 << 8],
            [0, 3, 1, 2, 0, 0, 1, 0, 1, 1, 2, 0, 0, 0, 2, 1, 1, 1, 1, 950200900, 0, 1, 3, 148, 253, 1]);
        $this->assertRandomParity(ReleaseFilesArchiveFallback::class, 6530);
    }

    public function test_release_processor_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["1\tnzb-parsing,DIRECT-DOWNLOADS,finalization,bogus\tno\t1000\t\t0\t\t", "2\t\t\t200000\t\t2\t\t", "3\t\t\t10\t\t1\tConnection reset\t",
            "3\t\t\t10\t0\tx\t\tL q"];
        $this->assertFields(ReleaseProcessor::class, $rows, -1, [1 | (2 | 32 | 2048) << 7 | 11 << 20 | 1 << 24 | 2 << 25 | 1 << 53, 7 | 2 << 4 | 15 << 20 | 1 << 30 | 2 << 33,
            12 | 1 << 4 | 15 << 20 | 2 << 27 | 1 << 33 | 2 << 41, 11 | 1 << 4 | 15 << 20 | 1 << 31 | 1 << 32 | 15 << 41 | 1 << 53 | 16 << 57],
            [0, 1, 0, 0, 0, 0, 0, 1, 0, 0, 0, 1, 1, 1, 2, 1, 0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0, 0, 0, 1, 0, 3, 0, 1, 0, 0, 0, 0, 3, 0, 1, 0, 1, 1, 1, 1, 201020, 200000, 1020, 3]);
        $this->assertRandomParity(ReleaseProcessor::class, 6540);
    }

    public function test_search_sync_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["1\tupdate\tname,SIZE,bogus\t10", "1\tupdate\tsize\t5", "1\tupdate\tnfo\t5", "2\tinsert\t\t7", "2\tdelete\t\t", "3\tupdate\t\t", "\tflush\t\t",
            "\tupdate\tname\t", "4\tdelete\t\t"];
        $all = (1 << 13) - 1;
        $this->assertFields(ReleaseSearchSyncCoordinator::class, $rows, -1, [1 | 33 << 6 | 33 << 19 | 1 << 49 | 1 << 59, 5 | 32 << 6 | 33 << 19 | 1 << 49,
            4 | 256 << 6 | 289 << 19 | 1 << 49, 1 | 1 << 3 | $all << 6 | $all << 19 | 2 << 49, 2 | 2 << 3 | 1 << 49, 7 | 1 << 49, 6 | 3 << 3 | 1 << 32 | 1 << 33,
            64 | 1 << 33, 3 | 2 << 3 | 1 << 33], [1, 2, 1, 1, 1, 1, 1, 1, 5, 1, 2, 1, 0, 2, 1, 1, 1, 1, 3, 1, 1, 2, 1, 1, 1, 1, 1, 1, 0, 0, 1, 1, 4, 27]);
        // 100 pending releases dispatch one batch without an explicit flush.
        $words = ReleaseSearchSyncCoordinator::w65SearchSyncCoordinatorPlanBatchPhp(array_map(static fn (int $i): string => "{$i}\tupdate\tname\t1", range(1, 100)));
        self::assertSame(1, $words[99] >> 32 & 1);
        self::assertSame([1, 100, 0, 0, 100], \array_slice($words, 100 + 26, 5));
        $this->assertRandomParity(ReleaseSearchSyncCoordinator::class, 6550);
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<string>  $items
     */
    #[DataProvider('edgeCases')]
    public function test_edge_case_parity(string $class, array $items): void
    {
        $this->requireBindings();
        $this->assertParity($class, $items);
        if ($items !== [] && ! str_contains(implode('', $items), "\0")) {
            self::assertSame($this->call($class, 'Php', $items), $this->exported($class, $items), $class);
        }
    }

    /** @return iterable<string, array{key-of<self::ENTRIES>, list<string>}> */
    public static function edgeCases(): iterable
    {
        $long = str_repeat('Ab.', 25000);
        $binary = "\xff\xfe\x01".str_repeat("\xDB\xB2\x85", 30);
        $unicode = ['Ünïcödé.part01.rar', '映画.mkv', 'ファイル.7z', "\u{1F3AC}.r00", 'İ.NFO', "\xC9\xE1.jpg", 'naïve.PART02.RAR', 'vidéo/cövér.JPG'];
        $digits = ['0', '1', '-1', '+1', ' 1 ', "\x0b7\x0b", '1.5', '1e3', '99', '100', '999', '1000', '999999', '1000000', '999999999', '1000000000', '999999999999',
            '1000000000000', '0x10', '٣', '１'];
        $shared = ['', ' ', "\t", str_repeat("\t", 12), "\x0b", '-', '.', ',', '"', '""', '0', '9', '(', '(1/2)', "\xC9\xE1", $long, $binary, $long."\t".$long,
            $long."\t".$binary."\t".$long, str_repeat("\t", 5000).'1', ...$unicode];
        foreach (array_keys(self::ENTRIES) as $class) {
            yield $class.' empty' => [$class, []];
            yield $class.' huge, binary and unicode' => [$class, $shared];
        }
        $nzb = [];
        foreach (['x.rar', 'x.RAR', 'x.part1.rar', 'x.part0001.rar', 'x.part12345.rar', 'x.part.rar', '.part01.rar', 'x.r0', 'x.r00', 'x.r000', 'x.7z', 'x.zip',
            'x.001', 'x.0001', 'x.par2', 'x.vol.par2', 'vol01.par2', 'x.VOL01+02.par2', 'x.nfo', 'x.mkv', 'x.MKV', 'x.ts', 'x.jpeg', 'x.txt', 'x', 'x.', '.', '..',
            'sample.mkv', 'x.SAMPLE.mp4', ...$unicode] as $name) {
            array_push($nzb, "\"{$name}\" yEnc (1/10)\t10\t1000", "{$name}\t\t");
        }
        foreach (['a (1/2)', 'a (12/3)', 'a(1/2', 'a (/2)', 'a (1/)', 'a (1/2) (3/4)', 'a (1234567/2)', 'a (1/1234567)', 'a (x/2)', 'a (1/2 )', 'a ) (1/2',
            'a (1/2/3)', '"" (1/2)', '" " (1/2)', '"a" "b" (1/2)', '"a', 'a"b"', '"a.rar"(00/00)'] as $subject) {
            $nzb[] = "{$subject}\t2\t5";
        }
        foreach ($digits as $value) {
            array_push($nzb, "\"a.rar\" (1/5)\t{$value}\t1", "\"a.rar\" (1/5)\t1\t{$value}");
        }
        yield 'nzb content bounds' => [NzbContentParser::class, $nzb];
        $file = [];
        foreach ($digits as $value) {
            array_push($file, "{$value}\ta\t1\t\t\t\t", "1\ta{$value}\t{$value}\t\t\t\t", "1\tb{$value}\t1\t\t\t\t{$value}");
        }
        foreach (['', '0', '0x', '0X1F', '0xdeadbeef', 'DEADBEEF', 'deadbeef0', '123456789', 'g', ' 0xab ', '-1', '0x-1', '0xx1', "\xC9"] as $crc) {
            $file[] = "3\tcrc{$crc}\t1\t{$crc}\t\t\t";
        }
        foreach (['', '0', '1', '9999999999', '99999999999', '1970-01-01 00:00:00', '2099-12-31T23:59:59', '2100-01-01 00:00:00', '1969-12-31 23:59:59',
            '2024-02-30 12:00:00', '2024-00-10 12:00:00', '2024-01-01 24:00:00', '2024-01-01 23:60:00', '2024-01-01 23:59:60', '2024-01-01X23:59:59',
            '2024/01/01 23:59:59', '2024-01-01 23:59', ' 2024-01-01 23:59:59 ', '2024-1-01 23:59:59', '+024-01-01 23:59:59'] as $ts) {
            $file[] = "4\tts{$ts}\t1\t\t{$ts}\t\t";
        }
        foreach (['', 'yes', 'YES', ' true ', '1', 'no', 'False', '0', 'maybe', "\xC9"] as $password) {
            $file[] = "5\tpw{$password}\t1\t\t\t{$password}\t";
        }
        for ($i = 0; $i < 30; $i++) {
            $file[] = "6\tFile{$i}.RAR\t".($i * 7)."\t\t\t\t".($i % 3 === 0 ? '' : (string) ($i % 2 === 0 ? $i * 7 : $i));
            $file[] = "6\tfile{$i}.rar\t1\t\t\t\t";
        }
        yield 'release file bounds' => [ReleaseFileManager::class, $file];
        $fallback = [];
        foreach (['a.jpg', 'A.JPG', 'dir/a.jpg', 'dir\\sub\\a.jpg', 'a/b/c/d/e/f/g/h/i/j/k/l/m/n/o/p/q/r/s/cover.jpg', '/', 'dir/', '.hidden.nfo', '__MACOSX/a.jpg',
            'x/__macosx/a.jpg', 'a.r00', 'a.r1', 'a.rar', 'a.7z', 'a.sfv', 'a.PAR2', 'thumbs.db', 'cover.png', 'poster.gif', 'folder.webp', 'proof.bmp',
            'sample.jpg', 'Sample.MKV', 'a.diz', 'a.flac', 'a.iso', 'a', 'a.', ...$unicode] as $name) {
            $fallback[] = "{$name}\t100000\t";
        }
        foreach ([0, 1, 1023, 1024, 65535, 65536, 65537, 1048575, 1048576, 16777215, 16777216, 16777217, 536870912, 536870913, 1073741823, 1073741824, 4294967296,
            4294967297, 999999999999] as $bytes) {
            array_push($fallback, "img{$bytes}.jpg\t{$bytes}\t", "nfo{$bytes}.nfo\t{$bytes}\t", "vid{$bytes}.mkv\t{$bytes}\t", "aud{$bytes}.mp3\t{$bytes}\t");
        }
        foreach ($digits as $value) {
            $fallback[] = "d{$value}.jpg\t{$value}\t";
        }
        foreach (['', 'yes', 'YES', ' true ', '1', 'no', 'maybe'] as $password) {
            $fallback[] = "p{$password}.jpg\t5000\t{$password}";
        }
        yield 'archive fallback bounds' => [ReleaseFilesArchiveFallback::class, $fallback];
        $processor = [];
        foreach (['', ',', implode(',', self::STAGES), 'FINALIZATION', ' finalization , direct-downloads ', 'finalization,workspace-cleanup', 'nzb_parsing',
            'nzb-parsing,nzb-parsing', 'workspace-preparation,nzb-parsing,release-initialization', 'timeout-handling', 'İ', 'a,b,c,d,e,f,g,h,i,j,k,l,m,n,o,p,q,r'] as $stages) {
            $processor[] = "1\t{$stages}\t\t10\t\t0\t\t";
        }
        foreach (['', 'g', 'G', 'w', 'p', 'b', 'l', 'n', 'gwpbln', 'GWPBLN', 'l, g', 'xyz', str_repeat('q', 30), "\xC9", 'nn'] as $flags) {
            $processor[] = "2\tfinalization\t\t10\t\t1\t\t{$flags}";
        }
        foreach ($digits as $value) {
            array_push($processor, "{$value}\t\t\t\t\t\t\t", "3\t\t\t{$value}\t\t\t\t", "3\t\t\t200000\t{$value}\t\t\t", "3\t\t\t200000\t\t{$value}\t\t",
                "3\t\t\t\t\t{$value}\tsocket\t", "3\t\t\t\t\t{$value}\t\tl");
        }
        foreach ([...self::ERRORS, 'TIMEOUT', 'timed  out', 'Error 430', 'CORRUPT data', 'Pass word', "\xC9socket"] as $error) {
            array_push($processor, "4\tfinalization,media-sources\t\t1\t\t1\t{$error}\t", "4\t\tyes\t1\t\t1\t{$error}\t");
        }
        foreach (['', 'yes', 'YES', ' true ', '1', 'no', 'False', '0', 'maybe'] as $password) {
            $processor[] = "5\tfinalization,archive-downloads\t{$password}\t1\t\t\t\t";
        }
        yield 'release processor bounds' => [ReleaseProcessor::class, $processor];
        $sync = [];
        foreach (['update', 'UPDATE', ' Insert ', 'delete', 'flush', 'FLUSH', 'upsert', '', 'İnsert'] as $op) {
            foreach (['', '1', '0', 'x'] as $rid) {
                $sync[] = "{$rid}\t{$op}\tname\t10";
            }
        }
        foreach (['', ',', implode(',', self::SYNC_FIELDS), 'NAME,Size', 'name,name', 'bogus', 'name,bogus,x', 'categories-id', 'İ'] as $fields) {
            array_push($sync, "7\tupdate\t{$fields}\t1", "8\tinsert\t{$fields}\t1");
        }
        foreach ($digits as $value) {
            array_push($sync, "{$value}\tupdate\tname\t1", "9\tupdate\tsize\t{$value}");
        }
        for ($i = 0; $i < 230; $i++) {
            $sync[] = ($i % 150 + 10)."\t".['update', 'update', 'insert', 'delete'][$i % 4]."\t".self::SYNC_FIELDS[$i % 13]."\t".($i % 50 === 0 ? 600000 : $i);
        }
        array_push($sync, "\tflush\t\t", "\tflush\t\t");
        yield 'search sync bounds' => [ReleaseSearchSyncCoordinator::class, $sync];
    }

    /**
     * Paired randomized benchmark: ROUNDS interleaved rounds visit every class and workload in
     * turn; each round times PAIRS pairs (after WARMUPS) of the PHP and Rust batch entry point on
     * one randomly chosen fixture, alternating which runs first, each side keeping the fastest of
     * REPEATS back-to-back calls. Common batches hold 320 typical rows, Uncommon batches 320-640
     * malformed or adversarial ones. Mean and nearest-rank p99 speedups pool every round's
     * samples. Every pair must agree; raw pairs are written outside the checkout. A class must
     * default to Rust exactly when all four metrics reach 1.5x. Gated behind
     * RUN_RUST_FFI_BENCHMARKS=1.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            self::markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the Wave 65.2 paired benchmark.');
        }
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(65));
        $fixtures = $samples = $records = [];
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach (['Common', 'Uncommon'] as $workload) {
                for ($v = 0; $v < 8; $v++) {
                    $fixtures[$class][$workload][] = $this->fixture($class, $random, $workload === 'Uncommon');
                }
            }
        }
        for ($round = 0; $round < self::ROUNDS; $round++) {
            foreach (array_keys(self::ENTRIES) as $class) {
                foreach (['Common', 'Uncommon'] as $workload) {
                    for ($i = -self::WARMUPS; $i < self::PAIRS; $i++) {
                        $variant = $random->getInt(0, 7);
                        $order = ($i & 1) === 0 ? [false, true] : [true, false];
                        $results = $elapsed = [];
                        foreach ($order as $native) {
                            $items = $fixtures[$class][$workload][$variant];
                            [$results[(int) $native], $elapsed[(int) $native]] = $class::withW65RustFfi($native, fn (): array => $this->timed($class, $items));
                        }
                        self::assertSame($results[0], $results[1], $class.' '.$workload);
                        if ($i >= 0) {
                            $samples[$class][$workload][] = [$elapsed[0], $elapsed[1]];
                            $records[] = [$class, $workload, $round, $variant, $order[0], $elapsed[0], $elapsed[1]];
                        }
                    }
                }
            }
        }
        $summary = [];
        foreach ($samples as $class => $workloads) {
            $metrics = [];
            foreach ($workloads as $workload => $pairs) {
                $php = array_column($pairs, 0);
                $rust = array_column($pairs, 1);
                $summary[$class][$workload] = [
                    'mean_speedup' => round(array_sum($php) / array_sum($rust), 3), 'p99_speedup' => round($this->p99($php) / $this->p99($rust), 3),
                    'php_mean_ns' => (int) (array_sum($php) / \count($php)), 'rust_mean_ns' => (int) (array_sum($rust) / \count($rust)),
                    'php_p99_ns' => $this->p99($php), 'rust_p99_ns' => $this->p99($rust), 'samples' => \count($php),
                ];
                $metrics[] = $summary[$class][$workload]['mean_speedup'];
                $metrics[] = $summary[$class][$workload]['p99_speedup'];
            }
            $summary[$class]['qualifies'] = min($metrics) >= 1.5;
        }
        $artifact = sys_get_temp_dir().'/w652-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS,
            'repeats' => self::REPEATS, 'summary' => $summary, 'records' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW65.2 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::ENTRIES) as $class) {
            self::assertSame($summary[$class]['qualifies'], $class::usesW65RustFfi(), $class.' default must follow the measured 1.5x gate');
        }
    }

    /**
     * Every rule is byte-level ASCII, so an explicit setlocale() changes neither implementation.
     * Declared last: once a script sets a locale, later regexes keep the rebuilt tables.
     */
    public function test_parity_holds_under_locale_tables(): void
    {
        $this->requireBindings();
        $locale = setlocale(LC_CTYPE, '0');
        try {
            foreach (['C.UTF-8', 'en_US.ISO8859-1', 'tr_TR.ISO8859-9'] as $candidate) {
                if (setlocale(LC_CTYPE, $candidate) === false) {
                    continue;
                }
                $cases = [
                    NzbContentParser::class => ["\"\xC9.PART01.RAR\" (1/2)\t2\t1", "\"İ.MKV\"\t1\t1", "I.VOL01+02.PAR2\t1\t1"],
                    ReleaseFileManager::class => ["1\t\xC9.MKV\t1\t0XABCDEF\t\t YES \t", "1\t\xE9.mkv\t1\t\t\tİ\t1", "1\tI.NFO\t1\t\t\t\t"],
                    ReleaseFilesArchiveFallback::class => ["\xC9/COVER.JPG\t5000\t", "İ.NFO\t10\tTRUE", "I/SAMPLE.MKV\t5\t"],
                    ReleaseProcessor::class => ["1\tFİNALIZATION,FINALIZATION\tYES\t1\t\t\tTIMEOUT \xC9\tGİ\xC9", "2\t\t\t1\t\t\tSOCKET\tL"],
                    ReleaseSearchSyncCoordinator::class => ["1\tUPDATE\tNAME,İMDBID,\xC9\t1", "1\tİNSERT\t\t1", "\tFLUSH\t\t"],
                ];
                foreach ($cases as $class => $items) {
                    $this->assertParity($class, $items);
                    self::assertSame($this->call($class, 'Php', $items), $this->exported($class, $items), $class);
                }
            }
        } finally {
            setlocale(LC_CTYPE, (string) $locale);
        }
    }

    // ---- helpers -----------------------------------------------------------------------------

    /**
     * Documented per-row fields (`word & $mask`) and summary words (without the digest) from both
     * implementations.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<string>  $rows
     * @param  list<int>  $fields
     * @param  list<int>  $tail
     */
    private function assertFields(string $class, array $rows, int $mask, array $fields, array $tail): void
    {
        $this->assertParity($class, $rows);
        foreach (['Php', 'Native'] as $suffix) {
            $words = $this->call($class, $suffix, $rows);
            self::assertSame($fields, array_map(static fn (int $word): int => $word & $mask, \array_slice($words, 0, \count($rows))), $class.'::'.$suffix);
            self::assertSame($tail, \array_slice($words, \count($rows), -1), $class.'::'.$suffix);
        }
        self::assertSame($this->call($class, 'Php', $rows), $this->exported($class, $rows), $class);
    }

    /**
     * The fastest of REPEATS back-to-back calls of the class's batch entry point.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  array<array-key, string>  $items
     * @return array{list<int>, int}
     */
    private function timed(string $class, array $items): array
    {
        $best = PHP_INT_MAX;
        $result = [];
        for ($r = 0; $r < self::REPEATS; $r++) {
            $start = hrtime(true);
            $result = $this->call($class, '', $items);
            $best = min($best, hrtime(true) - $start);
        }

        return [$result, $best];
    }

    /** @param  list<int>  $values */
    private function p99(array $values): int
    {
        sort($values);

        return $values[max(0, (int) ceil(\count($values) * 0.99) - 1)];
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function default(string $class): bool
    {
        $value = (new ReflectionClass($class))->getConstant('W65_RUST_FFI_DEFAULT');
        self::assertIsBool($value);

        return $value;
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    private function call(string $class, string $suffix, array $items): array
    {
        /** @var list<int> */
        return [$class, self::ENTRIES[$class].$suffix]($items);
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  array<array-key, string>  $items
     */
    private function assertParity(string $class, array $items): void
    {
        $php = $class::withW65RustFfi(false, fn (): array => $this->call($class, '', $items));
        $rust = $class::withW65RustFfi(true, fn (): array => $this->call($class, '', $items));
        self::assertSame($php, $rust, $class);
        self::assertSame($php, $this->call($class, 'Php', $items), $class);
        self::assertSame($php, $this->call($class, 'Native', $items), $class);
        self::assertCount(\count($items) + self::TAIL[$class], $php, $class);
        self::assertSame($this->digest(\array_slice($php, 0, -1)), $php[\count($php) - 1], $class);
    }

    /**
     * PARITY_BATCHES Common and Uncommon batches (at least 320 rows each) must match, and the
     * Rust export must actually produce them (no silent PHP fallback).
     *
     * @param  key-of<self::ENTRIES>  $class
     */
    private function assertRandomParity(string $class, int $seed): void
    {
        $items = 0;
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < self::PARITY_BATCHES; $i++) {
                $batch = $this->fixture($class, new Randomizer(new Mt19937($seed + 2 * $i + (int) $uncommon)), $uncommon);
                $this->assertParity($class, $batch);
                self::assertSame($this->call($class, 'Php', $batch), $this->exported($class, $batch), $class);
                $items += \count($batch);
            }
        }
        self::assertGreaterThanOrEqual(320 * 2 * self::PARITY_BATCHES, $items, $class);
    }

    /**
     * Calls the export directly with the entry point's framing.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<string>  $items
     * @return list<int>
     */
    private function exported(string $class, array $items): array
    {
        $ffi = $this->ffi($class);
        $count = \count($items);
        $cap = $count + 64;
        $out = $ffi->new('int64_t['.$cap.']');
        $blob = implode("\0", $items);
        $written = $this->invoke($ffi, self::SYMBOLS[$class], [$blob, \strlen($blob), $count, $out, $cap]);
        self::assertIsInt($written);
        self::assertGreaterThan(0, $written, $class);
        /** @var array<int, int> $values */
        $values = unpack('P*', FFI::string($out, $written * 8));

        return array_values($values);
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function ffi(string $class): FFI
    {
        $ffi = (new ReflectionMethod($class, 'w65RustFfiState'))->invoke(null, 'ffi');
        self::assertInstanceOf(FFI::class, $ffi, $class.' must load the nfo_w65 binding');

        return $ffi;
    }

    /** Each Wave 65.2 export must resolve and accept a one-item batch, so parity runs Rust. */
    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        foreach (self::SYMBOLS as $class => $symbol) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[64]');
            self::assertSame(1 + self::TAIL[$class], $this->invoke($ffi, $symbol, ['a', 1, 1, $out, 64]), $class);
        }
    }

    /**
     * Calls an export declared by a class's W65 C declaration.
     *
     * @param  list<mixed>  $args
     */
    private function invoke(FFI $ffi, string $symbol, array $args): mixed
    {
        return $ffi->{$symbol}(...$args);
    }

    /** A `char *` $bytes past the start of $buffer (pointer arithmetic through a `uintptr_t` view). */
    private function offset(FFI $ffi, CData $buffer, int $bytes): CData
    {
        $pointer = $ffi->new('char *[1]');
        $pointer[0] = $ffi->cast('char *', FFI::addr($buffer));
        $address = $ffi->cast('uintptr_t *', FFI::addr($pointer));
        $address[0] += $bytes;
        $result = $pointer[0];
        self::assertInstanceOf(CData::class, $result);

        return $result;
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
     * @return list<string>
     */
    private function fixture(string $class, Randomizer $random, bool $uncommon): array
    {
        $items = [];
        for ($i = 0, $n = $uncommon ? $random->getInt(320, 640) : 320; $i < $n; $i++) {
            $items[] = match ($class) {
                NzbContentParser::class => $this->nzbRow($random, $uncommon),
                ReleaseFileManager::class => $this->fileRow($random, $uncommon),
                ReleaseFilesArchiveFallback::class => $this->fallbackRow($random, $uncommon),
                ReleaseProcessor::class => $this->processorRow($random, $uncommon),
                ReleaseSearchSyncCoordinator::class => $this->syncRow($random, $uncommon),
            };
        }

        return $items;
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

    /** Printable noise, or binary noise without NUL bytes. */
    private function noise(Randomizer $random, int $max, bool $binary = false): string
    {
        $length = $random->getInt(0, $max);
        if ($length === 0) {
            return '';
        }

        return $binary ? str_replace("\0", "\1", $random->getBytes($length))
            : $random->getBytesFromString("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-[]()<>@/\\+=:,\"' \t", $length);
    }

    /** Case-flips, pads or corrupts a field the way hand-edited or truncated data does. */
    private function mangle(Randomizer $random, string $value): string
    {
        return match ($random->getInt(0, 6)) {
            0 => strtoupper($value),
            1 => ucfirst(strtolower($value)),
            2 => $this->pick($random, [' ', "\r\n", "\x0b", '']).$value.$this->pick($random, [' ', "\r\n", "\x0b", '']),
            3 => substr($value, 0, $random->getInt(0, max(0, \strlen($value) - 1))),
            4 => $value.$this->noise($random, 3),
            5 => str_replace(['a', 'e', 'o', '1'], ["\xE1", 'É', '0', $this->pick($random, ['l', ' ', '-', '11'])], $value),
            default => $value,
        };
    }

    /**
     * Replaces one random field of a tab-joined row with $mutate($field), or drops or adds
     * trailing fields.
     *
     * @param  list<string>  $fields
     * @param  callable(string): string  $mutate
     */
    private function mutateFields(Randomizer $random, array $fields, callable $mutate): string
    {
        $k = $random->getInt(0, \count($fields) - 1);
        match ($random->getInt(0, 5)) {
            0 => $fields = \array_slice($fields, 0, $k),
            1 => $fields[] = $this->noise($random, 10),
            default => $fields[$k] = $mutate($fields[$k]),
        };

        return implode("\t", $fields);
    }

    /**
     * A random comma-joined subset of $names.
     *
     * @param  list<string>  $names
     */
    private function subset(Randomizer $random, array $names, int $odds = 2): string
    {
        $picked = [];
        foreach ($names as $name) {
            if ($random->getInt(0, $odds) === 0) {
                $picked[] = $name;
            }
        }

        return implode($this->pick($random, [',', ',', ', ']), $picked);
    }

    /**
     * A random row from one of the shared uncommon shapes, or $special() for the class-specific
     * ones.
     *
     * @param  list<string>  $fields
     * @param  list<string>  $values
     * @param  callable(): string  $special
     */
    private function uncommon(Randomizer $random, array $fields, array $values, callable $special): string
    {
        return match ($random->getInt(0, 9)) {
            0 => $this->noise($random, 80, true),
            1 => $this->noise($random, 60),
            2 => '',
            3 => $this->mutateFields($random, $fields, fn (string $field): string => $this->mangle($random, $field)),
            4 => $this->mutateFields($random, $fields, fn (string $field): string => $this->pick($random, $values)),
            5, 6 => $special(),
            default => $this->mutateFields($random, $fields, fn (string $field): string => $this->noise($random, 12, true)),
        };
    }

    private function releaseName(Randomizer $random): string
    {
        $words = [];
        for ($w = $random->getInt(1, 4); $w > 0; $w--) {
            $words[] = ucfirst($this->pick($random, self::WORDS));
        }

        return implode($this->pick($random, ['.', '.', '_', ' ']), $words).'.'.$random->getInt(1990, 2025);
    }

    private function nzbRow(Randomizer $random, bool $uncommon): string
    {
        $total = $random->getInt(1, 400);
        $name = $this->releaseName($random).$this->pick($random, self::NZB_EXTENSIONS);
        $subject = $random->getInt(0, 4) === 0 ? $name.' yEnc ('.$random->getInt(1, $total).'/'.$total.')'
            : '['.$random->getInt(1, 40).'/40] - "'.$name.'" yEnc ('.$random->getInt(1, $total).'/'.$total.')';
        $fields = [$subject, (string) ($random->getInt(0, 5) === 0 ? $random->getInt(0, $total) : $total), (string) ($total * $random->getInt(300_000, 800_000))];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return $this->uncommon($random, $fields, ['-1', '9999999', '999999', '9999999999999', 'x', ' 3 ', '', '٣'], fn (): string => implode("\t", [
            $this->pick($random, ['"Ünïcödé.part01.rar" yEnc (1/2)', '映画.mkv (1/1234567)', '"" (1/2)', '"a.rar" (x/2)', '"a.vol.par2" (1/2) (3/4)', '(/)',
                '"'.$this->noise($random, 30).'"', $this->noise($random, 120, true).' (1/2)']), $fields[1], $fields[2]]));
    }

    private function fileRow(Randomizer $random, bool $uncommon): string
    {
        $size = $random->getInt(0, 9) === 0 ? '' : (string) $random->getInt(0, 4_000_000_000);
        $fields = [(string) $random->getInt(1, 40), $this->pick($random, self::WORDS).$random->getInt(1, 20).$this->pick($random, self::MEMBER_EXTENSIONS), $size,
            $this->pick($random, ['', '', sprintf('%08x', $random->getInt(0, 0xFFFFFFFF)), sprintf('0x%08X', $random->getInt(0, 0xFFFFFFFF)), dechex($random->getInt(0, 4095))]),
            $this->pick($random, ['', (string) $random->getInt(1_000_000_000, 1_800_000_000), gmdate('Y-m-d H:i:s', $random->getInt(0, 2_000_000_000))]),
            $this->pick($random, ['', '', 'no', 'yes', '0', '1']), $this->pick($random, ['', '', $size, (string) $random->getInt(0, 4_000_000_000)])];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return $this->uncommon($random, $fields, ['-1', '9999999999999', '0', 'x', ' 3 ', '', '٣', '0xZZ', '2024-02-30 12:00:00'], fn (): string => implode("\t", [
            $fields[0], $this->pick($random, ['Ünïcödé.MKV', 'A.MKV', 'a.mkv', ' a.mkv ', '', "\xC9.nfo"]), $fields[2],
            $this->pick($random, ['0x', 'DEADBEEF0', '0Xdead', 'g1', ' 1 ']), $this->pick($random, ['2099-12-31T23:59:59', '1969-12-31 23:59:59', '99999999999',
                '2024-01-01 23:59', '2024-01-01X00:00:00']), $this->pick($random, ['YES', 'True', 'maybe', "\xC9"]), $fields[6]]));
    }

    private function fallbackRow(Randomizer $random, bool $uncommon): string
    {
        $path = '';
        for ($d = $random->getInt(0, 2); $d > 0; $d--) {
            $path .= $this->pick($random, self::WORDS).$this->pick($random, ['/', '/', '\\']);
        }
        $base = $random->getInt(0, 2) === 0 ? $this->pick($random, self::MEMBER_WORDS) : $this->releaseName($random);
        $size = $random->getInt(0, 2_000_000_000) >> $random->getInt(0, 20);
        $fields = [$path.$base.$this->pick($random, self::MEMBER_EXTENSIONS), (string) $size, $this->pick($random, ['', '', '', 'no', 'yes'])];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return $this->uncommon($random, $fields, ['-1', '9999999999999', '0', 'x', ' 3 ', '', '٣'], fn (): string => implode("\t", [
            $this->pick($random, ['__MACOSX/._cover.jpg', '.DS_Store', 'a/', '/', 'Ünïcödé/Cövér.JPG', 'a\\b\\c\\d\\e\\f\\g\\h\\i\\j\\k\\l\\m\\n\\o\\p\\q.nfo', 'x.r99',
                'COVER.JPG', $this->noise($random, 40)]), $fields[1], $this->pick($random, ['YES', 'True', 'maybe', ''])]));
    }

    private function processorRow(Randomizer $random, bool $uncommon): string
    {
        $fields = [(string) $random->getInt(1, 2_000_000), $this->subset($random, self::STAGES, 1), $this->pick($random, ['', '', 'no', 'yes']),
            (string) $random->getInt(0, 200_000), $this->pick($random, ['', '', '60000', '120000', '0']), (string) $random->getInt(0, 4),
            $this->pick($random, self::ERRORS), $this->pick($random, ['', '', '', '', 'g', 'w', 'p', 'b', 'l', 'n', 'gl'])];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return $this->uncommon($random, $fields, ['-1', '9999999999999', '1000', 'x', ' 3 ', '', '٣'], fn (): string => implode("\t", [
            $fields[0], $this->pick($random, ['FINALIZATION', 'nzb_parsing', 'finalization,İ', ',,', implode(',', self::STAGES).',bogus']), $fields[2], $fields[3],
            $fields[4], $fields[5], $this->pick($random, ['TIMEOUT', "\xC9 socket", str_repeat('connection ', 40), 'Error 430', 'corrupt']),
            $this->pick($random, ['GWPBLN', 'xyz', str_repeat('q', 20), "l,\xC9", 'b p'])]));
    }

    private function syncRow(Randomizer $random, bool $uncommon): string
    {
        $fields = [(string) $random->getInt(1, 160), $this->pick($random, ['update', 'update', 'update', 'update', 'insert', 'delete', 'flush']),
            $this->subset($random, self::SYNC_FIELDS, 5), (string) ($random->getInt(0, 9) === 0 ? $random->getInt(0, 900_000) : $random->getInt(0, 4000))];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return $this->uncommon($random, $fields, ['-1', '9999999999999', '0', 'x', ' 3 ', '', '٣'], fn (): string => implode("\t", [
            $this->pick($random, ['', '0', $fields[0]]), $this->pick($random, ['UPDATE', ' Insert ', 'upsert', 'İnsert', 'FLUSH', '']),
            $this->pick($random, ['NAME,Size', 'bogus,x', ',,', implode(',', self::SYNC_FIELDS), 'İmdbid']), $fields[3]]));
    }
}
