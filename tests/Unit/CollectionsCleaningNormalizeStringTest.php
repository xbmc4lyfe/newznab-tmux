<?php

namespace Tests\Unit;

use App\Services\CollectionsCleaningService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * normalizeString() feeds the collection hash, so its output must stay identical to
 * the original detection over every mbstring encoding, quirks included.
 */
class CollectionsCleaningNormalizeStringTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function subjects(): array
    {
        return [
            'typical subject' => ['Some.Show.S01E02.1080p.WEB.h264-GROUP [01/50] - "some.show.s01e02.part01.rar" yEnc'],
            'collapses whitespace' => ["  padded   subject\t\twith  gaps  "],
            'empty' => [''],
            'whitespace only' => ["   \t  "],
            'two ascii bytes detected as UCS-2' => ['!P'],
            'utf-8 over every encoding but UCS-2 over a UTF/UCS subset' => ['S/3/S*Z%7}'],
            'four ascii bytes detected as UCS-2' => ['zzzz'],
            'four ascii bytes detected as UCS-4' => ['3333'],
            'even-length run detected as UCS-2' => [str_repeat('3', 24)],
            'odd-length run' => [str_repeat('Q', 25)],
            'utf-7 sequence' => ['+ADw-script+AD4-'],
            'plus that is not utf-7' => ['abc+def-ghi'],
            'utf7-imap sequence' => ['&ADw-name&AD4-'],
            'html entity' => ['a&amp;b'],
            'hz escape' => ['~{<:Ky2;S{#,NpJ)l6HK!#~}'],
            'tilde' => ['a~b{c}'],
            'quoted-printable look-alike' => ['abc=41=3Ddef'],
            'base64 look-alike' => ['SGVsbG8gV29ybGQ='],
            'encoded-word look-alike' => ['=?utf-8?B?SGk=?='],
            'backslash and yen-like bytes' => ['C:\\path\\to\\file'],
            'valid utf-8 latin' => ['café ünïcode'],
            'valid utf-8 cjk' => ['日本語 テスト'],
            'emoji' => ['emoji 😀'],
            'latin-1 bytes' => ["caf\xe9 latin1"],
            'cp1252 quotes' => ["\x93quoted\x94 cp1252"],
            'invalid utf-8' => ["bad \xc3\x28 utf8"],
            'nul byte' => ["\x00nul"],
            'control character' => ["bell\x07char"],
            'delete character' => ["del\x7fchar"],
            'escape sequence' => ["\x1b\$B\x24\x22\x1b(B"],
        ];
    }

    #[DataProvider('subjects')]
    public function test_matches_detection_over_every_encoding(string $subject): void
    {
        $this->assertSame(self::reference($subject), $this->normalize($subject));
    }

    public function test_matches_detection_over_every_encoding_for_fuzzed_ascii(): void
    {
        mt_srand(20261010);
        for ($i = 0; $i < 5000; $i++) {
            $length = mt_rand(0, 64);
            if ($i % 4 === 0) {
                $subject = str_repeat(chr(mt_rand(0x20, 0x7E)).($i % 8 === 0 ? chr(mt_rand(0x20, 0x7E)) : ''), intdiv($length, 2) + 1);
            } else {
                $subject = '';
                for ($j = 0; $j < $length; $j++) {
                    $subject .= chr(mt_rand(0x20, 0x7E));
                }
            }

            $this->assertSame(self::reference($subject), $this->normalize($subject), 'Subject: '.json_encode($subject));
        }
    }

    public function test_repeated_subjects_return_the_memoized_result(): void
    {
        $this->assertSame(self::reference('S/3/S*Z%7}'), $this->normalize('S/3/S*Z%7}'));
        $this->assertSame(self::reference('S/3/S*Z%7}'), $this->normalize('  S/3/S*Z%7}  '));
    }

    public function test_collapses_and_trims_whitespace(): void
    {
        $this->assertSame('a b c', $this->normalize("  a  b\t\tc \n"));
    }

    /**
     * The implementation this replaced, kept as the reference behaviour.
     */
    private static function reference(string $subject): string
    {
        return mb_convert_encoding(trim(preg_replace('/\s\s+/', ' ', $subject)), 'UTF-8', mb_list_encodings());
    }

    private function normalize(string $subject): string
    {
        $cleaner = new class extends CollectionsCleaningService
        {
            public function normalize(string $subject): string
            {
                return $this->normalizeString($subject);
            }
        };

        return $cleaner->normalize($subject);
    }
}
