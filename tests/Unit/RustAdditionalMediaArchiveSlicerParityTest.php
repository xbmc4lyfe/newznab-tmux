<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\AdditionalWorkPlanner;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\ConsoleOutputService;
use App\Services\AdditionalProcessing\Media\ArchivedMediaSlicer;
use App\Services\AdditionalProcessing\Media\FfmpegFrameGrabber;
use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\AdditionalProcessing\ReleaseFilesArchiveFallback;
use App\Services\AdditionalProcessing\ReleaseProcessor;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\ReleaseExtraService;
use App\Services\TempWorkspaceService;
use App\Support\RustPipelineLibrary;
use PHPUnit\Framework\TestCase;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;

class RustAdditionalMediaArchiveSlicerParityTest extends TestCase
{
    use CreatesProcessingConfiguration;

    protected function setUp(): void
    {
        parent::setUp();

        if (RustPipelineLibrary::resolveDomain(
            'wave9_additional_media_test',
            ReleaseProcessor::FFI_CDEF."\n"
            .ArchivedMediaSlicer::FFI_CDEF."\n"
            .FfmpegFrameGrabber::FFI_CDEF."\n"
            .ReleaseExtraService::FFI_CDEF
        ) === null) {
            $this->markTestSkipped('Rust pipeline FFI library is not built in this environment.');
        }
    }

    public function test_release_parse_release_files_parity(): void
    {
        $cases = [
            '',
            '   ',
            'Movie.2026.1080p.BluRay.x264-GROUP.mkv*4294967296|Sample/movie-sample.mkv*52428800|movie.nfo*4096',
            ' | Show.S01E01.720p.WEB-DL.mkv*1073741824 | | Show.S01E01.sample.mkv*25165824 | ',
            'file*with*stars.mkv*123456|missing_size*|*999|bad_size*12a3|zero*0',
            'nested/dir\\track 01.flac*33554432|readme.txt*512',
            "null\0byte.mkv*1024|valid.mkv*2048",
        ];

        foreach ($cases as $raw) {
            $supported = false;
            $php = ReleaseProcessor::parseReleaseFilesPhp($raw);
            $native = ReleaseProcessor::parseReleaseFilesNative($raw, $supported);
            $prod = ReleaseProcessor::parseReleaseFiles($raw);

            $this->assertSame($php, $native, 'parseReleaseFiles mismatch for: '.json_encode($raw));
            $this->assertSame($php, $prod);
            if (! str_contains($raw, "\0")) {
                $this->assertTrue($supported);
            }
        }
    }

    public function test_release_normalize_subject_parity(): void
    {
        $subjects = [
            '[01/50] - "Movie.2026.1080p.BluRay.x264-GROUP.part01.rar" yEnc (1/120)',
            'Movie.2026.1080p.WEB-DL.DDP5.1.H.264-GROUP.mkv yEnc (1/85)',
            '[03/18] - (1 of 4) - Show.Name.S02E05.720p.HDTV.x264-GRP.mkv.001 (1/45) yEnc',
            'Poster prefix [01/10] - Inside.Name.rar - (01/20) yEnc (1/5)',
            '   "  Quoted.With.Spaces.MKV  "   yEnc (1/10)',
            "Unquoted.With.Trailing.Newline.rar yEnc (1/5)\n",
            '',
        ];

        foreach ($subjects as $subject) {
            $supported = false;
            $php = ReleaseProcessor::normalizeSubjectPhp($subject);
            $native = ReleaseProcessor::normalizeSubjectNative($subject, $supported);
            $prod = ReleaseProcessor::normalizeSubject($subject);

            $this->assertTrue($supported);
            $this->assertSame($php, $native, "normalizeSubject mismatch for: {$subject}");
            $this->assertSame($php, $prod);
        }
    }

    public function test_release_contains_video_pattern_parity(): void
    {
        $subjects = [
            '[01/50] - "Movie.2026.1080p.BluRay.x264-GROUP.mkv" yEnc (1/120)',
            'Show.S01E02.720p.HDTV.mp4 yEnc (1/40)',
            'Clip.TS] (1/10)',
            'Old.Home.Video.WMV',
            "Trailing.Newline.AVI\n",
            'Archive.Only.part01.rar yEnc (1/50)',
            'Not.A.Video.mkvx yEnc (1/10)',
            '',
        ];

        foreach ($subjects as $subject) {
            $supported = false;
            $php = ReleaseProcessor::containsVideoPatternPhp($subject);
            $native = ReleaseProcessor::containsVideoPatternNative($subject, null, $supported);
            $prod = ReleaseProcessor::containsVideoPattern($subject);

            $this->assertTrue($supported);
            $this->assertSame($php, $native, "containsVideoPattern mismatch for: {$subject}");
            $this->assertSame($php, $prod);

            $customRegex = '\.(MKV|MP4)';
            $customSupported = false;
            $this->assertSame(
                ReleaseProcessor::containsVideoPatternPhp($subject, $customRegex),
                ReleaseProcessor::containsVideoPatternNative($subject, $customRegex, $customSupported),
            );
            $this->assertTrue($customSupported);
        }
    }

    public function test_release_has_usable_sample_filename_parity(): void
    {
        $subjects = [
            '"Movie.2026.1080p.sample.mkv" yEnc (1/15)',
            'Show.S01E01.Sample.mp4 yEnc (1/10)',
            '"Movie.2026.sample.jpg" yEnc (1/1)',
            'Movie.2026.sample.png',
            'Movie.2026.sample.webp]',
            '"Movie.2026.sample.part01.rar" yEnc (1/10)',
            '"Movie.2026.sample.rar" yEnc (1/10)',
            '"Movie.2026.1080p.mkv" yEnc (1/100)',
            "Movie.2026.sample.jpeg\n",
        ];

        foreach ($subjects as $subject) {
            $supported = false;
            $php = ReleaseProcessor::hasUsableSampleFilenamePhp($subject);
            $native = ReleaseProcessor::hasUsableSampleFilenameNative($subject, $supported);
            $prod = ReleaseProcessor::hasUsableSampleFilename($subject);

            $this->assertTrue($supported);
            $this->assertSame($php, $native, "hasUsableSampleFilename mismatch for: {$subject}");
            $this->assertSame($php, $prod);
        }
    }

    public function test_release_is_auxiliary_nzb_subject_parity(): void
    {
        $subjects = [
            '"Movie.2026.par2" yEnc (1/1)',
            '"Movie.2026.vol00+01.par2" yEnc (1/5)',
            '"Movie.2026.sfv" yEnc (1/1)',
            '"Movie.2026.srs" yEnc (1/1)',
            '"Movie.2026.nzb" yEnc (1/1)',
            '"00-group.nfo" yEnc (1/1)',
            '"release.inf" (1/1)',
            '"release.ofn" (1/1)',
            '"Movie.2026.nfo" followed by more than twenty trailing characters here (1/10)',
            '"Movie.2026.part01.rar" yEnc (1/50)',
            '"Movie.2026.1080p.mkv" yEnc (1/100)',
        ];

        foreach ($subjects as $subject) {
            $supported = false;
            $php = ReleaseProcessor::isAuxiliaryNzbSubjectPhp($subject);
            $native = ReleaseProcessor::isAuxiliaryNzbSubjectNative($subject, null, $supported);
            $prod = ReleaseProcessor::isAuxiliaryNzbSubject($subject);

            $this->assertTrue($supported);
            $this->assertSame($php, $native, "isAuxiliaryNzbSubject mismatch for: {$subject}");
            $this->assertSame($php, $prod);
        }
    }

    public function test_release_subject_matches_release_parity(): void
    {
        $pairs = [
            [
                '[01/50] - "The.Matrix.1999.1080p.BluRay.x264-GROUP.part01.rar" yEnc (1/120)',
                'The Matrix 1999 1080p BluRay x264-GROUP',
            ],
            [
                '"Show.Name.S03E07.2160p.WEB-DL.DDP5.1.H.265-GRP.mkv" yEnc (1/80)',
                'Show.Name.S03E07.2160p.WEB-DL.DDP5.1.H.265-GRP',
            ],
            [
                '"a9f4c2e1b8d706152433a9f4c2e1b8d7.part01.rar" yEnc (1/50)',
                'The.Matrix.1999.1080p.BluRay.x264-GROUP',
            ],
            [
                '---',
                'The.Matrix.1999',
            ],
            [
                '"Movie.2026.mkv"',
                '',
            ],
        ];

        foreach ($pairs as [$subject, $releaseName]) {
            $supported = false;
            $php = ReleaseProcessor::subjectMatchesReleasePhp($subject, $releaseName);
            $native = ReleaseProcessor::subjectMatchesReleaseNative($subject, $releaseName, $supported);
            $prod = ReleaseProcessor::subjectMatchesRelease($subject, $releaseName);

            $this->assertTrue($supported);
            $this->assertSame($php, $native, "subjectMatchesRelease mismatch for {$subject} vs {$releaseName}");
            $this->assertSame($php, $prod);
        }
    }

    public function test_release_extract_message_id_candidates_parity(): void
    {
        $config = $this->makeProductionRegexConfig(['processThumbnails' => true, 'processJPGSample' => true]);
        $processor = new ReleaseProcessor(
            $config,
            $this->createStub(NzbContentParser::class),
            new AdditionalWorkPlanner($config),
            $this->createStub(ArchiveExtractionService::class),
            $this->createStub(MediaExtractionService::class),
            $this->createStub(UsenetDownloadService::class),
            $this->createStub(ReleaseFileManager::class),
            $this->createStub(ReleaseFilesArchiveFallback::class),
            $this->createStub(TempWorkspaceService::class),
            $this->createStub(ConsoleOutputService::class),
        );

        $nzbContents = [
            [
                'title' => '"Movie.2026.1080p.part01.rar" yEnc (1/50)',
                'segments' => ['m1@news', 'm2@news'],
                'size' => 50_000_000,
                'filecount' => 1,
            ],
            [
                'title' => '"Movie.2026.1080p.sample.mkv" yEnc (1/10)',
                'segments' => ['s1@news', 's2@news'],
                'size' => 15_000_000,
                'filecount' => 1,
            ],
            [
                'title' => '"Movie.2026.poster.jpg" yEnc (1/1)',
                'segments' => ['j1@news'],
                'size' => 120_000,
                'filecount' => 1,
            ],
        ];

        $supported = false;
        $phpPlan = $processor->extractMessageIdCandidatesPhp($nzbContents, 'alt.binaries.movies');
        $nativePlan = $processor->extractMessageIdCandidatesNative($nzbContents, 'alt.binaries.movies', $supported);
        $prodPlan = $processor->extractMessageIdCandidates($nzbContents, 'alt.binaries.movies');

        $this->assertTrue($supported);
        $this->assertEquals($phpPlan, $nativePlan);
        $this->assertEquals($phpPlan, $prodPlan);
    }

    public function test_archived_media_slicer_parity(): void
    {
        $config = $this->makeConfig([
            'mediaSampleMaxBytes' => 64,
            'mediaMainVideoMinBytes' => 1_048_576,
        ]);
        $slicer = new ArchivedMediaSlicer($config);

        $mkvHeader = "\x1A\x45\xDF\xA3".str_repeat('A', 200);
        $data = str_repeat('H', 128).$mkvHeader.str_repeat('T', 256);

        $rawFiles = [
            [
                'name' => 'Sample/movie-sample.mkv',
                'size' => 512,
                'range' => '128-639',
                'next_offset' => 300,
                'source' => 'main',
                'pass' => 0,
                'is_dir' => 0,
                'compressed' => 0,
            ],
            [
                'name' => 'Movie.2026.1080p.mkv',
                'size' => 5_000_000,
                'range' => '128-5000127',
                'next_offset' => null,
                'source' => 'main',
                'pass' => 0,
                'is_dir' => 0,
                'compressed' => 0,
            ],
            [
                'name' => 'Compressed.mkv',
                'size' => 5_000_000,
                'range' => '128-5000127',
                'next_offset' => null,
                'source' => 'main',
                'pass' => 0,
                'is_dir' => 0,
                'compressed' => 1,
            ],
            [
                'name' => 'OutOfBounds.mp4',
                'size' => 4096,
                'range' => '99999-104094',
                'next_offset' => 104095,
                'source' => 'main',
                'pass' => 0,
                'is_dir' => 0,
                'compressed' => 0,
            ],
        ];

        $storedSupported = false;
        $storedPhp = $slicer->storedVideosPhp($rawFiles);
        $storedNative = $slicer->storedVideosNative($rawFiles, $storedSupported);
        $this->assertTrue($storedSupported);
        $this->assertSame($storedPhp, $storedNative);
        $this->assertSame($storedPhp, $slicer->storedVideos($rawFiles));

        foreach ($storedPhp as $video) {
            $rangeSupported = false;
            $this->assertSame($slicer->rangePhp($video), $slicer->rangeNative($video, $rangeSupported));
            $this->assertTrue($rangeSupported);
            $this->assertSame($slicer->rangePhp($video), $slicer->range($video));

            $entrySupported = false;
            $entryPhp = $slicer->entryForPhp($data, $video, 2, 5);
            $entryNative = $slicer->entryForNative($data, $video, 2, 5, $entrySupported);
            $this->assertTrue($entrySupported);
            $this->assertEquals($entryPhp, $entryNative);
            $this->assertEquals($entryPhp, $slicer->entryFor($data, $video, 2, 5));
        }
    }

    public function test_ffmpeg_clock_seconds_parity(): void
    {
        $inputs = [
            '00:00:00.000000000',
            '00:00:00',
            '00:01:30.500000000',
            '01:45:12.125',
            '02:00:00',
            '12:34:56.789',
            "00:02:03.500\n",
            '1:02:03',
            '01:2:03',
            '01:02:3',
            '01:02:03.',
            '-01:02:03',
            'invalid',
            '',
            null,
            123,
        ];

        foreach ($inputs as $input) {
            $supported = false;
            $php = FfmpegFrameGrabber::clockSecondsPhp($input);
            $native = FfmpegFrameGrabber::clockSecondsNative($input, $supported);
            $prod = FfmpegFrameGrabber::clockSeconds($input);

            $this->assertTrue($supported);
            if ($php === null) {
                $this->assertNull($native);
                $this->assertNull($prod);
            } else {
                $this->assertNotNull($native);
                $this->assertNotNull($prod);
                $this->assertEqualsWithDelta($php, $native, 1e-9);
                $this->assertEqualsWithDelta($php, $prod, 1e-9);
            }
        }
    }

    public function test_release_extra_make_codec_pretty_parity(): void
    {
        $service = new ReleaseExtraService;
        $codecs = [
            '36',
            "36\n",
            'V_MPEGH/ISO/HEVC',
            'hevc',
            '7',
            '27',
            'H264',
            'h264',
            'V_MPEG4/ISO/AVC',
            '20',
            'FMP4',
            'mp42',
            'MP43',
            'MPG4',
            'MPEG4/ISO/ASP',
            '2',
            "2\n",
            'MPEG',
            "MPEG\n",
            'mpeg',
            'DX50',
            'DIVX',
            'div3',
            'XVID',
            'xvid_enc',
            'WMV3',
            'WVC1',
            'AV1',
            'VP9',
            '',
        ];

        foreach ($codecs as $codec) {
            $supported = false;
            $php = $service->makeCodecPrettyPhp($codec);
            $native = $service->makeCodecPrettyNative($codec, $supported);
            $prod = $service->makeCodecPretty($codec);

            $this->assertTrue($supported);
            $this->assertSame($php, $native, 'makeCodecPretty mismatch for: '.json_encode($codec));
            $this->assertSame($php, $prod);
        }
    }

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('WAVE9_ADDITIONAL_MEDIA_BENCHMARK') !== '1') {
            $this->markTestSkipped('Set WAVE9_ADDITIONAL_MEDIA_BENCHMARK=1 to run paired randomized benchmarks.');
        }

        $config = $this->makeProductionRegexConfig([
            'processThumbnails' => true,
            'processJPGSample' => true,
            'processMediaInfo' => true,
            'processAudioInfo' => true,
            'mediaSampleMaxBytes' => 262144,
            'mediaMainVideoMinBytes' => 26214400,
        ]);
        $slicer = new ArchivedMediaSlicer($config);
        $extraService = new ReleaseExtraService;
        $processor = new ReleaseProcessor(
            $config,
            $this->createStub(NzbContentParser::class),
            new AdditionalWorkPlanner($config),
            $this->createStub(ArchiveExtractionService::class),
            $this->createStub(MediaExtractionService::class),
            $this->createStub(UsenetDownloadService::class),
            $this->createStub(ReleaseFileManager::class),
            $this->createStub(ReleaseFilesArchiveFallback::class),
            $this->createStub(TempWorkspaceService::class),
            $this->createStub(ConsoleOutputService::class),
        );
        $nzbContentsCommon = [];
        for ($i = 1; $i <= 30; $i++) {
            $nzbContentsCommon[] = [
                'title' => sprintf('"Movie.2026.1080p.BluRay.x264-GROUP.part%02d.rar" yEnc (1/50)', $i),
                'segments' => ["seg-{$i}-1@news", "seg-{$i}-2@news"],
                'size' => 50_000_000,
                'filecount' => 1,
            ];
        }
        $nzbContentsCommon[] = [
            'title' => '"Movie.2026.1080p.BluRay.x264-GROUP.sample.mkv" yEnc (1/10)',
            'segments' => ['sample-1@news', 'sample-2@news'],
            'size' => 15_000_000,
            'filecount' => 1,
        ];
        $nzbContentsUncommon = [];
        for ($i = 1; $i <= 80; $i++) {
            $nzbContentsUncommon[] = [
                'title' => sprintf('Poster [01/80] - "Show.Name.S02E01.2160p.WEB-DL.H.265-GRP.part%03d.rar" yEnc (1/40)', $i),
                'segments' => ["u-{$i}-1@news", "u-{$i}-2@news"],
                'size' => 100_000_000,
                'filecount' => 1,
            ];
        }
        $nzbContentsUncommon[] = [
            'title' => '"Show.Name.S02E01.2160p.WEB-DL.H.265-GRP.nfo" yEnc (1/1)',
            'segments' => ['u-nfo@news'],
            'size' => 4096,
            'filecount' => 1,
        ];
        $nzbContentsUncommon[] = [
            'title' => '"Show.Name.S02E01.2160p.WEB-DL.H.265-GRP.par2" yEnc (1/1)',
            'segments' => ['u-par2@news'],
            'size' => 32768,
            'filecount' => 1,
        ];

        $releaseFilesCommon = implode('|', array_map(
            static fn (int $i): string => sprintf('Show.Name.S01E%02d.1080p.WEB-DL.x264-GROUP.mkv*%d', $i, 1_500_000_000 + $i * 1024),
            range(1, 24)
        ));
        $releaseFilesUncommon = implode('|', array_map(
            static fn (int $i): string => sprintf('Season.Pack.S02E%02d.2160p.BluRay.REMUX.HEVC-GRP/part*%02d.mkv*%d', $i, $i, 8_500_000_000 + $i * 4096),
            range(1, 96)
        ));

        $subjectsCommon = [
            '[01/50] - "Movie.2026.1080p.BluRay.x264-GROUP.part01.rar" yEnc (1/120)',
            '[02/50] - "Movie.2026.1080p.BluRay.x264-GROUP.sample.mkv" yEnc (1/15)',
            '[03/50] - "Movie.2026.1080p.BluRay.x264-GROUP.nfo" yEnc (1/1)',
            'Show.Name.S02E05.720p.HDTV.x264-GRP.mp4 yEnc (1/45)',
        ];
        $subjectsUncommon = [
            'Poster [01/99] - (01 of 99) - Complex.Unquoted.Release.2026.2160p.UHD.BluRay.x265-GROUP.mkv - [01/99] - (1/250) yEnc (1/250)',
            '"a9f4c2e1b8d706152433a9f4c2e1b8d7.001" (01/85) yEnc',
            '"Movie.2026.vol007+008.par2" yEnc (1/12)',
            '"Movie.2026.sample.webp" yEnc (1/2)',
        ];

        $archiveData = str_repeat('X', 256)."\x1A\x45\xDF\xA3".str_repeat('M', 65536);
        $archiveFileCommon = [
            'name' => 'Sample/Movie.2026.sample.mkv',
            'size' => 32768,
            'range' => '256-33023',
            'next_offset' => 33024,
        ];

        $codecsCommon = ['V_MPEGH/ISO/HEVC', 'V_MPEG4/ISO/AVC', 'XVID', 'WVC1', 'AV1', 'VP9', 'DX50', 'FMP4'];
        $clocksCommon = ['01:42:15.125000000', '00:23:45.500', '00:00:00.000', '02:15:00', 'invalid'];

        $results = [
            'extractMessageIdCandidates.common' => $this->runPairedBenchmark(
                80,
                10,
                static fn () => $processor->extractMessageIdCandidatesPhp($nzbContentsCommon, 'alt.binaries.movies'),
                static fn () => $processor->extractMessageIdCandidatesNative($nzbContentsCommon, 'alt.binaries.movies'),
            ),
            'extractMessageIdCandidates.uncommon' => $this->runPairedBenchmark(
                80,
                8,
                static fn () => $processor->extractMessageIdCandidatesPhp($nzbContentsUncommon, 'alt.binaries.tv'),
                static fn () => $processor->extractMessageIdCandidatesNative($nzbContentsUncommon, 'alt.binaries.tv'),
            ),
            'parseReleaseFiles.common' => $this->runPairedBenchmark(
                120,
                25,
                static fn () => ReleaseProcessor::parseReleaseFilesPhp($releaseFilesCommon),
                static fn () => ReleaseProcessor::parseReleaseFilesNative($releaseFilesCommon),
            ),
            'parseReleaseFiles.uncommon' => $this->runPairedBenchmark(
                120,
                15,
                static fn () => ReleaseProcessor::parseReleaseFilesPhp($releaseFilesUncommon),
                static fn () => ReleaseProcessor::parseReleaseFilesNative($releaseFilesUncommon),
            ),
            'normalizeSubject.common' => $this->runPairedBenchmark(
                120,
                40,
                static function () use ($subjectsCommon): void {
                    foreach ($subjectsCommon as $s) {
                        ReleaseProcessor::normalizeSubjectPhp($s);
                    }
                },
                static function () use ($subjectsCommon): void {
                    foreach ($subjectsCommon as $s) {
                        ReleaseProcessor::normalizeSubjectNative($s);
                    }
                },
            ),
            'normalizeSubject.uncommon' => $this->runPairedBenchmark(
                120,
                40,
                static function () use ($subjectsUncommon): void {
                    foreach ($subjectsUncommon as $s) {
                        ReleaseProcessor::normalizeSubjectPhp($s);
                    }
                },
                static function () use ($subjectsUncommon): void {
                    foreach ($subjectsUncommon as $s) {
                        ReleaseProcessor::normalizeSubjectNative($s);
                    }
                },
            ),
            'containsVideoPattern.common' => $this->runPairedBenchmark(
                120,
                40,
                static function () use ($subjectsCommon): void {
                    foreach ($subjectsCommon as $s) {
                        ReleaseProcessor::containsVideoPatternPhp($s);
                    }
                },
                static function () use ($subjectsCommon): void {
                    foreach ($subjectsCommon as $s) {
                        ReleaseProcessor::containsVideoPatternNative($s);
                    }
                },
            ),
            'hasUsableSampleFilename.common' => $this->runPairedBenchmark(
                120,
                40,
                static function () use ($subjectsCommon, $subjectsUncommon): void {
                    foreach ([...$subjectsCommon, ...$subjectsUncommon] as $s) {
                        ReleaseProcessor::hasUsableSampleFilenamePhp($s);
                    }
                },
                static function () use ($subjectsCommon, $subjectsUncommon): void {
                    foreach ([...$subjectsCommon, ...$subjectsUncommon] as $s) {
                        ReleaseProcessor::hasUsableSampleFilenameNative($s);
                    }
                },
            ),
            'isAuxiliaryNzbSubject.common' => $this->runPairedBenchmark(
                120,
                40,
                static function () use ($subjectsCommon, $subjectsUncommon): void {
                    foreach ([...$subjectsCommon, ...$subjectsUncommon] as $s) {
                        ReleaseProcessor::isAuxiliaryNzbSubjectPhp($s);
                    }
                },
                static function () use ($subjectsCommon, $subjectsUncommon): void {
                    foreach ([...$subjectsCommon, ...$subjectsUncommon] as $s) {
                        ReleaseProcessor::isAuxiliaryNzbSubjectNative($s);
                    }
                },
            ),
            'subjectMatchesRelease.common' => $this->runPairedBenchmark(
                100,
                200,
                static function () use ($subjectsCommon): void {
                    foreach ($subjectsCommon as $s) {
                        ReleaseProcessor::subjectMatchesReleasePhp($s, 'Movie 2026 1080p BluRay x264-GROUP');
                    }
                },
                static function () use ($subjectsCommon): void {
                    foreach ($subjectsCommon as $s) {
                        ReleaseProcessor::subjectMatchesReleaseNative($s, 'Movie 2026 1080p BluRay x264-GROUP');
                    }
                },
            ),
            'subjectMatchesRelease.uncommon' => $this->runPairedBenchmark(
                100,
                200,
                static function () use ($subjectsUncommon): void {
                    foreach ($subjectsUncommon as $s) {
                        ReleaseProcessor::subjectMatchesReleasePhp($s, 'Complex Unquoted Release 2026 2160p UHD BluRay x265-GROUP');
                    }
                },
                static function () use ($subjectsUncommon): void {
                    foreach ($subjectsUncommon as $s) {
                        ReleaseProcessor::subjectMatchesReleaseNative($s, 'Complex Unquoted Release 2026 2160p UHD BluRay x265-GROUP');
                    }
                },
            ),
            'ArchivedMediaSlicer::range' => $this->runPairedBenchmark(
                120,
                60,
                static fn () => $slicer->rangePhp($archiveFileCommon),
                static fn () => $slicer->rangeNative($archiveFileCommon),
            ),
            'ArchivedMediaSlicer::entryFor' => $this->runPairedBenchmark(
                120,
                40,
                static fn () => $slicer->entryForPhp($archiveData, $archiveFileCommon, 0, 2),
                static fn () => $slicer->entryForNative($archiveData, $archiveFileCommon, 0, 2),
            ),
            'FfmpegFrameGrabber::clockSeconds' => $this->runPairedBenchmark(
                120,
                50,
                static function () use ($clocksCommon): void {
                    foreach ($clocksCommon as $c) {
                        FfmpegFrameGrabber::clockSecondsPhp($c);
                    }
                },
                static function () use ($clocksCommon): void {
                    foreach ($clocksCommon as $c) {
                        FfmpegFrameGrabber::clockSecondsNative($c);
                    }
                },
            ),
            'ReleaseExtraService::makeCodecPretty' => $this->runPairedBenchmark(
                120,
                50,
                static function () use ($extraService, $codecsCommon): void {
                    foreach ($codecsCommon as $c) {
                        $extraService->makeCodecPrettyPhp($c);
                    }
                },
                static function () use ($extraService, $codecsCommon): void {
                    foreach ($codecsCommon as $c) {
                        $extraService->makeCodecPrettyNative($c);
                    }
                },
            ),
        ];

        fwrite(STDERR, "\n=== Wave 9 Slice 9.5 Paired Randomized Benchmarks ===\n");
        foreach ($results as $label => $stats) {
            fwrite(
                STDERR,
                sprintf(
                    "%-38s mean=%.2fx p99=%.2fx (php_mean=%.1fns rust_mean=%.1fns)\n",
                    $label,
                    $stats['mean_speedup'],
                    $stats['p99_speedup'],
                    $stats['php_mean_ns'],
                    $stats['rust_mean_ns'],
                )
            );
        }

        $this->assertGreaterThanOrEqual(1.5, $results['extractMessageIdCandidates.common']['mean_speedup']);
        $this->assertGreaterThanOrEqual(1.5, $results['extractMessageIdCandidates.common']['p99_speedup']);
        $this->assertGreaterThanOrEqual(1.5, $results['extractMessageIdCandidates.uncommon']['mean_speedup']);
        $this->assertGreaterThanOrEqual(1.5, $results['extractMessageIdCandidates.uncommon']['p99_speedup']);
        $this->assertGreaterThanOrEqual(1.5, $results['subjectMatchesRelease.common']['mean_speedup']);
        $this->assertGreaterThanOrEqual(1.5, $results['subjectMatchesRelease.common']['p99_speedup']);
        $this->assertGreaterThanOrEqual(1.5, $results['subjectMatchesRelease.uncommon']['mean_speedup']);
        $this->assertGreaterThanOrEqual(1.5, $results['subjectMatchesRelease.uncommon']['p99_speedup']);
    }

    /**
     * @param  callable(): mixed  $phpFn
     * @param  callable(): mixed  $rustFn
     * @return array{mean_speedup: float, p99_speedup: float, php_mean_ns: float, rust_mean_ns: float}
     */
    private function runPairedBenchmark(int $pairs, int $innerLoops, callable $phpFn, callable $rustFn): array
    {
        for ($w = 0; $w < 10; $w++) {
            $phpFn();
            $rustFn();
        }

        $phpTimes = [];
        $rustTimes = [];
        for ($i = 0; $i < $pairs; $i++) {
            $rustFirst = (($i * 1103515245 + 12345) & 1) === 1;
            if ($rustFirst) {
                $t0 = hrtime(true);
                for ($j = 0; $j < $innerLoops; $j++) {
                    $rustFn();
                }
                $rustTimes[] = (hrtime(true) - $t0) / $innerLoops;

                $t1 = hrtime(true);
                for ($j = 0; $j < $innerLoops; $j++) {
                    $phpFn();
                }
                $phpTimes[] = (hrtime(true) - $t1) / $innerLoops;
            } else {
                $t0 = hrtime(true);
                for ($j = 0; $j < $innerLoops; $j++) {
                    $phpFn();
                }
                $phpTimes[] = (hrtime(true) - $t0) / $innerLoops;

                $t1 = hrtime(true);
                for ($j = 0; $j < $innerLoops; $j++) {
                    $rustFn();
                }
                $rustTimes[] = (hrtime(true) - $t1) / $innerLoops;
            }
        }

        sort($phpTimes);
        sort($rustTimes);
        $phpMean = array_sum($phpTimes) / count($phpTimes);
        $rustMean = max(1.0, array_sum($rustTimes) / count($rustTimes));
        $p99Index = min(count($phpTimes) - 1, (int) floor(count($phpTimes) * 0.99));
        $phpP99 = $phpTimes[$p99Index];
        $rustP99 = max(1.0, $rustTimes[$p99Index]);

        return [
            'mean_speedup' => $phpMean / $rustMean,
            'p99_speedup' => $phpP99 / $rustP99,
            'php_mean_ns' => $phpMean,
            'rust_mean_ns' => $rustMean,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeProductionRegexConfig(array $overrides = []): ProcessingConfiguration
    {
        return $this->makeConfig(array_merge([
            'audioFileRegex' => '\.(AAC|AIFF|APE|AC3|ASF|DTS|FLAC|MKA|MKS|MP2|MP3|RA|OGG|OGM|W64|WAV|WMA)',
            'ignoreBookRegex' => '/\b(epub|lit|mobi|pdf|sipdf|html)\b.*\.rar(?!.{20,})/i',
            'supportFileRegex' => '\.(?:vol\d{1,3}\+\d{1,3}|par2|srs|sfv|nzb)',
            'videoFileRegex' => '\.(AVI|F4V|IFO|M1V|M2V|M4V|MKV|MOV|MP4|MPEG|MPG|MPGV|MPV|OGV|QT|RM|RMVB|TS|VOB|WMV)',
        ], $overrides));
    }
}
