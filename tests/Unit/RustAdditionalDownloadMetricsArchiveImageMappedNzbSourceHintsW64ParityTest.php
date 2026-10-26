<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\DTO\DownloadedArchive;
use App\Services\AdditionalProcessing\DTO\DownloadMetrics;
use App\Services\AdditionalProcessing\DTO\ImageCandidate;
use App\Services\AdditionalProcessing\DTO\MappedNzbFile;
use App\Services\AdditionalProcessing\DTO\MediaSourceHints;
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

final class RustAdditionalDownloadMetricsArchiveImageMappedNzbSourceHintsW64ParityTest extends TestCase
{
    /** Per class: the batch entry, its Rust export, its FFI declaration constant, its trailer constant and the trailer word count. */
    private const TARGETS = [
        DownloadMetrics::class => ['w64DownloadMetricsPlanBatch', 'nntmux_w643_download_metrics_batch', 'W64_DOWNLOAD_METRICS_FFI_CDEF', 'W64_DOWNLOAD_METRICS_TRAILER', 18],
        DownloadedArchive::class => ['w64DownloadedArchivePlanBatch', 'nntmux_w643_downloaded_archive_batch', 'W64_DOWNLOADED_ARCHIVE_FFI_CDEF', 'W64_DOWNLOADED_ARCHIVE_TRAILER', 18],
        ImageCandidate::class => ['w64ImageCandidatePlanBatch', 'nntmux_w643_image_candidate_batch', 'W64_IMAGE_CANDIDATE_FFI_CDEF', 'W64_IMAGE_CANDIDATE_TRAILER', 16],
        MappedNzbFile::class => ['w64MappedNzbFilePlanBatch', 'nntmux_w643_mapped_nzb_file_batch', 'W64_MAPPED_NZB_FILE_FFI_CDEF', 'W64_MAPPED_NZB_FILE_TRAILER', 18],
        MediaSourceHints::class => ['w64MediaSourceHintsPlanBatch', 'nntmux_w643_media_source_hints_batch', 'W64_MEDIA_SOURCE_HINTS_FFI_CDEF', 'W64_MEDIA_SOURCE_HINTS_TRAILER', 16],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        DownloadMetrics::class => true,
        DownloadedArchive::class => true,
        ImageCandidate::class => true,
        MappedNzbFile::class => true,
        MediaSourceHints::class => true,
    ];

    /** One well-formed NUL-free item per class, in the order of TARGETS. */
    private const SAMPLES = [
        '1048576|10|10|1000000|0|ok|Eweka',
        "Movie.2019.tar.gz|2048|0|\x1f\x8b\x08",
        "Cover.jpg|600|900|204800|\xff\xd8\xff\xe0",
        'Movie.mkv|[01/10] "Movie.mkv" yEnc (1/50)|1000|1050|50|50|verified',
        'Movie.2019.1080p.x264.mkv|head|10000000|2097152|0',
    ];

    /** Edge cases shared by every helper: separators, whitespace, NUL and control bytes, non-ASCII, boundary and malformed items. */
    private const EDGE = ['', ' ', '  ', "\t", "\0", "\n", "\r", "\x0B", "\x0C", "\r\n", "\x01", "\x7f", "\xff", "caf\xc3\xa9", '|', '||', '|||',
        '||||', '|||||', '||||||', '|||||||', '||||||||', ' | | | | | | | ', "\t|\t|\t", "\0|\0|\0|\0|\0|\0|\0", 'a|b|c|d|e|f|g|h|i', '"', '""', '" "',
        '.', '..', '-', '_', '0', '00', '01', '1', '9', '10', '99', '100', '999', '1000', '9999', '10239', '10240', '65535', '65536', '999999',
        '1000000', '1048575', '1048576', '999999999999', '1000000000000', '-1', '+1', '+', ' 12 ', '1e3', '0x10', '0.5', '123456789012345678',
        'ok', 'OK', ' Ok ', 'missing', 'crc', 'CRC', 'timeout', 'Timeout', 'fail', 'verified', 'VERIFIED', 'unique-size', 'Unique-Size', 'unique',
        'complete', 'Complete', 'head', 'HEAD', 'mid', 'Mid', 'tail', 'x.rar', 'X.RAR', 'x.part1.rar', 'x.part01.rar', 'x.part0001.rar', 'x.part00001.rar',
        '.part1.rar', 'part1.rar', 'x.part.rar', 'x.r00', 'x.R99', 'x.r0', 'x.r000', 'x.zip', 'x.z01', 'x.Z09', 'x.7z', 'x.7Z', 'x.7z.001', 'x.7z.01',
        'x.001', 'x.000', 'x.01', 'x.0001', 'x.tar', 'x.tar.gz', 'x.tgz', 'x.gz', 'x.bz2', 'x.xz', '.rar', '.7z', 'rar', "Rar!\x1a\x07\x01\x00",
        "Rar!\x1a\x07\x00", "Rar!\x1a\x07", "PK\x03\x04\x14\x00\x00\x00\x08\x00", "PK\x03\x04\x14\x00\x00\x00\x00\x00", "PK\x03\x04\x14\x00\x00\x00\x0e\x00",
        "PK\x03\x04", "7z\xbc\xaf\x27\x1c", "\x1f\x8b", "\x1f", 'BZh9', "\xfd7zXZ\x00", "\xff\xd8\xff\xe0", "\xff\xd8", "\x89PNG\r\n\x1a\n", "\x89PNG",
        'GIF87a', 'GIF89a', 'GIF90a', "RIFF\0\0\0\0WEBPVP8 ", 'RIFFxxxxWEBP', 'RIFFxxxxWAVE', 'RIFF', 'BM', 'B', 'x.jpg', 'X.JPEG', 'x.png', 'x.gif',
        'x.webp', 'x.bmp', 'x.tiff', 'cover.jpg', 'Front Cover.PNG', 'poster', 'folder.jpg', 'back.jpg', 'sample.jpg', 'screens.png', 'thumb',
        'proof-front.jpg', 'covers.jpg', 'x.mkv', 'X.MKV', 'x.mp4', 'x.m4v', 'x.avi', 'x.ts', 'x.m2ts', 'x.wmv', 'x.mov', 'x.mpg', 'x.mpeg', 'x.webm',
        'x-sample.mkv', 'Sample.MKV', 'samples.mkv', 'x.sample', 'x.par2', 'x.vol00+01.par2', 'x.VOL01+02.PAR2', 'x.vol.par2', 'x.nfo', 'x.sfv',
        'x.srr', 'x.txt', 'x264', 'H264', 'avc', 'x265', 'HEVC', 'h.264', 'xvid', 'DivX', 'av1', 'vp9', 'mpeg2', 'mpeg', '[01/10] "x.mkv" yEnc (1/5)',
        '"unterminated', 'a"b"c', '" spaced.mkv "', 'Eweka', 'news.example', ...self::SAMPLES, "Movie.part01.rar|100|0|Rar!\x1a\x07\x01\x00",
        'movie.part02.rar|100|0|', 'movie.r00|100|1|Rar!', 'movie.zip|5|4|PK', '1|2|3|4|5|missing|x', 'Cover.png|10|10|100|GIF89a',
        'x.par2|"x.par2"|10|10|1|1|unique-size', 'x.mkv|mid|100|200|50', 'x.mkv|complete|||'];

    /**
     * EDGE plus long runs and boundary lengths.
     *
     * @return list<string>
     */
    private static function edgeCases(): array
    {
        return [...self::EDGE, str_repeat('a', 255), str_repeat('a', 256), str_repeat('a', 512), str_repeat('a ', 300), str_repeat('.', 600),
            str_repeat('|', 300), str_repeat('9', 40), str_repeat('9', 12), str_repeat('9', 13), str_repeat('9', 6), str_repeat('9', 7),
            str_repeat("\xc3\xa9", 200), str_repeat('x', 300).'.rar', str_repeat('x.', 200).'mkv', str_repeat('sample ', 60).'.mkv',
            str_repeat('cover.', 60).'jpg', str_repeat('"', 300), str_repeat("\xff", 300), "PK\x03\x04".str_repeat("\x08", 300),
            str_repeat('x264 ', 80), 'x.'.str_repeat('1', 300), str_repeat('Rar!', 100)];
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
        foreach (self::TARGETS as $class => [$entry, , $cdef, $trailerConstant, $trailer]) {
            $reflection = new ReflectionClass($class);
            foreach (['enableW64RustFfi', 'disableW64RustFfi', 'resetW64RustFfi', 'usesW64RustFfi', 'withW64RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()
                    || $reflection->getMethod($method)->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$method;
                }
            }
            foreach (['W64_RUST_FFI_DEFAULT', $cdef, $trailerConstant] as $constant) {
                $declared = $reflection->getReflectionConstant($constant);
                if ($declared === false || ! $declared->isPublic() || $declared->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$constant;
                }
            }
            if (! \is_bool($reflection->getConstant('W64_RUST_FFI_DEFAULT')) || $reflection->getConstant($trailerConstant) !== $trailer) {
                $missing[] = $class.' constant values';
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
        }
        $this->assertSame(array_column(array_values(self::TARGETS), 1), array_values(array_unique(array_column(array_values(self::TARGETS), 1))));
        $this->assertSame([], $missing);
    }

    public function test_w64_adds_no_properties(): void
    {
        // Property sets as of Wave 63 (static and instance), so Wave 64 cannot add state outside w64RustFfiState().
        $expected = [
            DownloadMetrics::class => ['bytesDownloaded', 'bytesReused', 'cacheHits', 'ffi', 'logicalRequests', 'mediaBytesDownloaded', 'networkRequests',
                'useRustFfi', 'w47Ffi', 'w47Out', 'w47RustFfi'],
            DownloadedArchive::class => ['data', 'files', 'title', 'useRustFfi'],
            ImageCandidate::class => ['ffi', 'messageIds', 'postedSize', 'sourceIndex', 'title', 'useRustFfi'],
            MappedNzbFile::class => ['confidence', 'ffi', 'file', 'sourceIndex', 'useRustFfi'],
            MediaSourceHints::class => ['availableBytes', 'position', 'realSize', 'useRustFfi'],
        ];
        foreach ($expected as $class => $names) {
            $actual = array_map(static fn ($p): string => $p->getName(), (new ReflectionClass($class))->getProperties());
            sort($actual);
            sort($names);
            $this->assertSame($names, $actual, $class);
        }
        $this->assertTrue((new ReflectionClass(DownloadedArchive::class))->isReadOnly());
        $this->assertTrue((new ReflectionClass(MediaSourceHints::class))->isReadOnly());
    }

    public function test_w64_defaults_and_toggles(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], (new ReflectionClass($class))->getConstant('W64_RUST_FFI_DEFAULT'), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW64RustFfi(), $class);
            $this->assertTrue($class::withW64RustFfi(true, static fn (): bool => $class::usesW64RustFfi()));
            $this->assertFalse($class::withW64RustFfi(false, static fn (): bool => $class::usesW64RustFfi()));
            $this->assertTrue($class::withW64RustFfi(false, static fn (): bool => $class::withW64RustFfi(true, static fn (): bool => $class::usesW64RustFfi())));
            $this->assertFalse($class::withW64RustFfi(true, static fn (): bool => $class::withW64RustFfi(false, static fn (): bool => $class::usesW64RustFfi())));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW64RustFfi(), $class);
            try {
                $class::withW64RustFfi(! $class::usesW64RustFfi(), $this->throwing('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW64RustFfi(), $class.' restores after an exception');
            $class::disableW64RustFfi();
            $this->assertFalse($class::usesW64RustFfi(), $class);
            $this->assertTrue($class::withW64RustFfi(true, static fn (): bool => $class::usesW64RustFfi()));
            $this->assertFalse($class::usesW64RustFfi(), $class.' scoped toggle restores an explicit disable');
            $class::enableW64RustFfi();
            $this->assertTrue($class::usesW64RustFfi(), $class);
            $class::resetW64RustFfi();
            $this->assertSame(self::DEFAULTS[$class], $class::usesW64RustFfi(), $class.' reset restores the default');
        }
        // Toggles are independent between classes.
        foreach (array_keys(self::TARGETS) as $class) {
            $flipped = ! $class::usesW64RustFfi();
            $class::withW64RustFfi($flipped, function () use ($class, $flipped): void {
                foreach (array_keys(self::TARGETS) as $other) {
                    $this->assertSame($other === $class ? $flipped : self::DEFAULTS[$other], $other::usesW64RustFfi(), $class.' vs '.$other);
                }
            });
            $class::enableW64RustFfi();
            foreach (array_keys(self::TARGETS) as $other) {
                $this->assertSame($other === $class ? true : self::DEFAULTS[$other], $other::usesW64RustFfi(), $class.' enable vs '.$other);
            }
            $class::resetW64RustFfi();
        }
        // Both dispatch paths return the PHP reference words, also after a reset drops the cached library.
        foreach (self::TARGETS as $class => [$entry]) {
            $items = [...self::SAMPLES, ''];
            $php = $class::{$entry.'Php'}($items);
            $this->assertSame($php, $class::withW64RustFfi(true, static fn (): array => $class::$entry($items)), $class);
            $this->assertSame($php, $class::withW64RustFfi(false, static fn (): array => $class::$entry($items)), $class);
            $class::resetW64RustFfi();
            $this->assertSame($php, $class::withW64RustFfi(true, static fn (): array => $class::$entry($items)), $class.' after reset');
            $this->assertSame($class::{$entry.'Php'}([]), $class::withW64RustFfi(true, static fn (): array => $class::$entry([])), $class);
        }
    }

    public function test_w64_existing_public_api_is_unchanged(): void
    {
        // Earlier-wave toggles keep their own defaults.
        $this->assertFalse(DownloadMetrics::usesRustFfi());
        $this->assertFalse(DownloadMetrics::usesW47ArticleStatsRustFfi());
        $this->assertTrue(DownloadMetrics::usesW53RustFfi());
        $this->assertTrue(DownloadedArchive::usesW47VolumeVerifyRustFfi());
        $this->assertTrue(DownloadedArchive::usesW54RustFfi());
        $this->assertFalse(ImageCandidate::usesRustFfi());
        $this->assertTrue(ImageCandidate::usesW48ImageScoreRustFfi());
        $this->assertFalse(ImageCandidate::usesW53RustFfi());
        $this->assertFalse(MappedNzbFile::usesRustFfi());
        $this->assertTrue(MappedNzbFile::usesW48FileMapRustFfi());
        $this->assertTrue(MappedNzbFile::usesW53RustFfi());
        $this->assertTrue(MediaSourceHints::usesW48HintExtractRustFfi());
        $this->assertTrue(MediaSourceHints::usesW54RustFfi());
        foreach ([true, false] as $enabled) {
            foreach (array_keys(self::TARGETS) as $class) {
                $class::withW64RustFfi($enabled, function (): void {
                    $summary = DownloadMetrics::summarizeTransferEfficiencyPhp(4, 2, 2, 300, 100, 150);
                    $this->assertSame(0.5, $summary['cacheHitRatio']);
                    $this->assertSame(0.25, $summary['byteReuseRatio']);
                    $this->assertSame(DownloadMetrics::transferEfficiencyBatchPhp([4], [2], [2], [300], [100], [150]),
                        DownloadMetrics::transferEfficiencyBatch([4], [2], [2], [300], [100], [150]));
                    $this->assertSame(DownloadedArchive::verifyVolumeBatchPhp(['x.part01.rar'], ["Rar!\x1a\x07\x01\x00"]),
                        DownloadedArchive::verifyVolumeBatch(['x.part01.rar'], ["Rar!\x1a\x07\x01\x00"]));
                    $this->assertSame(ImageCandidate::scoreImageBatchPhp(['cover.jpg'], ["\xff\xd8\xff"], [1000]),
                        ImageCandidate::scoreImageBatch(['cover.jpg'], ["\xff\xd8\xff"], [1000]));
                    $this->assertSame(MappedNzbFile::summarizeMappingBatchPhp([1], ['a.mkv'], [10], ['verified']),
                        MappedNzbFile::summarizeMappingBatch([1], ['a.mkv'], [10], ['verified']));
                    $this->assertSame(MediaSourceHints::extractHintBatchPhp(['Movie.x264.mkv']), MediaSourceHints::extractHintBatch(['Movie.x264.mkv']));
                    $this->assertSame(0.5, MediaSourceHints::head(200, 100)->availableFraction());
                });
            }
        }
    }

    public function test_w64_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, $symbol, $cdef, , $trailer]) {
            $declaration = \constant($class.'::'.$cdef);
            $this->assertStringContainsString($symbol, $declaration);
            $ffi = RustPipelineLibrary::resolveDomain('additional_w643', $declaration);
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

    public function test_w64_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, , , , $trailer]) {
            $items = [...self::SAMPLES, 'x.vol00+01.par2', "caf\xc3\xa9", ''];
            $invoke = new ReflectionMethod($class, 'w64Invoke');
            $raw = $invoke->invoke(null, $items, $trailer + \count($items));
            $this->assertIsArray($raw, $class.' stays on Rust');
            $this->assertSame($class::{$entry.'Php'}($items), $raw, $class);
            $this->assertNull($invoke->invoke(null, $items, $trailer + \count($items) + 1), $class.' rejects a word count mismatch');
            $nul = ["a\0b", 'ok', "x\0", "\0"];
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
            'download metrics: complete and failed fetches' => [DownloadMetrics::class, ['1048576|10|10|1000000|0|ok|Eweka', '5|0|4|0|2|timeout|', '1|2|3|4|5|bad|x'],
                [0 => 1 | 1 << 2 | 1 << 4 | 1 << 6 | 1 << 8 | 1 << 10 | 1 << 13 | 1000 << 17 | 1024 << 37,
                    1 => 1 | 1 << 2 | 1 << 4 | 1 << 6 | 1 << 8 | 4 << 10 | 2 << 15 | 1000 << 27, 2 => 1 | 1 << 2 | 1 << 4 | 1 << 6 | 1 << 8 | 5 << 10 | 1 << 13 | 3 << 15
                    | 666 << 17 | 714 << 27 | 244 << 37,
                    3 => 1, 4 => 0, 5 => 1, 6 => 1, 7 => 0, 8 => 1, 11 => 1, 13 => 1048582, 14 => 12, 15 => 17, 16 => 1000004, 17 => 7, 18 => 1024, 19 => 1, 20 => 2]],
            'downloaded archive: RAR5 first volume and continuation' => [DownloadedArchive::class,
                ["Movie.part01.rar|100|0|Rar!\x1a\x07\x01\x00", 'movie.part02.rar|100|0|', "x.zip|5|1|PK\x03\x04\x14\x00\x00\x00\x08\x00", 'notes|1|0|'],
                [0 => 1 | 2 << 3 | 1 << 7 | 1 << 9 | 1 << 11 | 1 << 20 | 16 << 36 | 8 << 44, 1 => 1 | 1 << 9 | 1 << 11 | 1 << 18 | 2 << 20 | 16 << 36,
                    2 => 3 | 3 << 3 | 1 << 7 | 1 << 9 | 1 << 11 | 1 << 13 | 2 << 16 | 2 << 18 | 1 << 20 | 5 << 36 | 10 << 44,
                    3 => 1 << 9 | 1 << 11 | 3 << 18 | 5 << 36,
                    4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 2, 10 => 1, 11 => 1, 17 => 2, 18 => 2, 19 => 100, 20 => 3, 21 => 1]],
            'image candidate: cover beats a tiny sample' => [ImageCandidate::class,
                ["Cover.jpg|600|900|204800|\xff\xd8\xff\xe0", 'sample.png|80|60|2000|GIF89a', ''],
                [0 => 1 | 1 << 3 | 1 << 6 | 1 << 9 | 1 << 11 | 1 << 13 | 1 << 15 | 1 << 17 | 110 << 20 | 666 << 27 | 2 << 43 | 9 << 51,
                    1 => 2 | 3 << 3 | 3 << 6 | 2 << 9 | 1 << 11 | 1 << 13 | 1 << 15 | 2 << 17 | 1 << 19 | 1333 << 27 | 2 << 43 | 10 << 51, 2 => 0,
                    3 => 1, 4 => 1, 6 => 1, 9 => 1, 10 => 1, 11 => 1, 12 => 1, 13 => 1, 14 => 1, 15 => 110, 16 => 206800, 17 => 2, 18 => 2]],
            'mapped nzb file: verified media and a recovery volume' => [MappedNzbFile::class,
                ['Movie.mkv|[01/10] "Movie.mkv" yEnc (1/50)|1000|1050|50|50|verified', 'x.vol00+01.PAR2|x.VOL00+01.par2|10|10|1|2|unique-size'],
                [0 => 1 | 1 << 3 | 1 << 6 | 1 << 8 | 2 << 10 | 1 << 12 | 1 << 14 | 1 << 16 | 95 << 20 | 1000 << 27 | 9 << 53,
                    1 => 3 | 2 << 3 | 1 << 6 | 1 << 8 | 1 << 10 | 1 << 12 | 1 << 14 | 2 << 16 | 1 << 18 | 75 << 20 | 500 << 27 | 1 << 37 | 15 << 53,
                    3 => 1, 5 => 1, 10 => 1, 11 => 1, 13 => 1, 14 => 1, 17 => 1, 18 => 1000, 19 => 2]],
            'media source hints: probe now, need more and skip' => [MediaSourceHints::class,
                ['Movie.2019.1080p.x264.mkv|head|10000000|2097152|0', 'Show.HEVC.mp4|mid|1000000|1000|999500', 'readme.txt||||', 'x.mkv|tail|||'],
                [0 => 1 | 1 << 3 | 1 << 6 | 1 << 8 | 1 << 10 | 1 << 12 | 1 << 14 | 1 << 16 | 1 << 18 | 209 << 20 | 2048 << 30 | 5 << 50,
                    1 => 2 | 2 << 3 | 1 << 6 | 2 << 8 | 1 << 10 | 1 << 12 | 1 << 14 | 2 << 16 | 2 << 18 | 1 << 20 | 3 << 50,
                    2 => 1000 << 20 | 2 << 50, 3 => 1 | 1 << 6 | 3 << 8 | 2 << 50,
                    4 => 1, 5 => 2, 6 => 1, 12 => 1, 13 => 3, 14 => 0, 15 => 2, 16 => 1, 17 => 1, 18 => 2098152, 19 => 1]],
            'download metrics empty batch' => [DownloadMetrics::class, [], array_fill(0, 18, 0)],
            'image candidate empty batch' => [ImageCandidate::class, [], array_fill(0, 16, 0)],
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
        // Edge values in every field position of every class's sample.
        $edges = self::edgeCases();
        foreach (self::SAMPLES as $sample) {
            $fields = explode('|', $sample);
            for ($slot = 0; $slot < \count($fields); $slot++) {
                $rows = [];
                foreach ($edges as $edge) {
                    $row = $fields;
                    $row[$slot] = $edge;
                    $rows[] = implode('|', $row);
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
        foreach ([641, 642, 643] as $seed) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity($class, $this->benchItems($class, new Randomizer(new Mt19937($seed)), $uncommon), 'seed '.$seed);
            }
        }
        $random = new Randomizer(new Mt19937(6433));
        $soup = [];
        for ($i = 0; $i < 400; $i++) {
            $length = $random->getInt(0, 64);
            $soup[] = $length === 0 ? '' : $random->getBytesFromString("rRaAzZpPkKsSmMvV0123456789||||||..--_ \"+/:\0\t\n\r\x01\x7f\xff\xd8\x8b\x1f\xc3\xa9", $length);
        }
        $this->assertParity($class, $soup, 'byte soup');
        $this->assertParity($class, array_values(array_filter($soup, static fn (string $s): bool => ! str_contains($s, "\0"))), 'NUL-free soup');
        for ($i = 0; $i < 300; $i++) {
            $this->assertParity($class, \array_slice($soup, $i, $random->getInt(0, 5)), 'soup slice '.$i);
        }
        // Every class's generator through every helper.
        $mixed = [];
        foreach (array_keys(self::TARGETS) as $other) {
            array_push($mixed, ...\array_slice($this->benchItems($other, new Randomizer(new Mt19937(6434)), true), 0, 80));
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
        // call, decoding and any PHP fallback (release dylib, 5 warmups, NNTMUX_W643_BENCH_ROUNDS interleaved paired
        // rounds per helper and workload (default 301, minimum 60), 5 iterations per round). Common = one batch of 320
        // well-formed items of the class's format; uncommon = 320 items mixing edge cases, malformed, long, NUL, CR/LF,
        // case-changed and non-ASCII items. Each helper defaults to Rust only when it clears 1.5x on both workloads'
        // mean and p99, per .ai/rules/rust-performance.md; the assertion below requires every default to match its gates.
        // Speedup = PHP latency / Rust latency, minimum over 3 runs at 301 rounds (PHP 8.5.12 NTS, release dylib, Apple
        // Silicon, shared host at load average ~6.4-7.8), common mean / p99, uncommon mean / p99:
        // - DownloadMetrics:   9.66x / 8.71x, 7.67x / 6.64x
        // - DownloadedArchive: 5.30x / 4.33x, 4.69x / 3.89x
        // - ImageCandidate:    6.21x / 4.44x, 5.48x / 4.43x
        // - MappedNzbFile:     4.41x / 3.48x, 4.65x / 4.17x
        // - MediaSourceHints:  6.79x / 2.91x, 5.92x / 2.88x
        // Every helper clears every gate in all three runs and defaults to Rust.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // RUN_RUST_FFI_BENCHMARKS=1 php artisan test --compact tests/Unit/RustAdditionalDownloadMetricsArchiveImageMappedNzbSourceHintsW64ParityTest.php --filter benchmark
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            $this->markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the Wave 64.3 benchmark.');
        }
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(6436));
        $warmup = 5;
        $rounds = max(60, (int) (getenv('NNTMUX_W643_BENCH_ROUNDS') ?: 301));
        $iterations = 5;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => $this->benchItems($class, new Randomizer(new Mt19937(6437)), false),
                'uncommon' => $this->benchItems($class, new Randomizer(new Mt19937(6438)), true),
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
        fwrite(STDERR, "\nRustAdditionalDownloadMetricsArchiveImageMappedNzbSourceHintsW64 speedup: {$summary}\n");
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
        $this->assertCount(self::TARGETS[$class][4] + \count($items), $php, $class.' '.$message);
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
            $row = match ($class) {
                DownloadMetrics::class => $this->metricsItem($random, $uncommon),
                DownloadedArchive::class => $this->archiveItem($random, $uncommon),
                ImageCandidate::class => $this->imageItem($random, $uncommon),
                MappedNzbFile::class => $this->mappedItem($random, $uncommon),
                default => $this->hintsItem($random, $uncommon),
            };
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $row = $this->pick($random, [strtoupper($row), strtolower($row), ' '.$row, $row."\t", $row."\0x", "caf\xc3\xa9".$row, $row.'|x', $row.'||',
                    str_repeat($row, 4), str_replace('.', ' ', $row), str_replace('|', ' | ', $row), "\x01".$row, $row."\r\n"]);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function releaseName(Randomizer $random): string
    {
        return $this->pick($random, ['Movie', 'Some.Show', 'The.Film', 'Another_Release', 'Doc', 'Concert Live'])
            .'.'.$random->getInt(1990, 2025).'.'.$this->pick($random, ['720p', '1080p', '2160p', 'DVDRip']).'.'
            .$this->pick($random, ['x264', 'x265', 'HEVC', 'XviD', 'AV1', 'h264', 'web']).'-'.$this->pick($random, ['GRP', 'NTb', 'FLUX', 'SPARKS']);
    }

    private function metricsItem(Randomizer $random, bool $noisy): string
    {
        $expected = $random->getInt(1, 400);
        $segments = $random->getInt(0, 3) === 0 ? $random->getInt(0, $expected) : $expected;

        return implode('|', [$segments * $random->getInt(380_000, 760_000), $segments, $expected, $random->getInt(1, 4) === 1 ? 0 : $random->getInt(1_000, 90_000_000),
            $random->getInt(0, 4) === 0 ? $random->getInt(1, 20) : 0, $this->pick($random, ['ok', 'ok', 'ok', 'missing', 'crc', 'timeout', '', $noisy ? 'busy' : 'ok']),
            $this->pick($random, ['Eweka', 'Newshosting', 'news.example.com', 'Frugal', '', 'eweka'])]);
    }

    private function archiveItem(Randomizer $random, bool $noisy): string
    {
        $base = $this->releaseName($random);
        $volume = $random->getInt(1, 40);
        $name = $base.$this->pick($random, ['.part'.str_pad((string) $volume, 2, '0', STR_PAD_LEFT).'.rar', '.rar', '.r'.str_pad((string) ($volume % 100), 2, '0', STR_PAD_LEFT),
            '.zip', '.z0'.($volume % 10), '.7z', '.7z.'.str_pad((string) $volume, 3, '0', STR_PAD_LEFT), '.'.str_pad((string) $volume, 3, '0', STR_PAD_LEFT), '.tar.gz', '.nfo']);
        $head = $this->pick($random, ["Rar!\x1a\x07\x01\x00", "Rar!\x1a\x07\x00", "PK\x03\x04\x14\x00\x00\x00\x08\x00", "PK\x03\x04\x0a\x00\x00\x00\x00\x00",
            "7z\xbc\xaf\x27\x1c\x00\x04", "\x1f\x8b\x08\x00", 'BZh91AY', "\xfd7zXZ\x00\x00", '', $noisy ? "\x00\x01\x02" : '']);

        return $name.'|'.$random->getInt(0, 4_000_000_000).'|'.$this->pick($random, ['0', '0', '0', '1', '2', '4', '', $noisy ? 'x' : '0']).'|'.$head
            .$this->bytes($random, 24);
    }

    private function imageItem(Randomizer $random, bool $noisy): string
    {
        $word = $this->pick($random, ['cover', 'Front', 'poster', 'folder', 'sample', 'screen', 'thumb', 'back', 'image', 'proof', 'art']);
        $name = $this->pick($random, [$word, $this->releaseName($random).'-'.$word, $word.'_'.$random->getInt(1, 99)]).'.'
            .$this->pick($random, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'JPG', $noisy ? 'tiff' : 'jpg']);
        $head = $this->pick($random, ["\xff\xd8\xff\xe0\x00\x10JFIF", "\x89PNG\r\n\x1a\n\x00\x00", 'GIF89a', "RIFF\x24\x00\x00\x00WEBPVP8 ", 'BM6', '', $noisy ? 'junk' : '']);

        return implode('|', [$name, $random->getInt(40, 4000), $random->getInt(40, 4000), $random->getInt(1_000, 8_000_000), $head.$this->bytes($random, 16)]);
    }

    private function mappedItem(Randomizer $random, bool $noisy): string
    {
        $base = $this->releaseName($random);
        $name = $base.$this->pick($random, ['.mkv', '.mp4', '-sample.mkv', '.par2', '.vol00+01.par2', '.part01.rar', '.r05', '.nfo', '.sfv', '.001']);
        $subjectName = $this->pick($random, [$name, $name, strtolower($name), $base.'.bin', 'other.file']);
        $size = $random->getInt(100, 4_000_000_000);
        $total = $random->getInt(1, 5000);

        return implode('|', [$name, '['.$random->getInt(1, 99).'/99] - "'.$subjectName.'" yEnc ('.$random->getInt(1, $total).'/'.$total.')',
            $size, $this->pick($random, [$size, $size + intdiv($size, 32), $size * 2, $noisy ? 'x' : $size]),
            $random->getInt(0, 3) === 0 ? $random->getInt(0, $total) : $total, $total, $this->pick($random, ['verified', 'unique-size', 'Verified', '', $noisy ? 'guess' : 'verified'])]);
    }

    private function hintsItem(Randomizer $random, bool $noisy): string
    {
        $real = $random->getInt(1_000_000, 8_000_000_000);
        $available = $random->getInt(0, 3) === 0 ? $random->getInt(0, 2_000_000) : $random->getInt(0, $real);

        return implode('|', [$this->releaseName($random).$this->pick($random, ['.mkv', '.mp4', '.avi', '.m2ts', '-sample.mkv', '.nfo', '.wmv', '.mpeg']),
            $this->pick($random, ['complete', 'head', 'mid', 'head', '', $noisy ? 'tail' : 'head']), $real, $available,
            $random->getInt(0, 2) === 0 ? 0 : $random->getInt(0, $real)]);
    }

    /** Zero to $max random bytes. */
    private function bytes(Randomizer $random, int $max): string
    {
        $length = $random->getInt(0, $max);

        return $length === 0 ? '' : $random->getBytes($length);
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
     * Calls a Wave 64.3 export by name.
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
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('additional_w643', MediaSourceHints::W64_MEDIA_SOURCE_HINTS_FFI_CDEF) === null) {
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
