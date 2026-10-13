<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NameFixing\Extractors\FileNameExtractor;
use App\Services\NameFixing\FilePrioritizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FileNameExtractorTest extends TestCase
{
    public function test_extracts_release_name_from_nzb_split_wrapper(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile(
            'NBA__NZBSPLIT__bdab31d6f79989608009e7e8eadcbe66__NZBSPLIT__NBA_20260419_PHI_BOS_1080p60_ABC.7z.073'
        );

        $this->assertNotNull($result);
        $this->assertSame('NBA.20260419.PHI.BOS.1080p60.ABC', $result->newName);
        $this->assertSame('NZBSPLIT wrapper', $result->method);
        $this->assertSame('File', $result->checkerName);
    }

    public function test_rejects_low_information_nzb_split_payloads(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile(
            'TEST__NZBSPLIT__1234567890abcdef__NZBSPLIT__setup.7z.001'
        );

        $this->assertNull($result);
    }

    #[DataProvider('completeTechnicalFilenames')]
    public function test_preserves_complete_technical_filename_title(string $filename, string $expected): void
    {
        $result = (new FileNameExtractor)->extractFromFile($filename);

        $this->assertNotNull($result);
        $this->assertSame($expected, $result->newName);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function completeTechnicalFilenames(): array
    {
        return [
            'release 2632544' => ['Chateau.Gaillard.Le.pari.fou.de.Richard.Coeur.de.Lion.2026.HDTVLight.1080p.x264.VF.AC3.2.0.@BS.rar', 'Chateau.Gaillard.Le.pari.fou.de.Richard.Coeur.de.Lion.2026.HDTVLight.1080p.x264.VF.AC3.2.0.@BS'],
            'release 2632547' => ['CIA.Les.guerres.secretes.de.l.apres-11.Septembre.Partie1sur3.2026.HDTVLight.1080p.x264.VF.AC3.2.0.@BS.rar', 'CIA.Les.guerres.secretes.de.l.apres-11.Septembre.Partie1sur3.2026.HDTVLight.1080p.x264.VF.AC3.2.0.@BS'],
            'release 2632569' => ['Mysteres.du.Systeme.solaire.2024.HDTVLight.1080p.x264.VF.AC3.2.0.@BS.rar', 'Mysteres.du.Systeme.solaire.2024.HDTVLight.1080p.x264.VF.AC3.2.0.@BS'],
            'release 2632648' => ['Chateau.Gaillard.Le.pari.fou.de.Richard.Coeur.de.Lion.2026.HDTVLight.1080p.x264.VF.AC3.2.0.@BS.rar', 'Chateau.Gaillard.Le.pari.fou.de.Richard.Coeur.de.Lion.2026.HDTVLight.1080p.x264.VF.AC3.2.0.@BS'],
            'release 2632656' => ['Dans.les.secrets.des.Illuminati.2026.HDTVLight.1080p.x264.VF.AC3.2.0.@BS.rar', 'Dans.les.secrets.des.Illuminati.2026.HDTVLight.1080p.x264.VF.AC3.2.0.@BS'],
            'release 2632665' => ['Liberte.La.statue.qui.voulait.changer.le.monde.2026.HDTVLight.1080p.x264.VF.AC3.2.0.@BS.rar', 'Liberte.La.statue.qui.voulait.changer.le.monde.2026.HDTVLight.1080p.x264.VF.AC3.2.0.@BS'],
            'release 2633143' => ['Mysteres.du.Systeme.solaire.2024.HDTVLight.1080p.x264.VF.AC3.2.0.@BS.rar', 'Mysteres.du.Systeme.solaire.2024.HDTVLight.1080p.x264.VF.AC3.2.0.@BS'],
            'release 2645100' => ['La.Joconde.Le.mystere.de.Mona.Lisa.2026.HDTVLight.1080p.x264.VF.AC3.2.0.@BS.rar', 'La.Joconde.Le.mystere.de.Mona.Lisa.2026.HDTVLight.1080p.x264.VF.AC3.2.0.@BS'],
            'release 2645104' => ['Liberte.La.statue.qui.voulait.changer.le.monde.2026.HDTVLight.1080p.x264.VF.AC3.2.0.@BS.rar', 'Liberte.La.statue.qui.voulait.changer.le.monde.2026.HDTVLight.1080p.x264.VF.AC3.2.0.@BS'],
            'release 2645106' => ['La.cite.de.Troie.a-t-elle.reellement.existe.2026.HDTVLight.1080p.x264.VF.AC3.2.0.@BS.rar', 'La.cite.de.Troie.a-t-elle.reellement.existe.2026.HDTVLight.1080p.x264.VF.AC3.2.0.@BS'],
            'homefront md5' => ['[pcela] Homefront (2013) - [BD 1080p][H264 8bit][DTS 5 1][MULTI][English+Polish].md5', '[pcela] Homefront (2013) - [BD 1080p][H264 8bit][DTS 5 1][MULTI][English+Polish]'],
            'homefront nfo' => ['[pcela] Homefront (2013) - [BD 1080p][H264 8bit][DTS 5 1][MULTI][English+Polish].nfo', '[pcela] Homefront (2013) - [BD 1080p][H264 8bit][DTS 5 1][MULTI][English+Polish]'],
            'homefront sha1' => ['[pcela] Homefront (2013) - [BD 1080p][H264 8bit][DTS 5 1][MULTI][English+Polish].sha1', '[pcela] Homefront (2013) - [BD 1080p][H264 8bit][DTS 5 1][MULTI][English+Polish]'],
            'homefront mkv' => ['[pcela] Homefront (2013) - [BD 1080p][H264 8bit][DTS 5 1][MULTI][English+Polish][86BC5CED].mkv', '[pcela] Homefront (2013) - [BD 1080p][H264 8bit][DTS 5 1][MULTI][English+Polish][86BC5CED]'],
            'unix archive path' => ['/srv/usenet/Chateau.Gaillard.Le.pari.fou.de.Richard.Coeur.de.Lion.2026.HDTVLight.1080p.x264.VF.AC3.2.0.@BS.rar', 'Chateau.Gaillard.Le.pari.fou.de.Richard.Coeur.de.Lion.2026.HDTVLight.1080p.x264.VF.AC3.2.0.@BS'],
            'windows media path' => ['D:\\Unsorted\\[pcela] Homefront (2013) - [BD 1080p][H264 8bit][DTS 5 1][MULTI][English+Polish][86BC5CED].mkv', '[pcela] Homefront (2013) - [BD 1080p][H264 8bit][DTS 5 1][MULTI][English+Polish][86BC5CED]'],
            'existing episode pattern' => ['Some.Show.S01E03.1080p.WEB-DL.x264-GROUP.mkv', 'Some.Show.S01E03.1080p.WEB-DL.x264-GROUP'],
        ];
    }

    #[DataProvider('completeLiteralReleaseFilenames')]
    public function test_preserves_complete_literal_basename_before_quality_token_heuristics(string $filename, string $expected): void
    {
        $result = (new FileNameExtractor)->extractFromFile($filename);

        $this->assertNotNull($result);
        $this->assertSame($expected, $result->newName);
        $this->assertSame('Complete literal basename', $result->method);
    }

    /** @return array<string, array{string, string}> */
    public static function completeLiteralReleaseFilenames(): array
    {
        $title = 'The.West.Wing.S07E01.The.Ticket.1080p.BluRay.10bit.EAC3.2.0.x265-iVy';

        return [
            'retained release 3118899' => [$title.'.mkv', $title],
            'retained Rosalia General Filename' => ['Saturday.Night.Live.S52E03.Shane.Gillis.Rosalía.October.10.2026.1080p.HULU.WEB-DL.DDP5.1.H.264-None.mkv', 'Saturday.Night.Live.S52E03.Shane.Gillis.Rosalía.October.10.2026.1080p.HULU.WEB-DL.DDP5.1.H.264-None'],
            'complete Windows basename' => ['D:\\Unsorted\\'.$title.'.mkv', $title],
            'complete basename under archive wrappers' => [$title.'.mkv.rar.par2', $title],
            'movie with dotted audio and codec' => ['Film.2026.1080p.WEB-DL.DDP5.1.H.264-NTb.mkv', 'Film.2026.1080p.WEB-DL.DDP5.1.H.264-NTb'],
            'combining mark spelling stays literal' => ["Cafe\u{0301}.Movie.2026.1080p.BluRay.x264-GROUP.mkv", "Cafe\u{0301}.Movie.2026.1080p.BluRay.x264-GROUP"],
            'UTF-8 character bound exceeds legacy byte bound' => [str_repeat('é', 170).'.Movie.2026.1080p.BluRay.x264-GROUP.mkv', str_repeat('é', 170).'.Movie.2026.1080p.BluRay.x264-GROUP'],
        ];
    }

    #[DataProvider('nonLiteralReleaseBasenames')]
    public function test_does_not_promote_incomplete_or_editorial_basename_to_literal_release(string $filename): void
    {
        $result = (new FileNameExtractor)->extractFromFile($filename);

        $this->assertNotSame('Complete literal basename', $result?->method);
    }

    /** @return array<string, array{string}> */
    public static function nonLiteralReleaseBasenames(): array
    {
        return [
            'unknown suffix' => ['Some.Show.S01E03.1080p.BluRay.10bit.x265-GROUP.unknown'],
            'metadata suffix' => ['Some.Show.S01E03.1080p.BluRay.10bit.x265-GROUP.txt'],
            'missing group' => ['Some.Show.S01E03.1080p.BluRay.10bit.x265.mkv'],
            'spaced editorial heading' => ['The West Wing S07E01 1080p BluRay x265-iVy.mkv'],
            'generic installer' => ['setup.2026.1080p.x265-GROUP.rar'],
            'bare identity has no file suffix' => ['Some.Show.S01E03.1080p.BluRay.10bit.x265-GROUP'],
            'hashed payload' => ['e5a5b12627947bf5ac5b7e75cfa31d53.mkv'],
            'overlong literal title' => [str_repeat('a', 260).'.Movie.2026.1080p.BluRay.x264-GROUP.mkv'],
            'nonprinting Unicode is not a spelling alias' => ["Rosal\u{200B}ia.Movie.2026.1080p.BluRay.x264-GROUP.mkv"],
            'invalid UTF-8' => ["Film.2026.1080p.BluRay.\xFF.x264-GROUP.mkv"],
        ];
    }

    #[DataProvider('uninformativeTechnicalBasenames')]
    public function test_does_not_promote_uninformative_basename_to_release_title(string $filename, string $forbiddenTitle): void
    {
        $result = (new FileNameExtractor)->extractFromFile($filename);

        $this->assertNotSame($forbiddenTitle, $result?->newName);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function uninformativeTechnicalBasenames(): array
    {
        return [
            'random hash' => ['af9458c7ea4d27c71a8496d42e65b8bb.rar', 'af9458c7ea4d27c71a8496d42e65b8bb'],
            'hashed suffix' => ['af9458c7ea4d27c71a8496d42e65b8bb.@BS.rar', 'af9458c7ea4d27c71a8496d42e65b8bb.@BS'],
            'readme' => ['readme.2026.1080p.@BS.rar', 'readme.2026.1080p.@BS'],
            'year without quality' => ['Some.Movie.2026.@BS.rar', 'Some.Movie.2026.@BS'],
            'quality without source or codec' => ['Some.Movie.2026.1080p.@BS.rar', 'Some.Movie.2026.1080p.@BS'],
            'source without year or episode' => ['Some.Movie.HDTVLight.1080p.x264.@BS.rar', 'Some.Movie.HDTVLight.1080p.x264.@BS'],
        ];
    }

    #[DataProvider('invalidTechnicalTokens')]
    public function test_technical_basename_requires_complete_tokens_and_a_real_title_prefix(string $filename, string $completeTitle): void
    {
        $result = (new FileNameExtractor)->extractFromFile($filename);

        $this->assertNotSame('Technical video basename', $result?->method);
        $this->assertNotSame($completeTitle, $result?->newName);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidTechnicalTokens(): array
    {
        return [
            'resolution embedded in another token' => ['Film.2026.HDTVLight.foo1080px.x264.@BS.rar', 'Film.2026.HDTVLight.foo1080px.x264.@BS'],
            'source embedded in another token' => ['Film.2026.BD123.1080p.x264.@BS.rar', 'Film.2026.BD123.1080p.x264.@BS'],
            'uploader tag does not supply the title prefix' => ['[pcela] BS (2013) - [BD 1080p][H264 8bit].mkv', '[pcela] BS (2013) - [BD 1080p][H264 8bit]'],
        ];
    }

    public function test_dvd_structure_is_excluded_before_filename_extraction(): void
    {
        $files = ['The Illusionist (2006)\\VIDEO_TS\\VIDEO_TS.BUP', 'The Illusionist (2006)\\VIDEO_TS\\VTS_01_0.IFO'];

        $this->assertSame([], (new FilePrioritizer)->prioritizeForMatching($files));
    }

    #[DataProvider('unicodeTechnicalFilenames')]
    public function test_does_not_promote_unicode_basename_before_update_cleaner_preserves_it(string $filename, string $completeTitle): void
    {
        $result = (new FileNameExtractor)->extractFromFile($filename);

        $this->assertNotSame('Technical video basename', $result?->method);
        $this->assertNotSame($completeTitle, $result?->newName);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unicodeTechnicalFilenames(): array
    {
        return [
            'accented title' => ['Léon.1994.HDTVLight.1080p.x264.@BS.rar', 'Léon.1994.HDTVLight.1080p.x264.@BS'],
            'cjk title' => ['天空之城.1986.HDTVLight.1080p.x264.@BS.rar', '天空之城.1986.HDTVLight.1080p.x264.@BS'],
        ];
    }

    #[DataProvider('matchingParentVideoPaths')]
    public function test_preserves_observed_title_when_video_basename_matches_parent(string $filename): void
    {
        $result = (new FileNameExtractor)->extractFromFile($filename);

        $this->assertNotNull($result);
        $this->assertSame('Ordinary World (2016) 1080p', $result->newName);
        $this->assertSame('Matching video parent basename', $result->method);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function matchingParentVideoPaths(): array
    {
        return [
            'observed backslash path' => ['Ordinary World (2016) 1080p\\Ordinary World (2016) 1080p.mkv'],
            'slash path' => ['Ordinary World (2016) 1080p/Ordinary World (2016) 1080p.mkv'],
        ];
    }

    #[DataProvider('nonMatchingParentVideoPaths')]
    public function test_parent_video_fallback_requires_observed_matching_title_and_complete_tokens(string $filename): void
    {
        $result = (new FileNameExtractor)->extractFromFile($filename);

        $this->assertNotSame('Matching video parent basename', $result?->method);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonMatchingParentVideoPaths(): array
    {
        return [
            'different parent and child' => ['Other World (2016) 1080p/Ordinary World (2016) 1080p.mkv'],
            'year missing from both' => ['Ordinary World 1080p/Ordinary World 1080p.mkv'],
            'resolution missing from both' => ['Ordinary World (2016)/Ordinary World (2016).mkv'],
            'metadata extension' => ['Ordinary World (2016) 1080p/Ordinary World (2016) 1080p.nfo'],
            'archive extension' => ['Ordinary World (2016) 1080p/Ordinary World (2016) 1080p.rar'],
            'uploader cannot supply title prefix' => ['[pcela] BS (2013) 1080p/[pcela] BS (2013) 1080p.mkv'],
            'resolution only in parent' => ['Ordinary World (2016) 1080p/Ordinary World (2016).mkv'],
            'year inside another number' => ['Ordinary World (12016) 1080p/Ordinary World (12016) 1080p.mkv'],
            'resolution inside another token' => ['Ordinary World (2016) foo1080px/Ordinary World (2016) foo1080px.mkv'],
            'unicode cleaner still loses accents' => ['Léon (1994) 1080p/Léon (1994) 1080p.mkv'],
        ];
    }
}
