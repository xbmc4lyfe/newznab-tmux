<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Services\NameFixing\FileNameCleaner;
use App\Support\PredbSearchDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileNameCleanerUnicodeIdentityTest extends TestCase
{
    /**
     * Retained 2026-10-11 https://hdencode.org/tag/tv-shows/feed/ item:
     * General Filename is this literal title plus .mkv; Duration is 1 h 8 min.
     * The RSS heading additionally carries a terminal display-size label (4.1 GB).
     */
    private const CANONICAL = 'Saturday.Night.Live.S52E03.Shane.Gillis.Rosalía.October.10.2026.1080p.HULU.WEB-DL.DDP5.1.H.264-None';

    #[Test]
    public function captured_full_length_filename_retains_the_publishers_accented_identity(): void
    {
        $cleaned = (new FileNameCleaner)->cleanForMatching(self::CANONICAL.'.mkv');

        $this->assertSame(self::CANONICAL, $cleaned);
        $this->assertSame(PredbSearchDocument::exactValue(self::CANONICAL), PredbSearchDocument::exactValue($cleaned));
    }

    #[Test]
    public function decomposed_marks_are_preserved_without_normalizing_to_a_different_byte_identity(): void
    {
        $decomposed = str_replace('í', "i\u{0301}", self::CANONICAL);

        $cleaned = (new FileNameCleaner)->cleanForMatching($decomposed.'.mkv');

        $this->assertSame($decomposed, $cleaned);
        $this->assertNotSame(self::CANONICAL, $cleaned);
    }

    #[Test]
    public function utf8_case_comparison_keeps_the_accent_in_both_case_forms(): void
    {
        $uppercase = mb_strtoupper(self::CANONICAL, 'UTF-8');

        $cleaned = (new FileNameCleaner)->cleanForMatching($uppercase.'.MKV');

        $this->assertSame($uppercase, $cleaned);
        $this->assertSame(PredbSearchDocument::exactValue(self::CANONICAL), PredbSearchDocument::exactValue($cleaned));
    }

    #[Test]
    public function unicode_letters_marks_and_numbers_survive_path_and_suffix_removal(): void
    {
        $title = "東京.Élève.١٢.Ⅻ.e\u{0301}.2026-GROUP";

        $cleaned = (new FileNameCleaner)->cleanForMatching('Folder/'.$title.'.part01.rar');

        $this->assertSame($title, $cleaned);
    }

    #[Test]
    #[DataProvider('accentLossCollisions')]
    public function accented_name_cannot_collapse_into_a_different_ascii_title(string $differentTitle): void
    {
        $cleaner = new FileNameCleaner;
        $original = $cleaner->cleanForMatching(self::CANONICAL.'.mkv');
        $different = $cleaner->cleanForMatching($differentTitle.'.mkv');

        $this->assertIsString($original);
        $this->assertIsString($different);
        $this->assertNotSame(PredbSearchDocument::exactValue($original), PredbSearchDocument::exactValue($different));
    }

    /** @return array<string, array{string}> */
    public static function accentLossCollisions(): array
    {
        return [
            'old dropped-letter output' => [str_replace('Rosalía', 'Rosala', self::CANONICAL)],
            'transliterated spelling' => [str_replace('Rosalía', 'Rosalia', self::CANONICAL)],
        ];
    }

    #[Test]
    #[DataProvider('invalidEncodings')]
    public function invalid_encoding_is_rejected_instead_of_repaired_into_another_title(string $filename): void
    {
        $this->assertFalse((new FileNameCleaner)->cleanForMatching($filename));
    }

    /** @return array<string, array{string}> */
    public static function invalidEncodings(): array
    {
        return [
            'isolated invalid byte' => [str_replace('Rosalía', "Rosal\xffa", self::CANONICAL).'.mkv'],
            'Latin1 accent' => [str_replace('Rosalía', "Rosal\xeda", self::CANONICAL).'.mkv'],
            'overlong sequence' => ["\xc0\xaf".self::CANONICAL.'.mkv'],
            'truncated codepoint' => [self::CANONICAL."\xc3.mkv"],
        ];
    }

    #[Test]
    #[DataProvider('unicodeControls')]
    public function nonprinting_unicode_cannot_fold_into_another_canonical_identity(string $filename): void
    {
        $this->assertFalse((new FileNameCleaner)->cleanForMatching($filename));
    }

    /** @return array<string, array{string}> */
    public static function unicodeControls(): array
    {
        return [
            'zero width spelling collision' => [str_replace('Rosalía', "Rosal\u{200b}ia", self::CANONICAL).'.mkv'],
            'C1 spelling collision' => [str_replace('Rosalía', "Rosal\u{0085}ia", self::CANONICAL).'.mkv'],
            'directional format control' => ["\u{202e}".self::CANONICAL.'.mkv'],
        ];
    }

    #[Test]
    #[DataProvider('literalTechnicalTitles')]
    public function only_complete_literal_technical_scene_titles_bypass_legacy_cleaning(string $title, bool $accepted): void
    {
        $this->assertSame($accepted, (new FileNameCleaner)->isLiteralTechnicalSceneTitle($title));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function literalTechnicalTitles(): iterable
    {
        yield 'retained accented technical title' => [self::CANONICAL, true];
        yield 'decomposed accent bytes' => [str_replace('í', "i\u{0301}", self::CANONICAL), true];
        yield 'Unicode script and literal underscores' => ['東京_Élève_2026_1080p_x265-GROUP', true];
        yield 'delimited PAL format' => ['Élève.Movie.2026.PAL-GRP', true];
        yield 'delimited dotted codec' => ['Élève.Movie.2026.H.264-GRP', true];
        yield 'delimited audio format' => ['Élève.Album.FLAC-GRP', true];
        yield 'two character group' => ['Élève.Movie.2026.WEB-DL-GR', true];
        yield 'fifteen character group' => ['Élève.Movie.2026.1080p-ABCDEFGHIJKLMNO', true];
        yield 'exact 255 character ceiling' => [str_repeat('É', 240).'.2026.1080p-GRP', true];
        yield '256 characters' => [str_repeat('É', 241).'.2026.1080p-GRP', false];
        yield 'substring PAL false positive' => ['Népali.Music-GRP', false];
        yield 'technical word only in group' => ['Élève.Movie.2026-PAL', false];
        yield 'release flag without technical format' => ['Élève.Movie.2026.REPACK-GRP', false];
        yield 'slash path' => ['Folder/'.self::CANONICAL, false];
        yield 'backslash path' => ['Folder\\'.self::CANONICAL, false];
        yield 'leading path traversal punctuation' => ['..'.self::CANONICAL, false];
        yield 'media extension' => [self::CANONICAL.'.mkv', false];
        yield 'archive and part suffix' => [self::CANONICAL.'.part01.rar', false];
        yield 'PAR2 extension' => [self::CANONICAL.'.par2', false];
        yield 'dotted sample suffix' => [self::CANONICAL.'.sample', false];
        yield 'hyphen sample suffix' => [self::CANONICAL.'-sample', false];
        yield 'hyphen proof suffix' => [self::CANONICAL.'-proof', false];
        yield 'hyphen thumbs suffix' => [self::CANONICAL.'-thumbs', false];
        yield 'hyphen part suffix' => [self::CANONICAL.'-part01', false];
        yield 'hyphen volume suffix' => [self::CANONICAL.'-vol01', false];
        yield 'space separated title' => ['Élève Movie 2026 1080p-GRP', false];
        yield 'leading whitespace' => [' '.self::CANONICAL, false];
        yield 'trailing whitespace' => [self::CANONICAL.' ', false];
        yield 'quoted title' => ['"'.self::CANONICAL.'"', false];
        yield 'trailing newline' => [self::CANONICAL."\n", false];
        yield 'embedded ASCII control' => ['Movie.'."\0".'2026.1080p-GRP', false];
        yield 'Unicode format control' => ['Movie.'."\u{202E}".'2026.1080p-GRP', false];
        yield 'invalid UTF8' => ['Movie.'."\xff".'.2026.1080p-GRP', false];
        yield 'one character group' => ['Élève.Movie.2026.1080p-G', false];
        yield 'sixteen character group' => ['Élève.Movie.2026.1080p-ABCDEFGHIJKLMNOP', false];
        yield 'non ASCII group' => ['Élève.Movie.2026.1080p-Grüpp', false];
        yield 'hex obfuscation' => [str_repeat('4', 32).'-GRP', false];
    }

    #[Test]
    public function existing_ascii_control_and_separator_suffix_behavior_stays_intact(): void
    {
        $cleaner = new FileNameCleaner;

        $this->assertSame('Exact.Release.2026-GROUP', $cleaner->cleanForMatching("\x01Folder\\Exact.Release.2026-GROUP.part01.rar\x02"));
        $this->assertSame('Exact_Release_2026-GROUP', $cleaner->cleanForMatching('Folder/Exact_Release_2026-GROUP.mkv'));
        $this->assertFalse($cleaner->cleanForMatching('VIDEO_TS/VTS_01_1.VOB'));
    }
}
