<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NameFixing\Data\NameFixResult;
use App\Services\NameFixing\Extractors\FileNameExtractor;
use App\Services\NameFixing\Extractors\NfoNameExtractor;
use App\Support\RustPipelineLibrary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NfoExtractorRustParityTest extends TestCase
{
    /**
     * @return array<string, mixed>|null
     */
    private static function snapshot(?NameFixResult $result): ?array
    {
        if ($result === null) {
            return null;
        }

        return [
            'newName' => $result->newName,
            'method' => $result->method,
            'checkerName' => $result->checkerName,
            'preDbId' => $result->preDbId,
            'confidence' => $result->confidence,
            'metadata' => $result->metadata,
        ];
    }

    public function test_rust_ffi_domain_is_available(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('nfo_extractors', NfoNameExtractor::FFI_CDEF);
        $this->assertNotNull($ffi, 'Rust nfo_extractors FFI domain must be available');
    }

    #[DataProvider('predbCandidateCases')]
    public function test_nfo_predb_candidates_matches_php(string $nfo, bool $expectNativeHandled = true): void
    {
        $extractor = new NfoNameExtractor;
        $fallbackExtractor = new NfoNameExtractor('/nonexistent/libnntmux_pipeline.so');

        $rustResult = $extractor->predbCandidates($nfo);
        $phpResult = $extractor->predbCandidatesPhp($nfo);
        $fallbackResult = $fallbackExtractor->predbCandidates($nfo);

        $this->assertSame($phpResult, $rustResult);
        $this->assertSame($phpResult, $fallbackResult);

        if ($expectNativeHandled && $nfo !== '') {
            $ffi = RustPipelineLibrary::resolveDomain('nfo_extractors', NfoNameExtractor::FFI_CDEF);
            $this->assertNotNull($ffi);
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, 65536, $cap);
            // @phpstan-ignore method.notFound
            $rc = (int) $ffi->nntmux_nfo_predb_candidates($nfo, strlen($nfo), $buf, $cap);
            $this->assertNotSame(-2, $rc, 'Expected Rust FFI to handle NFO predbCandidates without declining to PHP');
        }
    }

    /**
     * @return array<string, array{0: string, 1?: bool}>
     */
    public static function predbCandidateCases(): array
    {
        $overflowLines = [];
        for ($i = 1; $i <= 105; $i++) {
            $overflowLines[] = sprintf('Release.Title.Part.%03d.2026.1080p.WEB-DL.x264-GRP%03d', $i, $i);
        }

        return [
            'empty nfo' => ['', true],
            'literal fields and scene tokens with case-insensitive dedup' => [
                "Release Name: Some.Show.S01E02.1080p.web-dl.ddp5.1.h.264-grp\r\n"
                ."Complete Name : C:\\Downloads\\Some.Show.S01E02.1080p.WEB-DL.DDP5.1.H.264-GRP.mkv\r\n"
                ."Filename = /srv/incoming/Another.Movie.2025.2160p.UHD.BluRay.x265-SCENE.part01.rar\r\n"
                ."Notes: Some.Show.S01E02.1080p.WEB-DL.DDP5.1.H.264-GRP and Extra.Title.2024.720p.HDTV.x264-TEAM\r\n",
                true,
            ],
            'source field and continuation lines are excluded' => [
                "Source: Upstream.Release.2024.1080p.BluRay.x264-SRC\r\n"
                ."   C:\\temp\\Continuation.Release.2024.1080p.BluRay.x264-CONT.mkv\r\n"
                ."   File Name: Retained.Release.2024.720p.BRRip.x264-RET.mkv\r\n",
                true,
            ],
            'cross-line predb regex matches with newline are excluded' => [
                "First.Part.2024\nSecond.Part-GROUP\nValid.Release.2024.1080p.WEB-DL-GRP",
                true,
            ],
            'cp437 box drawing characters in nfo' => [
                "┌──────────────────────────────────────────────────────────┐\n"
                ."│ Release : Boxed.Movie.2026.1080p.BluRay.x264-BOXED       │\n"
                ."└──────────────────────────────────────────────────────────┘\n",
                true,
            ],
            'candidate overflow over 100 unique titles' => [
                implode("\n", $overflowLines),
                true,
            ],
            'unicode literal field falls back cleanly' => [
                "Release Name: Café.Movie.2026.1080p.BluRay.x264-GROUP.mkv\n",
                false,
            ],
        ];
    }

    #[DataProvider('nfoPatternCases')]
    public function test_nfo_extract_from_nfo_matches_php(string $nfo, ?string $expectedMethod, bool $expectNativeHandled = true): void
    {
        $extractor = new NfoNameExtractor;
        $fallbackExtractor = new NfoNameExtractor('/nonexistent/libnntmux_pipeline.so');

        $rustResult = $extractor->extractFromNfoRust($nfo);
        $phpResult = $extractor->extractFromNfoPhp($nfo);
        $defaultResult = $extractor->extractFromNfo($nfo);
        $fallbackResult = $fallbackExtractor->extractFromNfo($nfo);

        $this->assertSame(self::snapshot($phpResult), self::snapshot($rustResult));
        $this->assertSame(self::snapshot($phpResult), self::snapshot($defaultResult));
        $this->assertSame(self::snapshot($phpResult), self::snapshot($fallbackResult));
        $this->assertSame($expectedMethod, $rustResult?->method);

        // Also verify individual checkFor*Rust methods match their *Php counterparts
        $this->assertSame(self::snapshot($extractor->checkForTvPhp($nfo)), self::snapshot($extractor->checkForTvRust($nfo)));
        $this->assertSame(self::snapshot($extractor->checkForMoviePhp($nfo)), self::snapshot($extractor->checkForMovieRust($nfo)));
        $this->assertSame(self::snapshot($extractor->checkForMusicPhp($nfo)), self::snapshot($extractor->checkForMusicRust($nfo)));
        $this->assertSame(self::snapshot($extractor->checkForTitleYearPhp($nfo)), self::snapshot($extractor->checkForTitleYearRust($nfo)));
        $this->assertSame(self::snapshot($extractor->checkForGamePhp($nfo)), self::snapshot($extractor->checkForGameRust($nfo)));
        $this->assertSame(self::snapshot($extractor->checkForIguanaPhp($nfo)), self::snapshot($extractor->checkForIguanaRust($nfo)));

        if ($expectNativeHandled && $nfo !== '') {
            $ffi = RustPipelineLibrary::resolveDomain('nfo_extractors', NfoNameExtractor::FFI_CDEF);
            $this->assertNotNull($ffi);
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, 4096, $cap);
            // @phpstan-ignore method.notFound
            $rc = (int) $ffi->nntmux_nfo_extract_pattern_match($nfo, strlen($nfo), $buf, $cap);
            $this->assertNotSame(-2, $rc, 'Expected Rust FFI to handle NFO pattern match without declining to PHP');
        }
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2?: bool}>
     */
    public static function nfoPatternCases(): array
    {
        return [
            'complete literal nfo field' => [
                "Release Name: The.West.Wing.S07E01.The.Ticket.1080p.BluRay.10bit.EAC3.2.0.x265-iVy\n",
                'Complete literal NFO field',
            ],
            'generic tv 1 path with SxxExx' => [
                "Path : C:\\Releases\\Great.Show.S02E05.720p.HDTV.x264-GRP.mkv   \n",
                'Generic TV 1',
            ],
            'generic tv 2 colon with SxxExx' => [
                "Info :   My.Show.S03.E12.720p.WEB.h264-GROUP\r\n",
                'Generic TV 2',
            ],
            'generic tv dash between Sxx and Exx does not match [.-_ ]' => [
                "Info :   My.Show.S03-E12.720p.WEB.h264-GROUP\r\n",
                null,
            ],
            'generic movies 1' => [
                "Title :  Awesome.Film.2023.1080p.BluRay.x264-GRP\n",
                'Generic Movies 1',
            ],
            'generic movies 2 with double space' => [
                "   Awesome.Film.2022.720p.BDRip.XviD-GRP   \n",
                'Generic Movies 2',
            ],
            'generic movies 2 ignores Source prefix' => [
                "  Source.Film.2022.720p.BDRip.XviD-GRP   \n",
                null,
            ],
            'generic movies 3 ntsc multi dvdr' => [
                "   Classic.Movie.NTSC.MULTi.DVDR-GRP  \n",
                'Generic Movies 3',
            ],
            'music fm radio' => [
                "   Top.Artist.Live-FM-10-23\n",
                'Music FM RADIO',
            ],
            'title year with language resolution source and audio metadata' => [
                "Great Adventure (2021)\n"
                ."Language: DE\n"
                ."Resolution: 1920x1080\n"
                ."Source: Blu-Ray\n"
                ."Audio Format: DTS-HD\n",
                'Title (Year)',
            ],
            'title year uses raw metadata from excluded Source line' => [
                "Great Adventure (2021)\n"
                ."Source: BD\n"
                ."Width: (640\n"
                ."Codec Name: 0x0055 MPEG-1 Layer 3\n"
                ."Sprache: Englisch\n",
                'Title (Year)',
            ],
            'title year with fallback video codec and special mappings' => [
                "Another Story (1999)\n"
                ."Langue: FR\n"
                ."Frame Size: 1280 @\n"
                ."Writing Library: MPEG-4 Visual\n"
                ."Audio: (AC3\n",
                'Title (Year)',
            ],
            'title year skipped when audiobook or pdf present' => [
                "Great Adventure (2021)\nFormat: Audio Book\n",
                null,
            ],
            'pc games (c) with group' => [
                "Released by RELOADED\nSuper.Game.v1.2 (c) GameStudio\n",
                'PC Games (c)',
            ],
            'pc games *ISO* with group' => [
                "Team FAiRLiGHT presents\nMega.Shooter.2025 *ISO*\n",
                'PC Games *ISO*',
            ],
            'iguana release' => [
                "Supplier : Team IGUANA\n"
                ."Notes: manual.pdf\n"
                ."Cool Concert (2018)\n"
                ." [*] English\n"
                ." [*] DTS 2.0 MONO\n"
                ."Format : DVD9\n"
                ."[1920x1080] Resolution\n",
                'IGUANA',
            ],
        ];
    }

    #[DataProvider('fileNameExtractorCases')]
    public function test_filename_extractor_matches_php(string $filename, ?string $expectedMethod, bool $expectNativeHandled = true): void
    {
        $extractor = new FileNameExtractor;
        $fallbackExtractor = new FileNameExtractor(null, '/nonexistent/libnntmux_pipeline.so');

        $rustResult = $extractor->extractFromFile($filename);
        $phpResult = $extractor->extractFromFilePhp($filename);
        $fallbackResult = $fallbackExtractor->extractFromFile($filename);

        $this->assertSame(self::snapshot($phpResult), self::snapshot($rustResult), "Mismatch for filename: {$filename}");
        $this->assertSame(self::snapshot($phpResult), self::snapshot($fallbackResult));
        $this->assertSame($expectedMethod, $rustResult?->method, "Unexpected method for filename: {$filename}");

        if ($expectNativeHandled && $filename !== '') {
            $ffi = RustPipelineLibrary::resolveDomain('nfo_extractors', NfoNameExtractor::FFI_CDEF);
            $this->assertNotNull($ffi);
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, 4096, $cap);
            // @phpstan-ignore method.notFound
            $rc = (int) $ffi->nntmux_filename_extract_candidate($filename, strlen($filename), $buf, $cap);
            $this->assertNotSame(-2, $rc, "Expected Rust FFI to handle filename without declining: {$filename}");
        }
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2?: bool}>
     */
    public static function fileNameExtractorCases(): array
    {
        return [
            'nzbsplit wrapper valid' => [
                'NBA__NZBSPLIT__bdab31d6f79989608009e7e8eadcbe66__NZBSPLIT__NBA_20260419_PHI_BOS_1080p60_ABC.7z.073',
                'NZBSPLIT wrapper',
            ],
            'nzbsplit wrapper rejected' => [
                'TEST__NZBSPLIT__1234567890abcdef__NZBSPLIT__setup.7z.001',
                null,
            ],
            'complete literal basename' => [
                'The.West.Wing.S07E01.The.Ticket.1080p.BluRay.10bit.EAC3.2.0.x265-iVy.mkv',
                'Complete literal basename',
            ],
            'scene release with group' => [
                'Show.Title.2024.1080p.x264-GROUP\\file.dat',
                'Scene release with group',
            ],
            'tv SxxExx with quality' => [
                'Folder/Show.Name.S01E02.720p.HDTV.x264.mkv',
                'TV SxxExx with quality',
            ],
            'tv SxxExx with quality in parent folder' => [
                'Show.Name.S01E02.720p.HDTV/file.mkv',
                'TV SxxExx with quality',
            ],
            'generic tv' => [
                'Folder\\Show.Name.S02E10.Episode.Title.mkv',
                'Generic TV',
            ],
            '4k uhd movie' => [
                'Movie.Title.2023.PROPER.2160p.UHD.HDR10+.HEVC.remux.mkv',
                '4K/UHD Movie',
            ],
            'hd movie modern codec' => [
                'Movie.Title.2022.PROPER.1080p.BluRay.H.264.stream.mkv',
                'HD Movie modern codec',
            ],
            'generic movie 1' => [
                'Film.Name.2019.Custom.BDRip.Audio.mkv',
                'Generic movie 1',
            ],
            'generic movie 2' => [
                'Film.Name.2020.Tag.x265.extra.mkv',
                'Generic movie 2',
            ],
            'streaming service release' => [
                'Series.Title.AMZN.1080p.WEB-DL.DDP5.1.mkv',
                'Streaming service release',
            ],
            'generic music' => [
                'Artist-Album-FLAC-2021-GRP\\01-track.flac',
                'Generic music',
            ],
            'music groups' => [
                'Artist-Album-2021-C4\\01-track.mp3',
                'music groups',
            ],
            'music with codec' => [
                'Artist.Album.FLAC.16bit.2020. lossless-GRP/01.flac',
                'Music with codec',
            ],
            'movie (year) avi' => [
                'Movies\\Classic Film (1998).avi',
                'Movie (year) avi',
            ],
            'movie (year) iso' => [
                'Movies\\Classic Film (1998).iso',
                'Movie (year) iso',
            ],
            'movie (year) mkv' => [
                'Movies\\Classic Film (1998).mkv',
                'Movie (year) mkv/mp4',
            ],
            'movie (year) without title prefix after backslash does not match' => [
                'Movies\\(1998).avi',
                null,
            ],
            'rar archive path' => [
                'Release.Title.2021/CD1/archive.r01',
                'RAR archive path',
            ],
            'nintendo switch' => [
                'Super Mario Odyssey [0100000000010000].nsp',
                'Nintendo Switch',
            ],
            'console game release' => [
                'Game.Title.PS5.DUPLEX-GRP.iso',
                'Console game release',
            ],
            'ebook' => [
                'Author - Book Title.epub',
                'EBook',
            ],
            'cleaned scene name' => [
                'Scene.Release.Title.2024-GROUP.part01.rar',
                'Cleaned scene name',
            ],
            'technical video basename' => [
                'Chateau.Gaillard.Le.pari.fou.de.Richard.Coeur.de.Lion.2026.HDTVLight.1080p.x264.VF.AC3.2.0.@BS.rar',
                'Technical video basename',
            ],
            'matching video parent basename' => [
                'Ordinary World (2016) 1080p/Ordinary World (2016) 1080p.mkv',
                'Matching video parent basename',
            ],
            'folder name fallback' => [
                'Some Folder Release Name-GRP',
                'Folder name',
            ],
            'unicode fallback' => [
                'Saturday.Night.Live.S52E03.Shane.Gillis.Rosalía.October.10.2026.1080p.HULU.WEB-DL.DDP5.1.H.264-None.mkv',
                'Complete literal basename',
                false,
            ],
        ];
    }
}
