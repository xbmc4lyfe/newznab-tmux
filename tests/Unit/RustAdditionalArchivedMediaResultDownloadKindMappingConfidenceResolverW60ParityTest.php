<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\DTO\ArchivedMediaEntry;
use App\Services\AdditionalProcessing\DTO\ReleaseProcessingResult;
use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\AdditionalProcessing\Enums\MappingConfidence;
use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AdditionalProcessing\Media\MediaSourceResolver;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;

final class RustAdditionalArchivedMediaResultDownloadKindMappingConfidenceResolverW60ParityTest extends TestCase
{
    /** Class => batch method. */
    private const WAVE = [
        ArchivedMediaEntry::class => 'summarizeArchivedEntryBatch',
        ReleaseProcessingResult::class => 'classifyResultBatch',
        DownloadKind::class => 'planDownloadKindBatch',
        MappingConfidence::class => 'planMappingConfidenceBatch',
        MediaSourceResolver::class => 'resolveMediaSourceBatch',
    ];

    /** Header words before the per-record rows (one word per record). */
    private const HEADER = [
        ArchivedMediaEntry::class => ArchivedMediaEntry::W60_ENTRY_HEADER,
        ReleaseProcessingResult::class => ReleaseProcessingResult::W60_RESULT_HEADER,
        DownloadKind::class => DownloadKind::W60_KIND_HEADER,
        MappingConfidence::class => MappingConfidence::W60_MAPPING_HEADER,
        MediaSourceResolver::class => MediaSourceResolver::W60_SOURCE_HEADER,
    ];

    /**
     * Apple M-series/macOS arm64, PHP 8.5.12 CLI without opcache/JIT, release Rust; base 066cacde5 plus
     * this change, seed 606, 50 warmups and 1,000 pairs/workload (w606-benchmark-b351eb9313b3d67a.json in
     * system tmp). Common mean/p99 then uncommon mean/p99 speedups (PHP time / Rust time); a default is
     * Rust only when all four reach 1.5x. All five classes exceed 1.5x on every metric.
     */
    private const QUALIFICATION = [
        ArchivedMediaEntry::class => [2.406, 2.136, 2.335, 2.024],
        ReleaseProcessingResult::class => [3.734, 2.930, 2.966, 2.105],
        DownloadKind::class => [5.202, 3.523, 4.212, 3.423],
        MappingConfidence::class => [4.811, 5.602, 4.273, 2.836],
        MediaSourceResolver::class => [3.149, 3.499, 2.641, 2.661],
    ];

    private const RELEASES = [
        'The.Expanse.S06E03.1080p.WEB.H264-GRP', 'Dune.Part.Two.2024.2160p.UHD.BluRay.x265-TERMiNAL', 'Some.Movie.2023.720p.BluRay.XviD-AMIABLE',
        'Documentary.2022.1080p.AMZN.WEB-DL.DDP5.1.HEVC-NTb', 'Show.Name.S01E01.HDTV.AVC-LOL', 'Anime.Title.E12.1080p.AV1.Opus-Sub',
    ];

    private const VIDEO_EXTS = ['mkv', 'mkv', 'mkv', 'mp4', 'avi', 'ts', 'm2ts', 'wmv', 'mov', 'mpg', 'vob', 'webm', 'm4v'];

    private const OTHER_EXTS = ['nfo', 'jpg', 'png', 'srt', 'sub', 'txt', 'mp3', 'flac', 'rar', 'zip', '7z', 'sfv', 'par2', 'exe', ''];

    private const STAGES = ['download', 'archive', 'par2', 'media', 'sample', 'video', 'audio', 'image', 'nfo', 'persist', ''];

    private const KIND_ALIASES = ['Sample', 'MediaInfo', 'Par2Index', 'MediaSource', ' JPG ', 'PAR2_INDEX', 'Media_Source', 'mediainfo', 'info', 'mp3',
        'music', 'jpeg', 'image', 'preview', 'archive', 'rar', 'par2', 'par', 'source', 'video', 'samples', "\tAudio\n"];

    private const CODECS = ['h264', 'h264', 'hevc', 'HEVC', 'x264', 'avc1', 'hvc1', 'av1', 'xvid', 'vp9', '', '', ''];

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::WAVE as $class => $method) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW60RustFfi', 'withW60RustFfi', 'w60RustFfiState', $method, $method.'Php', $method.'Native'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            $state = $reflection->getMethod('w60RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            foreach ([$method, $method.'Php', $method.'Native', 'usesW60RustFfi', 'withW60RustFfi'] as $name) {
                self::assertTrue($reflection->getMethod($name)->isStatic(), $class.'::'.$name);
                self::assertTrue($reflection->getMethod($name)->isPublic(), $class.'::'.$name);
            }
            self::assertTrue($reflection->getReflectionConstant('W60_RUST_FFI_DEFAULT')?->isPublic() ?? false, $class);
            self::assertIsBool($reflection->getConstant('W60_RUST_FFI_DEFAULT'), $class);
            $cdefs = array_filter($reflection->getReflectionConstants(), static fn ($constant): bool => preg_match('/^W60_\w+_FFI_CDEF$/', $constant->getName()) === 1);
            self::assertCount(1, $cdefs, $class);
            $cdef = array_values($cdefs)[0];
            self::assertTrue($cdef->isPrivate(), $class);
            self::assertStringContainsString('nntmux_w606_', (string) $cdef->getValue(), $class);
            foreach ($reflection->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w60', $property->getName(), $class);
            }
        }
        self::assertTrue((new ReflectionClass(ArchivedMediaEntry::class))->isReadOnly());
        self::assertTrue((new ReflectionClass(ReleaseProcessingResult::class))->isReadOnly());
        self::assertTrue((new ReflectionClass(DownloadKind::class))->isEnum());
        self::assertTrue((new ReflectionClass(MappingConfidence::class))->isEnum());
        self::assertSame([], (new ReflectionClass(DownloadKind::class))->getProperties(\ReflectionProperty::IS_STATIC));
    }

    public function test_defaults_match_recorded_qualification_and_toggles_restore(): void
    {
        foreach (array_keys(self::WAVE) as $class) {
            $expected = (bool) (new ReflectionClass($class))->getConstant('W60_RUST_FFI_DEFAULT');
            self::assertSame($expected, min(self::QUALIFICATION[$class]) >= 1.5, $class);
            self::assertSame($expected, $class::usesW60RustFfi(), $class);
            if (! $expected) {
                self::assertFalse($class::usesW60RustFfi(), $class.' retains PHP by default');
            }
            foreach ([true, false] as $enabled) {
                $seen = $class::withW60RustFfi($enabled, function () use ($class, $enabled): bool {
                    $inner = $class::withW60RustFfi(! $enabled, static fn (): bool => $class::usesW60RustFfi());
                    self::assertSame(! $enabled, $inner);
                    foreach (array_keys(self::WAVE) as $other) {
                        if ($other !== $class) {
                            self::assertSame((bool) (new ReflectionClass($other))->getConstant('W60_RUST_FFI_DEFAULT'), $other::usesW60RustFfi());
                        }
                    }

                    return $class::usesW60RustFfi();
                });
                self::assertSame($enabled, $seen, $class);
                self::assertSame($expected, $class::usesW60RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW60RustFfi(! $expected, static function (): bool {
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown);
            self::assertSame($expected, $class::usesW60RustFfi(), $class);
        }
    }

    public function test_existing_behavior_is_unchanged(): void
    {
        $entry = new ArchivedMediaEntry('movie.mkv', 100, 2, str_repeat('x', 40), 10, 109, 50, 3, false, null);
        self::assertFalse($entry->isComplete());
        self::assertSame(60, $entry->continuationBytesPhp(100));
        self::assertSame(0, $entry->continuationBytes(20));
        self::assertSame(2, ArchivedMediaEntry::rankEntryBatchPhp(['a.mkv', 'b.sample.mkv'], [5, 9])[1]);
        $result = new ReleaseProcessingResult(1, 'g', ProcessingOutcome::Completed, stageDurations: ['a' => 1.0, 'b' => 2.0], elapsedSeconds: 4.0);
        self::assertTrue($result->isSuccessfulPhp());
        self::assertSame('b', $result->durationSummaryPhp()['slowestStage']);
        self::assertSame(2, ReleaseProcessingResult::summarizeResultBatchPhp(['completed', 'failed'], [1, 2], [0, 0], [0, 0], [0, 0])[0]);
        self::assertTrue(DownloadKind::Par2Index->isPreviewSourcePhp());
        self::assertFalse(DownloadKind::Sample->isPreviewSourcePhp());
        self::assertSame(DownloadKind::MediaInfo, DownloadKind::from('media-info'));
        self::assertSame(7, \count(DownloadKind::cases()));
        self::assertSame(1, DownloadKind::accountDownloadBatchPhp(['jpg'], [5], 10)[17]);
        self::assertSame('Unique size', MappingConfidence::UniqueSize->labelPhp());
        self::assertSame(2, MappingConfidence::Verified->rankPhp());
        self::assertSame(MappingConfidence::UniqueSize, MappingConfidence::tryFromNormalizedPhp(' UNIQUE_SIZE '));
        self::assertSame(2, \count(MappingConfidence::cases()));
        self::assertSame(MediaSourceResolver::W44_RUST_FFI_DEFAULT, MediaSourceResolver::usesRustFfi());
    }

    public function test_archived_entry_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(ArchivedMediaEntry::class, [[], [], [], [], []]);
        self::assertSame([0, -1, -1, 0], [$empty[0], $empty[10], $empty[12], $empty[27]]);
        $out = $this->assertParity(ArchivedMediaEntry::class, [
            ['Movie.2024.x265.mkv', 'dir\\Movie.Sample.mkv', '../evil.mkv', 'cover.JPG'], [8_000, 500, 900, 10], [8_000, 100, 900, 10], [0, 0, 0, 0], [0, 0, 0, 1],
        ]);
        self::assertSame([4, 3, 2, 1, 0, 1, 1, 3], \array_slice($out, 0, 8));
        self::assertSame([0, 8_000, 1, 500], \array_slice($out, 10, 4));
        self::assertSame(1 | 1 << 8 | 1 << 9 | 100 << 10 | 19 << 17 | 3 << 33 | 2 << 41, $out[ArchivedMediaEntry::W60_ENTRY_HEADER]);
        $this->assertParity(ArchivedMediaEntry::class, [
            ['', '/', '/abs/movie.mkv', 'C:\\x\\movie.mkv', "a\0b.mkv", '..', 'x/../y.mp4', 'x/..y.mp4', '.hidden.mkv', 'noext', 'trailing.', '.', 'a/b/',
                'SAMPLE.AVI', 'MOVIE.MKV', 'a.b.c.m2ts', str_repeat('a', 70_000).'.mkv', 'x.'.str_repeat('e', 300), "\xff\xfe.mkv", 'av1.webm', 'XviD.avi', '1', '7z', 'f.7z'],
            [PHP_INT_MIN, -1, 0, 1, 1 << 40, (1 << 40) - 1, PHP_INT_MAX, 100, 100, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5],
            [PHP_INT_MAX, 0, 1, 1, PHP_INT_MIN, 1 << 41, 50, 99, 101, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5],
            [0, 0, 0x30, -1, 0, PHP_INT_MIN, 0, 1],
            [1, 0, 0, 0, PHP_INT_MAX, 0, -1],
        ]);
        $this->assertParity(ArchivedMediaEntry::class, [['k' => 'a.mkv', 'z' => 'b.mkv'], [9 => 5, 2 => 7], ['x' => 5], [], [3 => 0, 1 => 0, 7 => 1]]);
    }

    public function test_release_result_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(ReleaseProcessingResult::class, [[], [], [], [], [], [], []]);
        self::assertSame([0, -1, -1, -1], [$empty[0], $empty[40], $empty[41], $empty[42]]);
        $out = $this->assertParity(ReleaseProcessingResult::class, [
            ['completed', 'timed-out', 'failed'], ['download', 'bogus', ''], [500, 900, 100], [400, 1_000, 300], [1, 1, 0], [2, 0, 0], [0, 1, 5], 3,
        ]);
        self::assertSame([1, 1, 1, 0, 0, 0], \array_slice($out, 14, 6));
        self::assertSame([900, 1, 1, 2, 60], \array_slice($out, 39, 5));
        self::assertSame(5 | 1 << 4 | 10 << 7 | 1 << 11 | 1 << 12 | 1 << 13 | 1 << 14 | 60 << 22, $out[ReleaseProcessingResult::W60_RESULT_HEADER + 1]);
        $outcomes = [...array_map(static fn (ProcessingOutcome $o): string => $o->value, ProcessingOutcome::cases()), 'COMPLETED', '', "failed\0", ' failed', 'x'];
        foreach ([PHP_INT_MIN, -1, 0, 1, 3, 11, PHP_INT_MAX] as $maxAttempts) {
            $this->assertParity(ReleaseProcessingResult::class, [
                $outcomes,
                [...self::STAGES, 'DOWNLOAD', "par2\0", 'other', '0', ' nfo', 'persist '],
                [PHP_INT_MIN, -1, 0, 1, 1 << 40, PHP_INT_MAX, 1 << 30, 5, 5, 5, 5, 1 << 35, 7],
                [0, 5, 1, 0, PHP_INT_MAX, PHP_INT_MIN, 0, 9, 5, 4],
                [0, 1, -1, PHP_INT_MIN, 0, 0, 1],
                [PHP_INT_MAX, 0, -5, 3],
                [PHP_INT_MIN, -1, 0, 1, 2, 3, 4, 9, 10, 11, 255, 256, 1 << 20, PHP_INT_MAX, 7, 2],
                $maxAttempts,
            ]);
        }
        $this->assertParity(ReleaseProcessingResult::class, [['a' => 'failed', 'b' => 'completed'], [5 => 'nfo'], ['x' => 9], [], [], [], ['q' => 1], 2]);
    }

    public function test_download_kind_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(DownloadKind::class, [[], [], [], 10, 60]);
        self::assertSame([0, -1, 10], [$empty[0], $empty[18], $empty[20]]);
        $out = $this->assertParity(DownloadKind::class, [['sample', 'MediaInfo', ' PAR2_INDEX ', 'jpeg', 'nope'], [50, 4, 9, 3, 1], [4_000, 400, 900, 300, 100], 45, 60]);
        self::assertSame([1, 1, 1, 1, 1], \array_slice($out, 9, 5));
        self::assertSame([67, 45, 4, 0, 3_700, 0], \array_slice($out, 15, 6));
        self::assertSame(0 | 1 << 7 | 40 << 8 | (60_000 + 40 * 250) << 29 | 6 << 58, $out[DownloadKind::W60_KIND_HEADER]);
        $kinds = [...array_map(static fn (DownloadKind $k): string => $k->value, DownloadKind::cases()), ...self::KIND_ALIASES, '', "jpg\0", 'JPG', '1', str_repeat('x', 40)];
        foreach ([[PHP_INT_MIN, PHP_INT_MIN], [0, 0], [3, 1], [1_000, 60], [PHP_INT_MAX, PHP_INT_MAX], [1 << 41, 86_401]] as [$budget, $timeout]) {
            $this->assertParity(DownloadKind::class, [
                $kinds,
                [PHP_INT_MIN, -1, 0, 1, 2, 3, 4, 8, 40, 41, 1 << 20, (1 << 20) - 1, PHP_INT_MAX, 7, 5, 9, 12],
                [PHP_INT_MAX, 0, -1, 1 << 40, 1_000, 5, 77, 1 << 30, PHP_INT_MIN, 3],
                $budget, $timeout,
            ]);
        }
        $this->assertParity(DownloadKind::class, [['a' => 'jpg', 'b' => 'Audio'], [9 => 3, 1 => 2], [], 4, 10]);
    }

    public function test_mapping_confidence_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(MappingConfidence::class, [[], [], [], [], []]);
        self::assertSame([0, -1, 0], [$empty[0], $empty[15], $empty[18]]);
        $out = $this->assertParity(MappingConfidence::class, [
            ['verified', 'UniqueSize', " UNIQUE_SIZE\n", 'guess'], [0, 2, 0, 9], [0, 0, 3, 0], [0, 0, 0, 0], [10, 20, 30, 40],
        ]);
        self::assertSame([1, 2, 1, 1, 1, 1, 1], \array_slice($out, 1, 7));
        self::assertSame([1, 1, 885, 360, 3, 10, 50, 221], \array_slice($out, 11, 8));
        $values = ['verified', 'unique-size', 'Verified', 'UniqueSize', 'VERIFIED', 'unique_size', "\0verified\0", 'uniquesize', '', ' ', "verified\0x", 'Unique-Size', 'x'];
        $this->assertParity(MappingConfidence::class, [
            $values,
            [PHP_INT_MIN, -1, 0, 1, 2, 7, 255, 256, PHP_INT_MAX, 25],
            [0, 1, 255, 1_000, -3, PHP_INT_MAX],
            [PHP_INT_MAX, 0, 3, 40, 255, PHP_INT_MIN, 1, 1],
            [PHP_INT_MIN, -1, 0, 1 << 40, PHP_INT_MAX, 12, 5],
        ]);
        $this->assertParity(MappingConfidence::class, [['q' => 'verified', 'r' => 'unique-size'], [5 => 1], [], ['z' => 3], [8 => 100, 2 => 200]]);
    }

    public function test_media_source_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(MediaSourceResolver::class, [[], [], []]);
        self::assertSame([0, -1, -1], [$empty[0], $empty[27], $empty[31]]);
        $out = $this->assertParity(MediaSourceResolver::class, [
            ['a/Movie.mkv', 'Movie.sample.mp4', 'show.part02.rar', 'show.part001.rar', 'x.wmv'], [5 << 20, 3 << 20, 1 << 20, 1 << 20, 4 << 20],
            ['', 'x264', 'vp8', '', ''], 1 << 20, PHP_INT_MAX,
        ]);
        self::assertSame([1, 1, 3, 0], \array_slice($out, 1, 4));
        self::assertSame([3, 1, 0, 0, 1, 0, 0, 3, 5 << 20, 12 << 20, 3], \array_slice($out, 21, 11));
        $paths = ['', '/', 'a/', 'movie.mkv', 'MOVIE.MKV', 'x/../movie.mkv', '..\\movie.mkv', "a\0.mkv", 'show.part1.rar', 'show.part01.rar', 'show.part0.rar',
            'show.part000.rar', 'show.part.rar', 'show.partx.rar', 'show.part10.RAR', 'archive.7z', 'archive.001', 'a.zip', 'cover.jpeg', 'x.webp', 'Sample/clip.mkv',
            'x265.mkv', 'h264 movie.ts', 'noext', 'C:\\a\\b.m2ts', str_repeat('p/', 40_000).'x.mov', "\xff.vob"];
        foreach ([[PHP_INT_MIN, PHP_INT_MAX], [0, 0], [1 << 20, 1 << 32], [PHP_INT_MAX, PHP_INT_MIN], [5, 5]] as [$min, $max]) {
            $this->assertParity(MediaSourceResolver::class, [
                $paths,
                [PHP_INT_MIN, -1, 0, 1, 5, 1 << 20, (1 << 20) - 1, 1 << 40, PHP_INT_MAX, 1 << 32, (1 << 32) + 1, 1 << 25, 3 << 30],
                [...self::CODECS, ' VP9 ', "\th264\t", "hevc\0", 'mpeg4', 'divx', 'vc1', 'HVC1', ' ', "\n"],
                $min, $max,
            ]);
        }
        $this->assertParity(MediaSourceResolver::class, [['k' => 'a.mkv', 'j' => 'b.rar'], [4 => 1 << 21], ['z' => 'hevc'], 0, PHP_INT_MAX]);
    }

    /**
     * @return iterable<string, array{0: class-string, 1: int, 2: bool, 3: int}>
     */
    public static function datasetProvider(): iterable
    {
        foreach (array_keys(self::WAVE) as $class) {
            $short = (new ReflectionClass($class))->getShortName();
            yield $short.' common' => [$class, 320, false, 606];
            yield $short.' uncommon' => [$class, 384, true, 607];
            yield $short.' large common' => [$class, 2_048, false, 608];
        }
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('datasetProvider')]
    public function test_realistic_dataset_parity(string $class, int $count, bool $uncommon, int $seed): void
    {
        $this->requireBindings();
        $out = $this->assertParity($class, $this->benchmarkArgs($class, $count, $uncommon, $seed));
        self::assertSame($count, $out[0]);
    }

    public function test_randomized_fuzz_parity(): void
    {
        $this->requireBindings();
        $checked = array_fill_keys(array_keys(self::WAVE), 0);
        for ($round = 0; $round < 40; $round++) {
            foreach (array_keys(self::WAVE) as $class) {
                $count = (new Randomizer(new Mt19937($round)))->getInt(0, 96);
                $this->assertParity($class, $this->benchmarkArgs($class, $count, $round % 2 === 1, 9_600 + $round));
                $checked[$class] += $count;
            }
        }
        foreach ($checked as $class => $records) {
            self::assertGreaterThanOrEqual(300, $records, $class);
        }
    }

    /**
     * Protocol (set NNTMUX_W606_BENCH=1): seed 606, 8 fixture variants, 50 warmups and
     * NNTMUX_W606_BENCH_PAIRS (default 1,000, at least 60) randomized-order interleaved pairs per
     * class/workload. Common batches hold 320 typical records; uncommon ones 384 malformed, unknown,
     * NUL-holding, oversized or extreme records. Fixtures are built outside timing and parity is
     * asserted on every pair. Timings cover public dispatch, marshaling, FFI and unpacking. Nearest-rank
     * p99. The summary and raw pairs are written to the system temp directory.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W606_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W606_BENCH=1 to run the paired Wave 60.6 benchmark.');
        }
        $this->requireBindings();
        $pairs = max(60, (int) (getenv('NNTMUX_W606_BENCH_PAIRS') ?: 1000));
        $random = new Randomizer(new Mt19937(606));
        $summary = $records = [];
        foreach (array_keys(self::WAVE) as $class) {
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $variants = [];
                for ($v = 0; $v < 8; $v++) {
                    $variants[] = $this->benchmarkCall($class, $uncommon ? 384 : 320, $uncommon, 606 + $v * 31 + ($uncommon ? 7 : 0));
                }
                $php = $rust = [];
                for ($i = -50; $i < $pairs; $i++) {
                    $variant = $random->getInt(0, 7);
                    $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                    $results = $elapsed = [];
                    foreach ($order as $native) {
                        $call = $variants[$variant];
                        // The routing scope is set outside the timed region; the public batch call (its own
                        // dispatch, marshaling, FFI and unpacking) is timed inside it.
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW60RustFfi($native, static function () use ($call): array {
                            $start = hrtime(true);
                            $result = $call();

                            return [$result, hrtime(true) - $start];
                        });
                    }
                    self::assertSame($results[0], $results[1], $class.' '.$workload);
                    if ($i >= 0) {
                        $php[] = $elapsed[0];
                        $rust[] = $elapsed[1];
                        $records[] = [$class, $workload, $variant, $order[0] ? 'rust-first' : 'php-first', $elapsed[0], $elapsed[1]];
                    }
                }
                sort($php);
                sort($rust);
                $p99 = (int) ceil($pairs * 0.99) - 1;
                $summary[$class][$workload] = [
                    'mean_speedup' => round(array_sum($php) / array_sum($rust), 3),
                    'p99_speedup' => round($php[$p99] / $rust[$p99], 3),
                    'php_mean_ns' => intdiv(array_sum($php), $pairs), 'rust_mean_ns' => intdiv(array_sum($rust), $pairs),
                    'php_p99_ns' => $php[$p99], 'rust_p99_ns' => $rust[$p99],
                ];
            }
        }
        $metadata = ['utc' => gmdate('c'), 'php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => $pairs, 'warmups' => 50, 'seed' => 606, 'variants' => 8,
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')];
        $artifact = sys_get_temp_dir().'/w606-benchmark-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($artifact, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW60.6 paired benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::WAVE) as $class) {
            $eligible = true;
            foreach ($summary[$class] as $metrics) {
                $eligible = $eligible && $metrics['mean_speedup'] >= 1.5 && $metrics['p99_speedup'] >= 1.5;
            }
            // A class below 1.5x on any metric must retain PHP; one that clears it may still retain PHP after a
            // failing run (see QUALIFICATION), but a Rust default must clear every metric.
            if (! $eligible || $class::usesW60RustFfi()) {
                self::assertSame($eligible, $class::usesW60RustFfi(), $class.' must default to Rust only when it clears >=1.5x mean/p99 on both workloads');
            }
        }
    }

    /**
     * Arguments of the class's batch method for one fixture.
     *
     * @param  class-string  $class
     * @return list<mixed>
     */
    private function benchmarkArgs(string $class, int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));

        return match ($class) {
            ArchivedMediaEntry::class => $this->entryBatch($random, $count, $uncommon),
            ReleaseProcessingResult::class => $this->resultBatch($random, $count, $uncommon),
            DownloadKind::class => $this->kindBatch($random, $count, $uncommon),
            MappingConfidence::class => $this->mappingBatch($random, $count, $uncommon),
            default => $this->sourceBatch($random, $count, $uncommon),
        };
    }

    /**
     * @param  class-string  $class
     * @return Closure(): list<int>
     */
    private function benchmarkCall(string $class, int $count, bool $uncommon, int $seed): Closure
    {
        $args = $this->benchmarkArgs($class, $count, $uncommon, $seed);
        $method = self::WAVE[$class];

        return static fn (): array => $class::$method(...$args);
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

    /** A release-style inner path: videos, samples and side files, sometimes in a folder. */
    private function innerPath(Randomizer $random): string
    {
        $release = $this->pick($random, self::RELEASES);
        $name = match ($random->getInt(0, 5)) {
            0 => strtolower($release).'-sample.'.$this->pick($random, self::VIDEO_EXTS),
            1, 2 => $release.'.'.$this->pick($random, self::VIDEO_EXTS),
            default => $release.'.'.$this->pick($random, self::OTHER_EXTS),
        };

        return match ($random->getInt(0, 3)) {
            0 => $release.'/'.$name,
            1 => 'Sample\\'.$name,
            default => $name,
        };
    }

    /**
     * Archived videos, samples and side files with partial heads; uncommon ones are traversal, absolute,
     * NUL-holding, empty, upper-case, random or oversized names with extreme sizes and flags.
     *
     * @return list<mixed>
     */
    private function entryBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $names = $sizes = $held = $methods = $encrypted = [];
        for ($i = 0; $i < $count; $i++) {
            $name = $this->innerPath($random);
            $size = $random->getInt(0, 9) === 0 ? $random->getInt(1_000, 900_000) : $random->getInt(50_000_000, 8_000_000_000);
            $have = $random->getInt(0, 2) === 0 ? $size : $random->getInt(0, $size);
            $method = $random->getInt(0, 5) === 0 ? 0x33 : 0;
            $locked = $random->getInt(0, 30) === 0 ? 1 : 0;
            if ($uncommon) {
                match ($random->getInt(0, 13)) {
                    0 => $name = '../'.$name,
                    1 => $name = '/'.$name,
                    2 => $name = $name."\0.mkv",
                    3 => $name = '',
                    4 => $name = strtoupper($name),
                    5 => $name = substr($random->getBytes(48), 0, $random->getInt(0, 48)),
                    6 => $name = str_repeat('dir/', $random->getInt(50, 400)).$name,
                    7 => $size = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    8 => $have = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    9 => $method = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    10 => $locked = $random->getInt(-5, 5),
                    11 => $name = 'x\\..\\'.$name,
                    default => null,
                };
            }
            $names[] = $name;
            $sizes[] = $size;
            $held[] = $have;
            $methods[] = $method;
            $encrypted[] = $locked;
        }

        return [$names, $sizes, $held, $methods, $encrypted];
    }

    /**
     * Results with outcome, slowest stage, timings, artifacts and attempts; uncommon ones are unknown,
     * upper-case or NUL-holding outcomes and stages with extreme timings and attempts.
     *
     * @return list<mixed>
     */
    private function resultBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $cases = ProcessingOutcome::cases();
        $outcomes = $stages = $elapsed = $staged = $artifacts = $files = $attempts = [];
        for ($i = 0; $i < $count; $i++) {
            $outcome = $random->getInt(0, 2) === 0 ? 'completed' : $this->pick($random, $cases)->value;
            $stage = $this->pick($random, self::STAGES);
            $time = $random->getInt(10_000, 600_000_000);
            $inStages = $random->getInt(0, $time + intdiv($time, 10));
            $artifact = $random->getInt(0, 1);
            $added = $random->getInt(0, 3) === 0 ? $random->getInt(1, 40) : 0;
            $attempt = $random->getInt(0, 5);
            if ($uncommon) {
                match ($random->getInt(0, 11)) {
                    0 => $outcome = strtoupper($outcome),
                    1 => $outcome = $outcome."\0",
                    2 => $outcome = 'mystery-'.$i,
                    3 => $stage = strtoupper($stage).' ',
                    4 => $stage = "stage\0".$i,
                    5 => $time = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    6 => $inStages = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    7 => $attempt = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    8 => $added = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    9 => $artifact = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    default => null,
                };
            }
            $outcomes[] = $outcome;
            $stages[] = $stage;
            $elapsed[] = $time;
            $staged[] = $inStages;
            $artifacts[] = $artifact;
            $files[] = $added;
            $attempts[] = $attempt;
        }

        return [$outcomes, $stages, $elapsed, $staged, $artifacts, $files, $attempts, $uncommon ? $random->getInt(-2, 12) : 3];
    }

    /**
     * Download kinds with article and byte requests; uncommon ones are case names, aliases, padded,
     * NUL-holding or unknown kinds with extreme requests, budgets and timeouts.
     *
     * @return list<mixed>
     */
    private function kindBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $cases = DownloadKind::cases();
        $kinds = $articles = $bytes = [];
        for ($i = 0; $i < $count; $i++) {
            $kind = $this->pick($random, $cases)->value;
            $requested = $random->getInt(1, 60);
            $size = $requested * $random->getInt(300_000, 800_000);
            if ($uncommon) {
                match ($random->getInt(0, 9)) {
                    0, 1 => $kind = $this->pick($random, self::KIND_ALIASES),
                    2 => $kind = strtoupper($kind),
                    3 => $kind = $kind."\0",
                    4 => $kind = 'unknown-'.$i,
                    5 => $requested = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    6 => $size = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    7 => $kind = ' '.$kind.'_',
                    default => null,
                };
            }
            $kinds[] = $kind;
            $articles[] = $requested;
            $bytes[] = $size;
        }

        return [$kinds, $articles, $bytes, $uncommon ? $random->getInt(PHP_INT_MIN, PHP_INT_MAX) : $count * 6, $uncommon ? $random->getInt(-100, 100_000) : 60];
    }

    /**
     * Mapping confidences with PAR2, NFO and header evidence; uncommon ones are case names, padded or
     * underscored, NUL-holding or unknown values with extreme evidence and sizes.
     *
     * @return list<mixed>
     */
    private function mappingBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $values = $par2 = $nfo = $header = $sizes = [];
        for ($i = 0; $i < $count; $i++) {
            $value = $random->getInt(0, 2) === 0 ? 'unique-size' : 'verified';
            $p = $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, 3);
            $n = $random->getInt(0, 4) === 0 ? 1 : 0;
            $h = $random->getInt(0, 2);
            $size = $random->getInt(1_000_000, 4_000_000_000);
            if ($uncommon) {
                match ($random->getInt(0, 10)) {
                    0 => $value = $this->pick($random, ['Verified', 'UniqueSize']),
                    1 => $value = ' '.strtoupper(str_replace('-', '_', $value))."\n",
                    2 => $value = $value."\0",
                    3 => $value = 'guess-'.$i,
                    4 => $p = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    5 => $n = $random->getInt(-10, 1_000),
                    6 => $h = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    7 => $size = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    default => null,
                };
            }
            $values[] = $value;
            $par2[] = $p;
            $nfo[] = $n;
            $header[] = $h;
            $sizes[] = $size;
        }

        return [$values, $par2, $nfo, $header, $sizes];
    }

    /**
     * Media source candidates (videos, samples, archive volumes, images) with codecs; uncommon ones are
     * traversal, NUL-holding, empty, random or deep paths, odd codecs and extreme sizes and bounds.
     *
     * @return list<mixed>
     */
    private function sourceBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $paths = $sizes = $codecs = [];
        for ($i = 0; $i < $count; $i++) {
            $release = $this->pick($random, self::RELEASES);
            $path = match ($random->getInt(0, 7)) {
                0 => $release.'.part'.str_pad((string) $random->getInt(1, 40), $random->getInt(1, 3), '0', STR_PAD_LEFT).'.rar',
                1 => strtolower($release).'.sample.'.$this->pick($random, self::VIDEO_EXTS),
                2 => $release.'.'.$this->pick($random, ['jpg', 'png', '7z', 'zip', '001', 'nfo']),
                default => $release.'.'.$this->pick($random, self::VIDEO_EXTS),
            };
            if ($random->getInt(0, 3) === 0) {
                $path = $release.'/'.$path;
            }
            $size = $random->getInt(0, 9) === 0 ? $random->getInt(0, 2_000_000) : $random->getInt(20_000_000, 9_000_000_000);
            $codec = $this->pick($random, self::CODECS);
            if ($uncommon) {
                match ($random->getInt(0, 11)) {
                    0 => $path = '../'.$path,
                    1 => $path = $path."\0",
                    2 => $path = '',
                    3 => $path = substr($random->getBytes(40), 0, $random->getInt(0, 40)),
                    4 => $path = str_repeat('deep/', $random->getInt(50, 300)).strtoupper($path),
                    5 => $codec = $this->pick($random, ['vc1', 'mpeg2', ' VP9 ', "\tHEVC", "h264\0", 'theora']),
                    6 => $size = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    7 => $path = 'x\\..\\'.$path,
                    default => null,
                };
            }
            $paths[] = $path;
            $sizes[] = $size;
            $codecs[] = $codec;
        }

        return [$paths, $sizes, $codecs, $uncommon ? $random->getInt(PHP_INT_MIN, 50_000_000) : 1 << 20,
            $uncommon ? $random->getInt(0, PHP_INT_MAX) : PHP_INT_MAX];
    }

    /**
     * PHP routing, Rust routing, the PHP and native methods agree; returns the PHP result.
     *
     * @param  class-string  $class
     * @param  list<mixed>  $args
     * @return list<int>
     */
    private function assertParity(string $class, array $args): array
    {
        $method = self::WAVE[$class];
        $php = $class::withW60RustFfi(false, static fn (): array => $class::$method(...$args));
        $rust = $class::withW60RustFfi(true, static fn (): array => $class::$method(...$args));
        self::assertSame($php, $class::{$method.'Php'}(...$args), $class);
        self::assertSame($php, $class::{$method.'Native'}(...$args), $class);
        self::assertSame($php, $rust, $class);
        self::assertCount(self::HEADER[$class] + $php[0], $php, $class);

        return $php;
    }

    private function requireBindings(): void
    {
        if (ArchivedMediaEntry::summarizeArchivedEntryBatchNative([], [], [], [], []) === null
            || ReleaseProcessingResult::classifyResultBatchNative([], [], [], [], [], [], []) === null
            || DownloadKind::planDownloadKindBatchNative([], [], [], 0) === null
            || MappingConfidence::planMappingConfidenceBatchNative([], [], [], [], []) === null
            || MediaSourceResolver::resolveMediaSourceBatchNative([], [], []) === null
        ) {
            self::markTestSkipped('The Wave 60.6 Rust bindings are unavailable; build rust/pipeline-ffi in release mode.');
        }
    }
}
