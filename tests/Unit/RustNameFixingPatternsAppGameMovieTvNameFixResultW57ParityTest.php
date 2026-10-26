<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NameFixing\Data\NameFixResult;
use App\Services\NameFixing\Patterns\AppPatterns;
use App\Services\NameFixing\Patterns\GamePatterns;
use App\Services\NameFixing\Patterns\MoviePatterns;
use App\Services\NameFixing\Patterns\TvPatterns;
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
 * Wave 57.4 batch helpers: AppPatterns, GamePatterns, MoviePatterns and TvPatterns candidate
 * rows (needle-group pre-screening of every ALL_PATTERNS entry, delimited token masks, scalar
 * tokens and candidate scores) and NameFixResult rows (flags, method class, PreDB source span,
 * confidence tier, trimmed name span, summary length) with a batch summary trailer. Each export
 * takes a concatenated blob plus packed int64 words; Rust writes fixed-width int64 words per
 * record that PHP decodes with one unpack('P*'), so a batch costs one FFI crossing.
 *
 * Every helper keeps its routing override, cached binding and output buffer in the function
 * static of w57RustFfiState().
 *
 * The paired benchmark (NNTMUX_W574_BENCH=1) times the PHP and native batch methods, including
 * packing and decoding, over interleaved rounds of 320 records in random order per round
 * (fixed seed). Rounds per workload: NNTMUX_W574_BENCH_ROUNDS (default 200, minimum 60). It
 * requires every default-enabled helper to clear 1.5x on common and uncommon mean and p99, and
 * skips without the native library.
 *
 * Three runs at 6231e7805 + this change (Apple Silicon, PHP 8.5.12 CLI, rustc 1.99.1 release
 * build, 200 rounds per workload after 10 warmups, load average ~7), minimum speedup per gate
 * (common mean/p99, uncommon mean/p99): appPatternRowBatch 6.35/5.92, 2.97/2.53;
 * gamePatternRowBatch 9.63/8.96, 3.89/3.53; moviePatternRowBatch 11.99/10.25, 5.22/4.36;
 * tvPatternRowBatch 9.79/7.93, 4.60/4.09; nameFixResultRowBatch 1.41/1.35, 1.00/0.96. Common
 * batches hold realistic release names and results; uncommon batches mix long (up to ~10 KB),
 * multibyte, control-byte, random-byte, case-mangled and separator-heavy names, NaN/INF and
 * out-of-range confidences and extreme PreDB ids. The four pattern helpers default to Rust;
 * nameFixResultRowBatch (packing the result properties costs about as much as the PHP checks)
 * defaults to PHP.
 */
final class RustNameFixingPatternsAppGameMovieTvNameFixResultW57ParityTest extends TestCase
{
    /** [class, batch method, int64 words per record, trailing summary words]. */
    private const array HELPERS = [
        'app' => [AppPatterns::class, 'appPatternRowBatch', 6, 0],
        'game' => [GamePatterns::class, 'gamePatternRowBatch', 7, 0],
        'movie' => [MoviePatterns::class, 'moviePatternRowBatch', 7, 0],
        'tv' => [TvPatterns::class, 'tvPatternRowBatch', 9, 0],
        'result' => [NameFixResult::class, 'nameFixResultRowBatch', 6, 8],
    ];

    private const int SEED = 5742026;

    private const array TITLE_WORDS = ['The', 'Office', 'Breaking', 'Bad', 'Doctor', 'Who', 'Star', 'Trek', 'Dune', 'Alien',
        'Matrix', 'Halo', 'Zelda', 'Elden', 'Ring', 'Mario', 'Kart', 'Photoshop', 'Office', 'Ubuntu', 'Studio', 'Pro'];

    private const array SPICE = ['é', 'ß', 'Σ', '日本', '—', "\t", '  ', '|', '!', '&', '(', ')', "'", "\n", "\0", "\x1f",
        "\x7f", "\xff", "\x80", '..', '--', '__', 'S', 'x', 'E', '-'];

    private const array GROUPS = ['GROUP', 'CODEX', 'PLAZA', 'FLT', 'EMPRESS', 'rG', 'TENOKE', 'RUNE', 'NTb', 'FLUX',
        'SPARKS', 'OUTLAWS', 'ALiAS', 'FitGirl', 'DODI', 'SKIDROW', 'TiNYiSO', 'P2P', 'DOGE', 'iNSOMNi'];

    /** Per-class token vocabulary, including every token the rows report. */
    private const array VOCAB = [
        'app' => ['Adobe', 'Photoshop', 'Illustrator', 'Premiere', 'After.Effects', 'InDesign', 'Lightroom', 'Microsoft',
            'Office', 'Windows', 'Visual.Studio', 'Linux', 'Ubuntu', 'Debian', 'CentOS', 'RHEL', 'Fedora', 'MacOS', 'Mac.OS.X',
            'OSX', 'UNIX', 'RPM', 'DEB', 'Incl', 'With', 'Keygen', 'Patch', 'Crack', 'Serial', 'License', 'WinAll', 'Win32',
            'Win64', 'x64', 'x86', 'amd64', 'ARM64', 'arm', 'i386', 'i686', 'Universal', 'Portable', 'Repack', 'Multilingual',
            'MULTi', 'Cracked', 'Registered', 'Activated', 'Pre-Activated', 'Patched', 'Fixed', 'Retail', 'Pro', 'Enterprise',
            'Ultimate', 'Professional', 'Freeware', '2021', '2024'],
        'game' => ['NSW', 'PS3', 'PS4', 'PS5', 'PSP', 'PSV', 'XBSX', 'XSX', 'XBOX.SERIES.X', 'XBOX ONE', 'XBOX.ONE', 'XBOX360',
            'Wii', 'WiiU', 'Switch', 'NDS', '3DS', 'GC', 'NGC', 'PC', 'WIN64', 'MAC', 'MACOSX', 'LINUX', 'ASIA', 'DLC', 'EUR',
            'GOTY', 'JPN', 'KOR', 'MULTI5', 'NTSC', 'NTSCU', 'PAL', 'RF', 'Region.Free', 'USA', 'XBLA', 'INTERNAL', 'PROPER',
            'READNFO', 'READ.NFO', 'FRENCH', 'GERMAN', 'PSN', 'UMD', 'Update', 'Patch', 'Hotfix', 'v1.2.3', 'GOG', 'Classic',
            'Galaxy', 'REPACK', 'RIP', 'R.G.', 'Mechanics', 'xatab', 'DUPLEX', 'RELOADED', 'CPY', 'RAZOR1911', 'HOODLUM',
            'DARKSiDERS', 'PROPHET', 'SiMPLEX', 'ANOMALY', 'OneUp', 'STRANGE', 'SWAG', 'SKY', 'SUXXORS'],
        'movie' => ['2160p', '4K', 'UHD', '1080p', '1080i', '720p', '480p', '480i', '576p', '576i', '1080', '2160', 'HDR', 'HDR10+',
            'DV', 'Dolby.Vision', 'HLG', 'REMUX', 'BluRay', 'Blu-Ray', 'BDRip', 'BRRip', 'WEB-DL', 'WEBDL', 'WEBRip', 'WEB-Rip',
            'WEB', 'HDTV', 'PDTV', 'SDTV', 'HDRip', 'DVDRip', 'DVD', 'DVDSCR', 'CAM', 'TS', 'VHS', 'VOD', 'HDCAM', 'Complete.UHD',
            'AMZN', 'ATVP', 'DSNP', 'HMAX', 'HULU', 'iT', 'NF', 'PMTP', 'PCOK', 'ROKU', 'STAN', 'VUDU', 'x264', 'x265', 'H264',
            'H.264', 'H265', 'H.265', 'HEVC', 'AVC', 'XviD', 'DivX', 'AV1', 'MPEG2', 'WMV', 'VP9', 'AAC', 'AC3', 'AC-3', 'DD5.1',
            'DDP5.1', 'DTS', 'DTS-HD', 'DTS-HD.MA', 'TrueHD', 'Atmos', 'EAC3', 'FLAC', 'MP3', 'Opus', 'FRENCH', 'German', 'MULTi',
            'Japanese', 'Japenese', 'Spanisch', 'Nordic'],
        'tv' => ['S01E02', 's1e2', 'S01.E02', 'S1-E123', 'S01E02-E03', 'S01', '1x02', '12x123', '|01x02', 'S01|02', 'ep01', 'Ep.123',
            'E01', 'COMPLETE', 'FULL', '2160p', '4K', 'UHD', '1080p', '720p', '480p', 'HDR10+', 'DV', 'AMZN', 'NF', 'DSNP', 'HMAX',
            'WEB-DL', 'WEBRip', 'WEB', 'HDTV', 'PDTV', 'BluRay', 'Blu-Ray', 'BDRip', 'DVDRip', 'REMUX', 'x264', 'x265', 'H.264',
            'HEVC', 'XviD', 'AV1', '10bit', 'AAC', 'AC3', 'DD5.1', 'DDP5.1', 'DTS', 'DTS-HD', 'Atmos', 'EAC3', 'NFL', 'NBA',
            'UFC', 'WWE', 'F1', 'Formula.1', 'MotoGP', 'IndyCar', 'NCWTS', 'NSCS', 'Boxing', '001', '1080'],
    ];

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            self::state($class, 'reset');
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
        return (new ReflectionMethod($class, 'w57RustFfiState'))->invoke(null, ...$args);
    }

    private static function native(string $class): bool
    {
        $cdef = (new ReflectionClass($class))->getConstant('W57_FFI_CDEF');
        self::assertIsString($cdef);

        return RustPipelineLibrary::resolveDomain('metadata_w57', $cdef) !== null;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<int>
     */
    private static function dispatch(string $class, string $method, bool $native, array $items): array
    {
        $result = $class::withW57RustFfi($native, static fn (): array => $class::{$method}($items));
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

    private static function bytes(Randomizer $random, int $length, ?string $alphabet = null): string
    {
        if ($length <= 0) {
            return '';
        }

        return $alphabet === null ? $random->getBytes($length) : $random->getBytesFromString($alphabet, $length);
    }

    private static function title(Randomizer $random, int $words): string
    {
        $parts = [];
        for ($i = 0; $i < $words; $i++) {
            $parts[] = self::pick($random, self::TITLE_WORDS);
        }

        return implode('.', $parts);
    }

    /** A realistic release name built in the order the class's patterns expect. */
    private static function commonName(Randomizer $random, string $key): string
    {
        $title = self::title($random, $random->getInt(1, 4));
        $year = (string) $random->getInt(1950, 2026);
        $group = self::pick($random, self::GROUPS);
        $v = static fn (array $values): string => self::pick($random, $values);

        return match ($key) {
            'app' => match ($random->getInt(0, 5)) {
                0 => $title.'.v'.$random->getInt(1, 30).'.'.$random->getInt(0, 9).'.'.$v(['Linux', 'MacOS', 'UNIX']).'.'
                    .$v(['RPM', 'DEB', 'X64']).'.'.$v(['Incl', 'With']).'.'.$v(['Keygen', 'Patch', 'Crack', 'Serial']).'-'.$group,
                1 => $title.'.'.$random->getInt(1, 2024).'.'.$v(['WinAll', 'Win32', 'Win64', 'x64', 'x86']).'.'
                    .$v(['Portable', 'Repack', 'Freeware', 'Retail']).'-'.$group,
                2 => $v(['Adobe', 'Photoshop', 'Lightroom', 'After.Effects']).'.'.$title.'.'.$year.'.v'.$random->getInt(1, 30).'.0-'.$group,
                3 => $v(['Microsoft.Office', 'Windows', 'Visual.Studio']).'.'.$random->getInt(10, 2024).'.'.$v(['Pro.Plus', 'Enterprise', 'Ultimate']).'-'.$group,
                4 => $title.'.v'.$random->getInt(1, 9).'.'.$random->getInt(0, 99).'.'.$random->getInt(0, 9).'.'
                    .$v(['Multilingual', 'MULTi', 'Portable', 'Repack', 'Cracked']).'.'.$v(['x64', 'arm64', 'Universal']).'-'.$group,
                default => $title.'.'.$v(['Linux', 'Ubuntu', 'Fedora', 'MacOS', 'Mac.OS.X', 'OSX']).'.'.$v(['x64', 'x86', 'arm64', 'amd64']).'-'.$group,
            },
            'game' => match ($random->getInt(0, 6)) {
                0 => $title.'.'.$v(['NSW', 'PS5', 'PS4', 'XBSX', 'XBOX.ONE', 'Switch', 'WiiU']).'.'.$v(['INTERNAL', 'PROPER', 'READ.NFO', 'MULTI5']).'-'.$group,
                1 => $title.'.'.$v(['EUR', 'USA', 'JPN', 'PAL', 'NTSCU', 'Region.Free', 'ASIA']).'.'.$v(['FRENCH', 'PSN', 'PROPER']).'.'
                    .$v(['PS3', 'PSP', 'NDS', 'Wii', 'XBOX360', 'GC']).'-'.$group,
                2 => $title.'.'.$v(['PS4', 'NSW', 'XBOX360', 'WiiU']).'.'.$v(['CODEX', 'DUPLEX', 'PLAZA', 'iNSOMNi', 'SUXXORS']).'-'.$group,
                3 => $title.'.'.$v(['PC', 'WIN64', 'MACOSX', 'LINUX']).'.'.$v(['CODEX', 'SKIDROW', 'GOG', 'rG', 'TiNYiSO']).'-'.$group,
                4 => $title.'.'.$v(['DLC', 'Update', 'Hotfix']).'.v'.$random->getInt(1, 9).'.'.$random->getInt(0, 9).'.'.$v(['PLAZA', 'GOG', 'P2P']).'-'.$group,
                5 => $title.'.'.$v(['GOG.Classic', 'GOG', 'REPACK.FitGirl', 'RIP.Mechanics', 'REPACK.R.G.']).'-'.$group,
                default => $title.'-'.$v(['OUTLAWS', 'ALiAS', 'CODEX', 'TENOKE', 'RUNE']),
            },
            'movie' => match ($random->getInt(0, 7)) {
                0 => $title.'.'.$year.'.'.$v(['BluRay', 'DVDRip', 'WEB-DL', 'HDTV']).'.'.$v(['x264', 'XviD', 'HEVC']).'.'.$v(['720p', '1080p', '480']).'-'.$group,
                1 => $title.'.'.$v(['720p', '1080p', '2160']).'.'.$v(['BluRay', 'WEB-DL', 'HDTV']).'.'.$v(['DTS', 'AAC', 'AC3']).'.'.$v(['x264', 'HEVC']).'-'.$group,
                2 => $title.'.'.$v(['720p', '1080p']).'.'.$v(['DTS', 'AAC', 'FLAC']).'.'.$v(['BluRay', 'WEB-DL']).'.'.$year.'-'.$group,
                3 => $title.'.'.$year.'.'.$v(['BluRay', 'DVDRip', 'WEB-DL']).'.'.$v(['x264', 'XviD', 'HEVC']).'.'.$v(['DTS', 'AC3', 'TrueHD']).'-'.$group,
                4 => $title.'.'.$v(['FRENCH', 'German', 'MULTi', 'Japanese']).'.'.$year.'.'.$v(['DTS', 'AC3', 'FLAC']).'.'.$v(['BluRay', 'DVDRip']).'-'.$group,
                default => self::movieName($random, $title, $year, $group),
            },
            default => match ($random->getInt(0, 8)) {
                7 => $title.'.'.$v(['S01E02', '1x02', 'ep01']).'.'.$v(['DD5.1', 'AAC', 'AC3']).'.'.$v(['HDTV', 'WEB-DL']).'.'.$v(['720p', '1080p']).'.'
                    .$v(['x264', 'XviD']).'-'.$group,
                8 => $v([
                    $title.'.S02E03.'.$v(['HDTV', 'DVDRip']).'.'.$v(['x264', 'XviD']).'-'.$group,
                    $title.'.'.$year.'.S01E02.'.$v(['HDTV', 'WEB-DL']).'-'.$group,
                    $title.'.S01E02.Part.'.$year.'.x264-'.$group,
                    $title.'.'.str_pad((string) $random->getInt(1, 999), 3, '0', STR_PAD_LEFT).'.'.$v(['720p', '1080p']).'.'.$v(['HEVC', 'x264']).'.10bit-'.$group,
                ]),
                6 => $title.'.S'.str_pad((string) $random->getInt(1, 30), 2, '0', STR_PAD_LEFT).'E'.str_pad((string) $random->getInt(1, 30), 2, '0', STR_PAD_LEFT)
                    .'.'.$v(['2160p', '4K', 'UHD']).'.'.$v(['AMZN', 'NF', 'ATVP']).'.'.$v(['WEB-DL', 'WEBRip']).'.'.$v(['HDR.', 'DV.', 'HDR10+.', ''])
                    .$v(['HEVC', 'x265', 'H.265']).'-'.$group,
                0, 1 => $title.'.S'.str_pad((string) $random->getInt(1, 30), 2, '0', STR_PAD_LEFT).'E'.str_pad((string) $random->getInt(1, 30), 2, '0', STR_PAD_LEFT)
                    .'.'.$v(['2160p', '1080p', '720p']).'.'.$v(['AMZN', 'NF', 'HMAX', 'DSNP']).'.'.$v(['WEB-DL', 'WEBRip']).'.'
                    .$v(['DDP5.1', 'AAC', 'Atmos']).'.'.$v(['x264', 'H.264', 'HEVC', 'x265']).'-'.$group,
                2 => $title.'.'.$random->getInt(1, 12).'x'.str_pad((string) $random->getInt(1, 30), 2, '0', STR_PAD_LEFT).'.'.$v(['720p', '480p']).'.'
                    .$v(['HDTV', 'DVDRip']).'.'.$v(['x264', 'XviD']).'-'.$group,
                3 => $title.'.'.$year.'.'.str_pad((string) $random->getInt(1, 12), 2, '0', STR_PAD_LEFT).'.'.str_pad((string) $random->getInt(1, 28), 2, '0', STR_PAD_LEFT)
                    .'.'.$v(['720p', '1080p', 'HDTV', 'WEB']).'-'.$group,
                4 => $v(['UFC', 'NFL', 'NBA', 'WWE', 'F1', 'MotoGP']).'.'.$v(['', 'x']).$year.'.'.str_pad((string) $random->getInt(1, 12), 2, '0', STR_PAD_LEFT).'.'
                    .str_pad((string) $random->getInt(1, 28), 2, '0', STR_PAD_LEFT).'.'.$v(['', 'NHL.', 'Boxing.', 'Formula.1.', 'NCWTS.'])
                    .$title.'.1080p.WEB.h264-'.$group,
                default => $title.'.S'.str_pad((string) $random->getInt(1, 9), 2, '0', STR_PAD_LEFT).'.'.$v(['COMPLETE', 'FULL']).'.'
                    .$v(['1080p', 'BluRay', 'WEB-DL']).'-'.$group,
            },
        };
    }

    private static function movieName(Randomizer $random, string $title, string $year, string $group): string
    {
        $v = static fn (array $values): string => self::pick($random, $values);

        return $title.'.'.($random->getInt(0, 3) === 0 ? $v(['FRENCH', 'German', 'MULTi', 'Nordic']).'.' : '').$year.'.'
            .$v(['2160p', '1080p', '720p', '480p', '4K', 'UHD']).'.'
            .($random->getInt(0, 1) === 0 ? $v(['AMZN', 'NF', 'DSNP', 'HMAX', 'iT']).'.' : '')
            .$v(['BluRay', 'WEB-DL', 'WEBRip', 'REMUX', 'HDTV', 'BDRip', 'DVDRip']).'.'
            .($random->getInt(0, 2) === 0 ? $v(['HDR', 'DV', 'HDR10+']).'.' : '')
            .$v(['x264', 'x265', 'H.265', 'HEVC', 'XviD', 'AV1']).'.'.$v(['DTS', 'DTS-HD.MA', 'TrueHD', 'Atmos', 'AAC', 'DD5.1', 'FLAC'])
            .'-'.$group;
    }

    /** Long, multibyte, control-byte, case-mangled and separator-heavy names. */
    private static function uncommonName(Randomizer $random, string $key): string
    {
        $mode = $random->getInt(0, 9);
        if ($mode === 0) {
            return self::bytes($random, $random->getInt(0, 64));
        }
        if ($mode === 1) {
            return self::bytes($random, $random->getInt(0, 48), 'sSeExX0123456789._ -|19202160pPkK');
        }
        $parts = [];
        $count = $random->getInt(1, $mode === 2 ? 220 : 24);
        for ($i = 0; $i < $count; $i++) {
            $part = match ($random->getInt(0, 4)) {
                0 => self::commonName($random, $key),
                1, 2 => self::pick($random, self::VOCAB[$key]),
                3 => self::pick($random, self::SPICE),
                default => (string) $random->getInt(0, $random->getInt(0, 1) === 0 ? 99 : PHP_INT_MAX),
            };
            $parts[] = $random->getInt(0, 2) === 0 ? ($random->getInt(0, 1) === 0 ? strtoupper($part) : strtolower($part)) : $part;
        }
        $glue = self::pick($random, ['.', ' ', '_', '-', '', '..', self::pick($random, self::SPICE)]);

        return implode($glue, $parts);
    }

    private static function confidence(Randomizer $random, bool $uncommon): float
    {
        if (! $uncommon) {
            return self::pick($random, [1.0, 1.0, 0.9, 0.8, 0.75, 0.5, 0.0]);
        }

        return self::pick($random, [NAN, INF, -INF, -0.0, 0.95, 0.9499999999, 0.7499999, 0.5, 0.4999999, 1.0000001, -1e-300,
            5e-324, $random->getFloat(-2.0, 3.0), $random->getFloat(0.0, 1.0)]);
    }

    private static function resultItem(Randomizer $random, bool $uncommon): NameFixResult
    {
        if (! $uncommon) {
            return match ($random->getInt(0, 4)) {
                0 => NameFixResult::fromPreDb(self::commonName($random, 'movie'), $random->getInt(1, 9_999_999), self::pick($random, ['srrdb', 'predb.me', 'xrel'])),
                1 => NameFixResult::unmatched(self::pick($random, ['TV', 'Movie', 'NFO', 'PreDB'])),
                default => NameFixResult::fromMatch(
                    self::commonName($random, self::pick($random, ['app', 'game', 'movie', 'tv'])),
                    self::pick($random, ['Generic TV 1', 'Title (Year)', 'PC Games (c)', 'Complete literal NFO field', 'PAR2 hash',
                        'Movie: UHD_HDR', 'TV: STREAMING_HD', 'Game: GOG', 'App: WINDOWS', 'CRC32', 'File Name', 'Music FM RADIO']),
                    self::pick($random, ['NFO', 'TV', 'Movie', 'Game', 'App', 'File', 'PAR2']),
                    $random->getInt(0, 1) === 0 ? 0 : $random->getInt(1, 9_999_999),
                    self::confidence($random, false),
                ),
            };
        }
        $name = match ($random->getInt(0, 5)) {
            0 => '',
            1 => self::pick($random, [' ', "\t\n", "\0", "\x0b", '  a.b.c  ', "\0Some.Name\0", '.hidden.name', '-dash.name', 'abcd', 'a.b.c',
                str_repeat('a.', 127).'a', str_repeat('a.', 128), "Name\x7f.x", 'Ünïcode.Ñame', '12345.678']),
            default => self::uncommonName($random, self::pick($random, ['app', 'game', 'movie', 'tv'])),
        };
        $method = self::pick($random, ['', 'PreDB: ', 'PreDB: x', 'PREDB: x', ' PreDB: x', 'PreDB:', 'nfo', 'NFO hash', 'Par2', 'SFV', 'SRR',
            'FileName', 'CRC', 'Hash MD5', 'UID', 'XXX', 'tvshow', 'Movie', 'GAME', 'happy', 'music', 'TITLE', 'unknown', 'Σ',
            self::uncommonName($random, 'tv')]);

        return new NameFixResult(
            $name,
            $method,
            self::pick($random, ['', 'NFO', 'PreDB', "\0", self::bytes($random, $random->getInt(0, 12))]),
            self::pick($random, [0, 1, -1, PHP_INT_MAX, PHP_INT_MIN, $random->getInt(PHP_INT_MIN, PHP_INT_MAX)]),
            self::confidence($random, true),
            $random->getInt(0, 1) === 0 ? [] : ['source' => 'x'],
        );
    }

    /** @return list<mixed> */
    private static function batch(string $key, Randomizer $random, int $size, bool $uncommon): array
    {
        $items = [];
        for ($i = 0; $i < $size; $i++) {
            $items[] = $key === 'result'
                ? self::resultItem($random, $uncommon)
                : ($uncommon ? self::uncommonName($random, $key) : self::commonName($random, $key));
        }

        return $items;
    }

    /** @return list<mixed> */
    private static function edgeItems(string $key): array
    {
        if ($key === 'result') {
            return [
                NameFixResult::matched('Show.S01E02.720p.HDTV.x264-GRP', 'TV: TV_SOURCE_GROUP', 'TV'),
                NameFixResult::unmatched(),
                NameFixResult::unmatched('Checker'),
                NameFixResult::fromPreDb('Movie.2023.1080p.BluRay.x264-GRP', 42, ''),
                NameFixResult::fromPreDb(' Padded.Name ', 7, 'srrdb', ''),
                new NameFixResult("\0", "\0", "\0", PHP_INT_MIN, NAN),
                new NameFixResult(str_repeat('x', 5000), str_repeat('PreDB: ', 300), str_repeat('c', 300), PHP_INT_MAX, INF),
                new NameFixResult('a.b c', 'PreDB: ', '', 1, -0.0),
                new NameFixResult('.a.b.c', 'Title', 'NFO', 0, 0.95),
                new NameFixResult('ABCDE', 'nfo', 'NFO', 0, 0.75),
                new NameFixResult('A_B_C', 'x', 'x', 0, 0.5),
                new NameFixResult("A\tB.C", 'x', 'x', 0, 0.49999999999999994),
                new NameFixResult('12.34.56', 'x', 'x', 0, 1.0000000000000002),
            ];
        }
        $vocab = self::VOCAB[$key];
        $edges = ['', '.', '-', '|', ' ', "\0", "\xff\xfe", str_repeat('-', 300), str_repeat('a', 5000), implode('.', $vocab),
            implode('-', $vocab), implode('', $vocab), strtoupper(implode(' ', $vocab)), strtolower(implode('_', $vocab))];
        foreach ($vocab as $token) {
            $edges[] = $token;
            $edges[] = 'x'.$token.'.y';
            $edges[] = 'Name.'.$token.'-GRP';
            $edges[] = '1'.strtoupper($token).'2';
        }
        foreach (self::GROUPS as $group) {
            $edges[] = 'Title-'.$group;
            $edges[] = 'Title-'.strtolower($group)."\n";
        }
        array_push($edges, 'v1.2', 'xv1.2', 'V10.20.30.40.', '1..2.3', 'App.v1-2.3', 'a.1.2a', '1.', '.1', 'v.1.2',
            '1999', '12000', '2020.10.10', '1999-1-01', '2020 12 31', '20201.12.31', 's01e02', 'xs01e02', '.s123e04',
            's01-e1234', 's1_e', 'S01xE02', '1x02', '01x002', '123x02', '1x0234', '|1x02', 's01.e02 1x02', 'S01E02 S03E04',
            '1080p 720p', '4k', 'UHD.2160p', '0480p', 'PS5-codex', 'game-', 'game-CODEX-', 'XBOX  ONE', 'xbox one', 'xboxone',
            'pre-activated', 'xpre-activated', 'pre-activatedx', 'h.264.h.265', 'dts-hd-ma', 'dd5.1ddp5.1', 'blu-ray-web-dl');

        return $edges;
    }

    // ---------------------------------------------------------------------
    // API contract
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_w57_switch_api_and_default(string $class, string $method, int $words, int $trailer, string $key): void
    {
        self::assertGreaterThan(0, $words);
        self::assertGreaterThanOrEqual(0, $trailer);
        self::assertNotSame('', $key);
        $reflection = new ReflectionClass($class);
        $constant = $reflection->getReflectionConstant('W57_RUST_FFI_DEFAULT');
        self::assertNotFalse($constant);
        self::assertTrue($constant->isPublic());
        self::assertIsBool($constant->getValue());
        self::assertSame($words, $reflection->getConstant('W57_ROW_WORDS'));
        foreach (['usesW57RustFfi', 'withW57RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            self::assertTrue($reflection->getMethod($name)->isPublic() && $reflection->getMethod($name)->isStatic(), $name);
        }
        $state = $reflection->getMethod('w57RustFfiState');
        self::assertTrue($state->isPrivate() && $state->isStatic());
        self::assertSame($class, $state->getDeclaringClass()->getName());

        self::assertSame($constant->getValue(), $class::usesW57RustFfi());
        self::assertFalse($class::withW57RustFfi(false, static fn (): bool => $class::usesW57RustFfi()));
        self::assertTrue($class::withW57RustFfi(true, static fn (): bool => $class::usesW57RustFfi()));
        self::assertSame($constant->getValue(), $class::usesW57RustFfi(), 'withW57RustFfi restores the default');

        $nested = $class::withW57RustFfi(false, static fn (): array => [
            $class::usesW57RustFfi(),
            $class::withW57RustFfi(true, static fn (): bool => $class::usesW57RustFfi()),
            $class::usesW57RustFfi(),
        ]);
        self::assertSame([false, true, false], $nested);

        try {
            $class::withW57RustFfi(! $constant->getValue(), static function (): never {
                throw new RuntimeException('boom');
            });
            self::fail('The callback exception must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame($constant->getValue(), $class::usesW57RustFfi(), 'restored after an exception');
    }

    public function test_state_lives_in_function_statics(): void
    {
        foreach (self::HELPERS as [$class]) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w57', $property->getName(), $class);
            }
            self::state($class, 'enabled', false);
            self::assertFalse($class::usesW57RustFfi());
            self::assertNull(self::state($class, 'reset'));
            self::assertSame(['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0], self::state($class));
        }
        TvPatterns::withW57RustFfi(true, static function (): void {
            self::assertTrue(TvPatterns::usesW57RustFfi());
            foreach ([AppPatterns::class, GamePatterns::class, MoviePatterns::class, NameFixResult::class] as $other) {
                self::assertNull(self::state($other, 'enabled'), $other);
            }
        });
    }

    public function test_defaults_follow_the_benchmark_decision(): void
    {
        $expected = [
            AppPatterns::class => true,
            GamePatterns::class => true,
            MoviePatterns::class => true,
            TvPatterns::class => true,
            NameFixResult::class => false,
        ];
        foreach ($expected as $class => $enabled) {
            self::assertSame($enabled, $class::W57_RUST_FFI_DEFAULT, $class);
            self::assertSame($enabled, $class::usesW57RustFfi(), $class);
        }
    }

    public function test_existing_public_api_is_unchanged(): void
    {
        foreach ([AppPatterns::class, GamePatterns::class, MoviePatterns::class, TvPatterns::class] as $class) {
            $expected = $class::ALL_PATTERNS;
            foreach ([false, true] as $enabled) {
                $class::withW57RustFfi($enabled, static function () use ($class, $expected): void {
                    self::assertSame($expected, $class::getAllPatterns());
                    self::assertSame(array_keys($expected), $class::getPriorityOrder());
                    self::assertSame(\count($expected), $class::getPatternCount());
                });
            }
        }
        $result = NameFixResult::fromPreDb('Some.Name', 5, 'srrdb');
        foreach ([false, true] as $enabled) {
            NameFixResult::withW57RustFfi($enabled, static function () use ($result): void {
                self::assertSame('PreDB: PreDB: srrdb => Some.Name', $result->getSummary());
                self::assertTrue($result->isSuccessful());
                self::assertSame(['source' => 'srrdb'], $result->metadata);
            });
        }
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
        $bad = [array_combine(['a', 'b', 'c'], $items), [1 => $items[0]]];
        if ($key === 'result') {
            $bad[] = [$items[0], 'not a result'];
        }
        foreach ($bad as $shape) {
            foreach (['Php', 'Native', ''] as $suffix) {
                try {
                    $class::{$method.$suffix}($shape);
                    self::fail($method.$suffix.' must reject a malformed batch');
                } catch (InvalidArgumentException $e) {
                    self::assertStringContainsString('W57', $e->getMessage());
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
            self::assertParity($class, $method, $words, $trailer, [$item], $key.' edge '.$i);
        }
        self::assertParity($class, $method, $words, $trailer, $edges, $key.' edges together');
        self::assertGreaterThanOrEqual(300, $records + \count($edges));
    }

    /** The pre-screen bit is a necessary condition: a pattern that matches always has its bit. */
    public function test_prescreen_never_rejects_a_matching_pattern(): void
    {
        foreach (['app' => AppPatterns::class, 'game' => GamePatterns::class, 'movie' => MoviePatterns::class, 'tv' => TvPatterns::class] as $key => $class) {
            $random = new Randomizer(new Mt19937(self::SEED + crc32('screen'.$key)));
            $names = [...self::edgeItems($key), ...self::batch($key, $random, 600, false), ...self::batch($key, $random, 300, true)];
            $method = self::HELPERS[$key][1].'Php';
            $words = self::HELPERS[$key][2];
            $rows = $class::{$method}($names);
            $patterns = array_values($class::ALL_PATTERNS);
            $hits = array_fill(0, \count($patterns), 0);
            foreach ($names as $n => $name) {
                foreach ($patterns as $bit => $pattern) {
                    if (preg_match($pattern, $name) === 1) {
                        $hits[$bit]++;
                        self::assertSame(1 << $bit, $rows[$n * $words] & (1 << $bit), $key.' pattern '.$bit.' '.json_encode($name, JSON_INVALID_UTF8_SUBSTITUTE));
                    }
                }
            }
            foreach ($hits as $bit => $count) {
                self::assertGreaterThan(0, $count, $key.' pattern '.$bit.' is exercised');
            }
        }
    }

    public function test_hand_checked_rows(): void
    {
        foreach ([false, true] as $native) {
            self::assertSame(
                [(1 << 1) | (1 << 4) | (1 << 6), 1, (1 << 2) | (1 << 6) | (1 << 7), (22 << 32) | 4, 1, 32, 0, 0, 0, -1, -1, 0],
                self::dispatch(AppPatterns::class, 'appPatternRowBatch', $native, ['Adobe.Photoshop.2024.v25.0.x64.Multilingual.Pre-Activated-GRP', '']),
            );
            self::assertSame(
                [1 | (1 << 2), 1 << 1, 0, 1, 0, 0, 8 * 2 + 2 + 4],
                self::dispatch(GamePatterns::class, 'gamePatternRowBatch', $native, ['Game.Name.PS5-CODEX']),
            );
            $movie = self::dispatch(MoviePatterns::class, 'moviePatternRowBatch', $native, ['Movie.2023.2160p.UHD.BluRay.x265.DTS-HD-GRP']);
            self::assertSame([2023, 2160, 1, (1 << 1) | (1 << 20) | (1 << 21)], \array_slice($movie, 1, 4));
            self::assertSame(1, $movie[0] & 1);
            self::assertSame(0, $movie[5]);
            $tv = self::dispatch(TvPatterns::class, 'tvPatternRowBatch', $native, ['Show.S01E02.720p.HDTV.x264-GRP', 'Daily.2023.12.15.720p.HDTV-GRP']);
            self::assertSame([1, 2, -1, 720, 1 << 10, 1], \array_slice($tv, 1, 6));
            self::assertSame([-1, -1, 20231215, 720, 1 << 10, 0], \array_slice($tv, 10, 6));
            self::assertSame(
                [1 | 2 | 4 | 64, 1, (7 << 32) | 5, 3, 11, 5 + 2 + 12 + 4 + 11, 256 | 128, 0, -1, 0, 0, 10, 1, 1, 1, 0, 1, 0, 0, 1],
                self::dispatch(NameFixResult::class, 'nameFixResultRowBatch', $native, [NameFixResult::fromPreDb('A.Good.Name', 9, 'srrdb', 'PreDB'), new NameFixResult('', '', '', 0, 0.0)]),
            );
        }
    }

    // ---------------------------------------------------------------------
    // Paired benchmark
    // ---------------------------------------------------------------------

    public function test_paired_benchmark(): void
    {
        if (getenv('NNTMUX_W574_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W574_BENCH=1 to run the paired benchmark.');
        }
        $rounds = max(60, (int) (getenv('NNTMUX_W574_BENCH_ROUNDS') ?: 200));
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
            if ($class::W57_RUST_FFI_DEFAULT && min($ratios) < 1.5) {
                $failures[] = $key.' min '.round(min($ratios), 2);
            }
        }
        fwrite(STDERR, "\nW57.4 paired benchmark ({$rounds} rounds x 320 records per workload):\n".implode("\n", $report)."\n");
        self::assertSame([], $failures, 'Default-enabled helpers must clear 1.5x on every gate.');
    }
}
