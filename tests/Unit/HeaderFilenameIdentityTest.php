<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\HeaderFilenameIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HeaderFilenameIdentityTest extends TestCase
{
    #[Test]
    #[DataProvider('subjects')]
    public function it_compares_the_declared_subject_filename_with_the_article_filename(string $subject, string $actual, bool $expected): void
    {
        self::assertSame($expected, HeaderFilenameIdentity::matches($subject, $actual));
    }

    #[Test]
    public function hostile_subjects_stay_within_the_regex_limits_without_jit(): void
    {
        $jit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
        try {
            foreach (['[1/1]'.str_repeat(' ', 990).'"a"', str_repeat('"a" ', 250), '"a'.str_repeat('.rar', 248).'"',
                str_repeat("'", 1000), '"'.str_repeat('Ab1', 330).'" yEnc',
                '[1/1]'.str_repeat(' ', 667).'x'.str_repeat('"a"', 111), '"a'.str_repeat('.ts', 332).'x" yEnc',
                '"a'.str_repeat('.7z', 332).'x" yEnc', '"a'.str_repeat('.r00', 249).'x" yEnc', '"'.str_repeat('A', 990).'b" yEnc',
                str_repeat(" 'a", 333), str_repeat("'a ", 333)] as $subject) {
                HeaderFilenameIdentity::matches($subject, 'Readable.Name.mkv');
                self::assertSame(PREG_NO_ERROR, preg_last_error(), substr($subject, 0, 20));
            }
        } finally {
            ini_set('pcre.jit', (string) $jit);
        }
    }

    /** @return array<string, array{string, string, bool}> */
    public static function subjects(): array
    {
        return [
            'unquoted subject names the body' => ['Release name anything.bin yEnc (1/2)', 'anything.bin', true],
            'unquoted subject does not name the body' => ['Release name yEnc (1/2)', 'anything.bin', false],
            'two yEnc markers in an unquoted subject' => ['setup.exe yEnc - movie.mkv yEnc', 'setup.exe', false],
            'dotted metadata in an unquoted parity subject' => ['set.par2 [site.com] yEnc', 'payload.mkv', false],
            'exact filename' => ['[1/8] - "Show.S01E01.mkv" yEnc', 'Show.S01E01.mkv', true],
            'filename before yEnc after a quoted tag' => ['"Tag Name" - [10/53] - "show.part10.rar" yEnc', 'show.part10.rar', true],
            'filename after the counter without yEnc' => ['"Tag Name" - [10/53] - "show.part10.rar"', 'show.part10.rar', true],
            'any quoted value equals the body name' => ['"show.part10.rar" - [10/53] - "Tag Name" yEnc', 'show.part10.rar', true],
            'apostrophe inside a double-quoted name' => ['Show\'s Title [2/8] - "Show\'s Title.par2" yEnc', 'Show\'s Title.par2', true],
            'opaque mixed-case subject' => ['[2/8] - "MdssqdkDoi1PGOHU3zIVNathiyE3zsFK.par2" yEnc', '39eba263b2397e84879c8185ca984e04.par2', true],
            'opaque recovery-volume subject' => ['[7/10] - "wtpFcbMsiTODbAh4RiaG.vol015+016.par2" yEnc', 'wtpFcbMsiTODbAh4RiaG-o1on5HdR2Y.par2', true],
            'opaque hexadecimal subject' => ['"'.str_repeat('a1', 8).'"', 'Readable.Name.mkv', true],
            'long single-case token' => ['"'.str_repeat('k', 20).'.rar" yEnc', 'other.rar', false],
            'long lowercase token with digits' => ['"cxjzxhjlgr3djfprozg6.rar" yEnc', 'other.rar', true],
            'same stem, different separators' => ['[2/9] - "SubsPlease Show - 01 1080p ABCD1234 .mkv.par2" yEnc', '[SubsPlease] Show - 01 (1080p) [ABCD1234].mkv.par2', true],
            'an extra word is another file' => ['[1/9] - "Readable.Release.Name.mkv" yEnc', 'Readable.Release.Name.Extra.mkv', false],
            'readable names differ' => ['[1/9] - "Readable.Release.Name.mkv" yEnc', 'Different.Release.mkv', false],
            'short tokens are readable claims' => ['"Movie2026.mkv" yEnc', 'Something.Else.mkv', false],
            'short stem prefixes do not match' => ['"abc.mkv" yEnc', 'abcdefghij.mkv', false],
            'opaque body under a readable subject' => ['[1/9] - "Readable.Release.Name.mkv" yEnc', 'Xk29dPq8LmZ0Yt5R.mkv', false],
            'executable body under an opaque subject' => ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'setup.exe', false],
            'declared executable' => ['[1/1] - "setup.exe" yEnc', 'setup.exe', true],
            'single-quoted filename' => ["[1/1] - 'movie.mkv' yEnc", 'movie.mkv', true],
            'single-quoted mismatch' => ["[1/1] - 'movie.mkv' yEnc", 'other.mkv', false],
            'readable name with a different volume' => ['[10/53] - "show.part10.rar" yEnc', 'show.part11.rar', false],
            'readable name with a different split index' => ['"Readable.Release.r00"', 'Readable.Release.r01', false],
            'readable name with a different extension' => ['"Readable.Release.Name.par2"', 'Readable.Release.Name.mkv', false],
            'readable recovery volume padding differs' => ['[8/9] - "Readable Release Name.vol007+008.par2" yEnc', 'Readable Release Name.vol031+032.par2', false],
            'non-Latin readable names differ' => ['[1/2] - "Фильм (2026) 1080p.mkv" yEnc', 'Другой (2026) 1080p.mkv', false],
            'non-Latin readable names agree' => ['[1/2] - "Фильм (2026) 1080p.mkv" yEnc', '[Фильм] 2026 1080p.mkv', true],
            'invalid UTF-8 subject' => ["[1/2] - \"Readable.Release.Name\xff.mkv\" yEnc", 'Different.mkv', false],
            'readable mismatch through the last quoted value' => ['"Tag" "Readable.Release.Name.mkv"', 'Different.Release.mkv', false],
            'executable with a trailing dot' => ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'setup.exe.', false],
            'script extension appended to a readable name' => ['[1/9] - "Readable.Release.Name.mkv" yEnc', 'Readable.Release.Name.mkv.hta', false],
            'part number prefix' => ['[1/9] - "Readable Release Name Part 1.mkv" yEnc', 'Readable Release Name Part 10.mkv', false],
            'volume number prefix' => ['[1/9] - "Book Series Vol 1.epub" yEnc', 'Book Series Vol 12.epub', false],
            'disc number prefix' => ['"Readable.Album.Disc1.flac" yEnc', 'Readable.Album.Disc12.flac', false],
            'episode number prefix' => ['[1/9] - "Readable.Release.Name.S01E1.mkv" yEnc', 'Readable.Release.Name.S01E10.mkv', false],
            'extra extension after an unlisted one' => ['"Readable.Release.Name.wmv" yEnc', 'Readable.Release.Name.wmv.html', false],
            'unlisted extension agrees' => ['"Readable.Release.Name.wmv" yEnc', 'Readable Release Name.wmv', true],
            'counter before yEnc picks the second value' => ['"Tag Name" - "readable.name.rar" (1/5) yEnc', 'readable.name.rar', true],
            'counter before yEnc rejects a different body' => ['"Tag Name" - "readable.name.rar" (1/5) yEnc', 'Tag.Name.rar', false],
            'sixteen-digit token is opaque only as hexadecimal' => ['"1234567890123456.rar" yEnc', 'other.rar', true],
            'camel-case title is a readable claim' => ['"TheMatrix1999.mkv" yEnc', 'Totally.Different.mkv', false],
            'unlisted script under an opaque subject' => ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'setup.msc', false],
            'executable under an unquoted subject' => ['Readable Release yEnc (1/2)', 'setup.exe', false],
            'executable named in an unquoted subject' => ['Readable setup.exe yEnc (1/2)', 'setup.exe', true],
            'opaque parity subject with a payload body' => ['[1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', 'Xk29dPq8LmZ0Yt5R.mkv', false],
            'opaque recovery volume with a payload body' => ['[1/1] - "Xk29dPq8LmZ0Yt5R.vol00+01.par2" yEnc', 'payload.mkv', false],
            'opaque parity subject with a parity body' => ['[1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', 'b7Qe1ZrT9wXy.vol03+04.PAR2', true],
            'long camel-case title' => ['"TheLordOfTheRingsReturnOfTheKing.mkv" yEnc', 'Totally.Different.mkv', false],
            'long single-case title' => ['"thelordoftheringsreturn.mkv" yEnc', 'Totally.Different.mkv', false],
            'appended sequel number' => ['"Movie.Name.Long.mkv" yEnc', 'Movie.Name.Long.2.mkv', false],
            'appended episode' => ['"Readable.Show.S01E01.mkv" yEnc', 'Readable.Show.S01E01E02.mkv', false],
            'executable inside another unquoted name' => ['Game Setup.exe yEnc (1/2)', 'p.exe', false],
            'script component under an opaque subject' => ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'evil.wsc', false],
            'disk image under an opaque subject' => ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'evil.iso', false],
            'executable body under an opaque disk image' => ['[1/9] - "Xk29dPq8LmZ0Yt5R.iso" yEnc', 'setup.exe', false],
            'disk image under an opaque disk image' => ['[1/9] - "Xk29dPq8LmZ0Yt5R.iso" yEnc', 'b7Qe1ZrT9wXy.ISO', true],
            'executable behind an NTFS stream suffix' => ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'setup.exe::$DATA', false],
            'long executable extension appended' => ['"Readable.Name.command" yEnc', 'Readable.Name.command.application', false],
            'long extension appended' => ['"Readable.Name.torrent" yEnc', 'Readable.Name.torrent.desktop', false],
            'extension added to an extensionless name' => ['"Readable Release Name" yEnc', 'Readable Release Name.torrent', false],
            'non-ASCII digit ends the shorter stem' => ['"readable name part ١" yEnc', 'readable name part ١ extra', false],
            'counter before yEnc ahead of a trailing tag' => ['"Readable.Name.rar" (1/5) yEnc - "Tag Name"', 'Readable Name.rar', true],
            'counter with a dash and a trailing tag' => ['"Tag Name" - [1/5] - "Readable.Name.rar" "Other Words"', 'Readable Name.rar', true],
            'last quoted value rather than the first' => ['"Readable.Name.rar" "Other Words"', 'Readable Name.rar', false],
            'apostrophe before a single quote' => ["[1/1] - x'movie.mkv' other.mkv yEnc", 'other.mkv', true],
            'apostrophe after a single quote' => ["[1/1] - 'movie.mkv'x other.mkv yEnc", 'other.mkv', true],
            'executable with trailing whitespace' => ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'setup.exe ', false],
            'nine-character token is readable' => ['"Xk29dPq8L.rar" yEnc', 'other.rar', false],
            'ten-character token admits a random body' => ['"Xk29dPq8Lm.rar" yEnc', 'b7Qe1ZrT9wXy.rar', true],
            'dense ten-character token admits a readable body' => ['"Xk29dPq8Lm.rar" yEnc', 'other.rar', true],
            'sparse short token rejects a readable body' => ['"LWgrwkDekU9VMNAO.rar" yEnc', 'other.rar', false],
            'initial-led titles do not vouch for each other' => ['"XFilesMovie.mkv" yEnc', 'DDaySpecial.mkv', false],
            'abbreviated titles do not vouch for each other' => ['"MrRobot2026.mkv" yEnc', 'DrStrange2025.mkv', false],
            'ten-character token admits its own extension' => ['"Xk29dPq8Lm.rar" yEnc', 'Xk29dPq8Lm-Qz81.rar', true],
            'number-led title' => ['"12AngryMen.mkv" yEnc', 'Completely.Different.mkv', false],
            'acronym title with a number' => ['"BBC2Documentary.mkv" yEnc', 'Completely.Different.mkv', false],
            'long random subject admits a readable body' => ['[02/36] "PA0iwGDzUsha5QpCCXoMHeHu9xDiTUtz" yEnc', 'Readable.Release.Name.par2', true],
            'parity body under an opaque payload subject' => ['[1/1] - "Xk29dPq8LmZ0Yt5R.rar" yEnc', 'set.vol00+01.par2', false],
            'package under an opaque subject' => ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.deb', false],
            'python script under an opaque subject' => ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.pyw', false],
            'single-class digit token below the hex length' => ['"1234567890.rar" yEnc', 'other.rar', false],
            'ASCII digit ends the shorter stem' => ['"Readable.Name.2.mkv" yEnc', 'Readable.Name.2.Extra.mkv', false],
            'stem comparison ignores case' => ['"Readable.Name.mkv" yEnc', 'readable.name.mkv', true],
            'colon before an executable under an opaque subject' => ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'Xk29: setup.exe', false],
            'colon before an executable under an unquoted subject' => ['Readable Release yEnc (1/2)', 'Title: setup.exe', false],
            'stream suffix changes a declared executable type' => ['[1/1] - "Xk29dPq8LmZ0Yt5R.exe" yEnc', 'setup.exe:x.scr', false],
            'colon inside a readable payload name' => ['[1/2] - "Movie: The Title.mkv" yEnc', 'Movie- The Title.mkv', true],
            'separators merge different numbers' => ['"Show 1 10.mkv" yEnc', 'Show 11 0.mkv', false],
            'dotted numbers merge differently' => ['"Readable.Name.1.10.mkv" yEnc', 'Readable.Name.11.0.mkv', false],
            'unquoted subject names a longer file' => ['Movie.2026.1080p.exe.mkv yEnc (1/50)', 'Movie.2026.1080p.exe', false],
            'unquoted subject names a sample file' => ['Movie.2026.1080p.exe-sample.mkv yEnc (1/50)', 'Movie.2026.1080p.exe', false],
            'unquoted subject names the executable before a dash' => ['Readable setup.exe - yEnc (1/2)', 'setup.exe', true],
            'roman numeral volume' => ['"Final.Fantasy.VI.iso" yEnc', 'Final.Fantasy.VII.iso', false],
            'letter part' => ['"Season.Finale.Part.A.mkv" yEnc', 'Season.Finale.Part.AB.mkv', false],
            'a removed Roman volume is another file' => ['"Final.Fantasy.VI.iso" yEnc', 'Final.Fantasy.iso', false],
            'an added Roman volume is another file' => ['"Star.Wars.mkv" yEnc', 'Star.Wars.VI.mkv', false],
            'a removed part letter is another file' => ['"Season.Finale.Part.A.mkv" yEnc', 'Season.Finale.Part.mkv', false],
            'unquoted subject names a file after a double dot' => ['Readable setup.exe..mkv yEnc', 'setup.exe', false],
            'unquoted executable after an underscore' => ['Readable _setup.exe yEnc', 'setup.exe', false],
            'unquoted executable before an underscore' => ['Readable setup.exe_ yEnc', 'setup.exe', false],
            'counter without a dash or yEnc' => ['[1/5]"Readable.Name.mkv"', 'Other.Thing.mkv', false],
            'stream form hides an executable under an opaque disk image' => ['[1/1] - "Xk29dPq8LmZ0Yt5R.iso" yEnc', 'evil.exe:x.iso', false],
            'trimmed form hides an executable under an opaque disk image' => ['[1/1] - "Xk29dPq8LmZ0Yt5R.iso" yEnc', 'evil.iso:x.exe', false],
            'word prefix needs eight characters' => ['"Movie.mkv" yEnc', 'Movie Extra.mkv', false],
            'symbol-only stems do not match' => ['"★★★.mkv" yEnc', '☆☆.mkv', false],
            'uppercase opaque parity subject with a payload body' => ['[1/1] - "Xk29dPq8LmZ0Yt5R.PAR2" yEnc', 'payload.mkv', false],
            'unquoted executable ignores case' => ['Readable SETUP.EXE yEnc', 'setup.exe', true],
            'digit before a single quote' => ["[1/1] - 1'movie.mkv' other.mkv yEnc", 'other.mkv', true],
            'underscore after a single quote' => ["[1/1] - 'movie.mkv'_ other.mkv yEnc", 'other.mkv', true],
            'invalid UTF-8 executable under an unquoted subject' => ['Readable Release yEnc (1/2)', "setup\xff.exe", false],
            'invalid UTF-8 body under an opaque subject' => ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', "payload\xff.mkv", false],
            'Thai vowel marks differ' => ['"ปูเป็นสัตว์.mkv" yEnc', 'ปิเป็นสัตว์.mkv', false],
            'Devanagari vowel mark missing' => ['[1/1] - "किताब नाम पुस्तक.mkv" yEnc', 'कताब नाम पुस्तक.mkv', false],
            'decomposed accents differ from plain letters' => ["\"Re\u{301}sume\u{301} Ultimate.mkv\" yEnc", 'Resume Ultimate.mkv', false],
            'Thai name with punctuation differences' => ['"ปูเป็นสัตว์ (2026).mkv" yEnc', 'ปูเป็นสัตว์ 2026.mkv', true],
            'digit with a combining mark ends the shorter stem' => ["\"Readable Name 2\u{20E3}.mkv\" yEnc", "Readable Name 2\u{20E3} Extra.mkv", false],
            'executable quoted beside a declared payload' => ['"tag.mkv" - [1/1] - "setup.exe" - "movie.mkv" yEnc', 'setup.exe', false],
            'payload quoted beside the declared filename' => ['"Tag Name" - [1/1] - "readable.name.rar" yEnc', 'Tag Name', true],
            'desktop launcher under an opaque subject' => ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.desktop', false],
            'yEnc must be a whole word' => ['"setup.exe" yEncoded - [1/1] - "movie.mkv" yEnc', 'setup.exe', false],
            'square-bracket counter before yEnc' => ['"setup.exe" [1/1] yEnc - "Tag Name"', 'setup.exe', true],
            'square-bracket counter before yEnc rejects another body' => ['"Readable.Name.rar" [1/5] yEnc - "Tag Name"', 'Other.Words.rar', false],
            'opaque parity subject with a trailing-space payload body' => ['[1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', 'payload.mkv ', false],
            'opaque parity subject with a trailing-dot payload body' => ['[1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', 'payload.mkv.', false],
            'acronym title' => ['"BBCDocumentary.mkv" yEnc', 'totally-different.mkv', false],
            'lower-camel title' => ['"iPhoneMovie2026.mkv" yEnc', 'totally-different.mkv', false],
            'parity declared beside a quoted payload' => ['"payload.mkv" - [1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', 'payload.mkv', false],
            'opaque subject with an unlisted media extension' => ['[1/9] - "Xk29dPq8LmZ0Yt5R.wmv" yEnc', 'b7Qe1ZrT9wXy4K.wmv', true],
            'composed and decomposed accents agree' => ['"Résumé Ultimate.mkv" yEnc', "Re\u{301}sume\u{301} Ultimate.mkv", true],
            'random letters without digits stay opaque' => ['"camjQoMXQvVmpZMR" yEnc', '0SDtjNqVpAyF7Orb', true],
            'number-bearing titles do not vouch for each other' => ['"12AngryMen.mkv" yEnc', '13GoingOn30.mkv', false],
            'acronym titles with numbers do not vouch for each other' => ['"BBC2Documentary.mkv" yEnc', 'Channel4Documentary.mkv', false],
            'compound archive suffix on an opaque subject' => ['[1/9] - "Xk29dPq8LmZ0Yt5R.tar.gz" yEnc', 'b7Qe1ZrT9wXy4KpL.tar.gz', true],
            'NTFS base name hides a parity body' => ['"0123456789abcdef0123456789abcdef.mkv" yEnc', 'set.vol00+01.par2:payload.mkv', false],
            'unquoted parity subject with a payload body' => ['set.par2 yEnc', 'payload.mkv', false],
            'unquoted payload subject with a parity body' => ['[1/5] - payload.part1.rar yEnc (1/50)', 'set.vol00+01.par2', false],
            'unquoted parity subject with a parity body' => ['[2/5] - set.vol00+01.par2 yEnc (1/2)', 'set.vol00+01.par2', true],
            'unquoted payload subject with a payload body' => ['Movie 2026 [1/5] - payload.part1.rar yEnc (1/50)', 'payload.part1.rar', true],
            'declared stream form hides a parity file' => ['"set.par2:payload.mkv" yEnc - "payload.mkv"', 'payload.mkv', false],
            'apostrophes inside a single-quoted name' => ["[1/1] - 'O'Brien's Movie.mkv' yEnc", 'Unrelated.Title.mkv', false],
            'apostrophes inside a matching single-quoted name' => ["[1/1] - 'O'Brien's Movie.mkv' yEnc", "O'Brien's Movie.mkv", true],
            'two yEnc-marked names are ambiguous' => ['"setup.exe" yEnc - [1/1] - "movie.mkv" yEnc', 'setup.exe', false],
            'unquoted executable after the yEnc marker' => ['movie.mkv yEnc (1/2) setup.exe', 'setup.exe', false],
            'quote after the yEnc marker is metadata' => ['movie.mkv yEnc (1/2) "setup.exe"', 'setup.exe', false],
            'quoted and unquoted yEnc markers are ambiguous' => ['"setup.exe" yEnc - movie.mkv yEnc', 'setup.exe', false],
            'long initial-led title' => ['"XFilesDocumentary2026.mkv" yEnc', 'Totally.Different.mkv', false],
            'long abbreviated title' => ['"MrRobotDocumentary2026.mkv" yEnc', 'Totally.Different.mkv', false],
            'readable extensionless body under a parity subject' => ['[1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', 'README', false],
            'combining mark extends an unquoted executable name' => ["Release setup.exe\u{301}.mkv yEnc", 'setup.exe', false],
            'long lowercase random token with inner digits' => ['"cxjzxhjlgr3djfprozg6.rar" yEnc', 'Readable.Name.rar', true],
            'opaque subject with an extensionless body' => ['[3/8] - "o9aeI3YpDwgHGoJ2ZsCm.vol00+01.par2" yEnc', '148f76878d7732b981734a9e0c51a348', true],
        ];
    }
}
