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

    /** @return array<string, array{string, string, bool}> */
    public static function subjects(): array
    {
        return [
            'no quoted filename' => ['Release name yEnc (1/2)', 'anything.bin', true],
            'exact filename' => ['[1/8] - "Show.S01E01.mkv" yEnc', 'Show.S01E01.mkv', true],
            'filename before yEnc after a quoted tag' => ['"Tag Name" - [10/53] - "show.part10.rar" yEnc', 'show.part10.rar', true],
            'filename after the counter without yEnc' => ['"Tag Name" - [10/53] - "show.part10.rar"', 'show.part10.rar', true],
            'any quoted value equals the body name' => ['"show.part10.rar" - [10/53] - "Tag Name" yEnc', 'show.part10.rar', true],
            'apostrophe inside a double-quoted name' => ['Show\'s Title [2/8] - "Show\'s Title.par2" yEnc', 'Show\'s Title.par2', true],
            'opaque mixed-case subject' => ['[2/8] - "MdssqdkDoi1PGOHU3zIVNathiyE3zsFK.par2" yEnc', '39eba263b2397e84879c8185ca984e04.par2', true],
            'opaque recovery-volume subject' => ['[7/10] - "wtpFcbMsiTODbAh4RiaG.vol015+016.par2" yEnc', 'wtpFcbMsiTODbAh4RiaG-o1on5HdR2Y.par2', true],
            'opaque hexadecimal subject' => ['"'.str_repeat('a1', 8).'"', 'Readable.Name.mkv', true],
            'long single-case token' => ['"'.str_repeat('k', 20).'.rar" yEnc', 'other.rar', true],
            'same stem, different separators' => ['[2/9] - "SubsPlease Show - 01 1080p ABCD1234 .mkv.par2" yEnc', '[SubsPlease] Show - 01 (1080p) [ABCD1234].mkv.par2', true],
            'same stem prefix' => ['[1/9] - "Readable.Release.Name.mkv" yEnc', 'Readable.Release.Name.Extra.mkv', true],
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
            'opaque subject with an extensionless body' => ['[3/8] - "o9aeI3YpDwgHGoJ2ZsCm.vol00+01.par2" yEnc', '148f76878d7732b981734a9e0c51a348', true],
        ];
    }
}
