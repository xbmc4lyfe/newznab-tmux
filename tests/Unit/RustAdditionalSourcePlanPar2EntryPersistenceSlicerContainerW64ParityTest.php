<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\DTO\MediaSourcePlan;
use App\Services\AdditionalProcessing\DTO\Par2FileEntry;
use App\Services\AdditionalProcessing\DTO\PersistenceMetrics;
use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Services\AdditionalProcessing\Media\ArchivedMediaSlicer;
use App\Services\AdditionalProcessing\Media\ContainerSplicer;
use App\Support\RustPipelineLibrary;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Wave 64.4 batch helpers: MediaSourcePlan media source rows (container and sample detection,
 * expected articles, head/tail segment windows under a byte budget), Par2FileEntry descriptor
 * rows (volume/archive/media role, hash16k class and prefix, 16k slice length, repeated names and
 * hashes), PersistenceMetrics write rollup rows (artifact kind, failures, repeated release kinds,
 * latency buckets and totals), ArchivedMediaSlicer stored-member rows (range bounds, in-volume
 * end, available bytes, segment span) and ContainerSplicer head/tail splice rows (Matroska
 * clusters, AVI movi/chunks, MPEG pack starts, transport stream packets and ISO box walks). Each
 * export takes a field-major NUL-joined blob (plus a 0x01 twin when a value holds a NUL) and
 * packed int64 columns; Rust writes fixed-width int64 rows and a batch trailer that PHP decodes
 * with one unpack('P*').
 *
 * Every helper keeps its routing override, cached binding, output buffer and declined-call count
 * in the function static of w64RustFfiState(); no class declares a new property.
 *
 * The paired benchmark (RUN_RUST_FFI_BENCHMARKS=1) times the PHP and native batch methods,
 * including validation, packing and decoding, over interleaved rounds of 320 records in random
 * order per round (fixed seed). Rounds per workload: W64_BENCH_ROUNDS (default 1000, minimum 60).
 * It requires every default-enabled helper to clear 1.5x on common and uncommon mean and p99,
 * asserts that helpers below 1.5x retain PHP by default, and skips without the native library.
 *
 * Decision: three runs at c62638c98 + this change (Apple Silicon, PHP 8.5.12 CLI, release build,
 * 1000 rounds per workload after 10 warmups, load average 6.1-8.7), minimum speedup per gate
 * (common mean/p99, uncommon mean/p99): w64MediaSourcePlanBatch 2.79/2.34, 2.49/2.16;
 * w64Par2FileEntryPlanBatch 3.74/3.09, 3.24/3.11; w64PersistenceMetricsPlanBatch 1.94/1.62,
 * 1.79/1.83; w64ArchivedMediaSlicerPlanBatch 2.63/2.31, 2.08/1.73; w64ContainerSplicerPlanBatch
 * 0.89/0.91, 0.89/0.86. Rust wins where PHP runs regexes, case folding, hashing and per-record
 * arithmetic; the splicer's PHP path is dominated by Zend's native strpos, and its native path
 * must copy every head and tail into the frame (twice when binary data holds NULs) before the
 * SWAR scans run, so it stays below 1.0x. Media source, PAR2 entry, persistence metrics and
 * archived media slicing default to Rust; container splicing retains PHP.
 */
final class RustAdditionalSourcePlanPar2EntryPersistenceSlicerContainerW64ParityTest extends TestCase
{
    /** [class, batch method, int64 words per record, trailing summary words]. */
    private const array HELPERS = [
        'media' => [MediaSourcePlan::class, 'w64MediaSourcePlanBatch', 5, 10],
        'par2' => [Par2FileEntry::class, 'w64Par2FileEntryPlanBatch', 5, 10],
        'persistence' => [PersistenceMetrics::class, 'w64PersistenceMetricsPlanBatch', 4, 16],
        'slicer' => [ArchivedMediaSlicer::class, 'w64ArchivedMediaSlicerPlanBatch', 6, 10],
        'splicer' => [ContainerSplicer::class, 'w64ContainerSplicerPlanBatch', 5, 9],
    ];

    /** W64_RUST_FFI_DEFAULT per class, from the paired benchmark decision. */
    private const array DEFAULTS = [
        MediaSourcePlan::class => true,
        Par2FileEntry::class => true,
        PersistenceMetrics::class => true,
        ArchivedMediaSlicer::class => true,
        ContainerSplicer::class => false,
    ];

    private const int SEED = 6442026;

    private const int PART = 716_800;

    private const string CLUSTER = "\x1F\x43\xB6\x75";

    private const string PACK = "\x00\x00\x01\xBA";

    private const array SPICE = ['é', 'ß', '日本', '😀', "\t", "\n", "\r", "\r\n", "\0", "\x01", "\x0b", "\x0c", "\x1f", "\x7f", "\xff", "\x80", "\xc3", "\xed\xa0\x80",
        ' ', '"', '.', '..', '/', '\\', '-', '_', '+', 'x', 'A', '0', '9', 'f', 'S', 'sample', '.mkv', '.r0', '.vol1', 'moov', 'mdat', "\x47", self::CLUSTER];

    private const array BASES = ['Show.Name.S01E02.1080p.WEB.H264-GRP', 'Movie.Title.2024.2160p.UHD.BluRay.x265-TEAM', 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        'Some Show - 1x05 - Episode Title', 'Film.(1999).DVDRip.XviD-OLD', 'Grp-Sample.Movie.720p', 'Ünïcödé.Fïlm.日本.2020'];

    private const array MEDIA_EXTENSIONS = ['.mkv', '.mkv', '.mkv', '.mp4', '.avi', '.ts', '.m2ts', '.wmv', '.mpg', '.MKV', '.mkv"', '.webm', '.ogm', '-sample.mkv', '.sample.mp4',
        '/Sample/s.mkv', '.nfo', '.srt', '.par2', '.jpg', ''];

    private const array PAR2_EXTENSIONS = ['.part01.rar', '.part12.rar', '.rar', '.r00', '.r17', '.R123', '.001', '.042', '.vol00+01.par2', '.vol031+32.PAR2', '.par2',
        '.mkv', '.mp4', '.avi', '.nfo', '.NFO', '.sfv', '.jpg', '.jpeg', '.png', '.7z', '.zip', '.mp3', '.flac', '-sample.mkv', '.srr', ''];

    private const array KINDS = ['media', 'media', 'audio', 'video', 'video', 'nfo', 'file', 'file', ' Video ', 'NFO', "file\n", 'other', ''];

    private const array ERRORS = ['', '', '', '', '', 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found', 'disk full', '  ', "Écriture échouée\n",
        'Manticore: connection refused'];

    private const array CONTAINERS = ['matroska', 'matroska', 'avi', 'mpeg', 'mpegts', 'mpegts', 'mov', 'mov', 'mov', 'asf', 'ogg', 'mkv', ''];

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            $class::resetW64RustFfi();
        }
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string, 2: int, 3: int, 4: string}> */
    public static function helperProvider(): array
    {
        $cases = [];
        foreach (self::HELPERS as $name => [$class, $method, $words, $trailer]) {
            $cases[$name] = [$class, $method, $words, $trailer, $name];
        }

        return $cases;
    }

    private static function state(string $class, mixed ...$args): mixed
    {
        return (new ReflectionMethod($class, 'w64RustFfiState'))->invoke(null, ...$args);
    }

    private static function native(string $class): bool
    {
        $cdef = (new ReflectionClass($class))->getConstant('W64_FFI_CDEF');
        self::assertIsString($cdef);

        return RustPipelineLibrary::resolveDomain('metadata_w64', $cdef) !== null;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<int>
     */
    private static function dispatch(string $class, string $method, bool $native, array $items): array
    {
        $result = $class::withW64RustFfi($native, static fn (): array => $class::{$method}($items));
        self::assertIsArray($result);
        self::assertTrue(array_is_list($result));

        return $result;
    }

    /** @param list<mixed> $items */
    private static function assertParity(string $class, string $method, int $words, int $trailer, array $items, string $label): void
    {
        $php = $class::{$method.'Php'}($items);
        self::assertSame($php, self::dispatch($class, $method, false, $items), $label.': PHP dispatch');
        self::assertSame($php, self::dispatch($class, $method, true, $items), $label.': Rust dispatch');
        self::assertSame($php, $class::{$method.'Native'}($items), $label.': native method');
        self::assertCount($items === [] ? 0 : \count($items) * $words + $trailer, $php, $label.': words per record');
        foreach ($php as $word) {
            self::assertIsInt($word);
        }
    }

    // ---------------------------------------------------------------------
    // Input generators
    // ---------------------------------------------------------------------

    /** @param list<mixed> $values */
    private static function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    private static function bytes(Randomizer $random, int $length): string
    {
        return $length <= 0 ? '' : $random->getBytes($length);
    }

    private static function extremeInt(Randomizer $random): int
    {
        return self::pick($random, [0, 1, -1, 2, 7, 8, 15, 16, 187, 188, 192, 999, 1000, 9_999, 10_000, 16_383, 16_384, 16_385, 99_999, 100_000, 999_999, 1_000_000,
            self::PART - 1, self::PART, self::PART + 1, 0xFFFF, 0x10000, 0xFFFFFF, 0x1000000, 1 << 30, (1 << 30) + 1, (1 << 40) - 1, 1 << 40, (1 << 40) + 1,
            1 << 62, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX - 1, PHP_INT_MIN + 1, $random->getInt(PHP_INT_MIN, PHP_INT_MAX), $random->getInt(-1000, 1000),
            $random->getInt(0, 1 << 41)]);
    }

    /** Insert, replace, delete or truncate a few bytes. */
    private static function mutate(Randomizer $random, string $value): string
    {
        for ($n = $random->getInt(1, 3); $n > 0; $n--) {
            $length = \strlen($value);
            $at = $length === 0 ? 0 : $random->getInt(0, $length - 1);
            $value = match ($random->getInt(0, 3)) {
                0 => substr($value, 0, $at).self::pick($random, self::SPICE).substr($value, $at),
                1 => substr($value, 0, $at).self::pick($random, self::SPICE).substr($value, $at + 1),
                2 => substr($value, 0, $at).substr($value, $at + 1),
                default => substr($value, 0, $at),
            };
        }

        return $value;
    }

    /** A realistic string, sometimes mutated, random or empty in uncommon batches. */
    private static function spoil(Randomizer $random, string $value, bool $uncommon): string
    {
        if (! $uncommon) {
            return $value;
        }

        return match ($random->getInt(0, 5)) {
            0, 1 => self::mutate($random, $value),
            2 => self::bytes($random, $random->getInt(0, 40)),
            3 => '',
            default => $value,
        };
    }

    private static function int(Randomizer $random, int $value, bool $uncommon): int
    {
        return $uncommon && $random->getInt(0, 3) === 0 ? self::extremeInt($random) : $value;
    }

    /** @param list<string> $extensions */
    private static function fileName(Randomizer $random, array $extensions): string
    {
        return self::pick($random, self::BASES).self::pick($random, $extensions);
    }

    /** @return list<mixed> */
    private static function mediaItem(Randomizer $random, bool $uncommon): array
    {
        $part = self::pick($random, [self::PART, self::PART, 768_000, 0]);
        $real = $random->getInt(0, 9) === 0 ? 0 : $random->getInt(20_000_000, 8_000_000_000);
        $articles = $random->getInt(0, 4) === 0 ? 0 : intdiv($real + ($part ?: self::PART) - 1, $part ?: self::PART) + $random->getInt(-3, 1);

        return [
            self::spoil($random, self::fileName($random, self::MEDIA_EXTENSIONS), $uncommon),
            self::int($random, $real, $uncommon),
            self::int($random, $articles, $uncommon),
            self::int($random, $part, $uncommon),
            self::int($random, $random->getInt(0, 6), $uncommon),
            self::int($random, self::pick($random, [0, 2_000_000, 10_000_000, 50_000_000, 200_000_000, 1_000_000_000]), $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function par2Batch(Randomizer $random, int $size, bool $uncommon): array
    {
        $items = [];
        while (\count($items) < $size) {
            $base = self::pick($random, self::BASES);
            for ($k = $random->getInt(1, 12); $k > 0 && \count($items) < $size; $k--) {
                $hash = match ($random->getInt(0, 9)) {
                    0 => '',
                    1 => strtoupper(md5($random->getBytes(8))),
                    2 => $items !== [] ? $items[array_key_last($items)][1] : md5('x'),
                    default => md5($random->getBytes(8)),
                };
                $items[] = [
                    self::spoil($random, $base.self::pick($random, self::PAR2_EXTENSIONS), $uncommon),
                    self::spoil($random, $hash, $uncommon),
                    self::int($random, $random->getInt(0, 20) === 0 ? 0 : $random->getInt(1, 400_000_000), $uncommon),
                ];
            }
        }

        return $items;
    }

    /** @return list<mixed> */
    private static function persistenceItem(Randomizer $random, bool $uncommon): array
    {
        return [
            self::spoil($random, self::pick($random, self::KINDS), $uncommon),
            self::spoil($random, self::pick($random, self::ERRORS), $uncommon),
            self::int($random, $random->getInt(0, 30) === 0 ? 0 : $random->getInt(1_000_000, 1_000_060), $uncommon),
            self::int($random, (int) (1000 * exp($random->getFloat(0, 9))), $uncommon),
            self::int($random, $random->getInt(0, 50), $uncommon),
            self::int($random, $random->getInt(0, 5) === 0 ? 0 : $random->getInt(100, 50_000_000), $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function slicerItem(Randomizer $random, bool $uncommon): array
    {
        $start = $random->getInt(0, 3) === 0 ? $random->getInt(20, 200) : $random->getInt(0, 60_000_000);
        $size = $random->getInt(1, 4_000_000_000);
        $range = match ($random->getInt(0, 9)) {
            0 => (string) $start,
            1 => $start.'-'.($start - 1),
            default => $start.'-'.($start + $size - 1),
        };

        return [
            self::spoil($random, self::fileName($random, self::MEDIA_EXTENSIONS), $uncommon),
            self::spoil($random, $range, $uncommon),
            self::int($random, $size, $uncommon),
            self::int($random, $random->getInt(0, 2) === 0 ? $start + $random->getInt(-100, 50_000_000) : -1, $uncommon),
            self::int($random, self::pick($random, [0, 768_000, 15_000_000, 50_000_000, 100_000_000]), $uncommon),
            self::int($random, self::pick($random, [self::PART, 768_000, 0]), $uncommon),
        ];
    }

    private static function box(string $type, string $body): string
    {
        return pack('N', 8 + \strlen($body)).$type.$body;
    }

    /** @return array{0: string, 1: string} */
    private static function headAndTail(Randomizer $random, string $container): array
    {
        $noise = static fn (int $min, int $max): string => self::bytes($random, $random->getInt($min, $max));
        $miss = $random->getInt(0, 9) === 0;

        return match ($container) {
            'matroska', 'mkv' => [
                "\x1A\x45\xDF\xA3".$noise(100, 900).($miss ? '' : self::CLUSTER).$noise(200, 800),
                $noise(0, 1500).self::CLUSTER.$noise(300, 2000),
            ],
            'avi' => [
                'RIFF'.pack('V', 99999).'AVI LIST'.pack('V', 600).'hdrl'.$noise(100, 700).'LIST'.pack('V', 99999).($miss ? 'mov!' : 'movi').'00dc'.$noise(50, 300),
                $noise(0, 1500).self::pick($random, ['00dc', '01wb', '00db']).$noise(300, 2000),
            ],
            'mpeg' => [
                self::PACK.$noise(200, 900),
                $noise(0, 1500).($miss ? '' : self::PACK).$noise(300, 2000),
            ],
            'mpegts' => self::transport($random, $miss),
            'mov' => [
                self::box('ftyp', 'isom'.str_repeat("\0", 12)).($random->getInt(0, 3) === 0 ? self::box('free', $noise(0, 40)) : '')
                    .($miss ? '' : self::box('moov', $noise(100, 1200))).pack('N', $random->getInt(0, 4) === 0 ? 0 : 5_000_000).'mdat'.$noise(100, 600),
                $noise(0, 1500).pack('N', $random->getInt(8, 9000)).self::pick($random, ['moof', 'mdat', 'free']).$noise(300, 2000),
            ],
            default => [$noise(100, 900), $noise(300, 2000)],
        };
    }

    /** @return array{0: string, 1: string} */
    private static function transport(Randomizer $random, bool $miss): array
    {
        $stride = $random->getInt(0, 3) === 0 ? 192 : 188;
        $packets = '';
        for ($k = $random->getInt(5, 12); $k > 0; $k--) {
            $packets .= "\x47".self::bytes($random, $stride - 1);
        }

        return [self::bytes($random, $random->getInt(0, 400)), ($miss ? '' : self::bytes($random, $random->getInt(0, 180))).$packets];
    }

    /** @return list<mixed> */
    private static function splicerItem(Randomizer $random, bool $uncommon): array
    {
        $container = self::pick($random, self::CONTAINERS);
        [$head, $tail] = self::headAndTail($random, $container);

        return [
            self::spoil($random, $container, $uncommon && $random->getInt(0, 2) === 0),
            self::spoil($random, $head, $uncommon),
            self::spoil($random, $tail, $uncommon),
            self::int($random, $random->getInt(0, 3) === 0 ? 188 * $random->getInt(0, 9_000_000) : $random->getInt(0, 4_000_000_000), $uncommon),
        ];
    }

    /** @return list<list<mixed>> */
    private static function batch(string $key, Randomizer $random, int $size, bool $uncommon): array
    {
        if ($key === 'par2') {
            return self::par2Batch($random, $size, $uncommon);
        }
        $items = [];
        for ($i = 0; $i < $size; $i++) {
            $items[] = match ($key) {
                'media' => self::mediaItem($random, $uncommon),
                'persistence' => self::persistenceItem($random, $uncommon),
                'slicer' => self::slicerItem($random, $uncommon),
                default => self::splicerItem($random, $uncommon),
            };
        }

        return $items;
    }

    /** @return list<list<mixed>> */
    private static function edgeItems(string $key): array
    {
        $max = PHP_INT_MAX;
        $min = PHP_INT_MIN;
        $nul = "a\0b";
        $hex = md5('edge');

        return match ($key) {
            'media' => [
                ['Movie.mkv', 10 * self::PART, 0, 0, 2, 4 * self::PART],
                ['Movie.mkv', 10 * self::PART, 0, 0, 2, 100 * self::PART],
                ['Movie.mkv', 10 * self::PART, 10, self::PART, 10, 0],
                ['Movie.mkv', 10 * self::PART, 10, self::PART, 0, 0],
                ['Movie.mkv', 10 * self::PART, 10, self::PART, 0, self::PART - 1],
                ['Movie.mkv', 0, 0, self::PART, 0, $max],
                ['Movie.sample.mkv', 1, 1, 1, 1, 1],
                ['sample', 1, 1, 1, 1, 1],
                ["x-SAMPLE\n", 1, 1, 1, 1, 1],
                ['x-samples.mkv', 1, 1, 1, 1, 1],
                ['readme.nfo', 1, 1, 1, 1, 1],
                [' movie.AVI" yEnc ', 1 << 40, 0, 1, 0, 1 << 40],
                ['a.nfo.mkv', $max, $max, $max, $max, $max],
                ['a.mkv.nfo', $min, $min, $min, $min, $min],
                ["a.mp4\x0bx", 5, 0, 2, -5, 3],
                ['a.mkv5', 1, 1, 1, 1, 1],
                ["\0 a.ts \x0b", 188, 0, 188, 0, 188],
                ['日本.webm', 123_456_789, 172, 716_800, 3, 5_000_000],
                ['', 0, 0, 0, 0, 0],
                [$nul.'.mkv', 1, 1, 1, 1, 1],
                ["Movie.mkv\0", 1_000, 2, 500, 1, 499],
            ],
            'par2' => [
                ['a.vol03+04.PAR2', $hex, 100],
                ['a.vol123456+4.par2', $hex, 100],
                ['a.vol1+123456.par2', strtoupper($hex), 16_383],
                ['a.part07.rar', strtoupper($hex), 16_384],
                ['a.part12345.rar', $hex, 16_385],
                ['a.r01', '', 0],
                ['a.R001', 'xyz', -1],
                ['a.001', $hex, $max],
                ['a.1234', $hex, $min],
                ['a.par2', $hex.'0', 1],
                ['a.mkv', substr($hex, 1).'G', 1],
                ['a.mkv', $hex, 1],
                ['a.sample.mkv', $hex, 1 << 40],
                ['a.JPEG', $hex, 5],
                ['a.nfo', $hex, 5],
                ['a.FLAC', $hex, 5],
                ['a.7Z', $hex, 5],
                ['a.zip', $hex, 5],
                ["a.r01\n", $hex, 5],
                ['.par2', $hex, 5],
                ['', '', 0],
                ['日本.mkv', 'ÄÖ', 1],
                [$nul, $nul, 1],
                ["x.rar\0", "\0".substr($hex, 1), 1],
            ],
            'persistence' => [
                ['nfo', '', 7, 500, 1, 10],
                ['NFO ', 'disk full', 7, 20_000, 2, 20],
                ['video', '', 7, 999, 0, 0],
                ['video', '  ', 8, 1_000, 0xFFFFFF, 1 << 40],
                ['audio', "\n", 0, 9_999, 0x1000000, (1 << 40) - 1],
                ['media', 'x', -1, 10_000, -1, -1],
                ['file', str_repeat('é', 3000), 9, 99_999, 3, 3],
                ['other', '', 9, 100_000, 3, 3],
                ['', '', 9, 999_999, 3, 3],
                [' FILE', 'err', 9, 1_000_000, 3, 3],
                ['Media', '', $max, $max, $max, $max],
                ['media', '', $min, $min, $min, $min],
                ['media', '', $max, 1 << 40, 1, 1],
                [$nul, $nul, 1, 1, 1, 1],
                ["nfo\0", "\0", 2, 2, 2, 2],
            ],
            'slicer' => [
                ['a.mkv', '100-1099', 1000, -1, 600, 256],
                ['a.mkv', '100-', 1000, 500, 600, 256],
                ['a.mkv', '100', 1000, 100, 600, 0],
                ['a.mkv', '0100-0099', 0, 101, 100, -1],
                ['a.sample.mkv', '0-99', 100, -1, 100, 1],
                ['a.nfo', '5-9', 5, -1, 0, 1 << 31],
                ['a.mkv', ' 5-9', 5, -1, 10, 1],
                ['a.mkv', '+5-9', 5, -1, 10, 1],
                ['a.mkv', '5--9', 5, -1, 10, 1],
                ['a.mkv', '999999999999999999-999999999999999999', $max, $max, $max, $max],
                ['a.mkv', '1000000000000000000-1', 1, 1, 1, 1],
                ['a.mkv', '-', $min, $min, $min, $min],
                ['b.avi', '1099511627775-1099511627776', 1 << 40, (1 << 40) + 5, 1 << 40, 3],
                ['日本.mp4', '7-7', 1, -1, 8, 7],
                ['', '', 0, 0, 0, 0],
                [$nul, $nul, 1, 1, 1, 1],
                ["c.mkv\0", "1\0-2", 1, 1, 1, 1],
            ],
            default => [
                ['matroska', "hdr\0".self::CLUSTER.'x', 'abc'.self::CLUSTER, 0],
                ['matroska', 'no cluster', self::CLUSTER, 0],
                ['avi', 'RIFF....AVI LIST....movi', 'xx1dc01wb', 5],
                ['avi', 'LISTmovi', '00dc', 0],
                ['avi', '1234LIS_....movi', '00dc', 0],
                ['mpeg', '', "x\0\0\x01\xBA", 0],
                ['mpeg', 'h', "\0\0\x01", 0],
                ['mpegts', '', str_repeat("\x47".str_repeat("\0", 187), 5), 188],
                ['mpegts', '', 'zz'.str_repeat("\x47".str_repeat("\1", 191), 5), 3],
                ['mpegts', '', str_repeat("\x47".str_repeat("\0", 187), 4), 0],
                ['mov', self::box('ftyp', 'isom').self::box('moov', 'abcd').pack('N', 0).'mdat', "xx\0\0\0\x07mdat\0\0\0\x08moof", 0],
                ['mov', pack('N', 1).'mdat'.pack('J', 16).self::box('moov', ''), "\0\0\0\x08mdat", 0],
                ['mov', pack('N', 1).'mdat'.pack('J', -1), 'free', 0],
                ['mov', pack('N', 1).'mdat'.pack('J', (1 << 62) + 1), '', 0],
                ['mov', pack('N', 1).'mdat'.pack('J', 15), "\0\0\0\x08skip", 0],
                ['mov', pack('N', 1).'mdat', "\0\0\0\x08skip", 0],
                ['mov', "\0\0\0\x04free", "\0\0\0\x09moov", 0],
                ['mov', self::box('free', '').self::box('moov', str_repeat('m', 100)), "\0\0\0\x08moov", 0],
                ['mov', self::box('moov', 'abc').'xy', "\0\0\0\x08moovmoof", $max],
                ['asf', 'x', 'y', $min],
                ['ogg', '', '', 0],
                ['Matroska', self::CLUSTER, self::CLUSTER, 0],
                ['', '', '', 0],
                [$nul, $nul, $nul, 1],
            ],
        };
    }

    // ---------------------------------------------------------------------
    // API contract
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_w64_switch_api_and_default(string $class, string $method, int $words, int $trailer, string $key): void
    {
        $reflection = new ReflectionClass($class);
        $constant = $reflection->getReflectionConstant('W64_RUST_FFI_DEFAULT');
        self::assertNotFalse($constant);
        self::assertTrue($constant->isPublic());
        self::assertIsBool($constant->getValue());
        $cdef = $reflection->getReflectionConstant('W64_FFI_CDEF');
        self::assertNotFalse($cdef);
        self::assertTrue($cdef->isPrivate());
        self::assertSame($class, $cdef->getDeclaringClass()->getName());
        self::assertStringContainsString('nntmux_w644_', (string) $cdef->getValue());
        self::assertSame($words, $reflection->getConstant('W64_ROW_WORDS'));
        self::assertSame($trailer, $reflection->getConstant('W64_TRAILER_WORDS'));
        foreach (['usesW64RustFfi', 'withW64RustFfi', 'enableW64RustFfi', 'disableW64RustFfi', 'resetW64RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            self::assertTrue($reflection->getMethod($name)->isPublic() && $reflection->getMethod($name)->isStatic(), $name);
        }
        $state = $reflection->getMethod('w64RustFfiState');
        self::assertTrue($state->isPrivate() && $state->isStatic());
        self::assertSame($class, $state->getDeclaringClass()->getName());

        self::assertSame($constant->getValue(), $class::usesW64RustFfi());
        self::assertFalse($class::withW64RustFfi(false, static fn (): bool => $class::usesW64RustFfi()));
        self::assertTrue($class::withW64RustFfi(true, static fn (): bool => $class::usesW64RustFfi()));
        self::assertSame($constant->getValue(), $class::usesW64RustFfi(), 'withW64RustFfi restores the default');

        $class::enableW64RustFfi();
        self::assertTrue($class::usesW64RustFfi());
        $class::disableW64RustFfi();
        self::assertFalse($class::usesW64RustFfi());
        self::assertTrue($class::withW64RustFfi(true, static fn (): bool => $class::usesW64RustFfi()));
        self::assertFalse($class::usesW64RustFfi(), 'withW64RustFfi restores an explicit override');
        $class::resetW64RustFfi();
        self::assertSame($constant->getValue(), $class::usesW64RustFfi());

        $nested = $class::withW64RustFfi(false, static fn (): array => [
            $class::usesW64RustFfi(),
            $class::withW64RustFfi(true, static fn (): bool => $class::usesW64RustFfi()),
            $class::usesW64RustFfi(),
        ]);
        self::assertSame([false, true, false], $nested);

        try {
            $class::withW64RustFfi(! $constant->getValue(), static function (): never {
                throw new RuntimeException('boom');
            });
            self::fail('The callback exception must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame($constant->getValue(), $class::usesW64RustFfi(), 'restored after an exception');
    }

    public function test_state_lives_in_function_statics(): void
    {
        foreach ([MediaSourcePlan::class, Par2FileEntry::class] as $readonly) {
            $reflection = new ReflectionClass($readonly);
            self::assertTrue($reflection->isReadOnly(), $readonly);
            self::assertSame([], $reflection->getProperties(\ReflectionProperty::IS_STATIC), $readonly);
        }
        foreach (self::HELPERS as [$class]) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w64', $property->getName(), $class);
            }
            self::state($class, 'enabled', true);
            self::assertTrue($class::usesW64RustFfi());
            self::state($class, 'enabled', false);
            self::assertFalse($class::usesW64RustFfi());
            self::assertNull(self::state($class, 'reset'));
            self::assertSame(['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0], self::state($class));
        }
        MediaSourcePlan::withW64RustFfi(false, static function (): void {
            foreach ([Par2FileEntry::class, PersistenceMetrics::class, ArchivedMediaSlicer::class, ContainerSplicer::class] as $other) {
                self::assertNull(self::state($other, 'enabled'), $other);
                self::assertSame(self::DEFAULTS[$other], $other::usesW64RustFfi(), $other);
            }
        });
    }

    public function test_defaults_follow_the_benchmark_decision(): void
    {
        foreach (self::DEFAULTS as $class => $enabled) {
            self::assertSame($enabled, $class::W64_RUST_FFI_DEFAULT, $class);
            self::assertSame($enabled, $class::usesW64RustFfi(), $class);
        }
    }

    public function test_existing_rust_ffi_toggles_are_independent(): void
    {
        $snapshot = static fn (): array => [MediaSourcePlan::usesRustFfi(), MediaSourcePlan::usesW48SlicePlanRustFfi(), MediaSourcePlan::usesW54RustFfi(),
            Par2FileEntry::usesRustFfi(), Par2FileEntry::usesW52RustFfi(), PersistenceMetrics::usesRustFfi(), PersistenceMetrics::usesW48WriteTelemetryRustFfi(),
            PersistenceMetrics::usesW53RustFfi(), ArchivedMediaSlicer::usesRustFfi(), ArchivedMediaSlicer::usesW50RustFfi(), ArchivedMediaSlicer::usesW55RustFfi(),
            ContainerSplicer::usesRustFfi(), ContainerSplicer::usesW50RustFfi(), ContainerSplicer::usesW55RustFfi()];
        $before = $snapshot();
        foreach ([false, true] as $enabled) {
            foreach (self::HELPERS as [$class]) {
                $class::withW64RustFfi($enabled, static function () use ($before, $snapshot): void {
                    self::assertSame($before, $snapshot());
                    self::assertNull((new MediaSourcePlan)->realSizeOfPhp(1));
                    self::assertSame([5, -1, -1], Par2FileEntry::volumePlanBatchPhp([new Par2FileEntry('a.par2', 1)]));
                    self::assertSame(2, PersistenceMetrics::summarizeDatabaseAndSearchLoadPhp(1, 2.0, 3, 1)['coalescedSearchSyncRequests']);
                    self::assertSame(self::PACK.'x', (new ContainerSplicer)->splicePhp('', 'y'.self::PACK.'x', VideoContainer::MpegProgram));
                    self::assertSame(0, ArchivedMediaSlicer::planSliceBatchPhp([], 0, 0, 0)[0]);
                });
            }
        }
        self::assertSame($before, $snapshot());
    }

    public function test_native_library_exports_every_symbol(): void
    {
        foreach (self::HELPERS as [$class]) {
            if (! self::native($class)) {
                self::markTestSkipped('The native pipeline library is not built.');
            }
            self::assertTrue(self::native($class), $class);
        }
    }

    #[DataProvider('helperProvider')]
    public function test_empty_batches_and_shape_errors(string $class, string $method, int $words, int $trailer, string $key): void
    {
        self::assertGreaterThan(0, $words + $trailer);
        foreach ([false, true] as $native) {
            self::assertSame([], self::dispatch($class, $method, $native, []));
        }
        $items = self::batch($key, new Randomizer(new Mt19937(self::SEED)), 3, false);
        $first = $items[0];
        $bad = [
            array_combine(['a', 'b', 'c'], $items),
            [1 => $first],
            [$first, 'not a record'],
            [\array_slice($first, 1)],
            [[...$first, 0]],
            [array_combine(range(1, \count($first)), $first)],
            [array_map(static fn (mixed $v): mixed => \is_int($v) ? (string) $v : 1.5, $first)],
            [array_map(static fn (mixed $v): mixed => \is_string($v) ? null : $v, $first)],
        ];
        foreach ($bad as $shape) {
            foreach (['Php', 'Native', ''] as $suffix) {
                try {
                    $class::{$method.$suffix}($shape);
                    self::fail($method.$suffix.' must reject a malformed batch');
                } catch (InvalidArgumentException $e) {
                    self::assertStringContainsString('W64', $e->getMessage());
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // Parity
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_randomized_and_edge_parity(string $class, string $method, int $words, int $trailer, string $key): void
    {
        $random = new Randomizer(new Mt19937(self::SEED + crc32($key)));
        $records = 0;
        foreach ([false, true] as $uncommon) {
            foreach ([1, 2, 7, 64, 320] as $size) {
                $items = self::batch($key, $random, $size, $uncommon);
                self::assertParity($class, $method, $words, $trailer, $items, $key.' batch '.$size.($uncommon ? ' uncommon' : ''));
                $records += $size;
            }
        }
        $edges = self::edgeItems($key);
        foreach ($edges as $i => $item) {
            self::assertParity($class, $method, $words, $trailer, [$item], $key.' edge '.$i.' '.json_encode($item, JSON_INVALID_UTF8_SUBSTITUTE));
        }
        self::assertParity($class, $method, $words, $trailer, $edges, $key.' edges together');
        self::assertGreaterThanOrEqual(300, $records + \count($edges));
        if (self::native($class)) {
            self::assertSame(0, self::state($class, 'misses'), $key.': every native call must succeed');
        }
    }

    #[DataProvider('helperProvider')]
    public function test_nul_bytes_use_the_twin_frame(string $class, string $method, int $words, int $trailer, string $key): void
    {
        $edges = self::edgeItems($key);
        $nul = array_values(array_filter($edges, static fn (array $item): bool => str_contains(implode('', array_filter($item, 'is_string')), "\0")));
        self::assertNotSame([], $nul);
        self::assertParity($class, $method, $words, $trailer, $nul, $key.' NUL-bearing values');
        if (self::native($class)) {
            self::assertSame(0, self::state($class, 'misses'), $key.': the 0x01 twin frames NUL-bearing values');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        foreach (self::HELPERS as $key => [$class, $method]) {
            $items = self::batch($key, new Randomizer(new Mt19937(self::SEED)), 9, true);
            $php = $class::{$method.'Php'}($items);
            self::state($class, 'ffi', false);
            self::assertSame($php, $class::{$method.'Native'}($items), $key);
            self::assertSame(0, self::state($class, 'misses'), $key.': a missing library is not a declined call');
            $class::resetW64RustFfi();
        }
    }

    public function test_hand_checked_rows(): void
    {
        $fnv = static function (string $value): int {
            /** @var array{1: int} $hash */
            $hash = unpack('N', hash('fnv1a32', $value, true));

            return $hash[1];
        };
        $part = self::PART;

        $media = MediaSourcePlan::w64MediaSourcePlanBatchPhp([
            ['Movie.MKV', 10 * $part, 0, 0, 2, 4 * $part],
            ['Movie.sample.mkv', 10 * $part, 10, $part, 0, 0],
        ]);
        self::assertSame([5 | 1 << 4 | 9 << 8 | 10 << 24, $fnv('movie.mkv'), 3 | 7 << 24, 4 * $part, 10], \array_slice($media, 0, 5));
        self::assertSame([2 | 1 << 4 | 128 | 16 << 8 | 10 << 24, $fnv('movie.sample.mkv'), 0 | 10 << 24, 0, 10], \array_slice($media, 5, 5));
        self::assertSame([2, 1, 1, 0, 0, 4 * $part, 6, 0, 0], \array_slice($media, 10, 9));

        $hex = md5('a');
        $par2 = Par2FileEntry::w64Par2FileEntryPlanBatchPhp([
            ['a.vol03+04.par2', $hex, 100],
            ['a.mkv', strtoupper($hex), 20_000],
        ]);
        self::assertSame([6 | 2 << 4 | 15 << 20 | 100 << 36, $fnv('a.vol03+04.par2'), (int) hexdec(substr($hex, 0, 15)), 100, 3 | 4 << 20], \array_slice($par2, 0, 5));
        self::assertSame([1 | 1 << 4 | 2 << 8 | 1 << 16 | 5 << 20 | 16_384 << 36, $fnv('a.mkv'), (int) hexdec(substr($hex, 0, 15)), 20_000, -1], \array_slice($par2, 5, 5));
        self::assertSame([2, 1, 0, 1, 0, 2, 0, 1, 20_100], \array_slice($par2, 10, 9));

        $persistence = PersistenceMetrics::w64PersistenceMetricsPlanBatchPhp([
            ['nfo', '', 7, 500, 1, 10],
            ['NFO ', ' disk full ', 7, 20_000, 2, 20],
        ]);
        self::assertSame([4 | 1 << 4 | 1 << 12, 0, 500, 0], \array_slice($persistence, 0, 4));
        self::assertSame([4 | 2 << 4 | 2 << 8 | 2 << 12 | 9 << 40, $fnv('disk full'), 20_000, 0], \array_slice($persistence, 4, 4));
        self::assertSame([2, 0, 0, 0, 0, 2, 0, 1, 1, 1, 20_500, 20_000, 1, 3, 30], \array_slice($persistence, 8, 15));

        $slicer = ArchivedMediaSlicer::w64ArchivedMediaSlicerPlanBatchPhp([
            ['a.mkv', '100-1099', 1000, -1, 600, 256],
            ['a.sample.mkv', '700-', 10, 705, 600, 256],
        ]);
        self::assertSame([32 | 1 << 8 | 5 << 12, 100, 1099, 1099, 500, 0], \array_slice($slicer, 0, 6));
        self::assertSame([2 | 8 | 16 | 1 << 8 | 1 << 12, 700, -1, 704, 0, 2], \array_slice($slicer, 6, 6));
        self::assertSame([2, 1, 1, 1, 1, 500, 0, 1000, 1], \array_slice($slicer, 12, 9));

        $splicer = ContainerSplicer::w64ContainerSplicerPlanBatchPhp([
            ['matroska', 'ab'.self::CLUSTER, 'xyz'.self::CLUSTER.'tail', 10],
            ['mov', self::box('ftyp', 'isom').self::box('moov', 'abcd'), "\0\0\0\x08moof", 0],
            ['ogg', 'h', 't', 0],
        ]);
        self::assertSame([1 | 1 << 4 | 6 << 32, 2, 3, 2 + 11 - 3, 13], \array_slice($splicer, 0, 5));
        self::assertSame([1 | 5 << 4 | (1 | 2 | 8) << 8 | 2 << 16 | 24 << 32, 24, 0, 32, 0], \array_slice($splicer, 5, 5));
        self::assertSame([2 | 7 << 4 | 1 << 32, -1, -1, 0, -1], \array_slice($splicer, 10, 5));
        self::assertSame([3, 2, 0, 1, 42, 32, 1, 1], \array_slice($splicer, 15, 8));
    }

    // ---------------------------------------------------------------------
    // Paired benchmark
    // ---------------------------------------------------------------------

    public function test_paired_benchmark(): void
    {
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            self::markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the paired benchmark.');
        }
        $rounds = max(60, (int) (getenv('W64_BENCH_ROUNDS') ?: 1000));
        $random = new Randomizer(new Mt19937(self::SEED));
        $report = [];
        $failures = [];
        foreach (self::HELPERS as $key => [$class, $method]) {
            if (! self::native($class)) {
                self::markTestSkipped('The native pipeline library is not built.');
            }
            $ratios = [];
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $php = [];
                $rust = [];
                for ($round = -10; $round < $rounds; $round++) {
                    $items = self::batch($key, $random, 320, $uncommon);
                    $order = $random->getInt(0, 1) === 0 ? ['Php', 'Native'] : ['Native', 'Php'];
                    $times = [];
                    foreach ($order as $suffix) {
                        $start = hrtime(true);
                        $class::{$method.$suffix}($items);
                        $times[$suffix] = hrtime(true) - $start;
                    }
                    if ($round >= 0) {
                        $php[] = $times['Php'];
                        $rust[] = $times['Native'];
                    }
                }
                sort($php);
                sort($rust);
                $p99 = (int) ceil(0.99 * $rounds) - 1;
                $ratios[$workload.' mean'] = array_sum($php) / max(1, array_sum($rust));
                $ratios[$workload.' p99'] = $php[$p99] / max(1, $rust[$p99]);
            }
            $report[] = \sprintf('%s::%s %s', $key, $method, implode(', ', array_map(
                static fn (string $label, float $ratio): string => \sprintf('%s %.2fx', $label, $ratio), array_keys($ratios), $ratios)));
            // A default-enabled helper below the gate fails; one below it must retain PHP.
            if ($class::usesW64RustFfi() && min($ratios) < 1.5) {
                $failures[] = $key.' min '.round(min($ratios), 2).' must retain PHP by default below 1.5x';
            }
        }
        fwrite(STDERR, "\nW64.4 paired benchmark ({$rounds} rounds x 320 records per workload):\n".implode("\n", $report)."\n");
        self::assertSame([], $failures, 'Default-enabled helpers must clear 1.5x on every gate.');
    }
}
