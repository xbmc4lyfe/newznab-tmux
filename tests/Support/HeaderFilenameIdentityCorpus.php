<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Releases\Verification\HeaderFilenameIdentity;
use Tests\Unit\HeaderFilenameIdentityTest;

/**
 * Deterministic synthetic (subject, actual) pairs for HeaderFilenameIdentity parity and
 * benchmarks. Everything is generated from fixed word lists and a seeded LCG; no production
 * subjects are used. Pairs deliberately cover readable, obfuscated, multi-quote, apostrophe,
 * Unicode, executable, whitespace and invalid UTF-8 shapes.
 */
final class HeaderFilenameIdentityCorpus
{
    private const array WORDS = [
        'Show', 'Title', 'Movie', 'Season', 'Episode', 'Release', 'Name', 'Example', 'Sample', 'Archive',
        'Night', 'Garden', 'River', 'Silver', 'Winter', 'Planet', 'Harbor', 'Echo', 'Story', 'Final',
    ];

    private const array UNICODE_WORDS = [
        'Фильм', 'Другой', 'Сезон', 'Ελληνικά', 'ΟΔΟΣ', 'Οδός', 'İSTANBUL', 'istanbul', 'Straße', 'STRASSE',
        '映画', '日本語', 'Ünïcödé', 'ÉTÉ', 'café', "cafe\u{301}", 'Ⅷ', '٣٤٥', 'Ｆｕｌｌ', 'ǅungla', 'ΣΑΣ', 'ǈ',
    ];

    private const array CHAINS = [
        '', '.mkv', '.MKV', '.avi', '.mp4', '.part01.rar', '.part10.rar', '.PART10.RAR', '.part011.rar',
        '.vol000+001.par2', '.vol031+032.par2', '.vol7-8.par2', '.par2', '.rar', '.r00', '.r01', '.R123',
        '.001', '.002', '.7z', '.7z.001', '.zip', '.nfo', '.sfv', '.nzb', '.mkv.par2', '.txt', '.srt',
        '.exe', '.EXE', '.dll', '.sh', '.command', '.hta', '.mkv.hta', '.tar', '.bin', '.part', '.r1',
    ];

    private int $state;

    /** @var list<int>|null */
    private ?array $styles = null;

    /** @var list<int>|null */
    private ?array $templates = null;

    private bool $plain = false;

    private function __construct(int $seed)
    {
        $this->state = $seed & 0xFFFFFFFF;
    }

    /**
     * The PHP verdict with preg warnings swallowed: an unquoted subject checked against an
     * executable name that is not valid UTF-8 makes the /u pattern fail to compile, which PHP
     * reports as a warning and treats as "not named" (false).
     */
    public static function php(string $subject, string $actual): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            return HeaderFilenameIdentity::matches($subject, $actual);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * The published unit cases, hand-picked edge cases, then $count generated pairs.
     *
     * @return list<array{string, string}>
     */
    public static function pairs(int $count, int $seed): array
    {
        $pairs = [];
        foreach (HeaderFilenameIdentityTest::subjects() as [$subject, $actual]) {
            $pairs[] = [$subject, $actual];
        }
        array_push($pairs, ...self::edges());
        $generator = new self($seed);
        for ($index = 0; $index < $count; $index++) {
            $pairs[] = $generator->pair();
        }

        return $pairs;
    }

    /** @return list<array{string, string}> */
    public static function edges(): array
    {
        $apostrophe = static fn (string $subject): array => [$subject, 'other.mkv'];

        return [
            ['', ''], ['"', 'x'], ['""', 'x'], ['"abc.mkv', 'x'], ["\"abc\n.mkv\"", 'other.mkv'], ["\"abc\r.mkv\"", 'other.mkv'],
            ['"" "abc.mkv" yEnc', 'x.mkv'], ['"" "abc.mkv" yEnc', ' '],
            ["\0\x0b \t\r\n\"abc.mkv\"\n\r\t \x0b\0", 'x.mkv'], ["[1/2]\x0c\"abc.mkv\"", 'x.mkv'], ["\"abc.mkv\"\u{a0}", 'x.mkv'],
            ["\"abc.mkv\" \u{fffe}", 'other.mkv'], ["\"abc.mkv\" \u{1f600}", 'other.mkv'],
            ["\"a\xc0\x80.mkv\"", 'x'], ["\"a\xed\xa0\x80.mkv\"", 'x'], ["\"a\xf4\x90\x80\x80.mkv\"", 'x'], ["\"abc.mkv\" \xe2\x82", 'x'],
            ['"Readable.Name.mkv"', "\xff\xfe.mkv"], ["\"\xc3\xa9.mkv\"", "\xc3\xa9.mkv"], ['"abcdefgh.mkv"', "ABCDEFGH\xff.mkv"],
            ['"abcdefgh.mkv"', "abcd\xe2\x82efgh.mkv"], ['"abcdefgh.mkv"', "abcd\xf0\x9fefgh.mkv"], ['"abcdefgh.mkv"', "\xc0\xafabcdefgh.mkv"],
            $apostrophe("[1/1] - 'movie.mkv' yEnc"), $apostrophe("a'movie.mkv'"), $apostrophe("1'movie.mkv'"), $apostrophe("_'movie.mkv'"),
            $apostrophe("\u{e9}'movie.mkv'"), $apostrophe("\u{661}'movie.mkv'"), $apostrophe("'movie.mkv'a"), $apostrophe("'movie.mkv'9"),
            $apostrophe("'movie.mkv'_"), $apostrophe("'movie.mkv'\u{4e2d}"), $apostrophe("'movie.mkv'\u{2160}"), $apostrophe(" 'movie.mkv' "),
            $apostrophe("-'movie.mkv'-"), $apostrophe("'movie.mkv'."), $apostrophe("\u{2014}'movie.mkv'\u{2014}"),
            $apostrophe("\u{1f600}'movie.mkv'\u{1f600}"), $apostrophe("'a 'movie.mkv' b'"), $apostrophe("it's a 'movie.mkv' it"),
            ['"Tag.A.mkv" "Yenc.Name.Here.mkv"'."\x0b".'yenc "Other.mkv"', 'Yenc.Name.Here.Extra.mkv'],
            ['"Tag.A.mkv" "Yenc.Name.Here.mkv" (1/x) yEnc "Other.mkv"', 'Yenc.Name.Here.Extra.mkv'],
            ['"Tag.A.mkv" "Yenc.Name.Here.mkv" (10/20)'."\t".'yEnC "Other.mkv"', 'Yenc.Name.Here.Extra.mkv'],
            ['"Tag.A.mkv" "Yenc.Name.Here.mkv"'."\u{a0}".'yEnc "Other.mkv"', 'Yenc.Name.Here.Extra.mkv'],
            ['"Tag.Zero.mkv" [1/9]--"After.Counter.Name.mkv"', 'After.Counter.Name.Extra.mkv'],
            ['"Tag.Zero.mkv" [12/345]'."\t\x0b-\r\n".'"After.Counter.Name.mkv"', 'After.Counter.Name.mkv'],
            ['"Readable.Name.mkv"', 'Readable.Name.exe.bak'], ['"Readable.Name.mkv"', "Readable.Name.exe\n"], ['"Readable.Name.mkv"', 'Readable.Name.exe .'],
            ['"Readable.Name.mkv"', "Readable.Name.EXE\t"], ['"Readable.Name.dll"', 'Readable.Name.exe'], ['"Readable.Name.mkv"', 'Readable.Name.pkg'],
            ['"Name.Of.Release.1234"', 'Name.Of.Release'], ['"Name.Of.Release.r1234"', 'Name.Of.Release'], ['"Name.Of.Release.mkv.bak"', 'Name.Of.Release'],
            ['"0123456789abcdef"', 'Readable.Other.Name.mkv'], ['"0123456789abcde"', 'Readable.Other.Name.mkv'], ['".mkv"', 'other.mkv'], ['"abcdefgh.mkv"', '.mkv'],
            ['"abcdefgh.mkv"', 'abcdefghij.mkv'], ['"abcdefghij.mkv"', 'abcdefgh.mkv'], ['"abcdefg.mkv"', 'abcdefghij.mkv'], ['"Ab-Cd_Ef.Gh.mkv"', 'abcdefgh.MKV'],
            ['"ΟΔΟΣ ΟΔΟΣ.mkv"', 'οδοσ οδος.mkv'], ['"ΣΑΣ Σ.mkv"', 'σας ς.mkv'], ['"İSTANBUL Release.mkv"', 'i̇stanbul release.mkv'], ['"Straße Release.mkv"', 'STRASSE Release.mkv'],
            ['"Ünïcödé Release.mkv"', 'ÜNÏCÖDÉ RELEASE.mkv'], ['"Фильм Релиз Один.mkv"', 'фильм релиз один.mkv'], ['"映画日本語映画日本語.mkv"', '映画日本語映画日本語映画.mkv'],
            ['"Ⅷ Release Name.mkv"', 'ⅷ release name.mkv'], ['"٣٤٥ Release Name.mkv"', '345 Release Name.mkv'], ['"Ｆｕｌｌ Width Name.mkv"', 'ｆｕｌｌ width name.mkv'],
            ['"ǅungla Release.mkv"', 'ǆungla release.mkv'], ["\"cafe\u{301} Release Name.mkv\"", 'café Release Name.mkv'],
            ['"'.str_repeat('ab', 4).'.mkv"', str_repeat('ab', 4)."\u{e9}.mkv"], ['"'.str_repeat("\u{e9}", 8).'.mkv"', str_repeat("\u{e9}", 8).'x.mkv'],
            ['"'.str_repeat("\u{e9}", 7).'.mkv"', str_repeat("\u{e9}", 7).'xyz.mkv'],
            ['Readable setup.exe yEnc (1/2)', 'SETUP.EXE'], ['Readable setup.exe yEnc (1/2)', 'setup.exe.'], ['Readable setup yEnc', 'setup.exe'],
            ['yEnc (1/2)', 'a.url'], ['Release yEnc', 'run.PS1XML'], ['Release yEnc', 'run.appxbundle'], ['Release yEnc', 'run.ws '], ['Release yEnc', ''],
            ['"Tag" "Readable.Release.Name.mkv"', 'Readable.Release.Name.mkv.cab'], ['"Readable.Release.Name.wmv"', 'Readable.Release.Name.WMV'],
            ['"Readable.Release.Name.abcdef"', 'Readable.Release.Name.abcdeg'], ['"Readable.Release.Name.abcdef"', 'Readable.Release.Name.ABCDEF'],
            ['"Readable.Release.Name.mkv"', 'Readable.Release.Name'], ['"Readable.Release.Name"', 'Readable.Release.Name.wmv'],
            ['"Readable.Release.Name2.mkv"', 'Readable.Release.Name23.mkv'], ['"Readable.Release.Name.mkv"', 'Readable.Release.Name9.mkv'],
            ['"Readable.Release.Name1.mkv"', 'Readable.Release.Name1a.mkv'], ['"Readable.Release.Name1.mkv"', 'Readable.Release.Name1.5.mkv'],
            ['"Readable.Release.Name١.mkv"', 'Readable.Release.Name١٢.mkv'], ['"Readable.Release.Name1.mkv"', 'Readable.Release.Name١.mkv'],
            ['"Abcdefghij.mkv"', 'x'], ['"AbCdEfGhIj.mkv"', 'x'], ['"AbCdEfGhI.mkv"', 'x'], ['"Abcd1Efgh2.mkv"', 'x'], ['"TheMatrix1999.mkv"', 'x'],
            ['"abcdefghijklmnopqrst.mkv"', 'x'], ['"abcdefghijklmnopqrs.mkv"', 'x'], ['"aB1cD2eF3gH4iJ5.mkv"', 'x'], ['"aB1cD2eF3gH4.mkv"', 'x'],
            ['"Readable.Name.mkv"', "Readable.Name.m\u{a0}kv"], ['"Readable.Name.mkv"', "Readable.Name.mkv\u{a0}"], ['"Readable.Name.mkv"', "Readable.Name.\u{2003}"],
            ['"Readable.Name.mkv"', "Readable.Name.mkv\u{85}"], ['"Readable.Name.torrent"', "Readable.Name.torrent\u{3000}x"], ['"Readable.Name.m\u{e9}"', "Readable.Name.M\u{c9}"],
            ["\"Readable.Name.\u{4e2d}\u{6587}\"", "Readable.Name.\u{4e2d}\u{6587}"], ['"Readable.Name.mkv"', "Readable.Name.\xff"], ['"Readable.Name.mkv"', "Readable\xffName.mkv"],
            ['"Readable Release Name"', 'Readable Release Name.'], ['"Readable Release Name"', 'Readable Release Name..x'], ['"Readable Release Name."', 'Readable Release Name'],
            ['"Xk29dPq8Lm.rar"', 'x.rar'], ['"Xk29dPq8L.rar"', 'x.rar'], ['"Abcdefghijklmnopqrst.rar"', 'x.rar'], ['"abcdefghijklmnopqrst1.rar"', 'x.rar'],
            ['"Readable Name Part \u{663}"', "Readable Name Part \u{663} Extra"], ['"Readable Name Part"', "Readable Name Part \u{663}"], ['"Readable Name Part \u{663}"', "Readable Name Part \u{663}"],
            ['"Xk29dPq8LmZ0Yt5R.mkv"', 'Xk29: setup.exe'], ['Release yEnc', 'Title: setup.exe'], ['"Xk29dPq8LmZ0Yt5R.exe"', 'setup.exe:x.scr'], ['"Setup.exe"', 'setup.exe:x.exe'],
            ['"Setup.exe:s.scr"', 'setup.scr'], ['"Setup.scr"', 'x.scr:y.exe'], ['"Xk29dPq8LmZ0Yt5R.exe"', 'setup.exe .'], ['"Xk29dPq8LmZ0Yt5R.exe."', 'setup.exe'],
            ['"Movie: The Title.mkv"', 'Movie- The Title.mkv'], ['"Show 1 10.mkv"', 'Show 11 0.mkv'], ['"Readable.Name.1.10.mkv"', 'Readable.Name.11.0.mkv'], ['"Show 1 10.mkv"', 'Show 1 10.mkv'],
            ['"Show 1 10 Extra Words.mkv"', 'Show 1 10 Extra Words More.mkv'], ['"Show 1 10 Extra Words.mkv"', 'Show 1 10 Extra Words 2.mkv'], ["\"Show \u{663}\u{664} Extra Words.mkv\"", "Show \u{663} \u{664} Extra Words.mkv"],
            ['"Show 1 Extra Words.mkv"', "Show \u{661} Extra Words.mkv"], ['"Show 1 Extra Words.mkv"', "Show 1 \xff Extra Words.mkv"], ['"Show Extra Words.mkv"', "Show Extra Words\xff1.mkv"],
            ['[1/2]  - "Readable.Name.mkv"', 'x.mkv'], ["[1/2] -\t \n\"Readable.Name.mkv\"", 'x.mkv'], ['[1/2] - - "Readable.Name.mkv"', 'x.mkv'], ['[1/2]- "Readable.Name.mkv"', 'x.mkv'],
            ['[1/'.str_repeat(' ', 667).'x'.str_repeat('"a"', 111), 'Readable.Name.mkv'], ['[1/1]'.str_repeat(' ', 667).'x'.str_repeat('"a"', 111), 'Readable.Name.mkv'],
            ['Movie.2026.1080p.exe.mkv yEnc (1/50)', 'Movie.2026.1080p.exe'], ['Movie.2026.1080p.exe-sample.mkv yEnc', 'Movie.2026.1080p.exe'], ['Readable setup.exe - yEnc (1/2)', 'setup.exe'],
            ['Readable setup.exe..mkv yEnc', 'setup.exe'], ['Readable setup.exe.-.x yEnc', 'setup.exe'], ['Readable setup.exe.- yEnc', 'setup.exe'], ['Readable setup.exe-_x yEnc', 'setup.exe'],
            ['Readable setup.exe.', 'setup.exe'], ["Readable setup.exe.\u{e9} yEnc", 'setup.exe'], ["Readable setup.exe-\u{663} yEnc", 'setup.exe'], ['Readable _setup.exe yEnc', 'setup.exe'], ['Readable setup.exe_ yEnc', 'setup.exe'],
            ['"Final.Fantasy.VI.iso"', 'Final.Fantasy.VII.iso'], ['"Season.Finale.Part.A.mkv"', 'Season.Finale.Part.AB.mkv'], ['"Readable.Release.Name.mkv"', 'Readable Release Name Extended.mkv'],
            ['"Readable.Release.Name.mkv"', 'Readable Release Nam.mkv'], ['"Readable.Release.Name.mkv"', 'ReadableReleaseName Extended.mkv'], ['"Readable Release Name.mkv"', 'ReadableRelease NameExtended.mkv'],
            ['"Readable Release Name Extended.mkv"', 'Readable Release Name.mkv'], ['"Readable Release Name.mkv"', 'Readable Release Name Part 2.mkv'], ['"Readable Release Name 3.mkv"', 'Readable Release Name 3 Extra.mkv'],
            ['"Readable Release Name.mkv"', "Readable Release Name \u{c9}tendue.mkv"], ['"Readable Release Name \u{e9}.mkv"', "Readable Release Name \u{e9}tendue.mkv"], ["\"\u{424}\u{438}\u{43b}\u{44c}\u{43c} \u{420}\u{435}\u{43b}\u{438}\u{437} \u{41e}\u{434}\u{438}\u{43d}.mkv\"", "\u{424}\u{438}\u{43b}\u{44c}\u{43c} \u{420}\u{435}\u{43b}\u{438}\u{437} \u{41e}\u{434}\u{438}\u{43d} \u{414}\u{43e}\u{43f}.mkv"],
            ['"Readable Release Name.mkv"', "Readable Release Name \xff Extra.mkv"], ['"Readable Release Name.mkv"', "Readable Release Name\xffExtra.mkv"], ['"Readable.Release.Name.ts.7z.r00.mkv"', 'Readable.Release.Name.mkv'],
            ['"a'.str_repeat('.ts', 332).'x" yEnc', 'Readable.Name.mkv'], ['"a'.str_repeat('.7z', 332).'x" yEnc', 'Readable.Name.mkv'], ['"a'.str_repeat('.r00', 249).'" yEnc', 'a.r00'], ['"abcdefgh'.str_repeat('.r00', 249).'" yEnc', 'abcdefgh'],
            ['"Release.PART01.RAR"', 'release.part01.rar'], ['"Release.part1.rar"', 'release.part01.rar'], ['"Release.R12"', 'release.r012'], ['"Release.123"', 'release.1234'], ['"Release.vol1+2.par2"', 'release.VOL1+2.PAR2'],
            ['[1/1] - "Xk29dPq8LmZ0Yt5R.iso"', 'evil.exe:x.iso'], ['[1/1] - "Xk29dPq8LmZ0Yt5R.iso"', 'evil.iso:x.exe'], ['"Movie.mkv"', 'Movie Extra.mkv'],
            ['"★★★.mkv"', '☆☆.mkv'], ['"★★★.mkv"', '★★★.mkv'], ['[1/1] - "Xk29dPq8LmZ0Yt5R.PAR2"', 'payload.mkv'], ['Readable SETUP.EXE yEnc', 'setup.exe'],
            ["[1/1] - 1'movie.mkv' yEnc", 'other.mkv'], ["[1/1] - 'movie.mkv'_ yEnc", 'other.mkv'], ['Readable Release yEnc', "setup\xff.exe"],
            ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', "payload\xff.mkv"], ['no quotes', "\xc3"], ["\"x\xff\"", "x\xff"], ["\"x\xff\"", 'x'], ['"x"', "\xff"], ['', "\xff"],
            ['"ปูเป็นสัตว์.mkv"', 'ปิเป็นสัตว์.mkv'], ['"ปูเป็นสัตว์ (2026).mkv"', 'ปูเป็นสัตว์ 2026.mkv'], ['"किताब नाम पुस्तक.mkv"', 'कताब नाम पुस्तक.mkv'], ['"किताब नाम पुस्तक.mkv"', 'किताब_नाम_पुस्तक.mkv'],
            ["\"Re\u{301}sume\u{301} Ultimate.mkv\"", 'Resume Ultimate.mkv'], ["\"Re\u{301}sume\u{301} Ultimate.mkv\"", "re\u{301}sume\u{301}.ultimate.mkv"], ["\"R\u{e9}sum\u{e9} Ultimate.mkv\"", "Re\u{301}sume\u{301} Ultimate.mkv"],
            ["\"Readable Name 2\u{20e3}.mkv\"", "Readable Name 2\u{20e3} Extra.mkv"], ["\"Readable Name 2\u{301}\u{302}.mkv\"", "Readable Name 2\u{301}\u{302} Extra.mkv"], ["\"Readable Name Part\u{301}.mkv\"", "Readable Name Part\u{301} Extra.mkv"],
            ["\"Readable Name \u{663}\u{301}.mkv\"", "Readable Name \u{663}\u{301} Extra.mkv"], ['"Readable Name 2.mkv"', "Readable Name 2\u{20e3}.mkv"], ["\"Readable Name Long\u{20e3}.mkv\"", "Readable Name Long\u{20e3} Extra.mkv"],
            ['"tag.mkv" - [1/1] - "setup.exe" - "movie.mkv" yEnc', 'setup.exe'], ['"Tag Name" - [1/1] - "readable.name.rar" yEnc', 'Tag Name'], ['"setup.exe" - [1/1] - "setup.exe" yEnc', 'setup.exe'],
            ['"setup.exe" - [1/1] - "setup.scr" yEnc', 'setup.exe'], ['"setup.exe" "movie.mkv" yEnc', 'setup.exe'], ['"movie.mkv" "setup.exe" yEnc', 'setup.exe'], ['"movie.mkv" "Xk29dPq8LmZ0Yt5R.exe" yEnc', 'Xk29dPq8LmZ0Yt5R.exe'],
            ['"setup.exe" yEncoded - [1/1] - "movie.mkv" yEnc', 'setup.exe'], ['"setup.exe" [1/1] yEnc - "Tag Name"', 'setup.exe'], ['"Readable.Name.rar" [1/5] yEnc - "Tag Name"', 'Other.Words.rar'],
            ['"Readable.Name.rar" (1/5) yEnc - "Tag Name"', 'Other.Words.rar'], ['"Readable.Name.rar" [1/5]yEnc - "Tag Name"', 'Other.Words.rar'], ['"Readable.Name.rar" [1/5] (2/5) yEnc - "Tag Name"', 'Other.Words.rar'],
            ['"Readable.Name.rar" [1/5) yEnc - "Tag Name"', 'Other.Words.rar'], ['"Readable.Name.rar" [1/] yEnc - "Tag Name"', 'Other.Words.rar'], ['"Readable.Name.rar" []yEnc - "Tag Name"', 'Other.Words.rar'],
            ["\"Readable.Name.rar\"\u{a0}yEnc - \"Tag Name\"", 'Other.Words.rar'], ["\"Readable.Name.rar\" (1/5)\u{3000}yEnc \"Tag Name\"", 'Other.Words.rar'], ["\"Readable.Name.rar\" (\u{661}/\u{665}) yEnc \"Tag Name\"", 'Other.Words.rar'],
            ["\"Readable.Name.rar\" [\u{ff11}/5] yEnc \"Tag Name\"", 'Other.Words.rar'], ['"Readable.Name.rar" yEnc_ "Tag Name"', 'Other.Words.rar'], ['"Readable.Name.rar" yEnc. "Tag Name"', 'Other.Words.rar'],
            ['"Readable.Name.rar" yEnc- "Tag Name"', 'Other.Words.rar'], ["\"Readable.Name.rar\" yEnc\u{e9} \"Tag Name\"", 'Other.Words.rar'], ["\"Readable.Name.rar\" yEnc\u{661} \"Tag Name\"", 'Other.Words.rar'],
            ["\"Readable.Name.rar\" yEnc\u{300} \"Tag Name\"", 'Other.Words.rar'], ['"Readable.Name.rar" YENC', 'Other.Words.rar'], ['"Readable.Name.rar" yEnc', 'Other.Words.rar'], ['"Readable.Name.rar" yEncx', 'Other.Words.rar'],
            ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.desktop'], ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.AppImage'], ['[1/9] - "Xk29dPq8LmZ0Yt5R.desktop" yEnc', 'run.desktop'],
            ['[1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', 'payload.mkv '], ['[1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', 'payload.mkv.'], ['[1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', "payload.mkv\t. "], ['[1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', 'payload.par2 .'],
            ['"Xk29dPq8Lm.rar" yEnc', 'b7Qe1ZrT9wXy.rar'], ['"Xk29dPq8Lm.rar" yEnc', 'other.rar'], ['"Xk29dPq8Lm.rar" yEnc', 'Xk29dPq8Lm-Qz81.rar'], ['"Xk29dPq8Lm.rar" yEnc', 'XK29DPQ8LM.rar'],
            ['"Xk29dPq8Lm.rar" yEnc', 'xk29dpq8lm'], ['"Xk29dPq8Lm.rar" yEnc', 'Xk29dPq8L.rar'], ['"Xk29dPq8Lm.rar" yEnc', 'Xk29dPq8Lm.exe'], ['"Xk29dPq8Lm.rar" yEnc', 'Xk29dPq8Lm:s.rar'], ['"Xk29dPq8Lm.rar" yEnc', 'Xk29dPq8Lm.rar.'],
            ['"Xk29dPq8Lm.wmv" yEnc', 'b7Qe1ZrT9wXy4K.wmv'], ['"Xk29dPq8Lm.wmv" yEnc', 'other.wmv'], ['"Xk29dPq8Lm.wmv.part01.rar" yEnc', 'other.rar'], ['"Xk29dPq8Lm.123456789" yEnc', 'other.mkv'],
            ['[02/36] "PA0iwGDzUsha5QpCCXoMHeHu9xDiTUtz" yEnc', 'Readable.Release.Name.par2'], ['"abcdefghijklmnopqrst" yEnc', 'Readable.Release.Name.mkv'], ['"abcdefghijklmnopqrs" yEnc', 'Readable.Release.Name.mkv'],
            ['"Xk29dPq8LmZ0Yt5R1234" yEnc', 'Readable.Release.Name.mkv'], ['"0123456789abcdef.mkv" yEnc', 'Readable.Release.Name.mkv'], ['"0123456789abcde.mkv" yEnc', 'Readable.Release.Name.mkv'],
            ['[1/1] - "Xk29dPq8LmZ0Yt5R.rar" yEnc', 'set.vol00+01.par2'], ['[1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', 'set.rar'], ['[1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', 'set.vol00+01.par2'], ['[1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', 'extensionless'],
            ['[1/1] - "Readable.Name.par2" yEnc', 'Readable.Name.mkv'], ['"Readable.Name.mkv" - "Readable.Name.par2" yEnc', 'Readable.Name.mkv'], ['"payload.mkv" - [1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', 'payload.mkv'],
            ['[1/1] - "Readable.Name.rar" yEnc', 'Readable.Name.PAR2'], ['[1/1] - "Readable.Name.par2" yEnc', 'Readable.Name.PAR2'], ['[1/1] - "Readable.Name.par2" yEnc', 'Readable.Name.par2 .'],
            ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.deb'], ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.RPM'], ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.snap'], ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.run'],
            ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.msu'], ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.py'], ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.pyw'], ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.pl'],
            ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.rb'], ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.php'], ['Release yEnc', 'payload.php'],
            ['"BBCDocumentary.mkv" yEnc', 'totally-different.mkv'], ['"iPhoneMovie2026.mkv" yEnc', 'totally-different.mkv'], ['"12AngryMen.mkv" yEnc', 'Completely.Different.mkv'], ['"BBC2Documentary.mkv" yEnc', 'Completely.Different.mkv'],
            ['"ABCDEFGH.mkv" yEnc', 'x.mkv'], ['"ABCDocument.mkv" yEnc', 'x.mkv'], ['"ABDocument.mkv" yEnc', 'x.mkv'], ['"abcDocumentFinal2026.mkv" yEnc', 'x.mkv'], ['"TheMatrix.mkv" yEnc', 'x.mkv'], ['"Document2026.mkv" yEnc', 'x.mkv'],
            ['"camjQoMXQvVmpZMR" yEnc', '0SDtjNqVpAyF7Orb'], ['"Final.Fantasy.VI.iso" yEnc', 'Final.Fantasy.iso'], ['"Star.Wars.mkv" yEnc', 'Star.Wars.VI.mkv'], ['"Season.Finale.Part.A.mkv" yEnc', 'Season.Finale.Part.mkv'],
            ['"Readable.Release.Name.mkv" yEnc', 'READABLE_RELEASE_NAME.MKV'], ['"Readable.Release.Name.mkv" yEnc', 'Readable Release Name Extra.mkv'], ['"Readable-Release-Name.mkv" yEnc', 'readablereleasename.mkv'],
            ['"Résumé Ultimate.mkv" yEnc', "Re\u{301}sume\u{301} Ultimate.mkv"], ['"Résumé Ultimate.mkv" yEnc', 'Resume Ultimate.mkv'], ['"Фильм Релиз.mkv" yEnc', 'фильм релиз.mkv'],
            ['"Readable.Release.Name.mkv" yEnc', 'Readable.Release.Name.1.mkv'], ['"Readable.Release.Name.mkv" yEnc', 'Readable.Release.Name.mkv.part01.rar'],
            ['"12AngryMen.mkv" yEnc', '13GoingOn30.mkv'], ['"BBC2Documentary.mkv" yEnc', 'Channel4Documentary.mkv'], ['"Xk29dPq8LmZ0Yt5R.tar.gz" yEnc', 'b7Qe1ZrT9wXy4KpL.tar.gz'], ['"Xk29dPq8LmZ0Yt5R.tgz" yEnc', 'x.tgz'],
            ['"Xk29dPq8LmZ0Yt5R.mkv.xz" yEnc', 'x.bz2'], ['"Xk29dPq8LmZ0Yt5R.zst" yEnc', 'x.zst'], ['"Readable.Name.tar.gz" yEnc', 'readable.name.tar.gz'], ['"Readable.Name.tar.gz" yEnc', 'readable.name.tar.xz'],
            ['"0123456789abcdef0123456789abcdef.mkv" yEnc', 'set.vol00+01.par2:payload.mkv'], ['"0123456789abcdef0123456789abcdef.mkv" yEnc', 'payload.mkv:set.par2'], ['"Readable.Name.par2" yEnc', 'set.vol00+01.par2 :x'],
            ['set.par2 yEnc', 'payload.mkv'], ['[1/5] - payload.part1.rar yEnc (1/50)', 'set.vol00+01.par2'], ['[2/5] - set.vol00+01.par2 yEnc (1/2)', 'set.vol00+01.par2'], ['Movie 2026 [1/5] - payload.part1.rar yEnc (1/50)', 'payload.part1.rar'],
            ['set.par2 yEnc', 'set.par2'], ['set.par2 yEnc', 'extensionless'], ['set.par2', 'payload.mkv'], ['a.b+c yEnc', 'x.par2'], ['a.par2+ yEnc', 'x.mkv'], ['a.abcdefgh yEnc', 'x.par2'], ['a.abcdefghi yEnc', 'x.par2'],
            ['a.abc+defghijkl yEnc', 'x.par2'], ['a.par2+defghijkl yEnc', 'x.mkv'], ['a.par2_ yEnc', 'x.mkv'], ['a.par2\u{e9} yEnc', 'x.mkv'], ['a.mkv.par2 yEnc', 'x.mkv'], ['a.par2.mkv yEnc', 'x.mkv'], ['Release YENC a.par2', 'x.mkv'],
            ['Release a.par2 notyEnc a.mkv', 'x.mkv'], ['a.par2 yEnc_ b.mkv', 'x.mkv'], ['a.par2 xyEnc b.mkv yEnc', 'x.mkv'], ['a.PAR2 yEnc', 'x.MKV'], ['a.par2 yEnc', 'x.par2:y.mkv'], ['a.mkv yEnc', 'x.par2:y.mkv'],
            ['a.mkv yEnc', 'x.mkv:y.par2'], ['a.mkv yEnc', 'x.mkv.'], ['a.exe yEnc', 'a.exe'], ['a.exe yEnc', 'b.exe'], ['a.exe b.mkv yEnc', 'a.exe'], ['a.exe b.mkv yEnc', 'b.mkv'],
            ['"Abcdef.mkv" yEnc', 'x.mkv'], ['"ABCDE.mkv" yEnc', 'x.mkv'], ['"BCDfg.mkv" yEnc', 'x.mkv'], ['"Xk29dPq8Lm.mkv" yEnc', 'x.mkv'], ['"AbCdEfGhIjKl.mkv" yEnc', 'x.mkv'], ['"thelordoftheringsreturn.mkv" yEnc', 'x.mkv'],
            ['"TheLordOfTheRings.mkv" yEnc', 'x.mkv'], ['"theLordOfTheRings.mkv" yEnc', 'x.mkv'], ['"THEMatrixReloaded.mkv" yEnc', 'x.mkv'], ['"THEMATRIXReloaded.mkv" yEnc', 'x.mkv'], ['"ABCDEFGHIJKLMNO.mkv" yEnc', 'x.mkv'],
            ['"Qzxwrtpsdfgh.mkv" yEnc', 'x.mkv'], ['"Mr2Bean.mkv" yEnc', 'x.mkv'], ['"xTheMatrix.mkv" yEnc', 'x.mkv'], ['"1234567890.mkv" yEnc', 'x.mkv'], ['"1234567890123456.mkv" yEnc', 'x.mkv'], ['"Spy2Spy.mkv" yEnc', 'x.mkv'],
            ['"set.par2:payload.mkv" yEnc - "payload.mkv"', 'payload.mkv'], ["[1/1] - 'O'Brien's Movie.mkv' yEnc", 'Unrelated.Title.mkv'], ["[1/1] - 'O'Brien's Movie.mkv' yEnc", "O'Brien's Movie.mkv"],
            ["[1/1] - 'O'Brien's Movie.mkv' yEnc", "O'Brien's Movie.mkv "], ["'a'b' yEnc", "a'b"], ["'a'b' yEnc", 'a'], ["x 'a'b'c' yEnc", "a'b'c"], [", 'x'], ['", 'x'], ["'a''", 'x'], ["'a'' yEnc", 'a'],
            ["'a
b' c'", 'x'], ["'a
b' c'", 'x'], ["'a b' 'c d'", 'c d'], ["'a b' 'c d'", 'a b'], ["it's 'a b' c'd' yEnc", 'a b'], ["it's 'a b' c'd' yEnc", "a b' c'd"],
            [str_repeat(" 'a", 333), 'x.mkv'], [str_repeat("'a ", 333), 'x.mkv'], [str_repeat("'", 1000), 'x.mkv'], ["'".str_repeat('a', 1000), 'x.mkv'], ["'a".str_repeat("'a", 600)."'", 'x.mkv'],
            ['"a.par2:b.mkv" yEnc', 'b.mkv'], ['"a.mkv:b.par2" yEnc', 'b.par2'], ['"a.mkv:b.par2" yEnc', 'b.mkv'], ['"a.par2:b.mkv" yEnc', 'b.par2'], ['"a.par2 :b.mkv" yEnc', 'b.mkv'],
            ['"setup.exe" yEnc - [1/1] - "movie.mkv" yEnc', 'setup.exe'], ['"a.mkv" yEnc "b.mkv" yEnc', 'a.mkv'], ['"a.mkv" yEnc "b.mkv"', 'a.mkv'], ['"a.mkv" "b.mkv" yEnc', 'a.mkv'], ['"a.mkv" (1/2) yEnc "b.mkv" [2/2] yEnc', 'b.mkv'],
            ['movie.mkv yEnc (1/2) setup.exe', 'setup.exe'], ['setup.exe yEnc (1/2) movie.mkv', 'setup.exe'], ['setup.exe movie.mkv', 'setup.exe'], ['setup.exe', 'setup.exe'], ['yEnc setup.exe', 'setup.exe'], ['setupyEnc.exe', 'setup.exe'],
            ['setup.exe_yEnc', 'setup.exe'], ['a setup.exe YENC', 'setup.exe'], ["a setup.exe\nyEnc\nb", 'setup.exe'], ['a setup.exe', 'SETUP.EXE'], ['yEnc', 'setup.exe'], ['setup.exe-', 'setup.exe'], ['setup.exe-yEnc', 'setup.exe'],
            ['Release name anything.bin yEnc (1/2)', 'anything.bin'], ['Release name yEnc (1/2)', 'anything.bin'], ['setup.exe yEnc - movie.mkv yEnc', 'setup.exe'], ['set.par2 [site.com] yEnc', 'payload.mkv'],
            ['Release name Anything.BIN yEnc', 'anything.bin '], ['Release name anything.bin. yEnc', 'anything.bin'], ['Release name anything.bin.mkv yEnc', 'anything.bin'], ['Release name anything.bin-x yEnc', 'anything.bin'],
            ['Release name anything.bin- yEnc', 'anything.bin'], ['Release name xanything.bin yEnc', 'anything.bin'], ['Release name anything.bin', 'anything.bin'], ['anything.bin', 'anything.bin'], ['yEnc anything.bin', 'anything.bin'],
            ['Release', '.'], ['Release .', '.'], ['Release yEnc', ''], ['', ''], ['Release name yEnc YENC', 'x'], ['Release name yEnc_ yEnc', 'Release'], ['Release yEnc1 yEnc', 'Release'], ["Release \u{e9}.bin yEnc", "\u{e9}.bin"],
            ["Release \u{212a}.exe yEnc", 'k.exe'], ["Release \u{17f}.mkv yEnc", 's.mkv'], ['Release anything.bin yEnc (1/2) anything.bin', 'anything.bin'],
            ['"LWgrwkDekU9VMNAO.rar" yEnc', 'other.rar'], ['"Xk29dPq8Lm.rar" yEnc', 'other.rar'], ['"XFilesMovie.mkv" yEnc', 'DDaySpecial.mkv'], ['"MrRobot2026.mkv" yEnc', 'DrStrange2025.mkv'], ['"aBcDeFgHiJ.mkv" yEnc', 'x.mkv'],
            ['"a1b2c3d4e5.mkv" yEnc', 'x.mkv'], ['"abcdefghij1.mkv" yEnc', 'x.mkv'], ['"aB3dEf6hI9.mkv" yEnc', 'x.mkv'], ['"Abcdefghi1.mkv" yEnc', 'x.mkv'], ['"Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'LWgrwkDekU9VMNAO.mkv'],
            ['"Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'XFilesMovie.mkv'], ['"Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'Xk29dPq8LmZ0Yt5R-extra.mkv'], ['"Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'xk29dpq8lmz0yt5r.mkv'],
            ['movie.mkv yEnc (1/2) "setup.exe"', 'setup.exe'], ['"setup.exe" yEnc - movie.mkv yEnc', 'setup.exe'], ['"setup.exe" - "movie.mkv" yEnc', 'setup.exe'], ['"setup.exe" - "movie.mkv" yEnc "x.mkv"', 'movie.mkv'],
            ['"a.mkv" yEnc (1/2) "b.mkv"', 'b.mkv'], ['"a.mkv" "b.mkv', 'a.mkv'], ['"a.mk" yEnc"v"', 'a.mkv'], ['"a.mkv"yEnc', 'a.mkv'], ["yEnc\u{301} \"a.mkv\"", 'a.mkv'], ["\u{301}yEnc \"a.mkv\"", 'a.mkv'], ["x\u{301}yEnc 'a.mkv'", 'a.mkv'],
            ['"XFilesDocumentary2026.mkv" yEnc', 'Totally.Different.mkv'], ['"MrRobotDocumentary2026.mkv" yEnc', 'Totally.Different.mkv'], ['[1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', 'README'], ['[1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', 'readme'],
            ['[1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', 'READ ME'], ['[1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', 'Xk29dPq8LmZ0Yt5R'], ['[1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', '12345'], ['[1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', 'LICENSE:x'],
            ['[1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', "\u{4e2d}\u{6587}"], ['[1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', 'README.'], ['[1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', 'a.b c'], ['[1/1] - "Xk29dPq8LmZ0Yt5R.rar" yEnc', 'README'],
            ['[1/1] - "Xk29dPq8LmZ0Yt5R.rar" yEnc', 'Xk29dPq8LmZ0Yt5R'], ['[1/1] - "Readable.Name.rar" yEnc', 'README'], ['[1/1] - "Readable.Name.par2" yEnc', 'README'], ['[1/1] - "Readable.Name.par2" yEnc', 'set.vol00+01.par2'],
            ["Release setup.exe\u{301}.mkv yEnc", 'setup.exe'], ["Release setup.exe\u{301} yEnc", 'setup.exe'], ["Release \u{301}setup.exe yEnc", 'setup.exe'], ['Release setup.exe\\u0301 yEnc', 'setup.exe'], ["Release setup.exe\u{20e3} yEnc", 'setup.exe'],
            ['"cxjzxhjlgr3djfprozg6.rar" yEnc', 'Readable.Name.rar'], ['"cxjzxhjlgr3djfprozg6.rar" yEnc', 'other.rar'], ['"abcdefghijklmnopqrst.rar" yEnc', 'other.rar'], ['"abcdefghijklmnopqrs1.rar" yEnc', 'other.rar'],
            ['"abcdefghijklmnopqrs1t.rar" yEnc', 'other.rar'], ['"abcdefghijklmnopqrst1.rar" yEnc', 'other.rar'], ['"1abcdefghijklmnopqrs.rar" yEnc', 'other.rar'], ['"abcdefghijklmnopqrstuvwxyz.rar" yEnc', 'other.rar'],
            ['"0123456789abcdef.rar" yEnc', 'other.rar'], ['"0123456789abcdeg.rar" yEnc', 'other.rar'], ['"Xk29dPq8LmZ0Yt5R.rar" yEnc', 'other.rar'], ['"aB1cD2eF3gH4iJ5kL6mN.rar" yEnc', 'other.rar'],
            ["[1/1] - 'Guns N' Roses.mkv' yEnc", 'Other.Thing.mkv'], ["[1/1] - 'Guns N' Roses.mkv' yEnc", "Guns N' Roses.mkv"], ["[1/1] - 'Directors' (2026) Cut.mkv' yEnc", "Directors' (2026) Cut.mkv"], ["'a' (1/2) yEnc", 'a'],
            ["'a' [1/2] yEnc", 'a'], ["'a' - [1/2] yEnc", 'a'], ["'a' - (1/2) yEnc", 'a'], ["'a' - 'b' yEnc", 'b'], ["'a' - \"b\" yEnc", 'a'], ["'a' -", 'a'], ["'a' - ", 'a'], ["'a' -x yEnc", 'a'], ["'a'yEnc", 'a'], ["'a' YENC", 'a'],
            ["'a' yEncx", 'a'], ["'a' yEnc\u{301}", 'a'], ["'a'\u{3000}yEnc", 'a'], ["'a'\u{a0}[1/2]", 'a'], ["'a' [\u{661}/\u{662}]", 'a'], ["'a' (1/2", 'a'], ["'a' (1/)", 'a'], ["'a'  \"b\"", 'a'], ["x 'a' 'b' yEnc", 'b'],
            ["'a'b' yEnc", "a'b"], ["'a'b' 'c' yEnc", 'c'], ["'a' b' yEnc", "a' b"], ["It's 'a'\n'b'", 'a'], ['"a yEnc b.mkv" - [1/1]', 'a yEnc b.mkv'], ['"a yEnc b.mkv" yEnc', 'a yEnc b.mkv'], ['"a.mkv" - yEnc - "yEnc.mkv"', 'a.mkv'],
            ["'yEnc' yEnc", 'yEnc'], ["'x yEnc y' yEnc", 'x yEnc y'], ['yEnc "a.mkv"', 'a.mkv'], ['"a.mkv" yEnc yEnc', 'a.mkv'],
            ['[1/1] - "Xk29dPq8LmZ0Yt5R.par2" yEnc', 'README'], ['[1/1] - "Readable.Name.rar" yEnc', 'README'], ['[1/1] - "Readable.Name.par2" yEnc', 'README'], ['[1/1] - "Readable.Name.rar" yEnc', "\u{4e2d}\u{6587}"],
            ['[1/1] - "Readable.Name.rar" yEnc', 'file.\u{4e2d}\u{6587}'], ['[1/1] - "Readable.Name.par2" yEnc', 'file.\u{4e2d}\u{6587}'], ['[1/1] - "Readable.Name.rar" yEnc', 'file.\u{e9}'], ['[1/1] - "Readable.Name.rar" yEnc', 'file.a b'],
            ['[1/1] - "Readable.Name.par2:x" yEnc', 'payload.mkv'], ['[1/1] - "Readable.Name.rar:x.par2" yEnc', 'payload.mkv'], ['[1/1] - "Readable.Name.rar:x.par2" yEnc', 'set.par2'], ['[1/1] - "x:y" yEnc', 'set.par2'],
            ['"S01E02Movie.mkv" yEnc', 'x.mkv'], ['"4KMovie.mkv" yEnc', 'x.mkv'], ['"3DMovie2026.mkv" yEnc', 'x.mkv'], ['"Movie4K.mkv" yEnc', 'x.mkv'], ['"S01E02.mkv" yEnc', 'x.mkv'], ['"BQ2Documentary.mkv" yEnc', 'x.mkv'],
            ['"XFilesDocumentary2026.mkv" yEnc', 'x.mkv'], ['"A1B2C3D4E5F6G7H8I9J0.mkv" yEnc', 'x.mkv'], ['"Abc1Def2Ghi3Jkl4Mno5Pqr6.mkv" yEnc', 'x.mkv'], ['"Abcdefghijklmnopqrs1.mkv" yEnc', 'x.mkv'],
            ['"Abcdefghijklmnopq1r2.mkv" yEnc', 'x.mkv'], ['"abcdefghijklmnop1rstu2.mkv" yEnc', 'x.mkv'], ['"Show.S01E01.mkv" yEnc', 'Show.S01E01.mkv'], ['"Xk29dPq8LmZ0Yt5R.S01E01.mkv" yEnc', 'x.mkv'],
            ['"Xk29dPq8LmZ0Yt5R.wmv" yEnc', 'x.wmv'], ['"Xk29dPq8LmZ0Yt5R.tar.wmv" yEnc', 'x.wmv'], ['"Xk29dPq8LmZ0Yt5R.S01E01" yEnc', 'x.mkv'], ['"Xk29dPq8LmZ0Yt5R.mkv.wmv" yEnc', 'x.mkv'],
            ['"C++ Primer.mkv" yEnc', 'C Primer.mkv'], ['"C++ Primer.mkv" yEnc', 'c-- primer.mkv'], ['"C++ Primer.mkv" yEnc', 'C++.Primer.mkv'], ['"F# In Action.mkv" yEnc', 'F In Action.mkv'], ['"F# In Action.mkv" yEnc', 'f#.in.action.mkv'],
            ['"AT&T Story.mkv" yEnc', 'AT T Story.mkv'], ['"AT&T Story.mkv" yEnc', 'at&t story.mkv'], ['"Rock & Roll.mkv" yEnc', 'Rock Roll.mkv'], ['"Rock & Roll.mkv" yEnc', 'rock&roll.MKV'],
            ['"Readable.Name.tar.lz4" yEnc', 'readable.name.tar.lz4'], ['"Readable.Name.tar.lzma" yEnc', 'readable.name.tar.lz'], ['"Readable.Name.txz" yEnc', 'readable.name.TXZ'], ['"Readable.Name.tbz2" yEnc', 'readable.name.tbz2'],
            ['"Readable.Name.zstd" yEnc', 'readable.name.zst'], ['"Readable.Name.br" yEnc', 'readable.name.br'], ['"Readable.Name.tar.br" yEnc', 'readable.name'], ['"Readable.Name.lz" yEnc', 'readable.name.lz4'],
            ['"Readable.Name.mkv.lz4" yEnc', 'readable.name.mkv'], ['"Xk29dPq8LmZ0Yt5R.lz4" yEnc', 'x.lz4'],
            ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.so'], ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.dylib'], ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.bundle'], ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.ko'],
            ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.node'], ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.class'], ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.wasm'], ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.pyc'],
            ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'payload.PYO'], ['[1/9] - "Xk29dPq8LmZ0Yt5R.so" yEnc', 'lib.so'], ['Release yEnc', 'lib.so'], ['Release lib.so yEnc', 'lib.so'],
            ['[1/9] - "Setup.exe" yEnc', "Setup.ex\u{e9}"], ['[1/9] - "Setup.exe:\u{e9}" yEnc', 'Setup.exe'], ['[1/9] - "Setup.exe:\u{e9}" yEnc', "Setup.exe:\u{c9}"], ['[1/9] - "Setup.exe:\u{e9}" yEnc', "Setup.exe:\u{e9}"],
            ["[1/9] - \"Readable.Name.m\u{c9}\" yEnc", "Readable.Name.m\u{e9}"], ["[1/9] - \"Readable.Name.e\u{301}\" yEnc", "Readable.Name.\u{e9}"], ['[1/9] - "Readable.Name.MKV" yEnc', 'readable.name.mkv'],
            ['"Setup.exe"', 'other.exe'], ['"Setup.exe"', 'SETUP.EXE'], ['"Setup.exe"', 'setup.dll'], ['"Xk29dPq8LmZ0Yt5R.iso"', 'x.exe'], ['"Xk29dPq8LmZ0Yt5R.exe"', 'x.EXE '],
            ['"Xk29dPq8LmZ0Yt5R.mkv"', 'setup.exe::$DATA'], ['"Xk29dPq8LmZ0Yt5R.mkv"', 'setup.exe:'], ['"Xk29dPq8LmZ0Yt5R.mkv"', 'a:b.exe'], ['"Xk29dPq8LmZ0Yt5R.exe::$DATA"', 'x.exe'],
            ['"Xk29dPq8LmZ0Yt5R.exe"', "x.exe. . \t"], ['"Setup.cmd:ads.exe"', 'x.cmd'],
            ['Game Setup.exe yEnc (1/2)', 'p.exe'], ['Game (setup.exe) yEnc', 'setup.exe'], ['Game _setup.exe yEnc', 'setup.exe'], ['Game setup.exe2 yEnc', 'setup.exe'],
            ["Game \u{17f}etup.exe yEnc", 'setup.exe'], ["Game \u{212a}.exe yEnc", 'k.exe'], ["Game \u{212a}.exe yEnc", 'K.EXE'], ["Game \u{e9}.exe yEnc", "\u{e9}.exe"],
            ["Game \u{c9}.exe yEnc", "\u{e9}.exe"], ['Game a.exe yEnc', "a\xff.exe"], ["Game \u{e9}.exe yEnc", "\xe9.exe"], ['Game 1.iso yEnc', '1.iso'], ['Game yEnc', 'disk.vhdx'],
            ['"Xk29dPq8LmZ0Yt5R.par2"', 'Xk29dPq8LmZ0Yt5R.mkv'], ['"Xk29dPq8LmZ0Yt5R.PAR2"', 'payload.mkv'], ['"Xk29dPq8LmZ0Yt5R.par2"', 'b7Qe1ZrT9wXy.vol03+04.PAR2'],
            ['"Xk29dPq8LmZ0Yt5R.par2"', 'Xk29dPq8LmZ0Yt5R'], ['"Xk29dPq8LmZ0Yt5R.vol00+01.par2"', 'payload.par2'], ['"o9aeI3YpDwgHGoJ2ZsCm.par2 "', 'x.mkv'],
            ['"TheLordOfTheRingsReturnOfTheKing.mkv"', 'x.mkv'], ['"thelordoftheringsreturn.mkv"', 'x.mkv'], ['"THELORDOFTHERINGS.mkv"', 'x.mkv'], ['"TheLordOfTheRings1999.mkv"', 'x.mkv'],
            ['"ABc1defghijkl.mkv"', 'x.mkv'], ['"Abc1Def2.mkv"', 'x.mkv'], ['"abcDefghij1234.mkv"', 'x.mkv'], ['"Abcdefghij12.mkv"', 'x.mkv'], ['"abcdefghijk12ab.mkv"', 'x.mkv'],
            ['"Movie.Name.Long.mkv"', 'Movie.Name.Long.2.mkv'], ['"Movie.Name.Long.2.mkv"', 'Movie.Name.Long.mkv'], ['"Readable.Show.Season1.mkv"', 'Readable.Show.Season12.mkv'],
            ['"Readable.Show.Season.mkv"', 'Readable.Show.Seasonٌ.mkv'], ['"Readable.Show.Season.mkv"', 'Readable.Show.Season٣.mkv'], ['"Readable.Show.Season.mkv"', 'Readable.Show.SeasonExtra.mkv'],
            ['"abcdefgh'.str_repeat('.mkv', 300).'x"', 'zzzzzzzz.mkv'], ['"abcdefgh'.str_repeat('.mkv', 300).'"', 'abcdefgh.mkv'],
        ];
    }

    /**
     * Benchmark strata: name => [category, description]. Common strata model the ordinary
     * subjects a release carries; uncommon ones are the shapes the rule special-cases.
     *
     * @return array<string, array{category: string, description: string}>
     */
    public static function strata(): array
    {
        return [
            'readable-quoted' => ['category' => 'common', 'description' => 'one quoted readable filename, counter and yEnc tail'],
            'obfuscated-quoted' => ['category' => 'common', 'description' => 'one quoted random-token filename'],
            'unquoted' => ['category' => 'common', 'description' => 'no quoted filename (single-pass fast path)'],
            'multi-quote-apostrophe' => ['category' => 'uncommon', 'description' => 'several quoted values, apostrophes and single quotes'],
            'unicode' => ['category' => 'uncommon', 'description' => 'Cyrillic, Greek, CJK and accented names (lowercase and lookarounds)'],
            'invalid-utf8' => ['category' => 'uncommon', 'description' => 'subject with an invalid UTF-8 byte (preg failure path)'],
            'long-subject' => ['category' => 'uncommon', 'description' => 'subject of roughly 1 KB with 24 quoted tags before the filename'],
        ];
    }

    /**
     * Deterministic pairs for one benchmark stratum; no corruption or noise beyond the stratum's.
     *
     * @return list<array{string, string}>
     */
    public static function stratum(string $name, int $count, int $seed): array
    {
        $generator = new self($seed);
        $generator->plain = true;
        $ordinary = [0, 1, 2, 3, 11];
        [$generator->styles, $generator->templates] = match ($name) {
            'readable-quoted' => [[0, 1, 2, 3, 8], $ordinary],
            'obfuscated-quoted' => [[5, 6, 7], $ordinary],
            'unquoted' => [[0, 1, 2, 3, 5], [5]],
            'multi-quote-apostrophe' => [[0, 1, 9], [6, 7, 8, 9, 10]],
            'unicode' => [[4], [0, 1, 2, 3, 6]],
            'invalid-utf8', 'long-subject' => [[0, 1, 2, 3], [0, 1]],
            default => throw new \InvalidArgumentException('Unknown stratum '.$name),
        };
        $pairs = [];
        for ($index = 0; $index < $count; $index++) {
            [$subject, $actual] = $generator->pair();
            if ($name === 'invalid-utf8') {
                $at = $generator->below(strlen($subject) + 1);
                $subject = substr($subject, 0, $at)."\xff".substr($subject, $at);
            } elseif ($name === 'long-subject') {
                $tags = '';
                for ($tag = 0; $tag < 24; $tag++) {
                    $tags .= '"'.$generator->name().'" - ';
                }
                $subject = $tags.$subject;
            }
            $pairs[] = [$subject, $actual];
        }

        return $pairs;
    }

    /** @return array{string, string} */
    private function pair(): array
    {
        $declared = $this->name();
        $subject = $this->subject($declared);
        $actual = $this->actual($declared);
        if (! $this->plain && $this->chance(5)) {
            $subject = $this->corrupt($subject);
        }
        if (! $this->plain && $this->chance(3)) {
            $actual = $this->corrupt($actual);
        }

        return [$subject, $actual];
    }

    private function name(): string
    {
        $style = $this->styles === null ? $this->below(10) : $this->pick($this->styles);
        if ($style < 4) {
            $words = [];
            for ($i = $this->between(2, 5); $i > 0; $i--) {
                $words[] = $this->pick(self::WORDS);
            }
            $name = implode($this->pick(['.', ' ', '_', '-', '.']), $words);
            if ($this->chance(40)) {
                $name .= $this->pick(['.S01E02', ' (2026)', '.2026.1080p', ' [ABCD1234]']);
            }
        } elseif ($style < 5) {
            $name = $this->pick(self::UNICODE_WORDS).$this->pick([' ', '.', '_']).$this->pick(self::UNICODE_WORDS).$this->pick(['', ' (2026) 1080p', '.2026']);
        } elseif ($style < 7) {
            $name = $this->token($this->between(8, 40), $this->pick(['abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789']));
        } elseif ($style < 8) {
            $name = $this->token($this->between(12, 36), '0123456789abcdef');
        } elseif ($style < 9) {
            $name = $this->pick(self::WORDS).$this->between(0, 2026);
        } else {
            $name = $this->pick(self::WORDS).$this->pick(["'s", '’s', '', '_x']).' '.$this->pick(self::WORDS);
        }

        return $name.$this->pick(self::CHAINS);
    }

    private function subject(string $declared): string
    {
        $part = $this->between(1, 99);
        $total = $this->between($part, 120);
        $counter = '['.$part.'/'.$total.']';
        $tag = $this->pick(['Tag Name', 'Group', 'Uploader', 'x']);
        $yenc = $this->pick([' yEnc', ' yenc', ' YENC', ' (1/2) yEnc', "\t(3/4)  yEnc", ' yEnc (1/8)', '', ' - yEnc']);
        $quote = $this->pick(['"', '"', '"', "'"]);
        $name = $quote.$declared.$quote;
        if ($quote === "'" && str_contains($declared, "'")) {
            $name = '"'.$declared.'"';
        }
        $subject = match ($this->templates === null ? $this->below(12) : $this->pick($this->templates)) {
            0 => $counter.' - '.$name.$yenc,
            1 => '"'.$tag.'" - '.$counter.' - '.$name.$yenc,
            2 => '"'.$tag.'" - '.$counter.' - '.$name,
            3 => $name.' - '.$counter.' - "'.$tag.'"'.$yenc,
            4 => $name,
            5 => $declared.' '.$counter.$yenc,
            6 => $counter.' '.$name.' "'.$this->name().'" '.$yenc,
            7 => $this->pick(self::WORDS)."'s ".$this->pick(self::WORDS).' '.$counter.' - '.$name.$yenc,
            8 => '"'.$this->name().'" "'.$declared.'" '.$counter.$yenc,
            9 => $counter.'- '.$name.' '.$this->pick(["'quoted'", "x'y'z", " 'single.mkv' ", "'é'"]),
            10 => $counter.' - "" '.$name.$yenc,
            default => $counter."  -\t".$name.$yenc,
        };
        if (! $this->plain && $this->chance(15)) {
            $subject = $this->pick([' ', "\t", "\n", "\0", "\x0b", "\x0c", "\u{a0}", '']).$subject.$this->pick([' ', "\r\n", "\0", "\x0c", '']);
        }

        return $subject;
    }

    private function actual(string $declared): string
    {
        $stem = preg_replace('/(?:\.(?:part\d+|vol\d+[+-]\d+|par2|rar|r\d{2,3}|\d{3}|7z|zip|mkv|avi|mp4|m4v|ts|nfo|sfv|nzb|epub|pdf|flac|mp3|iso|srt|txt|jpg|png))+\z/i', '', $declared) ?? $declared;

        return match ($this->below(16)) {
            0, 1, 2 => $declared,
            3 => strtoupper($declared),
            4 => mb_strtolower($declared),
            5 => str_replace(['.', ' '], ['_', '.'], $stem).$this->pick(self::CHAINS),
            6 => $stem.$this->pick(self::CHAINS),
            7 => $stem.'.Extra'.$this->pick(self::CHAINS),
            8 => substr($stem, 0, max(1, intdiv(strlen($stem), 2))).$this->pick(self::CHAINS),
            9 => $this->name(),
            10 => $this->token($this->between(8, 40), 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789').$this->pick(self::CHAINS),
            11 => $this->token(32, '0123456789abcdef'),
            12 => $declared.$this->pick(['.', ' ', "\t", '. ', '.exe', '.hta', '.sh', '.EXE.', ".mkv\n"]),
            13 => $this->pick(['setup.exe', 'run.cmd', 'x.pkg', 'a.dmg', '', '.mkv', 'x']),
            14 => '['.$stem.']'.$this->pick(self::CHAINS),
            default => $stem.$this->pick(["\u{e9}", '1', ' (1)', "\u{301}"]).$this->pick(self::CHAINS),
        };
    }

    private function corrupt(string $value): string
    {
        $bytes = $this->pick(["\x80", "\xff", "\xc3", "\xe2\x82", "\xed\xa0\x80", "\xf0\x9f\x98", "\0", "\r", "\n", '"', "'", "\xc0\xaf", "\u{fffe}", "\u{1f600}"]);
        $at = $value === '' ? 0 : $this->below(strlen($value) + 1);

        return match ($this->below(3)) {
            0 => substr($value, 0, $at).$bytes.substr($value, $at),
            1 => substr($value, 0, $at).$bytes.substr($value, $at + 1),
            default => $value.$bytes,
        };
    }

    private function token(int $length, string $alphabet): string
    {
        $token = '';
        for ($i = 0; $i < $length; $i++) {
            $token .= $alphabet[$this->below(strlen($alphabet))];
        }

        return $token;
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    private function pick(array $values): mixed
    {
        return $values[$this->below(count($values))];
    }

    private function chance(int $percent): bool
    {
        return $this->below(100) < $percent;
    }

    private function between(int $low, int $high): int
    {
        return $low + $this->below($high - $low + 1);
    }

    private function below(int $limit): int
    {
        $this->state = ($this->state * 1664525 + 1013904223) & 0xFFFFFFFF;

        return (int) (($this->state >> 8) % $limit);
    }
}
