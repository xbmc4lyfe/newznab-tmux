<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Services\NameFixing\FileNameCleaner;
use PHPUnit\Framework\TestCase;

class FileNameCleanerTest extends TestCase
{
    public function test_clean_for_matching_rejects_url_shortcuts(): void
    {
        $cleaner = new FileNameCleaner;

        $this->assertFalse($cleaner->cleanForMatching('Film ;-)/Extreem Online Contact.url'));
    }

    public function test_clean_for_matching_rejects_generic_dvd_structure_files(): void
    {
        $cleaner = new FileNameCleaner;

        $this->assertFalse($cleaner->cleanForMatching('Film ;-)/VIDEO_TS/VIDEO_TS.VOB'));
        $this->assertFalse($cleaner->cleanForMatching('Film ;-)/VIDEO_TS/VTS_01_1.VOB'));
        $this->assertFalse($cleaner->cleanForMatching('Film ;-)/1.jpg'));
    }

    public function test_clean_for_matching_keeps_useful_cover_names(): void
    {
        $cleaner = new FileNameCleaner;

        $this->assertSame('cover the fisher king', $cleaner->cleanForMatching('Film ;-)/cover the fisher king.jpg'));
    }

    public function test_normalize_candidate_title_removes_pdf_extension(): void
    {
        $cleaner = new FileNameCleaner;

        $this->assertSame('WOB Klassik 4.25', $cleaner->normalizeCandidateTitle('WOB Klassik 4.25.Pdf'));
    }

    public function test_format_search_name_keeps_scene_titles_dotted(): void
    {
        $cleaner = new FileNameCleaner;

        $this->assertSame(
            'Southern.Charm.S11E12.Even.Further.South.720p.AMZN.WEB-DL.DDP2.0.H.264-NTb',
            $cleaner->formatSearchName('Southern Charm S11E12 Even Further South 720p AMZN WEB-DL DDP2 0 H 264-NTb.mkv')
        );
    }

    public function test_numeric_prefix_can_be_preserved_for_predb_identity(): void
    {
        $cleaner = new FileNameCleaner;
        self::assertSame('123.Fixture.Alias.2026-GRP', $cleaner->cleanForMatching('123.Fixture.Alias.2026-GRP.mkv', preserveLeadingNumber: true));
        self::assertSame('123.Fixture.Alias.2026-GRP', $cleaner->cleanForMatching('123.Fixture.Alias.2026-GRP.part01.rar', preserveLeadingNumber: true));
    }

    public function test_default_music_track_number_cleanup_remains_unchanged(): void
    {
        $cleaner = new FileNameCleaner;
        self::assertSame('Artist.Track', $cleaner->cleanForMatching('01.Artist.Track.flac'));
        self::assertSame('01.Artist.Track', $cleaner->cleanForMatching('01.Artist.Track.flac', preserveLeadingNumber: true));
    }

    public function test_rust_ffi_and_php_fallback_produce_identical_cleaning_results(): void
    {
        $cleaner = new FileNameCleaner;
        $samples = [
            'Show.S01.1080p-GRP/Show.S01E01.Pilot.1080p.WEB-DL.DDP5.1.H.264-GRP.part01.rar',
            'Movie.2026.2160p.UHD.BluRay.REMUX.HEVC.DTS-HD.MA.7.1-GROUP.mkv',
            'Exact.Release.2026.1080p.WEB.x264-GRP.DTS.part12345.rar',
            '01.Artist.Track.Name.2026.FLAC-GRP.flac',
            'Film ;-)/VIDEO_TS/VTS_01_1.VOB',
            'Film ;-)/cover the fisher king.jpg',
            'Archive.Release.2026-GRP.vol00+01.par2',
            'Game.Setup.2026-GRP.7z.001',
            'Book.Title.2026.Retail-GRP.epub.rar',
            'Film ;-)/Extreem Online Contact.url',
            'VIDEO_TS/VTS_011.VOB',
            'VIDEO_TS/vts0101.vob',
            '.hidden_file.mkv',
        ];

        foreach ($samples as $sample) {
            self::assertSame(
                $cleaner->stripKnownFileSuffixesPhp($sample, true),
                $cleaner->stripFileSuffixes($sample),
                $sample
            );
            self::assertSame(
                $cleaner->computeForMatchingPhp($sample, false),
                $cleaner->cleanForMatching($sample, false),
                $sample
            );
            self::assertSame(
                $cleaner->computeForMatchingPhp($sample, true),
                $cleaner->cleanForMatching($sample, true),
                $sample
            );
        }
    }
}
