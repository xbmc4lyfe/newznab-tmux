<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\AdditionalProcessing\AdditionalProcessingDiagnostics;
use App\Services\AdditionalProcessing\AdditionalProcessingOrchestrator;
use App\Services\AdditionalProcessing\AdditionalWorkPlanner;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
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

final class RustAdditionalQueryDiagnosticsOrchestratorPlannerExtractionW64ParityTest extends TestCase
{
    /** Per class: the batch entry, its Rust export, its FFI declaration constant, the trailer word count and the FFI domain. */
    private const TARGETS = [
        AdditionalCandidateQuery::class => ['w64CandidateQueryPlanBatch', 'nntmux_w641_candidate_query_plan_batch', 'W64_CANDIDATE_QUERY_FFI_CDEF', 44,
            'additional_candidate_query_w641'],
        AdditionalProcessingDiagnostics::class => ['w64DiagnosticsPlanBatch', 'nntmux_w641_diagnostics_plan_batch', 'W64_DIAGNOSTICS_FFI_CDEF', 57,
            'additional_diagnostics_w641'],
        AdditionalProcessingOrchestrator::class => ['w64OrchestratorPlanBatch', 'nntmux_w641_orchestrator_plan_batch', 'W64_ORCHESTRATOR_FFI_CDEF', 28,
            'additional_orchestrator_w641'],
        AdditionalWorkPlanner::class => ['w64WorkPlannerPlanBatch', 'nntmux_w641_work_planner_plan_batch', 'W64_WORK_PLANNER_FFI_CDEF', 43,
            'additional_work_planner_w641'],
        ArchiveExtractionService::class => ['w64ArchiveExtractionPlanBatch', 'nntmux_w641_archive_extraction_plan_batch', 'W64_ARCHIVE_EXTRACTION_FFI_CDEF', 44,
            'archive_extraction_w641'],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        AdditionalCandidateQuery::class => true,
        AdditionalProcessingDiagnostics::class => true,
        AdditionalProcessingOrchestrator::class => true,
        AdditionalWorkPlanner::class => true,
        ArchiveExtractionService::class => true,
    ];

    /** A realistic `|`-separated item per class. */
    private const TEMPLATES = [
        AdditionalCandidateQuery::class => ['52428800', '-1', '-1', '1', '0', '', '', 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678', '0'],
        AdditionalProcessingDiagnostics::class => ['download', 'processed', '1200', '', '524288', '12'],
        AdditionalProcessingOrchestrator::class => ['25', '4', '1', '100', 'abcdef0123456789abcdef0123456789', '2400', '600', '12345', '8200'],
        AdditionalWorkPlanner::class => ['"Some.Show.S01E02.1080p.WEB-DL-GRP.part01.rar" yEnc (1/120)', '52428800', '120', '1', 'alt.binaries.teevee'],
        ArchiveExtractionService::class => ['rar', '0', 'Some.Show.S01E02/some.show.s01e02.mkv', '734003200', '734003200', '1'],
    ];

    /** Edge cases shared by every helper: separators, whitespace, NUL and control bytes, non-ASCII, boundary and malformed values. */
    private const EDGE = ['', ' ', '  ', "\t", "\0", "\n", "\x0B", "\r", "\r\n", "\x7f", "\x01", "\xff", "caf\xc3\xa9", "\xe2\x80\x8b", '|', '||', '|||',
        '||||', '|||||', '||||||', '|||||||', '||||||||', '|||||||||', ' | | ', "\t|\t|\t", "\0|\0|\0", 'a|b|c|d|e|f|g|h|i|j', '.', '..', '...', '-', '_',
        ':', '/', '//', '\\', '\\\\', '"', '""', '"a"', '" "', '"x.rar', 'x"', '0', '00', '1', '2', '3', '9', '10', '15', '16', '-0', '-1', '-2', '--1', '+1',
        '1-', ' 1 ', '1 2', '1.5', '1e3', '0x10', '25', '32', '33', '99', '100', '299', '300', '301', '500', '501', '1023', '1024', '1799', '1800',
        '1801', '4095', '4096', '10239', '10240', '29999', '30000', '59999', '60000', '1048575', '1048576', '1048577', '104857599', '104857600',
        '4294967295', '4294967296', '107374182399', '107374182400', '1099511627776', '1099511627777', '999999999999999', '1000000000000000',
        '-999999999999999', 'true', 'TRUE', 'false', 'yes', 'no', 'Yes', 'maybe', 'setup', 'download', 'archive', 'password', 'nfo', 'media', 'sample',
        'jpg', 'audio', 'par2', 'persist', 'search', 'SEARCH', 'Download', 'bogus', 'processed', 'skipped', 'failed', 'timeout', 'unsupported',
        'awaiting', 'notfound', 'passworded', 'Processed', 'FAILED', 'read timed out', 'Timeout', 'NNTP error 430', 'article missing', 'unrar exited 3',
        '7z: data error', 'bad archive', 'disk full', 'no space left', 'permission denied', 'Deadlock found', 'SQLSTATE[40001]', 'lock wait exceeded',
        'unknown failure', 'rar', 'RAR', 'zip', '7z', '7Z', 'tar', 'gz', 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678', 'A1B2C3D4E5F60718293A4B5C6D7E8F9012345678',
        'a1b2c3d4e5f60718293a4b5c6d7e8f901234567', 'a1b2c3d4e5f60718293a4b5c6d7e8f90123456789', 'g1b2c3d4e5f60718293a4b5c6d7e8f9012345678', 'f', 'F', 'g',
        'abcdef0123456789', 'abcdef012345678', 'abc-def0123456789', 'show.rar', 'Show.RAR', 'show.part01.rar', 'show.part1.rar', 'show.part001.rar',
        'show.part02.rar', 'show.part.rar', 'show.partx.rar', 'show.part12345678.rar', 'show.r00', 'show.r01', 'show.r99', 'show.r100', 'show.r1000',
        'show.R00', 'show.7z', 'show.7z.001', 'show.7z.002', 'show.7Z.001', 'show.zip', 'show.zipx', 'show.001', 'show.000', 'show.002', 'show.1000',
        'movie.mkv.001', 'movie.mkv.002', 'movie.MKV.001', 'show.par2', 'show.vol00+01.par2', 'show.VOL01+02.PAR2', 'show.nfo', 'show.diz', 'show.inf',
        'show.sfv', 'show.srs', 'show.nzb', 'show.mkv', 'show.avi', 'show.mp4', 'show.ts', 'show.sample.mkv', 'Sample/show.mkv', 'show-SAMPLE.mp4',
        'album.flac', 'album.mp3', 'song.w64', 'cover.jpg', 'cover.JPEG', 'cover.png', 'cover.webp', 'sample.jpg', 'book.epub', 'book.pdf', 'book.mobi',
        'book.lit', 'file.txt', 'file.abcdef', 'file.ab-c', 'file.', '.hidden', 'noext', 'dir/', 'dir\\', 'a/b/c/d.mkv', 'a\\b\\c.mkv', '/etc/passwd',
        '\\windows\\x.exe', 'C:\\x.dll', 'c:x', '1:x', '../x', 'a/../b', 'a/..', '..', 'a/./b', './a', 'setup.exe', 'SETUP.EXE', 'run.bat', 'x.cmd', 'x.com',
        'x.cpl', 'x.jar', 'x.js', 'x.msi', 'x.ps1', 'x.scr', 'x.sh', 'x.vbs', 'x.sh.txt', 'inner.rar', 'inner.zip', 'inner.7z', 'inner.r01', 'inner.r1',
        'Info.NFO', "a\x01b.nfo", "a\0b.mkv", '"Show.part01.rar" yEnc (1/50)', '"" yEnc', '"show.nfo" (1/1)', 'yEnc "a.rar" "b.rar"',
        'alt.binaries.sounds.flac', 'alt.binaries.music', 'alt.binaries.LOSSLESS', 'alt.binaries.mp3', 'alt.binaries.teevee', 'alt.binaries.e-book',
        'show.part0.rar', 'show.part00.rar', 'show.7z.000', 'show.7z.1', 'x.R01', 'x.ZIP', 'Show.Sample.JPG', 'a/b/', 'a//b', '/', 'z:', 'Z:\\', '-5', '-60'];

    /**
     * EDGE plus long runs and boundary lengths.
     *
     * @return list<string>
     */
    private static function edgeCases(): array
    {
        return [...self::EDGE, str_repeat('a', 15), str_repeat('9', 15), str_repeat('9', 16), str_repeat('a', 255), str_repeat('a', 256),
            str_repeat('a', 1023), str_repeat('a', 1024), str_repeat('x', 5000), str_repeat('.', 300), str_repeat('|', 300), str_repeat('/', 100),
            str_repeat('a/', 70).'x.mkv', str_repeat('../', 40), str_repeat('"', 70), str_repeat('\\', 70), str_repeat("\xc3\xa9", 200),
            str_repeat("\x01", 80), str_repeat('show.r00 ', 30), 'error: '.str_repeat('z', 2000), str_repeat('f', 40), str_repeat('0', 40)];
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $class::resetW64RustFfi();
        }
        parent::tearDown();
    }

    public function test_w64_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$entry, , $cdef, $trailer]) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW64RustFfi', 'enableW64RustFfi', 'disableW64RustFfi', 'resetW64RustFfi', 'withW64RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()
                    || $reflection->getMethod($method)->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$method;
                }
            }
            $default = $reflection->getReflectionConstant('W64_RUST_FFI_DEFAULT');
            if ($default === false || ! $default->isPublic() || $default->getDeclaringClass()->getName() !== $class || ! \is_bool($default->getValue())) {
                $missing[] = $class.'::W64_RUST_FFI_DEFAULT';
            }
            $declaration = $reflection->getReflectionConstant($cdef);
            if ($declaration === false || ! $declaration->isPublic() || $declaration->getDeclaringClass()->getName() !== $class) {
                $missing[] = $class.'::'.$cdef;
            }
            foreach (['w64RustFfiState', 'w64Invoke'] as $private) {
                $method = new ReflectionMethod($class, $private);
                if (! $method->isPrivate() || ! $method->isStatic() || $method->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$private;
                }
            }
            $state = new ReflectionMethod($class, 'w64RustFfiState');
            $params = array_map(static fn ($p): string => $p->getName().':'.$p->getType().'='.var_export($p->getDefaultValue(), true), $state->getParameters());
            if ($params !== ['op:?string=NULL', 'val:mixed=NULL'] || (string) $state->getReturnType() !== 'mixed') {
                $missing[] = $class.'::w64RustFfiState signature';
            }
            $uses = new ReflectionMethod($class, 'usesW64RustFfi');
            $with = new ReflectionMethod($class, 'withW64RustFfi');
            $withParams = array_map(static fn ($p): string => $p->getName().':'.$p->getType(), $with->getParameters());
            if (! $uses->getReturnType() instanceof ReflectionNamedType || $uses->getReturnType()->getName() !== 'bool' || $uses->getNumberOfParameters() !== 0
                || $withParams !== ['enabled:bool', 'callback:callable'] || (string) $with->getReturnType() !== 'mixed') {
                $missing[] = $class.' usesW64RustFfi/withW64RustFfi signatures';
            }
            foreach (['enableW64RustFfi', 'disableW64RustFfi', 'resetW64RustFfi'] as $toggle) {
                $method = new ReflectionMethod($class, $toggle);
                if ($method->getNumberOfParameters() !== 0 || (string) $method->getReturnType() !== 'void') {
                    $missing[] = $class.'::'.$toggle.' signature';
                }
            }
            // Wave 64 state lives in function-local statics only, never in properties.
            foreach ($reflection->getProperties() as $property) {
                if (str_contains(strtolower($property->getName()), 'w64')) {
                    $missing[] = $class.'::$'.$property->getName();
                }
            }
            $this->assertStringContainsString(self::TARGETS[$class][1], \constant($class.'::'.$cdef));
            $this->assertSame($trailer, \constant($class.'::'.str_replace('_FFI_CDEF', '_TRAILER', $cdef)), $class);
            $this->assertSame(array_fill(0, $trailer, 0), $class::{$entry.'Php'}([]), $class);
        }
        $this->assertTrue((new ReflectionClass(AdditionalWorkPlanner::class))->isReadOnly(), 'the planner stays a readonly class');
        $cdefs = array_column(array_values(self::TARGETS), 2);
        $this->assertSame($cdefs, array_values(array_unique($cdefs)));
        $this->assertSame([], $missing);
    }

    public function test_w64_defaults_and_toggles(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], (bool) (new ReflectionClass($class))->getConstant('W64_RUST_FFI_DEFAULT'), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW64RustFfi(), $class);
            $class::enableW64RustFfi();
            $this->assertTrue($class::usesW64RustFfi(), $class);
            $this->assertFalse($class::withW64RustFfi(false, static fn (): bool => $class::usesW64RustFfi()));
            $this->assertTrue($class::usesW64RustFfi(), $class.' restores the enabled override');
            $class::disableW64RustFfi();
            $this->assertFalse($class::usesW64RustFfi(), $class);
            $this->assertTrue($class::withW64RustFfi(true, static fn (): bool => $class::usesW64RustFfi()));
            $this->assertFalse($class::usesW64RustFfi(), $class.' restores the disabled override');
            $class::resetW64RustFfi();
            $this->assertSame(self::DEFAULTS[$class], $class::usesW64RustFfi(), $class);
            $this->assertTrue($class::withW64RustFfi(false, static fn (): bool => $class::withW64RustFfi(true, static fn (): bool => $class::usesW64RustFfi())));
            $this->assertFalse($class::withW64RustFfi(true, static fn (): bool => $class::withW64RustFfi(false, static fn (): bool => $class::usesW64RustFfi())));
            try {
                $class::withW64RustFfi(! $class::usesW64RustFfi(), $this->throwing('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW64RustFfi(), $class.' restores after an exception');
            // Flipping one class's toggle leaves the others at their defaults.
            foreach ([true, false] as $enabled) {
                $class::withW64RustFfi($enabled, function () use ($class, $enabled): void {
                    $this->assertSame($enabled, $class::usesW64RustFfi(), $class);
                    foreach (array_keys(self::TARGETS) as $other) {
                        if ($other !== $class) {
                            $this->assertSame(self::DEFAULTS[$other], $other::usesW64RustFfi(), $class.' vs '.$other);
                        }
                    }
                });
            }
        }
        // Both dispatch paths return the PHP reference words.
        foreach (self::TARGETS as $class => [$entry]) {
            $items = [...array_map(static fn (array $fields): string => implode('|', $fields), array_values(self::TEMPLATES)), "caf\xc3\xa9", ''];
            $php = $class::{$entry.'Php'}($items);
            $this->assertSame($php, $class::withW64RustFfi(true, static fn (): array => $class::$entry($items)), $class);
            $this->assertSame($php, $class::withW64RustFfi(false, static fn (): array => $class::$entry($items)), $class);
        }
    }

    public function test_w64_existing_public_api_is_unchanged(): void
    {
        $earlier = static fn (): array => [
            AdditionalCandidateQuery::usesW416RustFfi(), AdditionalCandidateQuery::usesW51RustFfi(),
            AdditionalProcessingDiagnostics::usesW416RustFfi(), AdditionalProcessingDiagnostics::usesW51RustFfi(),
            AdditionalProcessingOrchestrator::usesRustFfi(), AdditionalProcessingOrchestrator::usesW416RustFfi(), AdditionalProcessingOrchestrator::usesW51RustFfi(),
            AdditionalWorkPlanner::usesW406RustFfi(), AdditionalWorkPlanner::usesW52RustFfi(),
            ArchiveExtractionService::usesW406RustFfi(), ArchiveExtractionService::usesW52RustFfi(),
        ];
        $before = $earlier();
        $this->assertSame([AdditionalCandidateQuery::W416_RUST_FFI_DEFAULT, AdditionalProcessingDiagnostics::W416_RUST_FFI_DEFAULT,
            AdditionalProcessingOrchestrator::W416_RUST_FFI_DEFAULT, AdditionalWorkPlanner::W406_RUST_FFI_DEFAULT, ArchiveExtractionService::W406_RUST_FFI_DEFAULT],
            [$before[0], $before[2], $before[5], $before[7], $before[9]]);
        foreach (array_keys(self::TARGETS) as $class) {
            foreach ([true, false] as $enabled) {
                $class::withW64RustFfi($enabled, function () use ($earlier, $before, $class): void {
                    $this->assertSame($before, $earlier(), $class.' leaves earlier-wave toggles alone');
                });
            }
            $class::resetW64RustFfi();
            $this->assertSame($before, $earlier(), $class.' reset leaves earlier-wave toggles alone');
        }
        $this->assertSame(1048576, AdditionalCandidateQuery::DEFAULT_MIN_SIZE_BYTES);
        $this->assertSame(107374182400, AdditionalCandidateQuery::DEFAULT_MAX_SIZE_BYTES);
        $this->assertSame(25, AdditionalProcessingOrchestrator::effectiveQueryLimitPhp(0));
        $this->assertSame(40, AdditionalProcessingOrchestrator::effectiveQueryLimitPhp(40));
        $this->assertSame(AdditionalProcessingOrchestrator::BATCH_PROCESS, AdditionalProcessingOrchestrator::batchDispositionPhp(true, 3));
        $this->assertSame(AdditionalProcessingOrchestrator::BATCH_EMPTY, AdditionalProcessingOrchestrator::batchDispositionPhp(true, 0));
        $this->assertSame(AdditionalProcessingOrchestrator::BATCH_SETUP_FAILED, AdditionalProcessingOrchestrator::batchDispositionPhp(false, 3));
        $this->assertTrue(AdditionalProcessingOrchestrator::shouldClearClaimPhp('token', 1));
        $this->assertFalse(AdditionalProcessingOrchestrator::shouldClearClaimPhp('', 1));
        $this->assertTrue(ArchiveExtractionService::hasArchiveSignature("Rar!\x1A\x07\x00rest"));
        $this->assertFalse(ArchiveExtractionService::hasArchiveSignature('plain'));
        $this->assertNotSame([], AdditionalWorkPlanner::archiveVolumeBatchPhp(['show.part01.rar']));
        $this->assertNotSame([], ArchiveExtractionService::extractedEntryBatchPhp(['a/b.nfo']));
        $this->assertNotSame([], AdditionalProcessingDiagnostics::claimIndexMatchesPhp(['passwordstatus']));
    }

    public function test_w64_exports_resolve_and_guard_their_buffers(): void
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

    public function test_w64_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, , , $trailer]) {
            $items = [...array_map(static fn (array $fields): string => implode('|', $fields), array_values(self::TEMPLATES)),
                'archive|failed|50|unrar exited 3|0|0', 'show.vol00+01.par2|1|2|1|g', "caf\xc3\xa9", ''];
            $invoke = new ReflectionMethod($class, 'w64Invoke');
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
            $class::resetW64RustFfi();
            $this->assertSame($class::{$entry.'Php'}($items), $invoke->invoke(null, $items, $trailer + \count($items)), $class.' after reset');
        }
    }

    public function test_known_case_fields(): void
    {
        $bits = static fn (int $word, int $shift, int $width): int => $word >> $shift & ((1 << $width) - 1);

        $query = AdditionalCandidateQuery::w64CandidateQueryPlanBatchPhp(['5000000|-1|-1|1|0|||a1b2c3d4e5f60718293a4b5c6d7e8f9012345678|0',
            '5000000|1|-1|1|0|||b|0', '5000000|-1|0|1|0|||c|0', '5000000|0|-1|1|0|-5|900|d|1', '100|-1|-1|1|0|||e|0', '5000000|-1|-1|1|0||10|f|0',
            '5000000|-1|-1|1|0|||g|3', 'x|-1|-1|1|0|||g|0', '', '5000000|-1|-1|1|0|||A1B2C3D4E5F60718293A4B5C6D7E8F9012345678|0',
            '5000000|-1|-1|1|1|||0|0', '5000000|-1|-1|1|0|60|||0', '200000000000|-1|-1|1|0||||0', '5000000|-1|-1|2|0||||0', '5000000000|-1|-1|1|0||||0']);
        $this->assertSame([0, 10, 1, 3, 1], [$bits($query[0], 0, 4), $bits($query[0], 4, 5), $bits($query[0], 9, 8), $bits($query[0], 17, 2), $bits($query[0], 45, 10)]);
        $this->assertSame((int) hexdec(hash('fnv1a32', 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678')) & 0xFFFF, $bits($query[0], 29, 16));
        $this->assertSame([1, 2, 0, 2 | 4 | 8, 2, 1], [$bits($query[1], 0, 4), $bits($query[2], 0, 4), $bits($query[3], 0, 4), $bits($query[3], 9, 8),
            $bits($query[3], 45, 10), $bits($query[3], 19, 4)]);
        $this->assertSame([6, 8, 9, 10, 11, 16], [$bits($query[4], 0, 4), $bits($query[5], 0, 4), $bits($query[6], 0, 4), $bits($query[7], 0, 4),
            $bits($query[8], 0, 4), $bits($query[8], 4, 5)]);
        $this->assertSame(1 | 32 | 64, $bits($query[9], 9, 8));
        $this->assertSame([4, 5, 7, 3, 0, 1], [$bits($query[10], 0, 4), $bits($query[11], 0, 4), $bits($query[12], 0, 4), $bits($query[13], 0, 4),
            $bits($query[14], 0, 4), $bits($query[14], 17, 2)]);
        $this->assertSame([4, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1], \array_slice($query, 15, 12), 'reason counts');
        $this->assertSame([5000000 * 3 + 5000000000, 5000000000, 8, 4], [$query[15 + 40], $query[15 + 41], $query[15 + 42], $query[15 + 43]]);

        $diag = AdditionalProcessingDiagnostics::w64DiagnosticsPlanBatchPhp(['download|processed|1200||4096|3', 'archive|failed|50|unrar exited 3|0|0',
            'persist|failed|10|SQLSTATE[40001]: deadlock|0|0', 'media|processed|45000||0|0', 'bogus|skipped|x|note|1|0', '', 'NFO|Timeout|0|read timed out|0|0',
            'search|processed|0||0|0']);
        $this->assertSame([2, 1, 0, 0, 0, 3, 11], [$bits($diag[0], 0, 4), $bits($diag[0], 4, 4), $bits($diag[0], 8, 2), $bits($diag[0], 10, 3), $bits($diag[0], 13, 8),
            $bits($diag[0], 33, 10), $bits($diag[0], 21, 6)]);
        $this->assertSame((int) hexdec(hash('fnv1a32', 'download:processed')) & 0x3FF, $bits($diag[0], 53, 10));
        $this->assertSame([2, 3, 4, 3, 5, 14], [$bits($diag[1], 8, 2), $bits($diag[1], 10, 3), $bits($diag[1], 13, 8), $bits($diag[2], 8, 2), $bits($diag[2], 10, 3),
            $bits($diag[1], 43, 10)]);
        $this->assertSame([1, 2, 15, 1 | 4 | 8 | 128, 6], [$bits($diag[3], 8, 2), $bits($diag[3], 13, 8), $bits($diag[4], 0, 4), $bits($diag[4], 13, 8),
            $bits($diag[4], 10, 3)]);
        $this->assertSame([5, 4, 2, 1, 64], [$bits($diag[6], 0, 4), $bits($diag[6], 4, 4), $bits($diag[6], 8, 2), $bits($diag[6], 10, 3), $bits($diag[7], 13, 8)]);
        $this->assertSame([46260, 45000, 4097, 3, 4, 6], [$diag[8 + 51], $diag[8 + 52], $diag[8 + 53], $diag[8 + 54], $diag[8 + 55], $diag[8 + 56]]);

        $orch = AdditionalProcessingOrchestrator::w64OrchestratorPlanBatchPhp(['0|4|1|100|abcdefABCDEF012345|||42|10', '40|4|0|100|||||', '40|4|1|100||60|||',
            '40|4|1|100||2000|3000||', '40|4|1|0||||', '40|4|1|10||2000|||', 'x|4|1|10', '', '600|64|yes|1000|t|||-3|70000']);
        $this->assertSame([6, 2, 0, 1 | 16 | 32, 25, 4, 25, 4, 7], [$bits($orch[0], 0, 3), $bits($orch[0], 3, 2), $bits($orch[0], 5, 2), $bits($orch[0], 7, 8),
            $bits($orch[0], 15, 12), $bits($orch[0], 27, 8), $bits($orch[0], 35, 12), $bits($orch[0], 47, 8), $bits($orch[0], 55, 8)]);
        $this->assertSame([2, 3, 1, 4, 5, 7, 2, 10, 1], [$bits($orch[1], 0, 3), $bits($orch[2], 0, 3), $bits($orch[2], 5, 2), $bits($orch[3], 0, 3), $bits($orch[4], 0, 3),
            $bits($orch[5], 0, 3), $bits($orch[5], 5, 2), $bits($orch[5], 35, 12), $bits($orch[6], 0, 3)]);
        $this->assertSame((1 << 7) | (25 << 15) | (1 << 27), $orch[7]);
        $this->assertSame([6, 2 | 4 | 8 | 64, 600, 64, 600, 64, 10], [$bits($orch[8], 0, 3), $bits($orch[8], 7, 8), $bits($orch[8], 15, 12), $bits($orch[8], 27, 8),
            $bits($orch[8], 35, 12), $bits($orch[8], 47, 8), $bits($orch[8], 55, 8)]);
        $this->assertSame([635, 72, 600 * 64, 70010, 2000, 2], [$orch[9 + 22], $orch[9 + 23], $orch[9 + 24], $orch[9 + 25], $orch[9 + 26], $orch[9 + 27]]);

        $plan = AdditionalWorkPlanner::w64WorkPlannerPlanBatchPhp(['"Show.S01E01.part01.rar" yEnc (1/50)|50000000|50|1|alt.binaries.teevee',
            '"Show.S01E01.part02.rar" yEnc (1/50)|50000000|50|1|alt.binaries.teevee', 'show.r00|1|3|1|g', 'Show.vol00+01.par2|1|2|1|g', 'show.nfo|1|1|1|g',
            'Show.Sample.mkv|1|4|1|g', 'cover.jpg|20000|1|1|alt.binaries.sounds.flac', 'cover.jpg|20000|1|1|g', 'movie.mkv.001|1|1|1|g',
            'archive.7z.002|1|1|1|g', 'unnamed|5|2|1|g', '', 'show.par2|1|1|1|g', 'album.flac|1|1|1|g', 'book.epub|x|0|1|g']);
        $this->assertSame([2, 1 | 2, 3, 2, 1, 2, 120, 50], [$bits($plan[0], 0, 4), $bits($plan[0], 4, 10), $bits($plan[0], 14, 2), $bits($plan[0], 16, 8),
            $bits($plan[0], 24, 10), $bits($plan[0], 34, 2), $bits($plan[0], 54, 8) + 98, $bits($plan[0], 42, 12)]);
        $this->assertSame([64, 1, 2, 1, 1, 6, 16], [$bits($plan[1], 16, 8) & 64, $bits($plan[1], 4, 10), $bits($plan[2], 0, 4), $bits($plan[2], 4, 10),
            $bits($plan[2], 24, 10), $bits($plan[3], 0, 4), $bits($plan[3], 16, 8) & 16]);
        $this->assertSame([8, 16 | 32, 0, 64, 9, 8, 3, 1, 2], [$bits($plan[4], 4, 10), $bits($plan[5], 4, 10), $bits($plan[6], 4, 10), $bits($plan[7], 4, 10),
            $bits($plan[8], 0, 4), $bits($plan[8], 16, 8) & 8, $bits($plan[9], 0, 4), $bits($plan[9], 4, 10), $bits($plan[9], 24, 10)]);
        $this->assertSame([14, 256, 0, 4, 128, 512, 128], [$bits($plan[10], 0, 4), $bits($plan[10], 4, 10), $plan[11], $bits($plan[12], 4, 10), $bits($plan[13], 4, 10),
            $bits($plan[14], 4, 10), $bits($plan[14], 16, 8) & 128]);
        $this->assertSame([3, 0, 0, 13, 1], [$plan[15 + 39], $plan[15 + 40], $plan[15 + 41], $plan[15 + 37], $plan[15 + 12]]);
        $none = AdditionalWorkPlanner::w64WorkPlannerPlanBatchPhp(['notes.txt|1|1|1|g', ...array_fill(0, 81, 'book.epub|1|1|1|g')]);
        $this->assertSame([1, 1, 81, 0], [$none[82 + 41], $none[82 + 40], $none[82 + 12], $none[82 + 37]], 'book flood without supported candidates');

        $archive = ArchiveExtractionService::w64ArchiveExtractionPlanBatchPhp(['rar|0|Movie/movie.mkv|700|700|1', 'rar|1|movie.mkv|700|600|1',
            '7z|2|list.txt|1|1|0', '7z|2|list.txt|1|1|4', 'zip|0|../etc/passwd|1|1|2', 'zip|0|dir/|0|0|2', 'rar|0|setup.EXE|1|1|8', 'rar|0|inner.r01|1|1|0',
            'rar|0|Info.NFO|1|1|0', 'tar|0|x|1|1|0', 'rar|5|x|1|1|0', 'rar|0|C:\\Windows\\x.dll|1|1|0', '', 'zip|0|bomb.bin|9999999|10|2',
            'rar|0|big.mkv|1099511627777|1|0', 'rar|0|.hidden|1|2|0']);
        $this->assertSame([8, 1, 0, 1, 0, 256, 2, 15], [$bits($archive[0], 0, 4), $bits($archive[0], 4, 3), $bits($archive[0], 7, 2), $bits($archive[0], 9, 3),
            $bits($archive[0], 12, 2), $bits($archive[0], 14, 10), $bits($archive[0], 24, 6), $bits($archive[0], 42, 10)]);
        $this->assertSame([2, 1, 9, 2, 5, 8, 3, 3], [$bits($archive[1], 0, 4), $bits($archive[1], 12, 2), $bits($archive[2], 0, 4), $bits($archive[2], 12, 2),
            $bits($archive[2], 9, 3), $bits($archive[3], 0, 4), $bits($archive[3], 9, 3), $bits($archive[3], 12, 2)]);
        $this->assertSame([3, 2 | 256, 4, 5, 4, 6, 7, 11, 1], [$bits($archive[4], 0, 4), $bits($archive[4], 14, 10), $bits($archive[5], 0, 4), $bits($archive[6], 0, 4),
            $bits($archive[6], 9, 3), $bits($archive[7], 0, 4), $bits($archive[8], 0, 4), $bits($archive[9], 0, 4), $bits($archive[10], 0, 4)]);
        $this->assertSame([3, 1 | 128 | 256, 3, 2, 32, 10, 8, 512], [$bits($archive[11], 0, 4), $bits($archive[11], 14, 10), $bits($archive[11], 24, 6),
            $bits($archive[13], 9, 3), $bits($archive[13], 14, 10), $bits($archive[14], 0, 4), $bits($archive[15], 0, 4), $bits($archive[15], 14, 10)]);
        $this->assertSame((int) hexdec(hash('fnv1a32', '')) & 0x3FF, $bits($archive[12], 52, 10));
        $this->assertSame([700 + 1 + 1 + 1 + 9999999 + 1, 3, 13], [$archive[16 + 40], $archive[16 + 42], $archive[16 + 43]]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_edge_case_parity(string $class): void
    {
        $edges = self::edgeCases();
        $this->assertGreaterThanOrEqual(300, \count($edges));
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
        foreach ([' ', '.', '-', '_', ':', '|', '/', '\\', '"', ',', "\t", ''] as $glue) {
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
        foreach ([641, 642, 643] as $seed) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity($class, $this->benchItems($class, new Randomizer(new Mt19937($seed)), $uncommon), 'seed '.$seed);
            }
        }
        $random = new Randomizer(new Mt19937(6411));
        $soup = [];
        for ($i = 0; $i < 400; $i++) {
            $length = $random->getInt(0, 48);
            $soup[] = $length === 0 ? '' : $random->getBytesFromString("aAbBcCdDeEfFgGrRzZpP0123456789|||||..//\\\\\"-_ :+\0\t\r\n\x01\x7f\xff\xc3\xa9", $length);
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
            for ($j = 0, $n = $random->getInt(1, 9); $j < $n; $j++) {
                $parts[] = $this->pick($random, $tokens);
            }
            $tokenSoup[] = implode($this->pick($random, ['|', '|', '|', ' ', '.', '/', '\\', '"', '']), $parts);
        }
        $this->assertParity($class, $tokenSoup, 'token soup');
        // Every class's generator through every helper.
        $mixed = [];
        foreach (array_keys(self::TARGETS) as $other) {
            array_push($mixed, ...\array_slice($this->benchItems($other, new Randomizer(new Mt19937(6412)), true), 0, 80));
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
        // call, decoding and any PHP fallback (release dylib, 5 warmups, NNTMUX_W641_BENCH_ROUNDS interleaved paired
        // rounds per helper and workload (default 301, minimum 60), 5 iterations per round). Common = one batch of 320
        // well-formed items of the class's format; uncommon = 320 items mixing edge cases, malformed, long, NUL,
        // case-changed and non-ASCII items.
        // Speedup = PHP latency / Rust latency, minimum over 3 runs at 301 rounds (PHP 8.5.12 NTS, rustc 1.99.1 release
        // dylib, Apple Silicon, shared host at load average ~6.5-8.5), common mean / p99, uncommon mean / p99:
        // - AdditionalCandidateQuery:        5.35x / 4.76x, 4.69x / 2.80x
        // - AdditionalProcessingDiagnostics: 5.38x / 3.79x, 5.07x / 3.90x
        // - AdditionalProcessingOrchestrator: 7.66x / 5.79x, 7.39x / 5.67x
        // - AdditionalWorkPlanner:           6.38x / 4.96x, 4.54x / 3.73x
        // - ArchiveExtractionService:        6.50x / 6.39x, 6.46x / 5.39x
        // Every helper clears every gate in all three runs and defaults to Rust.
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per
        // .ai/rules/rust-performance.md; the assertion below requires every default to match its gates.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // RUN_RUST_FFI_BENCHMARKS=1 php artisan test --compact tests/Unit/RustAdditionalQueryDiagnosticsOrchestratorPlannerExtractionW64ParityTest.php --filter benchmark
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            $this->markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the Wave 64.1 benchmark.');
        }
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(6416));
        $warmup = 5;
        $rounds = max(60, (int) (getenv('NNTMUX_W641_BENCH_ROUNDS') ?: 301));
        $iterations = 5;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => $this->benchItems($class, new Randomizer(new Mt19937(6417)), false),
                'uncommon' => $this->benchItems($class, new Randomizer(new Mt19937(6418)), true),
            ];
            foreach ($workloads as $name => $items) {
                $this->assertGreaterThanOrEqual(300, \count($items));
                $this->assertSame($class::{$entry.'Php'}($items), $class::{$entry.'Native'}($items), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $sink += \count($class::withW64RustFfi($useRust, static fn (): array => $class::$entry($items)));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $rounds; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $useRust ? $class::enableW64RustFfi() : $class::disableW64RustFfi();
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
        fwrite(STDERR, "\nRustAdditionalQueryDiagnosticsOrchestratorPlannerExtractionW64 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW64RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
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
        $this->assertSame($php, $class::withW64RustFfi(true, static fn (): array => $class::$entry($items)), $class.' '.$message);
        $this->assertSame($php, $class::withW64RustFfi(false, static fn (): array => $class::$entry($items)), $class.' '.$message);
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
                AdditionalCandidateQuery::class => $this->candidateFields($random),
                AdditionalProcessingDiagnostics::class => $this->diagnosticFields($random),
                AdditionalProcessingOrchestrator::class => $this->orchestratorFields($random),
                AdditionalWorkPlanner::class => $this->plannerFields($random),
                default => $this->archiveFields($random),
            };
            if ($uncommon && $random->getInt(0, 2) === 0) {
                $fields[$random->getInt(0, \count($fields) - 1)] = $this->pick($random, ['', ' ', 'x', '-1', '1e3', "caf\xc3\xa9", "a\x01b", str_repeat('n', 300),
                    '9999999999999999', '..', '/abs', 'C:\\x', 'TRUE', 'unknown', 'Show.R00', '"q.rar"']);
            }
            $row = implode('|', $uncommon && $random->getInt(0, 4) === 0 ? \array_slice($fields, 0, $random->getInt(1, \count($fields))) : $fields);
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $row = $this->pick($random, [strtoupper($row), strtolower($row), ' '.$row, $row."\t", $row."\0x", "caf\xc3\xa9".$row, $row.'|x', $row.'||',
                    str_repeat($row, 3), str_replace('.', ' ', $row), str_replace('/', '\\', $row), str_replace('|', '| ', $row)]);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function guid(Randomizer $random): string
    {
        return bin2hex($random->getBytes(20));
    }

    private function title(Randomizer $random): string
    {
        return $this->pick($random, ['Some.Show.S01E02', 'The.Movie', 'Artist-Album', 'Great.Documentary', 'Linux.Distro', 'Big.Game', 'Old.Film'])
            .'.'.$random->getInt(1990, 2026).'.'.$this->pick($random, ['1080p', '720p', '2160p', 'WEB-DL', 'BluRay', 'FLAC']).'-'.$this->pick($random, ['GRP', 'NTb', 'FLUX']);
    }

    /** @return list<string> */
    private function candidateFields(Randomizer $random): array
    {
        return [(string) $random->getInt(0, 8_000_000_000), $this->pick($random, ['-1', '-1', '-1', '0', '1', '-2']), $this->pick($random, ['-1', '-1', '-1', '0', '1']),
            $this->pick($random, ['1', '1', '1', '0']), $this->pick($random, ['0', '0', '0', '1', '']), $this->pick($random, ['', '', '', (string) $random->getInt(-90000, 90000)]),
            $this->pick($random, ['', '', (string) $random->getInt(0, 1200)]), $this->guid($random), $this->pick($random, ['0', '0', '1', '2', '3', '5', ''])];
    }

    /** @return list<string> */
    private function diagnosticFields(Randomizer $random): array
    {
        return [$this->pick($random, ['setup', 'download', 'archive', 'password', 'nfo', 'media', 'sample', 'jpg', 'audio', 'par2', 'persist', 'search']),
            $this->pick($random, ['processed', 'processed', 'processed', 'skipped', 'failed', 'timeout', 'unsupported', 'awaiting', 'notfound', 'passworded']),
            (string) $random->getInt(0, 45000), $this->pick($random, ['', '', '', '', 'NNTP 430 no such article', 'unrar: CRC failed in '.$this->title($random),
                'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found', 'Connection timed out after 30s', 'disk quota exceeded', 'unexpected value']),
            (string) $random->getInt(0, 50_000_000), (string) $random->getInt(0, 64)];
    }

    /** @return list<string> */
    private function orchestratorFields(Randomizer $random): array
    {
        return [$this->pick($random, ['', '0', '25', '50', '100', '600', (string) $random->getInt(-5, 900)]), $this->pick($random, ['', '1', '4', '8', '16', '48']),
            $this->pick($random, ['1', '1', '1', 'true', '0', 'no']), (string) $random->getInt(0, 400), $this->pick($random, ['', bin2hex($random->getBytes(16)), 'token']),
            $this->pick($random, ['', '', (string) $random->getInt(0, 4000)]), $this->pick($random, ['', '0', '600', '3600']),
            (string) $random->getInt(-2, 9_000_000), (string) $random->getInt(0, 120000)];
    }

    /** @return list<string> */
    private function plannerFields(Randomizer $random): array
    {
        $title = $this->title($random);
        $file = $this->pick($random, [$title.'.part'.sprintf('%02d', $random->getInt(1, 40)).'.rar', $title.'.rar', $title.'.r'.sprintf('%02d', $random->getInt(0, 99)),
            $title.'.7z.'.sprintf('%03d', $random->getInt(1, 30)), $title.'.zip', $title.'.'.sprintf('%03d', $random->getInt(0, 40)), $title.'.par2',
            $title.'.vol'.sprintf('%02d', $random->getInt(0, 60)).'+'.$random->getInt(1, 64).'.par2', $title.'.nfo', $title.'.sfv', $title.'.mkv', $title.'-sample.mkv',
            $title.'.mkv.'.sprintf('%03d', $random->getInt(1, 9)), $title.'.flac', $title.'.mp3', $title.'.jpg', 'Sample.'.$title.'.jpg', $title.'.epub',
            $title.'.txt', bin2hex($random->getBytes(16))]);
        $subject = $random->getInt(0, 2) === 0 ? $file : '['.$random->getInt(1, 90).'/90] - "'.$file.'" yEnc ('.$random->getInt(1, 140).'/140)';

        return [$subject, (string) $random->getInt(0, 900_000_000), (string) $random->getInt(0, 140), $this->pick($random, ['1', '1', '1', '0', '3']),
            $this->pick($random, ['alt.binaries.teevee', 'alt.binaries.moovee', 'alt.binaries.sounds.flac', 'alt.binaries.mp3', 'alt.binaries.e-book'])];
    }

    /** @return list<string> */
    private function archiveFields(Randomizer $random): array
    {
        $title = $this->title($random);
        $path = $this->pick($random, [$title.'/'.strtolower($title).'.mkv', strtolower($title).'.mkv', $title.'/Sample/'.strtolower($title).'-sample.mkv',
            $title.'.nfo', 'Subs/English.srt', 'Subs\\Dutch.idx', $title.'.r'.sprintf('%02d', $random->getInt(0, 30)), 'inner.rar', 'setup.exe', 'Proof/proof.jpg',
            $title.'/', '../escape.txt', '/abs/path.bin', '.DS_Store', 'readme.txt']);

        return [$this->pick($random, ['rar', 'rar', 'rar', 'zip', '7z']), $this->pick($random, ['0', '0', '0', '0', '1', '2']), $path,
            (string) $random->getInt(0, 4_000_000_000), (string) $random->getInt(0, 4_000_000_000), (string) $random->getInt(0, 15)];
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
     * Calls a Wave 64.1 export by name.
     */
    private function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionMethod($class, 'w64RustFfiState'))->invoke(null, 'set', $enabled);
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
