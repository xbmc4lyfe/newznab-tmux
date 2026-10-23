<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\NameFixing\FileNameCleaner;
use App\Services\NameFixing\NzbSplitUnwrapper;
use App\Services\ReleaseCleaningService;
use App\Services\Releases\Verification\PayloadIdentityResolver;
use App\Support\RustPipelineLibrary;
use PHPUnit\Framework\TestCase;

final class ReleaseCleaningRustParityTest extends TestCase
{
    public function test_nzbsplit_unwrapper_matches_php_reference(): void
    {
        $this->assertTrue(RustPipelineLibrary::available());
        $unwrapper = new NzbSplitUnwrapper;

        $samples = [
            'WinterOlympics2026__NZBSPLIT__0456f274737cea074abd86a89144cc7b__NZBSPLIT__Winter_Olympic_Games_Milano_Cortina_2026_Closing_Ceremony_1080p25_WEB-DL_(MultiAudio).7z.065',
            'WinterOlympics2026__NZBSPLIT__0456f274737cea074abd86a89144cc7b__NZBSPLIT__Winter_Olympic_Games_Milano_Cortina_2026_Closing_Ceremony_1080p25_WEB-DL_(MultiAudio).7z.065" yEnc(1',
            'TV__NZBSPLIT__1234567890abcdef__NZBSPLIT__Some_Show_2026_Episode_01_1080p_WEB-DL.part01.rar',
            'MOVIE__NZBSPLIT__1234567890abcdef__NZBSPLIT__Some_Movie_2026_1080p_WEB-DL-GROUP.r05',
            'Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__Exact_Release_2026-GRP.7z.073" yEnc (1/42)',
            'Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__Exact_Release_2026-GRP.vol123+456.par2 yEnc (1/42)',
            'Outer__NZBSPLIT__invalid__NZBSPLIT__Title',
            'Outer__NZBSPLIT__abcdef0__NZBSPLIT__Title',
            'Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__',
            'Outer__NZBSPLIT__invalid__NZBSPLIT__Junk__NZBSPLIT__abcdef0123456789__NZBSPLIT__Title',
            '__NZBSPLIT__NZBSPLIT__abcdef01__NZBSPLIT__Title',
            'Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__NZBSPLIT__Title',
            'Regular.Release.Name.2026.1080p.WEB-DL',
            '',
        ];

        foreach ($samples as $sample) {
            $this->assertSame(
                $unwrapper->unwrapPhp($sample),
                $unwrapper->unwrap($sample),
                "unwrap mismatch for: {$sample}"
            );
            $this->assertSame(
                $unwrapper->literalPayloadPhp($sample),
                $unwrapper->literalPayload($sample),
                "literalPayload mismatch for: {$sample}"
            );
            $this->assertSame(
                $unwrapper->stripLiteralSubjectTrailerPhp($sample),
                $unwrapper->stripLiteralSubjectTrailer($sample),
                "stripLiteralSubjectTrailer mismatch for: {$sample}"
            );
        }
    }

    public function test_release_cleaning_service_matches_php_reference(): void
    {
        $this->assertTrue(RustPipelineLibrary::available());
        $cleaner = new ReleaseCleaningService;
        $fileCleaner = new FileNameCleaner;

        $fixerInputs = [
            'Some.Movie-GRP.vol01-03.par2',
            'Some.Movie-GRP.vol00+01.par2',
            'Release Name Some.Show.S01E02.1080p.WEB-DL-GRP.part01.rar',
            'sample-Movie.2024.720p.BluRay.x264-GRP.001.rev"',
            'Movie.2024.1080p.WEB-DL-GRP-sample.nfo',
            'TV__NZBSPLIT__1234567890abcdef__NZBSPLIT__Some_Show_2026_Episode_01_1080p_WEB-DL.part01.rar',
            "  Multiple   Spaces\t\tAnd\xc3\xa9NonAscii-GRP.zip  ",
        ];

        foreach ($fixerInputs as $input) {
            $this->assertSame(
                $cleaner->fixerCleanerPhp($input),
                $cleaner->fixerCleaner($input),
                "fixerCleaner mismatch for: {$input}"
            );
        }

        $fileInputs = [
            'Shes.the.Man.2006.BluRay.1080p.DTS-HD.MA.5.1.AVC.REMUX-FraMeSToR.vol01-03.par2',
            'C:\\posts\\Movie.2020.1080p-GRP.mkv',
            'Movie.2020.1080p.DTS.mkv',
            'Amélie.2001.1080p.BluRay-GRP.mkv',
            'Love Island US S08E07 720p AMZN WEB-DL DDP2 0 H 264-RAWR.mkv',
            'a3f9c1d8e2b7a6f50d4c3b2a1908f7e6.par2',
            'WinRAR 7.23 (x64) Final.rar.par2',
            'Annual.Report.Final.pdf',
            'setup12.exe',
            'Some.Show.S01.COMPLETE.1080p.WEB-DL.mkv',
        ];

        foreach ($fileInputs as $file) {
            $this->assertSame(
                $fileCleaner->isPlausibleReleaseTitlePhp($file),
                $fileCleaner->isPlausibleReleaseTitle($file),
                "isPlausibleReleaseTitle mismatch for: {$file}"
            );
            $this->assertSame(
                $cleaner->releaseNameFromFilePhp($file),
                $cleaner->releaseNameFromFile($file),
                "releaseNameFromFile mismatch for: {$file}"
            );
        }

        $subjectInputs = [
            '[1/9] - "Shes.the.Man.2006.BluRay.1080p.DTS-HD.MA.5.1.AVC.REMUX-FraMeSToR.par2" yEnc',
            '[3/9] - "Shes.the.Man.2006.BluRay.1080p.DTS-HD.MA.5.1.AVC.REMUX-FraMeSToR.vol01-03.par2" yEnc',
            '[1/2] - #34;Movie.2020.1080p-GRP.mkv#34; yEnc',
            '[1/7] - "a3f9c1d8e2b7a6f50d4c3b2a1908f7e6.par2" yEnc',
            '[82/84] - "26992-D-K-KIvQ-D.iby582+88.CNE7" lRap (1/2)',
            '[140022]-[04] - [01/40] - "140022-04.nfo" yEnc',
            '[12345]-[FULL]-[a.b.teevee]-[ Some.Scene.Release.S01E01.720p.HDTV.x264-GRP ]-[01/25] "some.rar" yEnc',
            'Plain   Subject  With  Extra   Spaces - yEnc',
        ];

        foreach ($subjectInputs as $subject) {
            $cleaner->subject = $subject;
            $this->assertSame(
                $cleaner->counterFileSubjectNamePhp(),
                $cleaner->counterFileSubjectName(),
                "counterFileSubjectName mismatch for: {$subject}"
            );
            $this->assertSame(
                $cleaner->releaseCleanerHelperPhp($subject),
                $cleaner->releaseCleanerHelper($subject),
                "releaseCleanerHelper mismatch for: {$subject}"
            );
            $this->assertSame(
                $cleaner->genericPhp(),
                $cleaner->generic(),
                "generic() mismatch for: {$subject}"
            );
            $this->assertSame(
                $cleaner->teeveePhp(),
                $cleaner->teevee(),
                "teevee() mismatch for: {$subject}"
            );
        }

        $resolver = new PayloadIdentityResolver($cleaner);
        $manifests = [
            [
                'identity_hints' => [
                    ['source' => 'yenc', 'name' => 'Some.Movie.2026.1080p.BluRay.x264-GRP.sample.mkv'],
                    ['source' => 'yenc', 'name' => 'Some.Movie.2026.1080p.BluRay.x264-GRP.part01.rar'],
                    ['source' => 'par2', 'name' => 'Some.Movie.2026.1080p.BluRay.x264-GRP.vol00+05.par2'],
                ],
            ],
            [
                'identity_hints' => [
                    ['source' => 'yenc', 'name' => 'Some.Show.S01E02.1080p.WEB-DL-GRP.mkv'],
                    ['source' => 'yenc', 'name' => 'Different.Show.S01E03.1080p.WEB-DL-GRP.mkv'],
                ],
            ],
            [
                'identity_hints' => [
                    ['source' => 'yenc', 'name' => 'Some.App.v6.41.Multilingual-GRP.rar'],
                ],
            ],
            [
                'identity_hints' => [
                    ['source' => 'yenc', 'name' => 'a3f9c1d8e2b7a6f50d4c3b2a1908f7e6.par2'],
                ],
            ],
        ];

        foreach ($manifests as $idx => $manifest) {
            $this->assertSame(
                $resolver->resolvePhp($manifest),
                $resolver->resolve($manifest),
                "PayloadIdentityResolver mismatch at index {$idx}"
            );
        }
    }
}
