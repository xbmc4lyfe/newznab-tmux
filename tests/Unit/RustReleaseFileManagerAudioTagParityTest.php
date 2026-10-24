<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\NameFixing\FileNameCleaner;
use App\Support\RustPipelineLibrary;
use PHPUnit\Framework\TestCase;

class RustReleaseFileManagerAudioTagParityTest extends TestCase
{
    private const string DEFAULT_VIDEO_FILE_REGEX = '\.(AVI|F4V|IFO|M1V|M2V|M4V|MKV|MOV|MP4|MPEG|MPG|MPGV|MPV|OGV|QT|RM|RMVB|TS|VOB|WMV)';

    private const int FUZZ_CASES = 4000;

    protected function setUp(): void
    {
        parent::setUp();

        if (RustPipelineLibrary::resolveDomain(
            'wave10_release_file_manager_audio_tag_test',
            ReleaseFileManager::FFI_CDEF."\n"
            .MediaExtractionService::FFI_CDEF."\n"
            .UsenetDownloadService::FFI_CDEF
        ) === null) {
            $this->markTestSkipped('Rust pipeline FFI library is not built in this environment.');
        }
    }

    protected function tearDown(): void
    {
        ReleaseFileManager::useRustFfi(true);
        MediaExtractionService::useRustFfi(true);
        UsenetDownloadService::useRustFfi(true);

        parent::tearDown();
    }

    public function test_is_nfo_filename_parity(): void
    {
        $cases = [
            'Movie.2026.1080p.BluRay.x264-GROUP.nfo', 'dir/FILE_ID.DIZ', 'readme.txt', 'Release.TXT', 'information.diz',
            'notes.txt', 'note.diz', 'about.txt', 'x.info', "x.info\n", "x.nfo\n\n", 'x.inf', 'x.infos', 'myinfo.txt',
            'a/00-grp.nfo/', 'a/grp.nfo/', 'a/.x-y.nfo/', 'a/x-.nfo/', 'a/-x.nfo/', 'a/x-y.z.nfo//', 'a/x.y-z.nfo/',
            'a/0-x.NFO/', "a/x-y.nfo\n/", 'movie.mkv', '', '/', '///', 'nfo', '.nfo', 'dir/sub/', "file\0id.txt",
            "caf\xc3\xa9-grp.nfo", "caf\xc3\xa9-grp.nfo/", "x\xff.diz", 'file_id.txt', 'file-id.diz', 'fileid.TXT',
        ];
        foreach ($cases as $case) {
            $this->assertNfoFilenameParity($case);
        }
        foreach ($this->fuzzStrings(1, ['nfo', 'NFO', 'diz', 'info', 'txt', 'readme', 'file_id', '00-', 'grp', '/']) as $case) {
            $this->assertNfoFilenameParity($case);
        }
    }

    public function test_extract_release_name_from_file_parity(): void
    {
        $manager = $this->releaseFileManager();
        $cases = [
            'dir/movie.2026.1080p.bluray-grp.mkv', 'show.name.s01e01.720p-grp (1)', 'track 01', 'x.r00', 'x.R123', 'x.r1',
            'Movie.Name.2020.1080p.BluRay.x264-GROUP.part01.rar', 'Movie_Name 2020-grp.MPEG', 'a.b', 'a-bc', '-ab', 'ab',
            'Name (2020)', "Some.Release.Name-GRP\n", 'Some.Release.Name-GRP/', 'Some.Release.Name-GRP.par2', 'x.par',
            '__NZBSPLIT__deadbeef01__NZBSPLIT__Movie_Name_2020-GRP.part01.rar', '__nzbsplit__XYZ__NZBSPLIT__bad',
            '__NZBSPLIT__0123456789__NZBSPLIT__"Show+Name_S01E01" yEnc (1/5)', '__NZBSPLIT__deadbeef__NZBSPLIT__',
            "caf\xc3\xa9.Release-GRP.mkv", "Release\0Name-GRP.mkv", 'Release.Name-GRP [01/10]', "it's.(a)[test]-grp ~",
            str_repeat('.', 40).'!ab.cd-ef (', str_repeat('a.', 600).'-x', '', '.', '..mkv', 'x.mkv.', 'x y z',
            "tab\tsep.name-grp (x)", "v\x0Btab.name-grp (x)", 'up_per CASE.words-here', 'x.TS', 'x.ts.nzb',
        ];
        foreach ($cases as $case) {
            $this->assertExtractReleaseNameParity($manager, $case);
        }
        $fragments = ['Movie', '.', '-', '_', ' ', '2026', '1080p', 'GRP', '.mkv', '.rar', '.r01', '(1)', '[1]', "'", '/',
            '__NZBSPLIT__', 'deadbeef01', 'yEnc', '.part01', '+', "\t", '!', '~'];
        foreach ($this->fuzzStrings(2, $fragments) as $case) {
            $this->assertExtractReleaseNameParity($manager, $case);
        }
    }

    public function test_normalize_file_size_parity(): void
    {
        $cases = [
            0, 1, -1, PHP_INT_MAX, PHP_INT_MIN, 0.0, 1.9, -0.5, 1.0e30, INF, NAN, null, true, [], '', '0', '000', '00012',
            '9223372036854775807', '9223372036854775808', '09223372036854775807', '99999999999999999999', '12a', ' 12',
            "12\n", '-12', '+12', '1.5', '١٢', str_repeat('0', 200).'42',
        ];
        foreach ($cases as $case) {
            $this->assertFileSizeParity($case);
        }
        foreach ($this->fuzzStrings(3, ['0', '1', '9', '922337', '2036854775807', ' ', "\n", 'a', '-']) as $case) {
            $this->assertFileSizeParity($case);
        }
    }

    public function test_audio_tag_text_parity(): void
    {
        $stringable = new class implements \Stringable
        {
            public function __toString(): string
            {
                return '  Album Name  ';
            }
        };
        $cases = [
            'Artist', '  Artist  ', "\t\nArtist\0", "Art\x01ist", "Art\x7fist", '', '   ', "caf\xc3\xa9", "caf\xe9",
            "\xed\xa0\x80", "\xf4\x90\x80\x80", "\xc0\xaf", "Art\x0bist", "\x0bArtist\x0b", 2026, 1.5, null, true, [],
            $stringable, "\xe2\x80\x8bZero width", "Tab\tInside",
        ];
        foreach ($cases as $case) {
            $this->assertAudioTagTextParity($case);
        }
        foreach ($this->fuzzStrings(4, ['Artist', ' ', "\t", "\0", "\x7f", "\xc3\xa9", "\xe9", "\xf0\x9f\x8e\xb5"]) as $case) {
            $this->assertAudioTagTextParity($case);
        }
    }

    public function test_audio_release_title_parity(): void
    {
        $cases = [
            ['Artist', 'Album', '2026-01-02', 'mp3'], ['Artist', 'Album', null, 'flac'], ['Artist', 'Album', 'UTC 1899 2001', 'Mp3'],
            ['Artist', 'Album', '1920', "m\x01"], ['Art', 'Alb', '', ''], [str_repeat('A', 240), 'Album', '2001', 'mp3'],
            [str_repeat('A', 236), 'Al', '2001', 'mp3'], ["Caf\xc3\xa9", 'Album', '2001', 'mp3'], ["Caf\xe9", 'Album', null, 'mp3'],
            ["Zero\xe2\x80\x8bWidth", 'Album', null, 'ogg'], ['Artist', 'Album', '20x26 19', 'wav'], ['A', 'B', '120001', 'wma'],
            [str_repeat("\xc3\xa9", 120), 'Album', null, 'mp3'], ['Artist', "Al\x7fbum", null, 'mp3'],
        ];
        foreach ($cases as [$performer, $album, $date, $ext]) {
            $this->assertAudioReleaseTitleParity($performer, $album, $date, $ext);
        }
        $fragments = ['Artist', 'Album', ' ', '-', '19', '20', '26', '7', "\x01", "\xc3\xa9", "\xe9"];
        $strings = $this->fuzzStrings(5, $fragments);
        for ($i = 0, $n = count($strings) - 3; $i < $n; $i += 4) {
            $this->assertAudioReleaseTitleParity($strings[$i], $strings[$i + 1], $i % 8 === 0 ? null : $strings[$i + 2], substr($strings[$i + 3], 0, 5));
        }
    }

    public function test_group_unavailable_message_parity(): void
    {
        $cases = [
            '411 No such news group', '411 NO SUCH NEWS GROUP', 'Group not found', 'group NOT found: alt.test', '', 'No such news',
            'news group not found', "No such news\0group", "Gr\xc3\xb6up not found", '480 Authentication required',
        ];
        foreach ($cases as $case) {
            $this->assertGroupUnavailableParity($case);
        }
        foreach ($this->fuzzStrings(6, ['No such news group', 'Group not found', 'NO SUCH', 'group', ' ', 'found']) as $case) {
            $this->assertGroupUnavailableParity($case);
        }
    }

    public function test_mapped_article_name_parity(): void
    {
        $cases = [
            'a.part01.RAR', 'a.7z', 'a.7Z.1', 'a.7z.0001', 'a.7z.', 'a.zip', 'a.001', "a.001\n", 'a.0001', 'a.01', '.123', '123',
            'Movie.MKV', 'Movie.Sample.mkv', 'Movie.mkv.par2', "Movie.mkv\n", 'Movie.mpeg', 'Movie.mpgv', 'Movie.qt', 'x.ts',
            'SAMPLE', 'movie.m4v', 'movie.mkvx', '', '.', "Movie\0.mkv", "Caf\xc3\xa9.mkv", 'a.rar.sample', 'a.RmVb',
        ];
        foreach ($cases as $case) {
            $this->assertMappedArticleParity($case);
        }
        $fragments = ['Movie', '.', 'rar', '7z', 'zip', '001', '1', 'mkv', 'MPEG', 'ts', 'sample', 'par2', "\n", 'x'];
        foreach ($this->fuzzStrings(7, $fragments) as $case) {
            $this->assertMappedArticleParity($case);
        }

        $supported = true;
        $this->assertSame(
            UsenetDownloadService::isMappedVideoNamePhp('Movie.webm', '\.(WEBM|MKV)'),
            UsenetDownloadService::isMappedVideoNameNative('Movie.webm', '\.(WEBM|MKV)', $supported),
        );
        $this->assertFalse($supported, 'custom video regexes must decline to PHP');
    }

    public function test_disabled_rust_ffi_falls_back_to_php(): void
    {
        ReleaseFileManager::useRustFfi(false);
        MediaExtractionService::useRustFfi(false);
        UsenetDownloadService::useRustFfi(false);

        $nfo = $title = $tag = $group = $kind = true;
        $this->assertTrue(ReleaseFileManager::isNfoFilenameNative('x.nfo', $nfo));
        $this->assertSame('Movie-Grp', $this->releaseFileManager()->extractReleaseNameFromFileNative('movie-grp.mkv', $title));
        $this->assertSame('Artist', MediaExtractionService::audioTagTextNative(' Artist ', $tag));
        $this->assertTrue(UsenetDownloadService::isGroupUnavailableMessageNative('No such news group', $group));
        $this->assertTrue(UsenetDownloadService::isMappedArchiveNameNative('a.rar', $kind));
        $this->assertSame([false, false, false, false, false], [$nfo, $title, $tag, $group, $kind]);
    }

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('WAVE10_RFM_AUDIO_TAG_BENCHMARK') !== '1') {
            $this->markTestSkipped('Set WAVE10_RFM_AUDIO_TAG_BENCHMARK=1 to run paired randomized benchmarks.');
        }

        $manager = $this->releaseFileManager();
        $workloads = [
            'isNfoFilename' => [
                ['Movie.2026.1080p.BluRay.x264-GROUP.mkv', 'Movie.2026.1080p.BluRay.x264-GROUP.nfo', 'Sample/movie-sample.mkv', 'movie.part01.rar'],
                ['Some/Deep/Path/Season.Pack.S02.2160p.WEB-DL.DDP5.1.H.265-GROUP/Season.Pack.S02E07.2160p.WEB-DL.DDP5.1.H.265-GROUP.mkv', 'a/00-grp.nfo/', 'FILE_ID.DIZ', 'release.info.txt'],
                static fn (string $s): bool => ReleaseFileManager::isNfoFilenamePhp($s),
                static fn (string $s): bool => ReleaseFileManager::isNfoFilenameNative($s),
            ],
            'extractReleaseNameFromFile' => [
                ['Movie.2026.1080p.BluRay.x264-GROUP.part01.rar', 'Show.Name.S01E01.720p.HDTV.x264-GRP.mkv', 'track 01.flac', 'movie-sample.mkv'],
                ['__NZBSPLIT__deadbeef01__NZBSPLIT__Movie_Name_2020-GRP.part01.rar', 'Show.Name.S02E05.1080p.WEB-DL.DDP5.1.H.264-GRP (1)', 'Some Long Release Name With Spaces 2026 [01/10]', 'dir/'.str_repeat('Long.Name.', 12).'x264-GRP.mkv'],
                static fn (string $s): ?string => $manager->extractReleaseNameFromFilePhp($s),
                static fn (string $s): ?string => $manager->extractReleaseNameFromFileNative($s),
            ],
            'normalizeFileSize' => [
                ['1073741824', '52428800', '4096', '0'],
                ['000000000000000000001073741824', '9223372036854775808', '12a3', '9223372036854775807'],
                static fn (string $s): ?int => ReleaseFileManager::normalizeFileSizePhp($s),
                static fn (string $s): ?int => ReleaseFileManager::normalizeFileSizeNative($s),
            ],
            'audioTagText' => [
                ['Artist Name', 'Album Title', '2026-01-02', 'Various Artists'],
                ["  Caf\xc3\xa9 del Mar \xf0\x9f\x8e\xb5  ", "Invalid \xe9 bytes", str_repeat('Long Album Title ', 12), "Ctrl\x01Char"],
                static fn (string $s): ?string => MediaExtractionService::audioTagTextPhp($s),
                static fn (string $s): ?string => MediaExtractionService::audioTagTextNative($s),
            ],
            'audioReleaseTitle' => [
                ['Artist Name', 'Album Title', 'Various Artists', 'Greatest Hits'],
                [str_repeat('Long Performer ', 8), "Caf\xc3\xa9 del Mar", str_repeat('Long Album ', 10), 'Live at 1999'],
                static fn (string $s): ?string => MediaExtractionService::audioReleaseTitlePhp($s, 'Album', 'UTC 2026-01-02', 'mp3'),
                static fn (string $s): ?string => MediaExtractionService::audioReleaseTitleNative($s, 'Album', 'UTC 2026-01-02', 'mp3'),
            ],
            'isGroupUnavailableMessage' => [
                ['430 No such article', '411 No such news group', 'Connection reset', '480 Authentication required'],
                [str_repeat('Server said: timeout while reading article body. ', 8), 'Group not found: alt.binaries.test', '', 'no SUCH news GROUP'],
                static fn (string $s): bool => UsenetDownloadService::isGroupUnavailableMessagePhp($s),
                static fn (string $s): bool => UsenetDownloadService::isGroupUnavailableMessageNative($s),
            ],
            'isMappedArchiveName' => [
                ['Movie.2026.1080p.part01.rar', 'Movie.2026.1080p.mkv', 'movie.7z.001', 'movie.r00'],
                [str_repeat('Long.Name.', 20).'part099.rar', 'archive.7z.0001', "Movie.001\n", 'movie.zipx'],
                static fn (string $s): bool => UsenetDownloadService::isMappedArchiveNamePhp($s),
                static fn (string $s): bool => UsenetDownloadService::isMappedArchiveNameNative($s),
            ],
            'isMappedVideoName' => [
                ['Movie.2026.1080p.BluRay.x264-GROUP.mkv', 'Movie.2026.sample.mkv', 'Movie.2026.1080p.part01.rar', 'Movie.mp4'],
                [str_repeat('Long.Name.', 20).'mpeg', "Movie.MKV\n", 'Movie.mkv.par2', 'Show.S01E01.2160p.WEB.H265-GRP.ts'],
                static fn (string $s): bool => UsenetDownloadService::isMappedVideoNamePhp($s, self::DEFAULT_VIDEO_FILE_REGEX),
                static fn (string $s): bool => UsenetDownloadService::isMappedVideoNameNative($s, self::DEFAULT_VIDEO_FILE_REGEX),
            ],
        ];

        $results = [];
        foreach ($workloads as $name => [$common, $uncommon, $php, $rust]) {
            foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $inputs) {
                $results["{$name}.{$kind}"] = $this->runPairedBenchmark(2000, $inputs, $php, $rust);
            }
        }

        foreach ($results as $label => $stats) {
            fwrite(STDERR, sprintf(
                "%-36s mean=%.2fx p99=%.2fx php_mean=%.0fns rust_mean=%.0fns php_p99=%.0fns rust_p99=%.0fns n=%d\n",
                $label,
                $stats['mean_speedup'],
                $stats['p99_speedup'],
                $stats['php_mean_ns'],
                $stats['rust_mean_ns'],
                $stats['php_p99_ns'],
                $stats['rust_p99_ns'],
                $stats['samples'],
            ));
        }
        $this->assertNotEmpty($results);
    }

    private function assertNfoFilenameParity(string $case): void
    {
        $supported = false;
        $php = ReleaseFileManager::isNfoFilenamePhp($case);
        $this->assertSame($php, ReleaseFileManager::isNfoFilenameNative($case, $supported), 'isNfoFilename: '.json_encode(bin2hex($case)));
        $this->assertTrue($supported);
        $this->assertSame($php, $this->releaseFileManager()->isNfoFilename($case));
    }

    private function assertExtractReleaseNameParity(ReleaseFileManager $manager, string $case): void
    {
        $supported = false;
        $php = $manager->extractReleaseNameFromFilePhp($case);
        $native = $manager->extractReleaseNameFromFileNative($case, $supported);
        $this->assertSame($php, $native, 'extractReleaseNameFromFile: '.bin2hex($case));
        $plainAscii = preg_match('/[^\x01-\x09\x0B-\x7F]/', $case) !== 1 && strlen($case) <= 1024;
        if ($plainAscii && stripos($case, '__NZBSPLIT__') === false && strlen($case) < 200 && preg_match('/[._-]{8}/', $case) !== 1) {
            $this->assertTrue($supported, 'extractReleaseNameFromFile declined: '.bin2hex($case));
        }
        $this->assertSame($php, (fn (string $f): ?string => $this->extractReleaseNameFromFile($f))->call($manager, $case));
    }

    private function assertFileSizeParity(mixed $case): void
    {
        $supported = false;
        $php = ReleaseFileManager::normalizeFileSizePhp($case);
        $this->assertSame($php, ReleaseFileManager::normalizeFileSizeNative($case, $supported), 'normalizeFileSize: '.var_export($case, true));
        $this->assertSame(is_string($case), $supported);
    }

    private function assertAudioTagTextParity(mixed $case): void
    {
        $supported = false;
        $php = MediaExtractionService::audioTagTextPhp($case);
        $label = is_string($case) ? bin2hex($case) : get_debug_type($case);
        $this->assertSame($php, MediaExtractionService::audioTagTextNative($case, $supported), 'audioTagText: '.$label);
        $this->assertSame(is_string($case), $supported);
    }

    private function assertAudioReleaseTitleParity(string $performer, string $album, ?string $date, string $ext): void
    {
        $supported = false;
        $php = MediaExtractionService::audioReleaseTitlePhp($performer, $album, $date, $ext);
        $native = MediaExtractionService::audioReleaseTitleNative($performer, $album, $date, $ext, $supported);
        $this->assertSame($php, $native, 'audioReleaseTitle: '.bin2hex($performer.'|'.$album.'|'.($date ?? 'null').'|'.$ext));
        $this->assertSame($php, MediaExtractionService::audioReleaseTitle($performer, $album, $date, $ext));
        if (mb_check_encoding($performer.$album.($date ?? '').$ext, 'ASCII')) {
            $this->assertTrue($supported);
        }
    }

    private function assertGroupUnavailableParity(string $case): void
    {
        $supported = false;
        $php = UsenetDownloadService::isGroupUnavailableMessagePhp($case);
        $this->assertSame($php, UsenetDownloadService::isGroupUnavailableMessageNative($case, $supported), 'isGroupUnavailableMessage: '.bin2hex($case));
        $this->assertTrue($supported);
    }

    private function assertMappedArticleParity(string $case): void
    {
        $archiveSupported = false;
        $videoSupported = false;
        $archive = UsenetDownloadService::isMappedArchiveNamePhp($case);
        $video = UsenetDownloadService::isMappedVideoNamePhp($case, self::DEFAULT_VIDEO_FILE_REGEX);
        $this->assertSame($archive, UsenetDownloadService::isMappedArchiveNameNative($case, $archiveSupported), 'isMappedArchiveName: '.bin2hex($case));
        $this->assertSame($video, UsenetDownloadService::isMappedVideoNameNative($case, self::DEFAULT_VIDEO_FILE_REGEX, $videoSupported), 'isMappedVideoName: '.bin2hex($case));
        $this->assertTrue($archiveSupported && $videoSupported);
        $this->assertSame($archive, UsenetDownloadService::isMappedArchiveName($case));
        $this->assertSame($video, UsenetDownloadService::isMappedVideoName($case, self::DEFAULT_VIDEO_FILE_REGEX));
    }

    /**
     * @param  list<string>  $fragments
     * @return list<string>
     */
    private function fuzzStrings(int $seed, array $fragments): array
    {
        mt_srand($seed);
        $alphabet = "aZ09._-'()[] /\n\t\x00\x0b\x7f\xc3\xa9\xff";
        $out = [];
        for ($i = 0; $i < self::FUZZ_CASES; $i++) {
            $s = '';
            $parts = mt_rand(0, 10);
            for ($p = 0; $p < $parts; $p++) {
                $s .= mt_rand(0, 2) === 0
                    ? $alphabet[mt_rand(0, strlen($alphabet) - 1)]
                    : $fragments[mt_rand(0, count($fragments) - 1)];
            }
            $out[] = $s;
        }

        return $out;
    }

    private function releaseFileManager(): ReleaseFileManager
    {
        $class = new \ReflectionClass(ReleaseFileManager::class);
        $manager = $class->newInstanceWithoutConstructor();
        $class->getProperty('fileNameCleaner')->setValue($manager, new FileNameCleaner);

        return $manager;
    }

    /**
     * Paired single-call samples over rotating inputs, with per-pair randomized order.
     *
     * @param  list<string>  $inputs
     * @return array{mean_speedup: float, p99_speedup: float, php_mean_ns: float, rust_mean_ns: float, php_p99_ns: float, rust_p99_ns: float, samples: int}
     */
    private function runPairedBenchmark(int $pairs, array $inputs, callable $phpFn, callable $rustFn): array
    {
        foreach ($inputs as $input) {
            for ($w = 0; $w < 50; $w++) {
                $phpFn($input);
                $rustFn($input);
            }
        }

        mt_srand(0x5EED);
        $phpTimes = [];
        $rustTimes = [];
        $count = count($inputs);
        for ($i = 0; $i < $pairs; $i++) {
            $input = $inputs[$i % $count];
            if (mt_rand(0, 1) === 1) {
                $t0 = hrtime(true);
                $rustFn($input);
                $rustTimes[] = hrtime(true) - $t0;
                $t1 = hrtime(true);
                $phpFn($input);
                $phpTimes[] = hrtime(true) - $t1;
            } else {
                $t0 = hrtime(true);
                $phpFn($input);
                $phpTimes[] = hrtime(true) - $t0;
                $t1 = hrtime(true);
                $rustFn($input);
                $rustTimes[] = hrtime(true) - $t1;
            }
        }

        sort($phpTimes);
        sort($rustTimes);
        $phpMean = array_sum($phpTimes) / count($phpTimes);
        $rustMean = max(1.0, array_sum($rustTimes) / count($rustTimes));
        $p99Index = min(count($phpTimes) - 1, (int) floor(count($phpTimes) * 0.99));
        $phpP99 = (float) $phpTimes[$p99Index];
        $rustP99 = max(1.0, (float) $rustTimes[$p99Index]);

        return [
            'mean_speedup' => $phpMean / $rustMean,
            'p99_speedup' => $phpP99 / $rustP99,
            'php_mean_ns' => $phpMean,
            'rust_mean_ns' => $rustMean,
            'php_p99_ns' => $phpP99,
            'rust_p99_ns' => $rustP99,
            'samples' => count($phpTimes),
        ];
    }
}
