<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\AdminDashboardSnapshotService;
use App\Services\AnidbService;
use App\Services\ArchiveProcessingService;
use App\Services\BlacklistService;
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
 * Wave 65.4 batch helpers: UsenetDownloadService download plan rows (message-ID validation and
 * de-duplication, BODY batching, retry/alternate-NNTP fallback, byte budget and yEnc assembly
 * size), AdminDashboardSnapshotService KPI rows (delta, change and utilization basis points,
 * threshold and service health), AnidbService match rows (title normalization, base title,
 * episode and year tokens, romaji/english/kanji Levenshtein similarity), ArchiveProcessingService
 * inspection rows (signature/extension kind, password detection, member classes, nested first
 * volumes) and BlacklistService rule rows (status, rule group, column, literal pattern parsing
 * and matching, first hit per rule). Each export takes a field-major NUL-joined blob (plus a 0x01
 * twin when a value holds a NUL) and packed int64 columns; Rust writes fixed-width int64 rows and
 * a batch trailer that PHP decodes with one unpack('P*').
 *
 * Every helper keeps its routing override, cached binding, output buffer and declined-call count
 * in the function static of w65RustFfiState(); no class declares a new property.
 *
 * The paired benchmark (RUN_RUST_FFI_BENCHMARKS=1) times the PHP and native batch methods,
 * including validation, packing and decoding, over interleaved rounds of 320 records in random
 * order per round (fixed seed). Rounds per workload: W65_BENCH_ROUNDS (default 1000, minimum 60).
 * It requires every default-enabled helper to clear 1.5x on common and uncommon mean and p99,
 * asserts that helpers below 1.5x retain PHP by default, and skips without the native library.
 *
 * Decision: three runs at 2a50c0765 + this change (Apple Silicon, PHP 8.5.12 CLI, release build,
 * 1000 rounds per workload after 10 warmups, load average 4.8-5.7), minimum speedup per gate
 * (common mean/p99, uncommon mean/p99): w65UsenetDownloadPlanBatch 2.53/2.43, 2.25/2.25;
 * w65AdminDashboardSnapshotPlanBatch 2.14/2.14, 1.84/1.77; w65AnidbMatchPlanBatch 2.45/2.70,
 * 2.37/2.37; w65ArchiveProcessingPlanBatch 3.94/3.42, 3.11/2.68; w65BlacklistEvalPlanBatch
 * 1.80/1.80, 0.93/0.94. Rust wins where PHP runs regexes, case folding, hashing, Levenshtein
 * and per-record arithmetic. Uncommon blacklist batches mostly end at the cheap status, group,
 * column and pattern checks, so PHP does little work per record while the native path still
 * packs six string columns (and a 0x01 twin when spoiled values hold NULs) and four int columns.
 * Usenet download, admin snapshot, AniDB match and archive processing default to Rust; blacklist
 * evaluation retains PHP.
 */
final class RustUsenetDownloadAdminSnapshotAnidbArchiveBlacklistW65ParityTest extends TestCase
{
    /** [class, batch method, int64 words per record, trailing summary words]. */
    private const array HELPERS = [
        'usenet' => [UsenetDownloadService::class, 'w65UsenetDownloadPlanBatch', 5, 13],
        'admin' => [AdminDashboardSnapshotService::class, 'w65AdminDashboardSnapshotPlanBatch', 4, 13],
        'anidb' => [AnidbService::class, 'w65AnidbMatchPlanBatch', 5, 12],
        'archive' => [ArchiveProcessingService::class, 'w65ArchiveProcessingPlanBatch', 4, 12],
        'blacklist' => [BlacklistService::class, 'w65BlacklistEvalPlanBatch', 3, 12],
    ];

    /** W65_RUST_FFI_DEFAULT per class, from the paired benchmark decision. */
    private const array DEFAULTS = [
        UsenetDownloadService::class => true,
        AdminDashboardSnapshotService::class => true,
        AnidbService::class => true,
        ArchiveProcessingService::class => true,
        BlacklistService::class => false,
    ];

    private const int SEED = 6542026;

    private const int PART = 716_800;

    private const array SPICE = ['é', 'ß', '日本', '😀', "\t", "\n", "\r", "\r\n", "\0", "\x01", "\x0b", "\x0c", "\x1f", "\x7f", "\xff", "\x80", "\xc3", "\xed\xa0\x80",
        ' ', '"', '.', '..', '/', '\\', '-', '_', '|', '^', '$', '*', '(', ')', '[', ']', '<', '>', '@', ',', 'x', 'A', '0', '9', 'e05', ' - 12 ', '.rar', '.part01'];

    private const array GROUPS = ['alt.binaries.teevee', 'alt.binaries.teevee', 'alt.binaries.moovee', 'Alt.Binaries.HDTV ', 'alt.binaries.anime', ''];

    private const array NNTP_ERRORS = ['', '', '', '', '411 No such news group', '430 No Such Article', 'Group not found: alt.binaries.x', 'Connection timed out', ' ',
        '411 NO SUCH NEWS GROUP'];

    private const array KINDS = ['releases', 'groups', 'users', 'queue', 'queue', 'storage', 'storage', 'failed', 'reported', ' Queue ', 'STORAGE', 'other', ''];

    private const array SERVICES = ['operational', 'operational', 'operational', 'degraded', 'partial_outage', 'major_outage', 'maintenance', '', 'Unknown', ' Degraded '];

    private const array ANIME = [
        ['Shingeki no Kyojin', 'Attack on Titan', '進撃の巨人', 2013, 25],
        ['Boku no Hero Academia', 'My Hero Academia', '僕のヒーローアカデミア', 2016, 13],
        ['Kimetsu no Yaiba', 'Demon Slayer: Kimetsu no Yaiba', '鬼滅の刃', 2019, 26],
        ['Sousou no Frieren', "Frieren: Beyond Journey's End", '葬送のフリーレン', 2023, 28],
        ['Spy x Family', '', 'スパイファミリー', 2022, 12],
        ['Mob Psycho 100', 'Mob Psycho 100', 'モブサイコ100', 2016, 12],
    ];

    private const array ARCHIVE_EXTENSIONS = ['.rar', '.rar', '.part01.rar', '.part02.rar', '.part001.rar', '.r00', '.7z', '.zip', '.001', '.002', '.nzb', '.RAR', ''];

    private const array MEMBERS = ['Movie.2024.1080p.mkv', 'Sample/sample.mkv', 'cd1/inner.part01.rar', 'inner.part02.rar', 'inner.r00', 'cover.jpg', 'info.nfo', 'track01.flac',
        'x.vol00+01.par2', 'nested.7z', 'nested.ZIP', 'a.001', 'a.002', 'readme.txt', 'noext', " spaced.mp4 \r", 'dir.d/file', '日本.webm', 'x.'];

    private const array ARCHIVE_ERRORS = ['', '', '', '', 'Password required', 'File is ENCRYPTED', 'Corrupt header', ' ', "Unexpected end\n"];

    private const array SUBJECTS = ['[01/50] - "Show.Name.S01E02.1080p.WEB.H264-GRP.part01.rar" yEnc (1/100)', 'Movie.Title.2024.2160p.UHD.BluRay.x265-TEAM - [12/40] - "mt.r10" yEnc',
        '(German) Film.1999.DVDRip.XviD - "film.nfo" yEnc (1/1)', 'Ünïcödé 日本 - "a.sample.mkv" yEnc', 'password protected - "x.7z" yEnc'];

    private const array POSTERS = ['poster@example.com (Poster)', 'Yenc@power-post.org (Yenc-PP-GUI)', 'anon <a@b.c>', 'NZBGeek <geek@nzb.invalid>'];

    private const array PATTERNS = ['x264', 'h264-grp', '^\[01/', 'yEnc \(1/100\)$', 'WEB|HDTV|DVDRip', 'power-post\.org \(Yenc-PP-GUI\)$', '^anon', '.*sample.*', '\d{3}',
        'foo||bar', '', 'German', 'part01\.rar', '^password protected - "x\.7z" yEnc$', 'GEEK', 'news\.example$', '(?i)x', 'a\\'];

    private const array RULE_GROUPS = ['', '', '*', 'alt.binaries.*', 'alt.binaries.teevee', 'ALT.BINARIES.MOOVEE', 'alt.binaries.other', 'alt.*', ' alt.binaries.teevee '];

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            $class::resetW65RustFfi();
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
        return (new ReflectionMethod($class, 'w65RustFfiState'))->invoke(null, ...$args);
    }

    private static function native(string $class): bool
    {
        $cdef = (new ReflectionClass($class))->getConstant('W65_FFI_CDEF');
        self::assertIsString($cdef);

        return RustPipelineLibrary::resolveDomain('metadata_w65', $cdef) !== null;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<int>
     */
    private static function dispatch(string $class, string $method, bool $native, array $items): array
    {
        $result = $class::withW65RustFfi($native, static fn (): array => $class::{$method}($items));
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
        return self::pick($random, [0, 1, -1, 2, 3, 4, 19, 20, 21, 127, 128, 255, 256, 999, 1000, 1899, 1900, 2099, 2100, 9999, 10_000, 0xFFFF, 0x10000,
            self::PART - 1, self::PART, self::PART + 1, 1 << 30, (1 << 30) + 1, (1 << 40) - 1, 1 << 40, (1 << 40) + 1, 1 << 62, PHP_INT_MAX, PHP_INT_MIN,
            PHP_INT_MAX - 1, PHP_INT_MIN + 1, $random->getInt(PHP_INT_MIN, PHP_INT_MAX), $random->getInt(-1000, 1000), $random->getInt(0, 1 << 41)]);
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

    /** @return list<mixed> */
    private static function usenetItem(Randomizer $random, bool $uncommon): array
    {
        $ids = [];
        $prefix = bin2hex($random->getBytes(4));
        for ($k = $random->getInt(0, 9) === 0 ? 0 : $random->getInt(1, 30); $k > 0; $k--) {
            $n = $random->getInt(1, 40);
            $ids[] = $random->getInt(0, 1) === 0 ? '<part'.$n.'of40.'.$prefix.'@news.example.com>' : 'part'.$n.'of40.'.$prefix.'@news.example.com';
        }

        return [
            self::spoil($random, implode(self::pick($random, [' ', ' ', "\n", ',', ', ', "\r\n"]), $ids), $uncommon),
            self::spoil($random, self::pick($random, self::GROUPS), $uncommon),
            self::spoil($random, self::pick($random, self::NNTP_ERRORS), $uncommon),
            self::int($random, self::pick($random, [self::PART, self::PART, 768_000, 500_000, 0]), $uncommon),
            self::int($random, self::pick($random, [0, 0, 2_000_000, 10_000_000, 50_000_000, 1_000_000_000]), $uncommon),
            self::int($random, $random->getInt(0, 4), $uncommon),
            self::int($random, $random->getInt(0, 1), $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function adminItem(Randomizer $random, bool $uncommon): array
    {
        $current = $random->getInt(0, 9) === 0 ? 0 : $random->getInt(1, 5_000_000);
        $previous = $random->getInt(0, 9) === 0 ? 0 : max(0, $current + $random->getInt(-$current, $current) >> 2);

        return [
            self::spoil($random, self::pick($random, self::KINDS), $uncommon),
            self::spoil($random, self::pick($random, self::SERVICES), $uncommon),
            self::int($random, $current, $uncommon),
            self::int($random, $previous, $uncommon),
            self::int($random, self::pick($random, [0, 500, 1_000, 5_000, 8_000, 100_000]), $uncommon),
            self::int($random, self::pick($random, [0, 2_000, 9_000, 9_500, 1_000_000]), $uncommon),
            self::int($random, self::pick($random, [0, 5_000_000, 10_000_000, 1_000_000_000_000]), $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function anidbItem(Randomizer $random, bool $uncommon): array
    {
        $anime = self::pick($random, self::ANIME);
        $other = $random->getInt(0, 4) === 0 ? self::pick($random, self::ANIME) : $anime;
        $episode = $random->getInt(1, 30);
        $pad = \sprintf('%02d', $episode);
        $release = match ($random->getInt(0, 6)) {
            0 => '[SubsPlease] '.$anime[0].' - '.$pad.' (1080p) [ABCD1234].mkv',
            1 => str_replace([' ', ':', "'"], ['.', '', ''], $anime[1] ?: $anime[0]).'.S01E'.$pad.'.1080p.WEB.H264-GRP',
            2 => $anime[0].' ('.$anime[3].') - '.$episode.' [BD 1080p HEVC FLAC]',
            3 => '[Grp] '.$anime[2].' 第'.$episode.'話 [720p]',
            4 => $anime[0].' '.$anime[3].' Vol.2 BDRip x265',
            5 => '['.$anime[0].'] - Ep'.$pad.' [Multi-Subs]',
            default => '(C99) '.$anime[1].' Ep '.$episode.' - Episode Title',
        };

        return [
            self::spoil($random, $release, $uncommon),
            self::spoil($random, $other[0], $uncommon),
            self::spoil($random, $other[1], $uncommon),
            self::spoil($random, $other[2], $uncommon),
            self::int($random, $random->getInt(0, 2) === 0 ? 0 : $anime[3] + $random->getInt(-2, 2), $uncommon),
            self::int($random, $random->getInt(0, 9) === 0 ? 0 : $other[3], $uncommon),
            self::int($random, $random->getInt(0, 5) === 0 ? 0 : $other[4], $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function archiveItem(Randomizer $random, bool $uncommon): array
    {
        $members = [];
        for ($k = $random->getInt(0, 9) === 0 ? 0 : $random->getInt(1, 14); $k > 0; $k--) {
            $members[] = self::pick($random, self::MEMBERS);
        }
        $head = self::pick($random, ["Rar!\x1a\x07\x00", "Rar!\x1a\x07\x01\x00", "7z\xBC\xAF\x27\x1C", "PK\x03\x04", 'Rar!', '']).self::bytes($random, $random->getInt(0, 24));

        return [
            self::spoil($random, 'Show.Name.S01E02.1080p-GRP'.self::pick($random, self::ARCHIVE_EXTENSIONS), $uncommon),
            self::spoil($random, $head, $uncommon),
            self::spoil($random, implode(self::pick($random, ["\n", "\n", "\r\n", "\n\n"]), $members), $uncommon),
            self::spoil($random, self::pick($random, self::ARCHIVE_ERRORS), $uncommon),
            self::int($random, $random->getInt(0, 9) === 0 ? 1 : 0, $uncommon),
            self::int($random, $random->getInt(0, 9) === 0 ? 0 : $random->getInt(100, 50_000_000), $uncommon),
            self::int($random, $random->getInt(0, 3), $uncommon),
            self::int($random, $random->getInt(0, 3), $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function blacklistItem(Randomizer $random, bool $uncommon): array
    {
        return [
            self::spoil($random, self::pick($random, self::SUBJECTS), $uncommon),
            self::spoil($random, self::pick($random, self::POSTERS), $uncommon),
            self::spoil($random, '<part'.$random->getInt(1, 99).'of99.'.bin2hex($random->getBytes(3)).'@news.example>', $uncommon),
            self::spoil($random, self::pick($random, self::GROUPS), $uncommon),
            self::spoil($random, self::pick($random, self::PATTERNS), $uncommon),
            self::spoil($random, self::pick($random, self::RULE_GROUPS), $uncommon),
            self::int($random, self::pick($random, [1, 1, 1, 2, 3]), $uncommon),
            self::int($random, self::pick($random, [1, 1, 2]), $uncommon),
            self::int($random, $random->getInt(0, 9) === 0 ? 0 : 1, $uncommon),
            self::int($random, $random->getInt(1, 40), $uncommon),
        ];
    }

    /** @return list<list<mixed>> */
    private static function batch(string $key, Randomizer $random, int $size, bool $uncommon): array
    {
        $items = [];
        for ($i = 0; $i < $size; $i++) {
            $items[] = match ($key) {
                'usenet' => self::usenetItem($random, $uncommon),
                'admin' => self::adminItem($random, $uncommon),
                'anidb' => self::anidbItem($random, $uncommon),
                'archive' => self::archiveItem($random, $uncommon),
                default => self::blacklistItem($random, $uncommon),
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
        $part = self::PART;

        return match ($key) {
            'usenet' => [
                ['<a@b> <c@d>,<a@b> bad', 'Alt.Bin', '', 0, $part, 0, 0],
                ['<<a@b>>', ' alt.binaries.x ', '411 No such news group', $part, 0, 0, 1],
                ['a@b', '', 'group NOT FOUND', $part, 0, 0, 0],
                ['a@b', '', '430 no article', $part, 0, 2, 0],
                ['a@b', '', '430 no article', $part, 0, 3, 0],
                ['a@b c@d e@f', '', '', 10, 25, 0, 0],
                ['a@b', '', '', $part, $part - 1, 0, 0],
                ['<> @b a@ a@b@c a b', 'x', '', 1, 1, 1, 1],
                [str_repeat('x', 248).'@b', '', '', $max, $max, $max, $max],
                [str_repeat('x', 249).'@b', '', '', $min, $min, $min, $min],
                ["a\x0b@b a\x7f@b é@日本 a\xff@\x80", '日本', "\n", 1 << 30, 1 << 40, -1, -1],
                [" \t\r\n,,", '', '', 0, 0, 0, 0],
                ['', '', '', 0, 0, 0, 0],
                [$nul.'@x a@b', $nul, $nul, 1, 1, 1, 1],
                ["a@b\0", "\0", "No such news group\0", 2, 2, 2, 2],
            ],
            'admin' => [
                ['queue', 'degraded', 150, 100, 100, 200, 0],
                ['releases', 'operational', 50, 100, 1_000, 4_000, 0],
                ['releases', '', 0, 0, 1, 1, 0],
                ['users', 'major_outage', 10, 0, 0, 0, 0],
                ['storage', 'maintenance', 90, 0, 8_000, 9_000, 100],
                ['storage', '', 90, 0, 8_000, 9_000, 0],
                [' STORAGE ', 'Partial_Outage', 1 << 40, 1, 1, 1, 1],
                ['failed', 'weird', 5, 5, 5, 6, 5],
                ['reported', 'operational', $max, $max, $max, $max, $max],
                ['groups', 'operational', $min, $min, $min, $min, $min],
                ['other', 'degraded', 1, 2, 3, 4, 5],
                ['', '', 0, 0, 0, 0, 0],
                ['日本', 'é', 1, 1, 1, 1, 1],
                [$nul, $nul, 1, 2, 3, 4, 5],
                ["queue\0", "degraded\0", 7, 3, 1, 9, 0],
            ],
            'anidb' => [
                ['[Grp] Show Name - 05 [1080p]', 'Show Name', '', 'Shou Name', 0, 2019, 12],
                ['Show.Name.2019.S01E12.1080p', 'show name', '', '', 0, 2020, 12],
                ['Show Name 2019 ep0013', 'Show Name', '', '', 2030, 2019, 12],
                ['1080p Show', 'Show', '', '', 0, 0, 0],
                ['[a] [b] (c)', 'x', 'y', 'z', 0, 0, 0],
                ['a[b (c', 'a b c', '', '', 1, 1, 1],
                ['進撃の巨人 第05話', '', '', '進撃の巨人 第05話', 2013, 2013, 25],
                ['Title s123e4 e12345 episode', 'title', '', '', 0, 0, 0],
                ['Title 00', 'Title', '', '', 0, 0, 5],
                ['Title 1899 2100 0001 99999', 'Titl', 'Title', 'Title', $max, $max, $max],
                ['Title', 'Title', 'Title', 'Title', $min, $min, $min],
                ['', '', '', '', 0, 0, 0],
                ['Ünïcödé Fïlm', 'ÜNÏCÖDÉ FÏLM', "\xff\x80", '😀', 1, 1, 1],
                [$nul, $nul, $nul, $nul, 1, 1, 1],
                ["Show\0Name - 03", "Show\0Name", '', '', 0, 0, 3],
            ],
            'archive' => [
                ['a.rar', "Rar!\x1a\x07\x00", "x.mkv\n\ny.part01.rar\r\ny.part02.rar", '', 0, 10, 0, 2],
                ['a.rar', "Rar!\x1a\x07\x01\x00", 'y.part01.rar', '', 0, 10, 2, 2],
                ['a.zip', "Rar!\x1a\x07\x00", 'a.txt', '', 0, 0, 0, 0],
                ['a.7z', "7z\xBC\xAF\x27\x1C", 'c.jpg', 'Wrong PASSWORD', 0, 1, 0, 0],
                ['a.001', 'PK', 'c.flac', 'crc error', 0, 1, 0, 0],
                ['a.R07', '', '', '', 1, 1, 0, 0],
                ['a.part0.rar', 'PK', "a.part0.rar\na.part000.rar\na.part010.rar\na.PART001.RAR\na.part.rar", '', 0, $max, $max, $max],
                ['noext', '', "dir.d/file\nx.\n.rar\nx.1234\nx.r1", '', 0, $min, $min, $min],
                ['日本.rar', 'Rar!', "日本.mkv\nÜ.nfo", '', 0, 1 << 40, 255, 256],
                ['', '', '', '', 0, 0, 0, 0],
                ['a.zip', "PK\x03\x04", " \t\n\r\n", '', 0, 1, 0, 1],
                [$nul, $nul, $nul, '', 0, 1, 0, 1],
                ["a\0.zip", "\0", "\0x.nfo", "\0", 0, 0, 0, 0],
            ],
            default => [
                ['Some.Movie.x264', 'poster', 'id@x', 'alt.binaries.x', 'foo|X264', 'alt.*', 1, 1, 1, 9],
                ['x', 'y', 'z', 'alt.binaries.x', '(a)', '', 1, 2, 1, 9],
                ['Some.Movie.x264', 'poster', 'id@x', 'alt.binaries.x', 'x264', '', 1, 1, 1, 9],
                ['a', 'Yenc@Power-Post.org', 'm', 'g', 'power-post\.org$', '*', 2, 2, 1, 3],
                ['a', 'b', '<ID@x>', 'g', '^<id@x>$', 'G', 3, 1, 1, 4],
                ['a', 'b', '<ID@x>', 'g', '^<id', 'h', 3, 1, 1, 4],
                ['a', 'b', 'c', 'g', 'c', 'g', 4, 1, 1, 5],
                ['a', 'b', 'c', 'g', 'c', 'g', 1, 3, 1, 5],
                ['a', 'b', 'c', 'g', '', 'g', 1, 1, 1, 5],
                ['a', 'b', 'c', 'g', 'a', 'g', 1, 1, 0, 5],
                ['', 'b', 'c', 'g', 'a|^b', 'g', 1, 1, 1, 6],
                ['', 'b', 'c', 'g', 'z', 'g*', 1, 1, 1, 6],
                ['ab', 'b', 'c', 'g', '^$', 'g', 1, 1, 1, 6],
                ['a$b', 'b', 'c', 'g', 'a\$b', 'g', 1, 1, 1, 7],
                ['a$b', 'b', 'c', 'g', 'a$b', 'g', 1, 1, 1, 7],
                ['ÄB日本', 'b', 'c', 'g', 'äb日本', 'g', 1, 2, 1, 8],
                ['x', 'y', 'z', 'g', 'X', 'g', $max, $max, $max, $max],
                ['x', 'y', 'z', 'g', 'X', 'g', $min, $min, $min, $min],
                ['x', 'y', 'z', 'g', 'X', 'g', 1, 1, 1, $min],
                [$nul, $nul, $nul, $nul, $nul, $nul, 1, 1, 1, 1],
                ["sub\0ject", '', '', 'g', "\0j", '', 1, 1, 1, 2],
            ],
        };
    }

    // ---------------------------------------------------------------------
    // API contract
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_w65_switch_api_and_default(string $class, string $method, int $words, int $trailer, string $key): void
    {
        $reflection = new ReflectionClass($class);
        $constant = $reflection->getReflectionConstant('W65_RUST_FFI_DEFAULT');
        self::assertNotFalse($constant);
        self::assertTrue($constant->isPublic());
        self::assertIsBool($constant->getValue());
        $cdef = $reflection->getReflectionConstant('W65_FFI_CDEF');
        self::assertNotFalse($cdef);
        self::assertTrue($cdef->isPrivate());
        self::assertSame($class, $cdef->getDeclaringClass()->getName());
        self::assertStringContainsString('nntmux_w654_', (string) $cdef->getValue());
        self::assertSame($words, $reflection->getConstant('W65_ROW_WORDS'));
        self::assertSame($trailer, $reflection->getConstant('W65_TRAILER_WORDS'));
        foreach (['usesW65RustFfi', 'withW65RustFfi', 'enableW65RustFfi', 'disableW65RustFfi', 'resetW65RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            self::assertTrue($reflection->getMethod($name)->isPublic() && $reflection->getMethod($name)->isStatic(), $name);
        }
        $state = $reflection->getMethod('w65RustFfiState');
        self::assertTrue($state->isPrivate() && $state->isStatic());
        self::assertSame($class, $state->getDeclaringClass()->getName());

        self::assertSame($constant->getValue(), $class::usesW65RustFfi());
        self::assertFalse($class::withW65RustFfi(false, static fn (): bool => $class::usesW65RustFfi()));
        self::assertTrue($class::withW65RustFfi(true, static fn (): bool => $class::usesW65RustFfi()));
        self::assertSame($constant->getValue(), $class::usesW65RustFfi(), 'withW65RustFfi restores the default');

        $class::enableW65RustFfi();
        self::assertTrue($class::usesW65RustFfi());
        $class::disableW65RustFfi();
        self::assertFalse($class::usesW65RustFfi());
        self::assertTrue($class::withW65RustFfi(true, static fn (): bool => $class::usesW65RustFfi()));
        self::assertFalse($class::usesW65RustFfi(), 'withW65RustFfi restores an explicit override');
        $class::resetW65RustFfi();
        self::assertSame($constant->getValue(), $class::usesW65RustFfi());

        $nested = $class::withW65RustFfi(false, static fn (): array => [
            $class::usesW65RustFfi(),
            $class::withW65RustFfi(true, static fn (): bool => $class::usesW65RustFfi()),
            $class::usesW65RustFfi(),
        ]);
        self::assertSame([false, true, false], $nested);

        try {
            $class::withW65RustFfi(! $constant->getValue(), static function (): never {
                throw new RuntimeException('boom');
            });
            self::fail('The callback exception must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame($constant->getValue(), $class::usesW65RustFfi(), 'restored after an exception');
    }

    public function test_state_lives_in_function_statics(): void
    {
        foreach (self::HELPERS as [$class]) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w65', $property->getName(), $class);
            }
            self::state($class, 'enabled', true);
            self::assertTrue($class::usesW65RustFfi());
            self::state($class, 'enabled', false);
            self::assertFalse($class::usesW65RustFfi());
            self::assertNull(self::state($class, 'reset'));
            self::assertSame(['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0], self::state($class));
        }
        UsenetDownloadService::withW65RustFfi(false, static function (): void {
            foreach ([AdminDashboardSnapshotService::class, AnidbService::class, ArchiveProcessingService::class, BlacklistService::class] as $other) {
                self::assertNull(self::state($other, 'enabled'), $other);
                self::assertSame(self::DEFAULTS[$other], $other::usesW65RustFfi(), $other);
            }
        });
    }

    public function test_defaults_follow_the_benchmark_decision(): void
    {
        foreach (self::DEFAULTS as $class => $enabled) {
            self::assertSame($enabled, $class::W65_RUST_FFI_DEFAULT, $class);
            self::assertSame($enabled, $class::usesW65RustFfi(), $class);
        }
    }

    public function test_existing_rust_ffi_toggles_are_independent(): void
    {
        $snapshot = static fn (): array => [UsenetDownloadService::usesW406RustFfi(), UsenetDownloadService::usesW52RustFfi(),
            AdminDashboardSnapshotService::usesRustFfi(), AdminDashboardSnapshotService::usesW51RustFfi(), AnidbService::usesW394RustFfi(),
            AnidbService::usesW454RustFfi(), AnidbService::usesRustFfi(), AnidbService::usesW53RustFfi(), ArchiveProcessingService::$useRustFfi,
            ArchiveProcessingService::usesW466RustFfi(), ArchiveProcessingService::usesW53RustFfi(), BlacklistService::usesW401RustFfi(),
            BlacklistService::usesW53RustFfi()];
        $before = $snapshot();
        foreach ([false, true] as $enabled) {
            foreach (self::HELPERS as [$class]) {
                $class::withW65RustFfi($enabled, static function () use ($before, $snapshot): void {
                    self::assertSame($before, $snapshot());
                    self::assertTrue(UsenetDownloadService::isGroupUnavailableMessagePhp('411 No such news group'));
                    self::assertSame('video', ArchiveProcessingService::fileNameClassPhp('a.MKV'));
                    self::assertSame(1, ArchiveProcessingService::archiveEntryClassBatchPhp(['a.mkv'])[0]);
                    self::assertSame([0, 0, 1], AdminDashboardSnapshotService::bucketActivityTimestampsPhp([100], 100, 60, 1));
                    self::assertSame([], BlacklistService::firstLiteralRuleMatchesPhp([], [], [], []));
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
                    self::assertStringContainsString('W65', $e->getMessage());
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
            $class::resetW65RustFfi();
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

        $usenet = UsenetDownloadService::w65UsenetDownloadPlanBatchPhp([
            ['<a@b> <c@d>,<a@b> bad', ' Alt.Bin ', '', 0, $part, 0, 0],
            ['a@b', '', '411 No such news group', $part, 0, 0, 1],
        ]);
        self::assertSame([1 | (1 | 2 | 4) << 4 | 4 << 12 | 2 << 28 | 1 << 44, $fnv('alt.bin'), 1, $part, $part + 2 * 5600 + 96], \array_slice($usenet, 0, 5));
        self::assertSame([2 | (8 | 16 | 32) << 4 | 1 << 12 | 1 << 28 | 1 << 44, 0, 1, $part, $part + 11_296], \array_slice($usenet, 5, 5));
        self::assertSame([2, 1, 1, 0, 0, 0, 5, 3, 1, 1, 2 * $part, 2 * ($part + 11_296)], \array_slice($usenet, 10, 12));

        $admin = AdminDashboardSnapshotService::w65AdminDashboardSnapshotPlanBatchPhp([
            ['queue', 'degraded', 150, 100, 100, 200, 0],
            ['storage', '', 95, 0, 8_000, 9_000, 100],
        ]);
        self::assertSame([1 | 1 << 4 | 4 << 8 | 2 << 12 | (2 | 8) << 16, 50, 5000, -1], \array_slice($admin, 0, 4));
        self::assertSame([2 | 2 << 4 | 5 << 8 | (2 | 4) << 16, 95, 10_000, 9_500], \array_slice($admin, 4, 4));
        self::assertSame([2, 0, 1, 1, 0, 2, 0, 245, 145, 1, 9_500, 0], \array_slice($admin, 8, 12));

        $anidb = AnidbService::w65AnidbMatchPlanBatchPhp([
            ['[Grp] Show Name - 05 [1080p]', 'Show Name', '', 'Shou Name', 0, 2019, 12],
            ['Show Name 2019 ep0013', '', 'Other', '', 0, 2016, 12],
        ]);
        self::assertSame([1 | 1 << 4 | 2 << 10 | (1 | 2 | 8) << 12 | 9 << 20 | 5 << 36, 10_000, 10_000 | 0xFFFF << 16 | 8888 << 32, $fnv('show name'), 0],
            \array_slice($anidb, 0, 5));
        self::assertSame([4 | 2 << 4 | 2 << 8 | 3 << 10 | (8 | 16) << 12 | 9 << 20 | 13 << 36, 1111, 0xFFFF | 1111 << 16 | 0xFFFF << 32, $fnv('show name'), 2019],
            \array_slice($anidb, 5, 5));
        self::assertSame([2, 1, 0, 0, 1, 1, 1, 0, 1, 1, 11_111], \array_slice($anidb, 10, 11));

        $archive = ArchiveProcessingService::w65ArchiveProcessingPlanBatchPhp([
            ['a.rar', "Rar!\x1a\x07\x00", "x.mkv\n\ny.part01.rar\r\ny.part02.rar", '', 0, 10, 0, 2],
            ['A.ZIP', "Rar!\x1a\x07\x01\x00", 'c.jpg', 'Password required', 0, 0, 0, 0],
        ]);
        self::assertSame([4 | 1 << 4 | 1 << 8 | 3 << 16 | 1 << 32, 1 << 8 | 2 << 48, $fnv('a.rar'), 10], \array_slice($archive, 0, 4));
        self::assertSame([1 | 2 << 4 | (1 | 2 | 8 | 32 | 64) << 8 | 1 << 16, 1 << 24, $fnv('a.zip'), 0], \array_slice($archive, 4, 4));
        self::assertSame([2, 1, 0, 0, 1, 0, 0, 4, 1, 1, 10], \array_slice($archive, 8, 11));

        $blacklist = BlacklistService::w65BlacklistEvalPlanBatchPhp([
            ['Some.Movie.x264', 'poster', 'id@x', 'alt.binaries.x', 'foo|X264', 'alt.*', 1, 1, 1, 9],
            ['x', 'y', 'z', 'alt.binaries.x', '(a)', '', 1, 2, 1, 9],
            ['Some.X264', 'p', 'm', 'g', 'X264$', '', 1, 2, 1, 9],
        ]);
        self::assertSame([6 | 1 << 4 | 4 << 8 | 2 << 16 | 2 << 24 | 8 << 32, 11, 9], \array_slice($blacklist, 0, 3));
        self::assertSame([5 | 1 << 4 | 3 << 32, -1, 9], \array_slice($blacklist, 3, 3));
        self::assertSame([7 | 1 << 4 | 2 << 8 | 1 << 16 | 1 << 24 | 5 << 32, 5, 9], \array_slice($blacklist, 6, 3));
        self::assertSame([3, 0, 0, 0, 0, 1, 1, 1, 0, 0, 1], \array_slice($blacklist, 9, 11));
    }

    // ---------------------------------------------------------------------
    // Paired benchmark
    // ---------------------------------------------------------------------

    public function test_paired_benchmark(): void
    {
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            self::markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the paired benchmark.');
        }
        $rounds = max(60, (int) (getenv('W65_BENCH_ROUNDS') ?: 1000));
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
            if ($class::usesW65RustFfi() && min($ratios) < 1.5) {
                $failures[] = $key.' min '.round(min($ratios), 2).' must retain PHP by default below 1.5x';
            }
        }
        fwrite(STDERR, "\nW65.4 paired benchmark ({$rounds} rounds x 320 records per workload):\n".implode("\n", $report)."\n");
        self::assertSame([], $failures, 'Default-enabled helpers must clear 1.5x on every gate.');
    }
}
