<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\MovieService;
use App\Services\TvProcessing\Providers\LocalDbProvider;
use App\Support\RustPipelineLibrary;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TvMovieRustParityTest extends TestCase
{
    #[Test]
    public function tv_parse_info_matches_php_across_all_branches_and_edge_cases(): void
    {
        $this->assertTrue(RustPipelineLibrary::available());

        $provider = new LocalDbProvider;
        $cases = [
            '',
            '0',
            'a3f8e2c19b7d4e6f8a1b2c3d4e5f6a7b',
            'The.Expanse.S01E02.720p.HDTV.x264-GRP',
            'Show.Name.S02E03E04.720p.HDTV.x264-GRP',
            'Show.Name.S02E0304.720p.HDTV.x264-GRP',
            'Show.Name.S02E03-04.720p.HDTV.x264-GRP',
            'Show.Name.S02E03-E04.720p.HDTV.x264-GRP',
            'Show.Name.S02.E03a.720p.HDTV.x264-GRP',
            'Doctor.Who.S00E05.720p.HDTV.x264-GRP',
            'The.Expanse.S01.1080p.BluRay.x264-GRP',
            'The.Expanse.S01D2.1080p.BluRay.x264-GRP',
            'The Expanse Season 3 Complete 1080p',
            'Show.Name.3x07.HDTV.XviD-GRP',
            'Doctor.Who.2005.S01E01.720p.BluRay.x264-GRP',
            'The.Daily.Show.2024.03.05.Guest.720p.WEB.h264-GRP',
            'Tagesschau.25.12.2020.German.720p.HDTV.x264-GRP',
            'Tagesschau.05.12.2020.German.720p.HDTV.x264-GRP',
            'Show.Name.05-12-2020.720p.HDTV.x264-GRP',
            'Show.Name.12-25-2020.720p.HDTV.x264-GRP',
            'Show.Name.24.12.23.720p.HDTV.x264-GRP',
            'Show.Name.31.02.2020.720p.HDTV.x264-GRP',
            'Show.Name.2019.E05.720p.HDTV.x264-GRP',
            'Show.Name.2019.Part2.720p.HDTV.x264-GRP',
            'Show.Name.2019.1080p.WEB.h264-GRP',
            'Show.Name.Part.3.720p.HDTV.x264-GRP',
            'Show.Name.Pt.4.720p.HDTV.x264-GRP',
            'Band.Of.Brothers.EP06.Bastogne.DVDRiP.XviD-DEiTY',
            '[SubsPlease]_Some_Anime_-_06_[1080p].mkv',
            'Some_Anime_-_Episode_Title_-_12_[720p]',
            'Agents.of.S.H.I.E.L.D..S04E15.1080p.WEB-DL.DD5.1.H264-GRP',
            'S.H.I.E.L.D.S01E01.720p.HDTV.x264-KILLERS',
            'M.A.S.H.S03E08.720p.BluRay.x264-GRP',
            'The.Daily.Show.with.Trevor.Noah.2022.05.10.1080p.WEB.h264-GRP',
            'Die.Simpsons.S32E04.German.Dubbed.DL.1080p.WEB.x264-GRP',
            'The.Office.US.S05E14.1080p.BluRay.x265-HEVC',
            'Shameless.UK.S02E01.720p.HDTV.x264-GRP',
            'Taskmaster.AU.S01E03.1080p.WEB-DL.AAC2.0.H.264-GRP',
            'History.Channel.Vikings.S04E01.720p.HDTV.x264-GRP',
            'sample-The.Expanse.S01E01.720p.HDTV.x264-GRP',
            '--The.Expanse.S01E01.720p.HDTV.x264-GRP',
        ];

        $maxTwoDigitYear = (int) date('y') + 1;
        foreach ($cases as $relname) {
            $supported = false;
            $rust = RustPipelineLibrary::tvParseInfo($relname, $maxTwoDigitYear, null, $supported);
            $php = $provider->parseInfoPhp($relname);
            $this->assertTrue($supported, "Expected Rust support for: {$relname}");
            $this->assertSame($php, $rust, "Mismatch for parseInfo({$relname})");
            $this->assertSame($php, $provider->parseInfo($relname));
        }
    }

    #[Test]
    public function tv_clean_name_matches_php(): void
    {
        $provider = new LocalDbProvider;
        $names = [
            '',
            'The.Expanse',
            'History Channel Ancient Aliens',
            'Discovery Channel Gold Rush',
            'Show & Tell: Special! (2024) $100',
            'Tatort.German.DL.Dubbed.Subbed.MULTI',
            'Megaestructuras - Donde otros trabajan - Working Hard',
            'Show Name - 12',
            'Café_René_ß_Ümlaut_Σ',
        ];

        foreach ($names as $name) {
            $rust = RustPipelineLibrary::tvCleanName($name);
            $php = $provider->cleanNamePhp($name);
            $this->assertSame($php, $rust, "Mismatch for cleanName({$name})");
            $this->assertSame($php, $provider->cleanName($name));
        }
    }

    #[Test]
    public function movie_parse_search_name_and_candidate_evaluation_match_php(): void
    {
        Cache::flush();

        $service = new class extends MovieService
        {
            /**
             * @return array{ok: bool, title: string, year: string}
             */
            public function parseViaDefault(string $name): array
            {
                $this->currentTitle = '';
                $this->currentYear = '';
                $ok = $this->parseMovieSearchName($name);

                return ['ok' => $ok, 'title' => $this->currentTitle, 'year' => $this->currentYear];
            }

            /**
             * @return array{ok: bool, title: string, year: string}
             */
            public function parseViaPhp(string $name): array
            {
                $this->currentTitle = '';
                $this->currentYear = '';
                $ok = $this->parseMovieSearchNamePhp($name);

                return ['ok' => $ok, 'title' => $this->currentTitle, 'year' => $this->currentYear];
            }
        };

        $movieCases = [
            '',
            '0',
            '123456',
            'The.Free.Fall.2022.1080p.AMZN.WEB-DL.DDP5.1.H.264-GPRS',
            'Blade.Runner.2049.2017.2160p.UHD.BluRay.x265-GRP',
            '2001.A.Space.Odyssey.1968.REMASTERED.1080p.BluRay.x264-GRP',
            'Salve.Geral.Irmandade.2026.2160p.Netflix.WEB-DL.HEVC.10bit.DDP5.1.Atmos.6Audios-QHstudIo',
            'Some.Movie.Directors.Cut.1080p.BluRay.x264-GRP',
            'Another.Movie.DVDRip.XviD-GRP',
            'Short Movie Title',
            '[Group] Movie.Title.(Special.Edition).[1080p].2023.WEB-DL.H264-GRP',
        ];

        foreach ($movieCases as $releaseName) {
            Cache::flush();
            $php = $service->parseViaPhp($releaseName);
            $rust = $service->parseViaDefault($releaseName);
            $this->assertSame($php, $rust, "Mismatch for parseMovieSearchName({$releaseName})");
        }
    }
}
