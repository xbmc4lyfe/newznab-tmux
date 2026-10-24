<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\DTO\Par2FileEntry;
use App\Services\AdditionalProcessing\Media\MediaSourceResolver;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\EpisodeHydrationService;
use App\Support\RustPipelineLibrary;
use dariusiii\rarinfo\SzipInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use stdClass;

/**
 * Parity and paired-benchmark suite for Wave 7 Slice 7.6 (`rust/pipeline-ffi/src/additional.rs`):
 * - `EpisodeHydrationService` (`nntmux_episode_hydrate_batch_nul`, `nntmux_episode_parse_searchname`)
 * - `ArchiveExtractionService` (`nntmux_archive_7z_validate_end_header`, `nntmux_archive_rank_nfo_files_nul`,
 *   `nntmux_archive_is_nfo_file`, `nntmux_archive_detect_standalone_video`, `completeStoredEntries`)
 * - `MediaSourceResolver::wantedFiles` (`nntmux_media_source_rank_wanted_files_nul`)
 */
final class RustEpisodeHydrationArchiveRankParityTest extends TestCase
{
    private const string MISSING_LIBRARY = '/nonexistent/libnntmux_pipeline.so';

    private const string DEFAULT_VIDEO_FILE_REGEX = '\.(AVI|F4V|IFO|M1V|M2V|M4V|MKV|MOV|MP4|MPEG|MPG|MPGV|MPV|OGV|QT|RM|RMVB|TS|VOB|WMV)';

    private const int SEED = 20261024;

    #[Test]
    public function rust_ffi_domains_are_available(): void
    {
        self::assertTrue(RustPipelineLibrary::available());
        self::assertNotNull(RustPipelineLibrary::resolveDomain('episode_hydration', EpisodeHydrationService::FFI_CDEF));
        self::assertNotNull(RustPipelineLibrary::resolveDomain('archive_extraction', ArchiveExtractionService::FFI_CDEF));
        self::assertNotNull(RustPipelineLibrary::resolveDomain('media_source_resolver', MediaSourceResolver::FFI_CDEF));
    }

    /**
     * @return array<string, array{string, bool, bool, array{series: int|null, episode: int|null}}>
     */
    public static function episodeSearchnameCases(): array
    {
        return [
            'SxxExx standard' => ['Show.Name.S02E14.1080p.WEB-DL-GRP', true, true, ['series' => 2, 'episode' => 14]],
            'SxxExx zero season' => ['Show.Name.s00e05.720p.HDTV', true, true, ['series' => 0, 'episode' => 5]],
            'SxxExx 3 digit episode' => ['Long.Show.S1E124.720p', true, true, ['series' => 1, 'episode' => 124]],
            'SxxExx 4 digit episode rejected' => ['Long.Show.S01E1234.720p', true, true, ['series' => null, 'episode' => null]],
            'NxNN standard' => ['Show.Name.3x09.720p.HDTV', true, true, ['series' => 3, 'episode' => 9]],
            'NxNN uppercase X' => ['Show.Name.12X105.HDTV', true, true, ['series' => 12, 'episode' => 105]],
            'Season Episode words' => ['Show.Season.04.Episode.12.DVDRip', true, true, ['series' => 4, 'episode' => 12]],
            'Season Episode vertical tab and formfeed' => ["Show.Season\x0B2\x0CEpisode_08.mkv", true, true, ['series' => 2, 'episode' => 8]],
            'Compact 3-digit when both missing' => ['Sitcom.Name.408.HDTV-GRP', true, true, ['series' => 4, 'episode' => 8]],
            'Compact 3-digit skipped when series already present' => ['Sitcom.Name.408.Part.6.HDTV-GRP', false, true, ['series' => null, 'episode' => 6]],
            'Compact 3-digit 100 rejected' => ['Movie.100.200.300.mkv', true, true, ['series' => null, 'episode' => null]],
            'Date based YYYY.MM.DD' => ['Daily.Show.2024.01.15.Guest.1080p', true, true, ['series' => 2024, 'episode' => 115]],
            'Date based mixed separators' => ['Daily.Show.2024-11_05.Guest.1080p', true, true, ['series' => 2024, 'episode' => 1105]],
            'Part N sets series 1 when missing' => ['Miniseries.Part.03.2160p', true, true, ['series' => 1, 'episode' => 3]],
            'Pt N keeps existing series' => ['Miniseries.Pt_7.1080p', false, true, ['series' => null, 'episode' => 7]],
            'Part N ignored when episode already present' => ['Miniseries.Part.03.2160p', true, false, ['series' => null, 'episode' => null]],
            'Ep N sets series 1 when missing' => ['Anime.Show.Ep.24.1080p', true, true, ['series' => 1, 'episode' => 24]],
            'Ep 024 matches Pattern 4 compact 3-digit before Pattern 7' => ['Anime.Show.Ep.024.1080p', true, true, ['series' => 0, 'episode' => 24]],
            'E N sets episode only when series present' => ['Anime.Show.E9.1080p', false, true, ['series' => null, 'episode' => 9]],
            'Pattern 1 precedes Pattern 2 even if later in string' => ['Show.2x05.S03E09.mkv', true, true, ['series' => 3, 'episode' => 9]],
            'Pattern 6 Part 0 stops before Pattern 7 Ep 5' => ['Show.Part.0.Ep.5.mkv', true, true, ['series' => 1, 'episode' => 0]],
        ];
    }

    /**
     * @param  array{series: int|null, episode: int|null}  $expected
     */
    #[Test]
    #[DataProvider('episodeSearchnameCases')]
    public function episode_parse_searchname_matches_php(
        string $searchname,
        bool $missingSeries,
        bool $missingEpisode,
        array $expected,
    ): void {
        $service = new EpisodeHydrationService;
        $fallbackService = new EpisodeHydrationService(self::MISSING_LIBRARY);

        $supported = false;
        $native = $service->parseSearchnameNative($searchname, $missingSeries, $missingEpisode, $supported);
        $php = $service->parseSearchnamePhp($searchname, $missingSeries, $missingEpisode);
        $fallbackUsed = true;
        $fallback = $fallbackService->parseSearchnameNative($searchname, $missingSeries, $missingEpisode, $fallbackUsed);

        self::assertTrue($supported);
        self::assertFalse($fallbackUsed);
        self::assertSame($expected, $php);
        self::assertSame($php, $native);
        self::assertSame($php, $fallback);
    }

    #[Test]
    public function episode_hydrate_batch_matches_php_across_curated_and_random_collections(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        $service = new EpisodeHydrationService;
        $fallbackService = new EpisodeHydrationService(self::MISSING_LIBRARY);

        $tokens = [
            'Show', 'Series', 'S01E02', 's12e105', 'S00E01', '3x07', '10X22', 'Season.2.Episode.9',
            "Season\x0B03_Episode-14", '102', '009', '300', '2024.01.15', '2023-11_04', 'Part.3',
            'Pt_12', 'Part0', 'Ep.18', 'E07', '1080p', '720p', '2160p', 'WEB-DL', 'x264', 'H265',
        ];
        $seps = ['.', '_', '-', ' ', "\t", "\n", "\x0B", "\x0C", '/', ''];
        $dates = [
            null,
            '',
            '2024-01-15',
            '2024/08/09',
            '2024-02-29 20:30:00',
            '2023-12-31T23:59:59',
            '2024-02-30', // Non-canonical rollover date handled via per-item PHP fallback
            'January 15, 2024', // Non-canonical textual date handled via per-item PHP fallback
        ];

        $rawItems = [];
        for ($i = 0; $i < 350; $i++) {
            $parts = [];
            $count = $random->getInt(1, 7);
            for ($j = 0; $j < $count; $j++) {
                $parts[] = $tokens[$random->getInt(0, count($tokens) - 1)];
                $parts[] = $seps[$random->getInt(0, count($seps) - 1)];
            }
            $rawItems[] = [
                'tv_episodes_id' => $random->getInt(0, 5) === 0 ? -1 : 0,
                'searchname' => implode('', $parts),
                'firstaired' => $dates[$random->getInt(0, count($dates) - 1)],
                'series' => match ($random->getInt(0, 4)) {
                    0 => 0,
                    1 => 5,
                    default => null,
                },
                'episode' => match ($random->getInt(0, 4)) {
                    0 => 0,
                    1 => 11,
                    default => null,
                },
            ];
        }

        $cloneBatch = static function (array $specs): array {
            $out = [];
            foreach ($specs as $spec) {
                $obj = new stdClass;
                $obj->tv_episodes_id = $spec['tv_episodes_id'];
                $obj->searchname = $spec['searchname'];
                $obj->firstaired = $spec['firstaired'];
                $obj->series = $spec['series'];
                $obj->episode = $spec['episode'];
                $out[] = $obj;
            }

            return $out;
        };

        $expected = $cloneBatch($rawItems);
        $actual = $cloneBatch($rawItems);
        $fallback = $cloneBatch($rawItems);

        $service->hydrateEpisodeMetadataPhp($expected);
        $supported = false;
        $service->hydrateEpisodeMetadataNative($actual, $supported);
        $fallbackSupported = true;
        $fallbackService->hydrateEpisodeMetadataNative($fallback, $fallbackSupported);

        self::assertTrue($supported);
        self::assertFalse($fallbackSupported);
        self::assertEquals($expected, $actual);
        self::assertEquals($expected, $fallback);
    }

    #[Test]
    public function archive_7z_end_header_validation_matches_php(): void
    {
        $service = $this->makeArchiveService();
        $fallbackService = $this->makeArchiveService(self::MISSING_LIBRARY);

        $endHeader = "\x01\x04\x06\x00\x01\x09\x80\x80\x00\x05\x01\x19\x07\x00".random_bytes(120);
        $endCrc = crc32($endHeader);
        $startBody = pack('PPV', 4096, strlen($endHeader), $endCrc);
        $validHead = SzipInfo::MARKER_SIGNATURE."\x00\x04".pack('V', crc32($startBody)).$startBody.random_bytes(64);
        $validTail = random_bytes(256).$endHeader;

        $supported = false;
        $native = $service->withSevenZipEndHeaderNative($validHead, $validTail, $supported);
        $php = $service->withSevenZipEndHeaderPhp($validHead, $validTail);
        self::assertTrue($supported);
        self::assertIsString($php);
        self::assertSame($php, $native);
        self::assertSame($php, $service->withSevenZipEndHeader($validHead, $validTail));
        self::assertSame($php, $fallbackService->withSevenZipEndHeader($validHead, $validTail));

        $invalidCases = [
            'corrupt tail crc' => [$validHead, substr($validTail, 0, -1).(chr(ord($validTail[strlen($validTail) - 1]) ^ 1))],
            'tail shorter than next_size' => [$validHead, substr($endHeader, 0, -1)],
            'head shorter than 32 bytes' => [substr($validHead, 0, 31), $validTail],
            'non-7z signature' => ['Rar!'.substr($validHead, 4), $validTail],
            'zero next_size' => [
                SzipInfo::MARKER_SIGNATURE."\x00\x04".pack('V', 0).pack('PPV', 100, 0, 0),
                $validTail,
            ],
            'next_offset past cap' => [
                SzipInfo::MARKER_SIGNATURE."\x00\x04".pack('V', 0).pack('PPV', ArchiveExtractionService::SEVENZIP_MAX_ARCHIVE_BYTES, 16, $endCrc),
                $validTail,
            ],
            'next_offset plus next_size overflow' => [
                SzipInfo::MARKER_SIGNATURE."\x00\x04".pack('V', 0).pack('PPV', PHP_INT_MAX - 16, 64, $endCrc),
                $validTail,
            ],
        ];

        foreach ($invalidCases as $label => [$head, $tail]) {
            $usedRust = false;
            self::assertNull($service->withSevenZipEndHeaderPhp($head, $tail), $label.' php');
            self::assertNull($service->withSevenZipEndHeaderNative($head, $tail, $usedRust), $label.' native');
            self::assertTrue($usedRust, $label.' rust path');
        }
    }

    #[Test]
    public function archive_nfo_ranking_and_detection_match_php(): void
    {
        $service = $this->makeArchiveService();
        $fallbackService = $this->makeArchiveService(self::MISSING_LIBRARY);

        $curatedNames = [
            '', '/', '///', 'release.nfo', 'DIR/RELEASE.NFO', 'dir/file.diz', 'dir/file.inf',
            'dir/file_id.diz', 'dir/fileid.diz', 'dir/file-id.diz', 'dir/readme.txt', 'dir/readme.1st',
            'dir/read.me', 'dir/readmenow.txt', 'dir/info.txt', 'dir/information.txt', 'dir/about.txt',
            'dir/notes.txt', 'dir/release.txt', 'dir/00-group_name.txt', 'dir/0-group-name.nfo',
            'dir/group-release.2026.1080p.txt', 'dir/group-release.nfo/', "dir/release.nfo\n",
            "dir/00-group.txt\n", "dir/readme.txt\n", 'dir/notes_extra.txt', 'dir/-bad.txt',
            'dir/bad-.txt', 'dir/a_b-c.txt', 'dir/movie.mkv', 'dir/track01.flac',
        ];

        foreach ($curatedNames as $name) {
            $supported = false;
            $expected = $service->isNfoFile($name);
            $actual = $service->isNfoFileNative($name, $supported);
            self::assertTrue($supported, $name);
            self::assertSame($expected, $actual, 'isNfoFile mismatch on '.var_export($name, true));
        }

        $random = new Randomizer(new Mt19937(self::SEED + 1));
        for ($batch = 0; $batch < 200; $batch++) {
            $count = $random->getInt(0, 60);
            $files = [];
            for ($i = 0; $i < $count; $i++) {
                $name = $curatedNames[$random->getInt(0, count($curatedNames) - 1)];
                if ($random->getInt(0, 1) === 1) {
                    $name = 'Sub'.$i.'/'.$name;
                }
                $files[] = ['name' => $name, 'size' => $i * 100, 'index' => $i];
            }

            $supported = false;
            $expected = $service->sortFilesWithNfoPriorityPhp($files);
            $actual = $service->sortFilesWithNfoPriorityNative($files, $supported);
            self::assertTrue($supported, 'batch #'.$batch);
            self::assertSame($expected, $actual, 'batch #'.$batch);
            self::assertSame($expected, $service->sortFilesWithNfoPriority($files), 'batch #'.$batch);
            self::assertSame($expected, $fallbackService->sortFilesWithNfoPriority($files), 'batch #'.$batch);
        }
    }

    #[Test]
    public function standalone_video_detection_and_stored_entries_match_php(): void
    {
        $service = $this->makeArchiveService();

        $videoFixtures = [
            'avi' => 'RIFF'.str_repeat("\0", 4).'AVI '.str_repeat("\0", 16),
            'mkv' => "\x1A\x45\xDF\xA3".str_repeat("\0", 24),
            'mpg pack' => "\x00\x00\x01\xBA".str_repeat("\0", 24),
            'mpg seq' => "\x00\x00\x01\xB3".str_repeat("\0", 24),
            'ts 3 packets' => str_repeat("\x47".str_repeat("\0", 187), 3),
            'ts only 2 packets' => str_repeat("\x47".str_repeat("\0", 187), 2),
            'mp4 isom' => "\x00\x00\x00\x18ftypisom".str_repeat("\0", 16),
            'mp4 non-printable brand' => "\x00\x00\x00\x18ftypis\x1Fm".str_repeat("\0", 16),
            'mp4 newline in brand' => "\x00\x00\x00\x18ftypisom\n".str_repeat("\0", 16),
            'wmv' => "\x30\x26\xB2\x75\x8E\x66\xCF\x11".str_repeat("\0", 16),
            'flv' => "FLV\x01".str_repeat("\0", 16),
            'ogv' => 'OggS'.str_repeat("\0", 16),
            'mkv + ftyp collision' => "\x1A\x45\xDF\xA3ftypisom".str_repeat("\0", 16),
            'short' => 'RIFF',
            'empty' => '',
            'rar archive' => "Rar!\x1A\x07\x00".str_repeat("\0", 32),
        ];

        foreach ($videoFixtures as $label => $bytes) {
            $supported = false;
            $expected = $service->detectStandaloneVideo($bytes);
            $actual = $service->detectStandaloneVideoNative($bytes, $supported);
            self::assertTrue($supported, $label);
            self::assertSame($expected, $actual, $label);
        }

        $storedCases = [
            ['name' => 'valid.jpg', 'size' => 10, 'range' => '20-29', 'source' => 'main', 'pass' => 0, 'compressed' => 0],
            ['name' => 'truncated.jpg', 'size' => 11, 'range' => '20-29', 'source' => 'main', 'pass' => 0, 'compressed' => 0],
            ['name' => 'compressed.jpg', 'size' => 10, 'range' => '20-29', 'source' => 'main', 'pass' => 0, 'compressed' => 1],
            ['name' => 'encrypted.jpg', 'size' => 10, 'range' => '20-29', 'source' => 'main', 'pass' => 1, 'compressed' => 0],
            ['name' => 'dir', 'size' => 10, 'range' => '20-29', 'source' => 'main', 'pass' => 0, 'compressed' => 0, 'is_dir' => 1],
            ['name' => 'nested.jpg', 'size' => 10, 'range' => '20-29', 'source' => 'nested', 'pass' => 0, 'compressed' => 0],
            ['name' => 'no_dash.jpg', 'size' => 10, 'range' => '20', 'source' => 'main', 'pass' => 0, 'compressed' => 0],
            ['name' => 'leading_dash.jpg', 'size' => 6, 'range' => '-5-10', 'source' => 'main', 'pass' => 0, 'compressed' => 0],
            ['name' => 'zero_size.jpg', 'size' => 0, 'range' => '0--1', 'source' => 'main', 'pass' => 0, 'compressed' => 0],
            'not-an-array',
        ];

        self::assertSame(
            ArchiveExtractionService::completeStoredEntriesLegacy($storedCases),
            ArchiveExtractionService::completeStoredEntries($storedCases),
        );
    }

    #[Test]
    public function media_source_resolver_wanted_files_matches_php(): void
    {
        $resolver = $this->makeResolver();
        $customShortResolver = $this->makeResolver(videoRegex: '\.(AVI|MKV|MP4)');
        $fallbackResolver = $this->makeResolver(libraryPath: self::MISSING_LIBRARY);

        $curatedNames = [
            'Movie.part01.rar', 'Movie.part1.rar', 'Movie.part001.rar', 'Movie.part02.rar',
            'Movie.part10.rar', 'Movie.part0.rar', 'part02.rar', 'Movie.part02.bar.rar',
            'Movie.rar', 'Movie.7z', 'Movie.7z.001', 'Movie.7z.01', 'Movie.7z.002',
            'Movie.zip', 'Movie.001', 'Movie.01', 'Movie.1', 'Movie.002', 'Movie.r00',
            'Movie.sample.mkv', 'sample.mkv', 'dir/sample/Movie.mkv', 'dir\\sample_clip.mp4',
            "Movie-sample\n", "Movie-sample\n.mkv", 'mysample.mkv', 'sample1.mkv',
            'Movie.2026.1080p.mkv', 'Movie.2026.720p.mp4', 'Movie.2026.avi', 'Movie.2026.ts',
            'poster.jpg', 'cover.jpeg', 'screen.png', 'fanart.webp', "poster.jpg\n",
            'movie.nfo', 'movie.sfv', 'movie.par2',
        ];

        $random = new Randomizer(new Mt19937(self::SEED + 2));
        for ($batch = 0; $batch < 200; $batch++) {
            $count = $random->getInt(0, 60);
            $entries = [];
            for ($i = 0; $i < $count; $i++) {
                $name = $curatedNames[$random->getInt(0, count($curatedNames) - 1)];
                $size = $random->getInt(0, 5) * 1000000;
                $entries[] = new Par2FileEntry($name, $size);
            }

            foreach ([$resolver, $customShortResolver] as $r) {
                $supported = false;
                $expected = $r->wantedFilesPhp($entries);
                $actual = $r->wantedFilesNative($entries, $supported);
                self::assertTrue($supported);
                self::assertSame($expected, $actual);
                self::assertSame($expected, $r->wantedFiles($entries));
            }

            $fbSupported = true;
            self::assertSame(
                $resolver->wantedFilesPhp($entries),
                $fallbackResolver->wantedFilesNative($entries, $fbSupported),
            );
            self::assertFalse($fbSupported);
        }
    }

    /**
     * Opt-in paired randomized benchmark:
     * WAVE7_EPISODE_ARCHIVE_BENCHMARK=1 php artisan test --compact tests/Unit/RustEpisodeHydrationArchiveRankParityTest.php --filter=benchmark
     */
    #[Test]
    public function paired_randomized_benchmark(): void
    {
        if (getenv('WAVE7_EPISODE_ARCHIVE_BENCHMARK') !== '1') {
            self::markTestSkipped('Opt-in paired randomized benchmark.');
        }

        $random = new Randomizer(new Mt19937(self::SEED));
        $pairs = max(50, min(2000, (int) (getenv('WAVE7_BENCHMARK_PAIRS') ?: 400)));
        $p99Idx = (int) ceil($pairs * 0.99) - 1;

        $hydrationSvc = new EpisodeHydrationService;
        $archiveSvc = $this->makeArchiveService();
        $resolver = $this->makeResolver();

        $templates = [
            ['Show.Name.S02E14.1080p.WEB-DL.DDP5.1.H.264-GRP', null],
            ['Another.Show.3x09.720p.HDTV.x264-GRP', null],
            ['Classic.Show.Season.04.Episode.12.DVDRip.XviD-GRP', null],
            ['Sitcom.Name.408.720p.HDTV-GRP', null],
            ['Late.Night.Show.2024.09.18.Guest.Name.1080p.WEB.h264-GRP', null],
            ['Documentary.Event.Part.02.2160p.WEB-DL.HEVC-GRP', null],
            ['Anime.Series.Name.Ep.024.1080p.CR.WEB-DL.AAC2.0.H.264-GRP', null],
            ['Unmatched.Feature.Film.2021.1080p.BluRay.x264-GRP', null],
            ['Daily.Show.Special.1080p.WEB-DL-GRP', '2024-05-19'],
            ['Talk.Show.2023.720p.HDTV-GRP', '2023-11-04 20:00:00'],
        ];

        $makeReleases = static function (int $n) use ($templates): array {
            $out = [];
            for ($i = 0; $i < $n; $i++) {
                [$sn, $fa] = $templates[$i % count($templates)];
                $obj = new stdClass;
                $obj->tv_episodes_id = 0;
                $obj->searchname = $sn.'.'.$i;
                $obj->firstaired = $fa;
                $obj->series = null;
                $obj->episode = null;
                $out[] = $obj;
            }

            return $out;
        };

        $makeArchiveFiles = static function (int $n): array {
            $files = [];
            for ($i = 0; $i < $n; $i++) {
                $name = match ($i) {
                    intdiv($n, 2) => 'Release/00-scene_group.txt',
                    $n - 2 => 'Release/some-group.nfo',
                    default => sprintf('Release/Track_%03d_Audio_File.flac', $i),
                };
                $files[] = ['name' => $name, 'size' => 10000 + $i];
            }

            return $files;
        };

        $makePar2Entries = static function (int $n): array {
            $entries = [];
            for ($i = 1; $i <= $n - 6; $i++) {
                $entries[] = new Par2FileEntry(sprintf('Movie.2026.1080p.part%03d.rar', $i), 50000000);
            }
            $entries[] = new Par2FileEntry('Movie.2026.1080p.sample.mkv', 15000000);
            $entries[] = new Par2FileEntry('Movie.2026.1080p.mkv', 1400000000);
            $entries[] = new Par2FileEntry('Movie.2026.1080p.extra.mp4', 200000000);
            $entries[] = new Par2FileEntry('poster.jpg', 450000);
            $entries[] = new Par2FileEntry('movie.nfo', 4200);
            $entries[] = new Par2FileEntry('movie.sfv', 1200);

            return $entries;
        };

        $make7zPair = static function (int $endHeaderBytes): array {
            $endHeader = random_bytes($endHeaderBytes);
            $endCrc = crc32($endHeader);
            $startBody = pack('PPV', 500000, strlen($endHeader), $endCrc);
            $head = SzipInfo::MARKER_SIGNATURE."\x00\x04".pack('V', crc32($startBody)).$startBody.random_bytes(4096);
            $tail = random_bytes(16384).$endHeader;

            return [$head, $tail];
        };

        $releases100 = $makeReleases(100);
        $releases500 = $makeReleases(500);
        $resetReleases = static function (array $batch): void {
            foreach ($batch as $r) {
                $r->series = null;
                $r->episode = null;
            }
        };
        $files40 = $makeArchiveFiles(40);
        $files250 = $makeArchiveFiles(250);
        $par2Common = $makePar2Entries(45);
        $par2Uncommon = $makePar2Entries(200);
        [$head7zCommon, $tail7zCommon] = $make7zPair(2048);
        [$head7zUncommon, $tail7zUncommon] = $make7zPair(16384);

        $noop = static function (): void {};
        $workloads = [
            'episode_hydration_common_100' => [
                static fn () => $resetReleases($releases100),
                static fn () => $hydrationSvc->hydrateEpisodeMetadataPhp($releases100),
                static fn () => $hydrationSvc->hydrateEpisodeMetadataNative($releases100),
            ],
            'episode_hydration_uncommon_500' => [
                static fn () => $resetReleases($releases500),
                static fn () => $hydrationSvc->hydrateEpisodeMetadataPhp($releases500),
                static fn () => $hydrationSvc->hydrateEpisodeMetadataNative($releases500),
            ],
            'archive_sort_nfo_common_40' => [
                $noop,
                static fn () => $archiveSvc->sortFilesWithNfoPriorityPhp($files40),
                static fn () => $archiveSvc->sortFilesWithNfoPriorityNative($files40),
            ],
            'archive_sort_nfo_uncommon_250' => [
                $noop,
                static fn () => $archiveSvc->sortFilesWithNfoPriorityPhp($files250),
                static fn () => $archiveSvc->sortFilesWithNfoPriorityNative($files250),
            ],
            'archive_7z_end_header_common_2k' => [
                $noop,
                static fn () => $archiveSvc->withSevenZipEndHeaderPhp($head7zCommon, $tail7zCommon),
                static fn () => $archiveSvc->withSevenZipEndHeaderNative($head7zCommon, $tail7zCommon),
            ],
            'archive_7z_end_header_uncommon_16k' => [
                $noop,
                static fn () => $archiveSvc->withSevenZipEndHeaderPhp($head7zUncommon, $tail7zUncommon),
                static fn () => $archiveSvc->withSevenZipEndHeaderNative($head7zUncommon, $tail7zUncommon),
            ],
            'media_source_wanted_common_45' => [
                $noop,
                static fn () => $resolver->wantedFilesPhp($par2Common),
                static fn () => $resolver->wantedFilesNative($par2Common),
            ],
            'media_source_wanted_uncommon_200' => [
                $noop,
                static fn () => $resolver->wantedFilesPhp($par2Uncommon),
                static fn () => $resolver->wantedFilesNative($par2Uncommon),
            ],
        ];

        foreach ($workloads as $name => [$prepFn, $phpFn, $rustFn]) {
            for ($w = 0; $w < 5; $w++) {
                $prepFn();
                $phpFn();
                $prepFn();
                $rustFn();
            }
            $phpTimes = [];
            $rustTimes = [];
            for ($i = 0; $i < $pairs; $i++) {
                if ($random->getInt(0, 1) === 0) {
                    $prepFn();
                    $t0 = hrtime(true);
                    $phpFn();
                    $phpTimes[] = (hrtime(true) - $t0) / 1e3;
                    $prepFn();
                    $t0 = hrtime(true);
                    $rustFn();
                    $rustTimes[] = (hrtime(true) - $t0) / 1e3;
                } else {
                    $prepFn();
                    $t0 = hrtime(true);
                    $rustFn();
                    $rustTimes[] = (hrtime(true) - $t0) / 1e3;
                    $prepFn();
                    $t0 = hrtime(true);
                    $phpFn();
                    $phpTimes[] = (hrtime(true) - $t0) / 1e3;
                }
            }
            sort($phpTimes);
            sort($rustTimes);
            $meanSpeedup = (array_sum($phpTimes) / $pairs) / (array_sum($rustTimes) / $pairs);
            $p99Speedup = $phpTimes[$p99Idx] / $rustTimes[$p99Idx];

            fwrite(STDOUT, json_encode([
                'workload' => $name,
                'pairs' => $pairs,
                'php_mean_us' => round(array_sum($phpTimes) / $pairs, 3),
                'php_p99_us' => round($phpTimes[$p99Idx], 3),
                'rust_mean_us' => round(array_sum($rustTimes) / $pairs, 3),
                'rust_p99_us' => round($rustTimes[$p99Idx], 3),
                'speedup_mean' => round($meanSpeedup, 2),
                'speedup_p99' => round($p99Speedup, 2),
            ], JSON_THROW_ON_ERROR)."\n");

            self::assertGreaterThanOrEqual(1.5, $meanSpeedup, $name.' mean speedup >= 1.5x');
            self::assertGreaterThanOrEqual(1.5, $p99Speedup, $name.' p99 speedup >= 1.5x');
        }
    }

    private function makeArchiveService(?string $libraryPath = null): ArchiveExtractionService
    {
        return new ArchiveExtractionService(
            $this->makeConfig(),
            libraryPath: $libraryPath,
        );
    }

    private function makeResolver(?string $videoRegex = null, ?string $libraryPath = null): MediaSourceResolver
    {
        $config = $this->makeConfig($videoRegex !== null ? ['videoFileRegex' => $videoRegex] : []);
        $dl = (new ReflectionClass(UsenetDownloadService::class))->newInstanceWithoutConstructor();

        return new MediaSourceResolver($config, $dl, libraryPath: $libraryPath);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeConfig(array $overrides = []): ProcessingConfiguration
    {
        $reflection = new ReflectionClass(ProcessingConfiguration::class);
        /** @var ProcessingConfiguration $config */
        $config = $reflection->newInstanceWithoutConstructor();

        $values = array_merge([
            'unrarPath' => false,
            'unzipPath' => false,
            'sevenZipPath' => false,
            'timeoutPath' => false,
            'timeoutSeconds' => 0,
            'extractUsingRarInfo' => true,
            'debugMode' => false,
            'par2MediaMapping' => true,
            'par2VerifyFiles' => 2,
            'mediaMaxBytes' => 16777216,
            'videoFileRegex' => self::DEFAULT_VIDEO_FILE_REGEX,
        ], $overrides);

        foreach ($values as $property => $value) {
            $prop = new ReflectionProperty(ProcessingConfiguration::class, $property);
            $prop->setValue($config, $value);
        }

        return $config;
    }
}
